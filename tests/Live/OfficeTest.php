<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Live;

use PHPUnit\Framework\TestCase;
use Piston\Sips\Configuration\Credentials;
use Piston\Sips\Configuration\Environment;
use Piston\Sips\Field\Amount;
use Piston\Sips\Field\TransactionReference;
use Piston\Sips\Office\OfficeRequest;
use Piston\Sips\Response\ResponseCode;
use Piston\Sips\Security\Sha256Seal;
use Piston\Sips\Sips;

/**
 * The cash-management services, against Worldline's test platform.
 *
 * Every call here names a transaction that does not exist, so nothing can be
 * captured, cancelled or refunded. That is the point: the platform answers
 * `25` (transaction not found) **only once it has read the request**, and `34`
 * (suspected fraud) when the seal is wrong - so the pair of answers is a
 * yes/no on the seal rule, the field names and the interface version, from the
 * only authority on the subject.
 *
 * The credentials are the ones printed in the Sips Office JSON guide for its
 * test environment.
 */
final class OfficeTest extends TestCase
{
    private const MERCHANT_ID = '201040040170001';

    private const SECRET_KEY = 'rxSP61eeP_oNi5TxCD7Ngy9YcwC8MLw6OlmFGGcsY54';

    public function test_a_cancel_reaches_the_platform_and_is_answered(): void
    {
        $result = $this->sips()->cancel(OfficeRequest::cancel(
            $this->unknownTransaction(),
            Amount::fromMinor(1000, 'EUR'),
        ));

        $this->assertTrue($result->sealVerified, 'the answer must be sealed by our own key');
        $this->assertSame(ResponseCode::TransactionNotFound, $result->responseCode());
        $this->assertTrue($result->isTransactionUnknown());
        $this->assertFalse($result->isDone());
        $this->assertFalse($result->isWorthRetrying());
        $this->assertNull($result->errorFieldName(), 'a named field means the request was malformed');
    }

    public function test_a_refund_reaches_the_platform_and_is_answered(): void
    {
        $result = $this->sips()->refund(OfficeRequest::refund(
            $this->unknownTransaction(),
            Amount::fromMinor(1000, 'EUR'),
        ));

        $this->assertTrue($result->sealVerified);
        $this->assertSame(ResponseCode::TransactionNotFound, $result->responseCode());
        $this->assertNull($result->errorFieldName());
    }

    public function test_a_validate_reaches_the_platform_and_is_answered(): void
    {
        $result = $this->sips()->validate(OfficeRequest::validate(
            $this->unknownTransaction(),
            Amount::fromMinor(1000, 'EUR'),
        ));

        $this->assertTrue($result->sealVerified);
        $this->assertSame(ResponseCode::TransactionNotFound, $result->responseCode());
        $this->assertNull($result->errorFieldName());
    }

    /**
     * The control. Without it, the three tests above would pass for a library
     * that sent nonsense and a platform that answered 25 to everything.
     */
    public function test_the_platform_refuses_a_seal_computed_with_the_wrong_key(): void
    {
        $sips = new Sips(
            Credentials::of(self::MERCHANT_ID, 'not-the-right-key'),
            Environment::Test,
        );

        $result = $sips->cancel(OfficeRequest::cancel(
            $this->unknownTransaction(),
            Amount::fromMinor(1000, 'EUR'),
        ));

        $code = $result->responseCode();

        $this->assertSame(ResponseCode::SuspectedFraudSeal, $code);
        $this->assertTrue($code->isMerchantsFault());
        $this->assertFalse($result->isDone());
    }

    /**
     * And the other algorithm, declared as such: the platform honours
     * `sealAlgorithm` rather than assuming one.
     */
    public function test_the_platform_accepts_the_plain_sha256_algorithm_when_it_is_declared(): void
    {
        $sips = new Sips(
            Credentials::of(self::MERCHANT_ID, self::SECRET_KEY),
            Environment::Test,
            new Sha256Seal,
        );

        $result = $sips->cancel(OfficeRequest::cancel(
            $this->unknownTransaction(),
            Amount::fromMinor(1000, 'EUR'),
        ));

        $this->assertSame(ResponseCode::TransactionNotFound, $result->responseCode());
        $this->assertTrue($result->sealVerified);
    }

    /**
     * The answer is sealed with the algorithm the request asked for, so a
     * verifier that only knew one would read half of them as forgeries.
     */
    public function test_the_answer_is_sealed_with_the_algorithm_of_the_request(): void
    {
        $hmac = $this->sips()->cancel(OfficeRequest::cancel($this->unknownTransaction(), Amount::fromMinor(1000, 'EUR')));

        $sha256 = (new Sips(
            Credentials::of(self::MERCHANT_ID, self::SECRET_KEY),
            Environment::Test,
            new Sha256Seal,
        ))->cancel(OfficeRequest::cancel($this->unknownTransaction(), Amount::fromMinor(1000, 'EUR')));

        // Same request, same answer code, two different seals on the wire.
        $this->assertSame($hmac->rawResponseCode(), $sha256->rawResponseCode());
        $this->assertNotSame($hmac->field('seal'), $sha256->field('seal'));
        $this->assertTrue($hmac->sealVerified);
        $this->assertTrue($sha256->sealVerified);
    }

    private function sips(): Sips
    {
        return new Sips(Credentials::of(self::MERCHANT_ID, self::SECRET_KEY), Environment::Test);
    }

    private function unknownTransaction(): TransactionReference
    {
        // Unique per run, so no earlier run can have created it.
        return TransactionReference::of('NOSUCH'.date('ymdHis').random_int(100, 999));
    }
}
