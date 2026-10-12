<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Unit\Paypage;

use PHPUnit\Framework\TestCase;
use Piston\Sips\Configuration\Credentials;
use Piston\Sips\Exception\InvalidField;
use Piston\Sips\Field\Amount;
use Piston\Sips\Field\TransactionReference;
use Piston\Sips\Paypage\CaptureMode;
use Piston\Sips\Paypage\OrderChannel;
use Piston\Sips\Paypage\PaymentRequest;
use Piston\Sips\Security\HmacSha256Seal;

final class PaymentRequestTest extends TestCase
{
    public function test_it_builds_the_mandatory_fields_in_a_fixed_order(): void
    {
        $fields = $this->request()->fields(Credentials::simulation(), new HmacSha256Seal);

        $this->assertSame([
            'merchantId' => '002001000000002',
            'keyVersion' => '1',
            'sealAlgorithm' => 'HMAC-SHA-256',
            'amount' => '2500',
            'currencyCode' => '978',
            'orderChannel' => 'INTERNET',
            'captureMode' => 'AUTHOR_CAPTURE',
            'captureDay' => '0',
            'normalReturnUrl' => 'https://shop.test/retour',
        ], $fields);
    }

    /**
     * The algorithm is declared in the message, because the platform's default
     * is the one it recommends against.
     */
    public function test_the_seal_algorithm_is_part_of_the_request(): void
    {
        $fields = $this->request()->fields(Credentials::simulation(), new \Piston\Sips\Security\Sha256Seal);

        $this->assertSame('SHA-256', $fields['sealAlgorithm']);
    }

    public function test_an_absent_optional_field_is_left_out(): void
    {
        $fields = $this->request()->fields(Credentials::simulation(), new HmacSha256Seal);

        foreach (['transactionReference', 'automaticResponseUrl', 'orderId', 'customerContact.email', 'returnContext'] as $absent) {
            $this->assertArrayNotHasKey($absent, $fields);
        }
    }

    public function test_it_carries_the_optional_fields_it_is_given(): void
    {
        $request = new PaymentRequest(
            amount: Amount::fromMinor(2500, 'EUR'),
            normalReturnUrl: 'https://shop.test/retour',
            reference: TransactionReference::of('REF001'),
            automaticResponseUrl: 'https://shop.test/notification',
            orderId: 'ORD101',
            customerEmail: 'ada@example.com',
            customerIpAddress: '127.0.0.1',
            returnContext: 'cart=42',
            statementReference: 'MYSHOP',
        );

        $fields = $request->fields(Credentials::simulation(), new HmacSha256Seal);

        $this->assertSame('REF001', $fields['transactionReference']);
        $this->assertSame('https://shop.test/notification', $fields['automaticResponseUrl']);
        $this->assertSame('ORD101', $fields['orderId']);
        $this->assertSame('ada@example.com', $fields['customerContact.email']);
        $this->assertSame('127.0.0.1', $fields['customerIpAddress']);
        $this->assertSame('cart=42', $fields['returnContext']);
        $this->assertSame('MYSHOP', $fields['statementReference']);
    }

    /**
     * A shop configured to generate its own references must be able to send
     * none at all - sending one is an error on that shop.
     */
    public function test_the_transaction_reference_is_optional(): void
    {
        $fields = $this->request()->fields(Credentials::simulation(), new HmacSha256Seal);

        $this->assertArrayNotHasKey('transactionReference', $fields);
    }

    public function test_the_capture_mode_and_channel_are_declared(): void
    {
        $request = new PaymentRequest(
            amount: Amount::fromMinor(100, 'EUR'),
            normalReturnUrl: 'https://shop.test/retour',
            orderChannel: OrderChannel::MailOrTelephone,
            captureMode: CaptureMode::Validation,
            captureDay: 7,
        );

        $fields = $request->fields(Credentials::simulation(), new HmacSha256Seal);

        $this->assertSame('MOTO', $fields['orderChannel']);
        $this->assertSame('VALIDATION', $fields['captureMode']);
        $this->assertSame('7', $fields['captureDay']);
    }

    public function test_a_relative_return_url_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('expected an absolute http(s) URL');

        new PaymentRequest(amount: Amount::fromMinor(100, 'EUR'), normalReturnUrl: '/retour');
    }

    public function test_a_relative_automatic_response_url_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('automaticResponseUrl');

        new PaymentRequest(
            amount: Amount::fromMinor(100, 'EUR'),
            normalReturnUrl: 'https://shop.test/ok',
            automaticResponseUrl: 'notification',
        );
    }

    public function test_an_email_that_is_not_one_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('customerContact.email');

        new PaymentRequest(
            amount: Amount::fromMinor(100, 'EUR'),
            normalReturnUrl: 'https://shop.test/ok',
            customerEmail: 'not-an-email',
        );
    }

    public function test_an_impossible_capture_day_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('expected 0 to 99 days');

        new PaymentRequest(
            amount: Amount::fromMinor(100, 'EUR'),
            normalReturnUrl: 'https://shop.test/ok',
            captureDay: 100,
        );
    }

    /** For a documented field this library has no named argument for yet. */
    public function test_extra_fields_are_sent_as_given(): void
    {
        $request = new PaymentRequest(
            amount: Amount::fromMinor(100, 'EUR'),
            normalReturnUrl: 'https://shop.test/ok',
            extra: ['paymentMeanBrandList' => 'VISA,MASTERCARD', 'fraudData.bypass3DS' => 'ALL'],
        );

        $fields = $request->fields(Credentials::simulation(), new HmacSha256Seal);

        $this->assertSame('VISA,MASTERCARD', $fields['paymentMeanBrandList']);
        $this->assertSame('ALL', $fields['fraudData.bypass3DS']);
    }

    public function test_an_extra_field_cannot_overwrite_the_request_itself(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('"amount" is already built from the request itself');

        (new PaymentRequest(
            amount: Amount::fromMinor(100, 'EUR'),
            normalReturnUrl: 'https://shop.test/ok',
            extra: ['amount' => '1'],
        ))->fields(Credentials::simulation(), new HmacSha256Seal);
    }

    private function request(): PaymentRequest
    {
        return new PaymentRequest(
            amount: Amount::fromMinor(2500, 'EUR'),
            normalReturnUrl: 'https://shop.test/retour',
        );
    }
}
