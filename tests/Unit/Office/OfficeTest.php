<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Unit\Office;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Piston\Sips\Configuration\Environment;
use Piston\Sips\Exception\InvalidField;
use Piston\Sips\Exception\MissingHttpClient;
use Piston\Sips\Exception\ServiceFailed;
use Piston\Sips\Field\Amount;
use Piston\Sips\Field\Currency;
use Piston\Sips\Field\TransactionReference;
use Piston\Sips\Office\OfficeRequest;
use Piston\Sips\Office\OfficeResult;
use Piston\Sips\Office\Operation;
use Piston\Sips\Response\ResponseCode;
use Piston\Sips\Security\ConcatenatedValues;
use Piston\Sips\Security\HmacSha256Seal;
use Piston\Sips\Security\Sha256Seal;
use Piston\Sips\Sips;
use Piston\Sips\Tests\Support\FakeHttpClient;
use Piston\Sips\Tests\Support\Platform;
use Piston\Sips\Tests\Support\TransportFailure;

final class OfficeTest extends TestCase
{
    public function test_the_three_operations_have_their_own_path(): void
    {
        $this->assertSame('cashManagement/validate', Operation::Validate->path());
        $this->assertSame('cashManagement/cancel', Operation::Cancel->path());
        $this->assertSame('cashManagement/refund', Operation::Refund->path());
        $this->assertTrue(Operation::Refund->isRefund());
        $this->assertFalse(Operation::Cancel->isRefund());
    }

    /** The field set the platform actually accepts, and nothing more. */
    public function test_a_cancel_sends_exactly_the_verified_fields(): void
    {
        $fields = OfficeRequest::cancel(
            TransactionReference::of('REF001'),
            Amount::fromMinor(1000, 'EUR'),
        )->fields(Platform::credentials(), new HmacSha256Seal);

        $this->assertSame([
            'merchantId' => '002001000000002',
            'keyVersion' => '1',
            'sealAlgorithm' => 'HMAC-SHA-256',
            'interfaceVersion' => 'CR_WS_2.17',
            'transactionReference' => 'REF001',
            'operationAmount' => '1000',
            'currencyCode' => '978',
        ], $fields);
    }

    /**
     * `CR_WS_`, not `IR_WS_`: the latter answers 12 with
     * `errorFieldName: interfaceVersion`.
     */
    public function test_the_interface_version_is_the_cash_management_one(): void
    {
        $this->assertStringStartsWith('CR_WS_', OfficeRequest::INTERFACE_VERSION);
    }

    public function test_a_refund_of_nothing_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('a refund of nothing is not a refund');

        OfficeRequest::refund(TransactionReference::of('REF001'), Amount::zero('EUR'));
    }

    public function test_an_extra_field_cannot_overwrite_the_request(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('"operationAmount" is already built');

        OfficeRequest::cancel(
            TransactionReference::of('REF001'),
            Amount::fromMinor(1000, 'EUR'),
            ['operationAmount' => '1'],
        )->fields(Platform::credentials(), new HmacSha256Seal);
    }

    public function test_it_posts_json_to_the_operation_url(): void
    {
        $http = FakeHttpClient::replying('{"responseCode":"00","seal":"'.$this->sealOf(['responseCode' => '00']).'"}');

        $result = $this->sips($http)->cancel(OfficeRequest::cancel(
            TransactionReference::of('REF001'),
            Amount::fromMinor(1000, 'EUR'),
        ));

        $this->assertSame('POST', $http->lastRequest()->getMethod());
        $this->assertSame(
            'https://office-server.test.sips-services.com/rs-services/v2/cashManagement/cancel',
            (string) $http->lastRequest()->getUri(),
        );
        $this->assertSame('application/json', $http->lastRequest()->getHeaderLine('Content-Type'));
        $this->assertTrue($result->isDone());
    }

    public function test_the_body_carries_the_seal_of_its_own_values(): void
    {
        $http = FakeHttpClient::replying('{"responseCode":"25"}');

        $this->sips($http)->refund(OfficeRequest::refund(
            TransactionReference::of('REF001'),
            Amount::fromMinor(1000, 'EUR'),
        ));

        $body = $http->lastBodyFields();
        $seal = $body['seal'];
        unset($body['seal']);

        /** @var array<string, string> $body */
        $this->assertSame(
            (new HmacSha256Seal)->compute(ConcatenatedValues::of($body), Platform::credentials()->key)->value(),
            $seal,
        );

        // And the string it was computed over excludes the two fields it must.
        $this->assertStringNotContainsString('HMAC-SHA-256', ConcatenatedValues::of($body));
    }

    public function test_the_declared_algorithm_is_the_one_used(): void
    {
        $http = FakeHttpClient::replying('{"responseCode":"25"}');

        $sips = new Sips(
            Platform::credentials(),
            Environment::Test,
            new Sha256Seal,
            http: $http,
            requests: new Psr17Factory,
            streams: new Psr17Factory,
        );

        $sips->cancel(OfficeRequest::cancel(TransactionReference::of('REF001'), Amount::fromMinor(1000, 'EUR')));

        $this->assertSame('SHA-256', $http->lastBodyFields()['sealAlgorithm']);
    }

    /**
     * Sips seals what it answers, and an answer that cannot be verified is not
     * an answer however good its response code looks.
     */
    public function test_an_unsealed_acceptance_is_not_done(): void
    {
        $http = FakeHttpClient::replying('{"responseCode":"00"}');

        $result = $this->sips($http)->cancel(OfficeRequest::cancel(
            TransactionReference::of('REF001'),
            Amount::fromMinor(1000, 'EUR'),
        ));

        $this->assertSame(ResponseCode::Accepted, $result->responseCode());
        $this->assertFalse($result->sealVerified);
        $this->assertFalse($result->isDone(), 'an unverified 00 must not count as done');
    }

    public function test_a_response_sealed_with_another_key_is_not_done(): void
    {
        $forged = (new HmacSha256Seal)->compute('00', \Piston\Sips\Security\SecretKey::of('another-key'))->value();
        $http = FakeHttpClient::replying('{"responseCode":"00","seal":"'.$forged.'"}');

        $result = $this->sips($http)->cancel(OfficeRequest::cancel(
            TransactionReference::of('REF001'),
            Amount::fromMinor(1000, 'EUR'),
        ));

        $this->assertFalse($result->sealVerified);
        $this->assertFalse($result->isDone());
    }

    public function test_a_transport_failure_is_not_a_refusal(): void
    {
        $http = FakeHttpClient::failing(new TransportFailure('Connection timed out'));

        $this->expectException(ServiceFailed::class);
        $this->expectExceptionMessage('could not be reached: Connection timed out');

        $this->sips($http)->cancel(OfficeRequest::cancel(TransactionReference::of('REF001'), Amount::fromMinor(1000, 'EUR')));
    }

    public function test_an_http_error_is_not_an_answer(): void
    {
        $http = FakeHttpClient::replying('<html>Gateway Timeout</html>', 504);

        $this->expectException(ServiceFailed::class);
        $this->expectExceptionMessage('answered HTTP 504');

        $this->sips($http)->refund(OfficeRequest::refund(TransactionReference::of('REF001'), Amount::fromMinor(1000, 'EUR')));
    }

    public function test_a_body_that_is_not_json_is_not_an_answer(): void
    {
        $http = FakeHttpClient::replying('<html>maintenance</html>');

        $this->expectException(ServiceFailed::class);
        $this->expectExceptionMessage('not JSON');

        $this->sips($http)->validate(OfficeRequest::validate(TransactionReference::of('REF001'), Amount::fromMinor(1000, 'EUR')));
    }

    public function test_without_a_client_it_says_what_to_install(): void
    {
        $message = MissingHttpClient::create()->getMessage();

        $this->assertStringContainsString('PSR-18', $message);
        $this->assertStringContainsString('php-http/discovery', $message);
    }

    public function test_the_result_reads_the_platform_answer(): void
    {
        $result = OfficeResult::from([
            'responseCode' => '00',
            'newAmount' => 1500,
            'operationDateTime' => '2026-10-12T14:53:03+02:00',
        ], true);

        $this->assertTrue($result->isDone());
        $this->assertSame('15.00', $result->newAmount()?->toDecimal());
        $this->assertSame('2026-10-12 14:53:03', $result->occurredAt()?->format('Y-m-d H:i:s'));
        $this->assertNull($result->errorFieldName());
    }

    public function test_the_result_names_the_refused_states(): void
    {
        $unknown = OfficeResult::from(['responseCode' => '25'], true);
        $wrongState = OfficeResult::from(['responseCode' => '24'], true);
        $retryable = OfficeResult::from(['responseCode' => '90'], true);
        $malformed = OfficeResult::from(['responseCode' => '12', 'errorFieldName' => 'comment'], true);

        $this->assertTrue($unknown->isTransactionUnknown());
        $this->assertTrue($wrongState->isStateInvalid());
        $this->assertTrue($retryable->isWorthRetrying());
        $this->assertSame('comment', $malformed->errorFieldName());

        foreach ([$unknown, $wrongState, $retryable, $malformed] as $refused) {
            $this->assertFalse($refused->isDone());
        }
    }

    public function test_a_new_amount_can_be_read_in_another_currency(): void
    {
        $result = OfficeResult::from(['responseCode' => '00', 'newAmount' => 1500], true);

        $this->assertSame('1500', $result->newAmount(Currency::of('JPY'))?->toDecimal());
    }

    public function test_an_unreadable_new_amount_is_null(): void
    {
        $this->assertNull(OfficeResult::from(['newAmount' => 'lots'], true)->newAmount());
        $this->assertNull(OfficeResult::from([], true)->newAmount());
        $this->assertNull(OfficeResult::from(['operationDateTime' => 'yesterday'], true)->occurredAt());
    }

    private function sips(FakeHttpClient $http): Sips
    {
        $factory = new Psr17Factory;

        return new Sips(
            Platform::credentials(),
            Environment::Test,
            new HmacSha256Seal,
            http: $http,
            requests: $factory,
            streams: $factory,
        );
    }

    /** @param array<string, string> $fields */
    private function sealOf(array $fields): string
    {
        return (new HmacSha256Seal)
            ->compute(ConcatenatedValues::of($fields), Platform::credentials()->key)
            ->value();
    }
}
