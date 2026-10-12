<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Piston\Sips\Configuration\Credentials;
use Piston\Sips\Configuration\Environment;
use Piston\Sips\Field\Amount;
use Piston\Sips\Field\TransactionReference;
use Piston\Sips\Office\OfficeRequest;
use Piston\Sips\Paypage\CaptureMode;
use Piston\Sips\Paypage\PaymentRequest;
use Piston\Sips\Security\ConcatenatedValues;
use Piston\Sips\Security\HmacSha256Seal;
use Piston\Sips\Security\Sha256Seal;
use Piston\Sips\Sips;
use Piston\Sips\Tests\Support\FakeHttpClient;
use Piston\Sips\Tests\Support\Platform;

/**
 * One order, from the form to the refund.
 *
 * The parts are tested on their own elsewhere; what this checks is that they
 * agree with each other - that the reference and amount a form was sealed with
 * are the ones a response is compared against, that the two seal shapes are
 * each used where they belong, and that the whole chain holds with the other
 * algorithm too.
 *
 * No network: the live suite does that. This is the same journey with the
 * platform played by a double, so it can also be put through the failures a
 * real platform will not produce on demand.
 */
final class PaymentJourneyTest extends TestCase
{
    public function test_a_payment_from_the_form_to_the_refund(): void
    {
        $sips = Sips::simulation();
        $reference = TransactionReference::of('REF7896543');
        $amount = Amount::fromMinor(2500, 'EUR');

        // 1. The form the customer is sent to.
        $form = $sips->form(new PaymentRequest(
            amount: $amount,
            normalReturnUrl: 'https://shop.test/retour',
            reference: $reference,
            automaticResponseUrl: 'https://shop.test/notification',
            orderId: 'ORD101',
            captureMode: CaptureMode::Validation,
            customerEmail: 'ada@example.com',
            returnContext: 'cart=42',
        ));

        $this->assertSame('https://payment-webinit.simu.sips-services.com/paymentInit', $form->action());
        $this->assertSame('HP_2.57', $form->interfaceVersion());

        // The seal, recomputed without the library's own algorithm object:
        // HMAC-SHA256 of the Data element exactly as it travels.
        $this->assertSame(
            hash_hmac('sha256', $form->data(), '002001000000002_KEY1'),
            $form->seal(),
        );

        // The browser sees escaped values; the seal covered the raw ones.
        $this->assertStringContainsString('name="Seal" value="'.$form->seal().'"', $form->toInputs());

        // 2. The response, on either channel - same fields, same seal.
        $post = Platform::sealedResponse([
            'merchantId' => '002001000000002',
            'transactionReference' => $reference->value(),
            'amount' => $amount->wireValue(),
            'currencyCode' => '978',
            'responseCode' => '00',
            'authorisationId' => '664865',
            'maskedPan' => '############0600',
            'paymentMeanBrand' => 'VISA',
            'holderAuthentProgram' => '3DS_V2',
            'returnContext' => 'cart=42',
            // The field a future version of the platform might add.
            'someFieldFromTheFuture' => 'surprise',
        ]);

        $response = $sips->response($post);

        $this->assertTrue($response->isAccepted());
        $this->assertTrue($response->matches($reference, $amount, '002001000000002'));
        $this->assertSame('664865', $response->authorisationId());
        $this->assertSame('############0600', $response->maskedPan());
        $this->assertSame('cart=42', $response->returnContext());
        $this->assertSame('surprise', $response->field('someFieldFromTheFuture'));
        $this->assertInstanceOf(HmacSha256Seal::class, $response->sealedWith());

        // 3. Captured when the goods ship, since it was taken in VALIDATION.
        $http = FakeHttpClient::replying($this->sealedReply(['responseCode' => '00', 'newAmount' => '2500']));

        $capture = $this->withTransport($http)->validate(OfficeRequest::validate($reference, $amount));

        $this->assertTrue($capture->isDone());
        $this->assertSame(
            'https://office-server.simu.sips-services.com/rs-services/v2/cashManagement/validate',
            (string) $http->lastRequest()->getUri(),
        );

        // The Office seal is the other shape: values, sorted by name, without
        // keyVersion and sealAlgorithm.
        $body = $http->lastBodyFields();
        $seal = $body['seal'];
        unset($body['seal']);

        /** @var array<string, string> $body */
        $this->assertSame(
            hash_hmac('sha256', ConcatenatedValues::of($body), '002001000000002_KEY1'),
            $seal,
        );

        // 4. And refunded when it comes back.
        $http = FakeHttpClient::replying($this->sealedReply(['responseCode' => '00', 'newAmount' => '1500']));

        $refund = $this->withTransport($http)->refund(
            OfficeRequest::refund($reference, Amount::fromMinor(1000, 'EUR')),
        );

        $this->assertTrue($refund->isDone());
        $this->assertSame('15.00', $refund->newAmount()?->toDecimal());
    }

    /**
     * The same journey with the response altered in flight, which is the only
     * reason any of the above is worth asserting.
     */
    public function test_a_response_that_was_tampered_with_is_refused(): void
    {
        $sips = Sips::simulation();

        $post = Platform::sealedResponse([
            'merchantId' => '002001000000002',
            'transactionReference' => 'REF7896543',
            'amount' => '2500',
            'currencyCode' => '978',
            'responseCode' => '00',
        ]);

        $post['Data'] = str_replace('amount=2500', 'amount=1', $post['Data']);

        $this->assertNull($sips->attemptResponse($post));
    }

    /**
     * And with the fields merely reordered, which no amount of reading the
     * values would catch.
     */
    public function test_a_response_whose_fields_were_reordered_is_refused(): void
    {
        $sips = Sips::simulation();

        $post = Platform::sealedResponse([
            'merchantId' => '002001000000002',
            'responseCode' => '00',
            'amount' => '2500',
        ]);

        $post['Data'] = 'amount=2500|merchantId=002001000000002|responseCode=00';

        $this->assertNull($sips->attemptResponse($post));
    }

    /**
     * An authentic response for the wrong money: the seal is valid and the
     * order still does not match.
     */
    public function test_an_authentic_response_for_another_amount_is_caught_by_the_shop(): void
    {
        $sips = Sips::simulation();

        $post = Platform::sealedResponse([
            'merchantId' => '002001000000002',
            'transactionReference' => 'REF7896543',
            'amount' => '100',
            'currencyCode' => '978',
            'responseCode' => '00',
        ]);

        $response = $sips->response($post);

        $this->assertTrue($response->isAccepted(), 'the message is authentic');
        $this->assertFalse(
            $response->matches(TransactionReference::of('REF7896543'), Amount::fromMinor(2500, 'EUR')),
            'and it is not the order the shop is waiting for',
        );
    }

    /**
     * The whole chain on the older algorithm, which is what a shop that
     * integrated years ago is still running.
     */
    public function test_the_same_journey_holds_with_the_plain_sha256_algorithm(): void
    {
        $sips = new Sips(Credentials::simulation(), Environment::Simulation, new Sha256Seal);

        $form = $sips->form(new PaymentRequest(
            amount: Amount::fromMinor(2500, 'EUR'),
            normalReturnUrl: 'https://shop.test/retour',
            reference: TransactionReference::of('REF001'),
        ));

        $this->assertStringContainsString('sealAlgorithm=SHA-256', $form->data());
        $this->assertSame(hash('sha256', $form->data().'002001000000002_KEY1'), $form->seal());

        $post = Platform::sealedResponse(
            ['merchantId' => '002001000000002', 'responseCode' => '00'],
            new Sha256Seal,
        );

        $this->assertInstanceOf(Sha256Seal::class, $sips->response($post)->sealedWith());
    }

    /** @param array<string, string> $fields */
    private function sealedReply(array $fields): string
    {
        $fields['seal'] = (new HmacSha256Seal)
            ->compute(ConcatenatedValues::of($fields), Credentials::simulation()->key)
            ->value();

        return (string) json_encode($fields);
    }

    private function withTransport(FakeHttpClient $http): Sips
    {
        $factory = new Psr17Factory;

        return new Sips(
            Credentials::simulation(),
            Environment::Simulation,
            new HmacSha256Seal,
            http: $http,
            requests: $factory,
            streams: $factory,
        );
    }
}
