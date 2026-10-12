<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Unit\Response;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Sips\Field\Amount;
use Piston\Sips\Field\TransactionReference;
use Piston\Sips\Response\PaymentResponse;
use Piston\Sips\Response\ResponseCode;
use Piston\Sips\Security\Encoding;
use Piston\Sips\Tests\Support\Vectors;

final class PaymentResponseTest extends TestCase
{
    /** The documented response: 104 fields, read without a single throw. */
    public function test_it_reads_the_documented_response(): void
    {
        $response = PaymentResponse::fromPost([
            'Data' => Vectors::responseData(),
            'Seal' => Vectors::RESPONSE_SEAL_HMAC,
            'InterfaceVersion' => 'HP_2.57',
        ]);

        $this->assertTrue($response->isAccepted());
        $this->assertSame(ResponseCode::Accepted, $response->responseCode());
        $this->assertSame('SIM20221114112037', $response->reference()?->value());
        $this->assertSame('039000254447216', $response->merchantId());
        $this->assertSame('1000', $response->field('amount'));

        $amount = $response->amount();

        $this->assertNotNull($amount);
        $this->assertSame('10.00', $amount->toDecimal());
        $this->assertSame('EUR', $amount->currency()->code());
        $this->assertSame('664865', $response->authorisationId());
        $this->assertSame('00', $response->acquirerResponseCode());
        $this->assertSame('############0600', $response->maskedPan());
        $this->assertSame('VISA', $response->paymentMeanBrand());
        $this->assertSame('CARD', $response->paymentMeanType());
        $this->assertSame('202401', $response->cardExpiry());
        $this->assertSame('3DS_V2', $response->authenticationProgram());
        $this->assertSame('NOT_PARTICIPATING', $response->authenticationStatus());
        $this->assertSame('2022-11-14 11:21:12', $response->occurredAt()?->format('Y-m-d H:i:s'));
        $this->assertSame(Vectors::RESPONSE_SEAL_HMAC, $response->seal?->value());
        $this->assertSame('HP_2.57', $response->interfaceVersion);
    }

    /**
     * The `null` literals in that response - there are dozens - must read as
     * absent and not as the word.
     */
    public function test_the_null_literals_of_the_documented_response_are_absent(): void
    {
        $response = PaymentResponse::fromPost(['Data' => Vectors::responseData()]);

        $this->assertNull($response->field('customerMobilePhone'));
        $this->assertNull($response->field('dccStatus'));
        $this->assertNull($response->field('challengeMode3DS'));
    }

    #[DataProvider('responseCodes')]
    public function test_only_zero_zero_is_a_payment(string $code, bool $accepted): void
    {
        $response = PaymentResponse::fromPost(['Data' => 'responseCode='.$code]);

        $this->assertSame($accepted, $response->isAccepted());
        $this->assertSame($code, $response->rawResponseCode());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function responseCodes(): iterable
    {
        yield 'accepted' => ['00', true];
        yield 'refused' => ['05', false];
        yield 'cancelled by the customer' => ['17', false];
        yield 'bad seal' => ['34', false];
        yield 'a code nobody has seen' => ['A7', false];
        yield 'empty' => ['', false];
    }

    public function test_a_code_it_does_not_know_reads_as_null_rather_than_guessing(): void
    {
        $response = PaymentResponse::fromPost(['Data' => 'responseCode=A7']);

        $this->assertNull($response->responseCode());
        $this->assertFalse($response->isAccepted());
        $this->assertSame('A7', $response->rawResponseCode());
    }

    /**
     * The whole class is reachable with nonsense in every field, because the
     * alternative is an unanswered server-to-server POST.
     */
    public function test_nothing_throws_on_a_payload_made_of_rubbish(): void
    {
        $response = PaymentResponse::fromPost([
            'Data' => 'responseCode=??|amount=lots|currencyCode=EUR|transactionReference=has spaces'
                .'|transactionDateTime=yesterday|merchantId=|panExpiryDate=never',
            'Seal' => 'not-a-seal',
            'InterfaceVersion' => '',
            'Encode' => 'rot13',
        ]);

        $this->assertNull($response->responseCode());
        $this->assertFalse($response->isAccepted());
        $this->assertNull($response->amount());
        $this->assertNull($response->reference());
        $this->assertNull($response->seal);
        $this->assertNull($response->occurredAt());
        $this->assertNull($response->merchantId());
        $this->assertSame('never', $response->cardExpiry());
    }

    public function test_an_empty_post_is_an_empty_response(): void
    {
        $response = PaymentResponse::fromPost([]);

        $this->assertSame([], $response->fields());
        $this->assertFalse($response->isAccepted());
        $this->assertNull($response->seal);
    }

    public function test_it_ignores_values_that_are_not_scalar(): void
    {
        $response = PaymentResponse::fromPost(['Data' => ['nested' => 'array']]);

        $this->assertSame('', $response->data->transmitted());
    }

    public function test_an_amount_without_its_currency_is_not_an_amount(): void
    {
        // Assuming euros would compare the right number against the wrong
        // money for a shop that sells in two currencies.
        $this->assertNull(PaymentResponse::fromPost(['Data' => 'amount=1000'])->amount());
        $this->assertNull(PaymentResponse::fromPost(['Data' => 'currencyCode=978'])->amount());
    }

    public function test_it_reads_an_amount_in_a_currency_without_a_minor_unit(): void
    {
        $response = PaymentResponse::fromPost(['Data' => 'amount=1000|currencyCode=392']);
        $amount = $response->amount();

        $this->assertNotNull($amount);
        $this->assertSame('1000', $amount->toDecimal());
        $this->assertSame('JPY', $amount->currency()->code());
    }

    public function test_it_reads_a_base64_encoded_response(): void
    {
        $plain = 'responseCode=00|amount=2500|currencyCode=978|transactionReference=REF001';

        $response = PaymentResponse::fromPost([
            'Data' => base64_encode($plain),
            'Encode' => 'base64',
        ]);

        $this->assertTrue($response->isAccepted());
        $this->assertSame('REF001', $response->reference()?->value());
        $this->assertSame(Encoding::Base64, $response->data->encoding());
        $this->assertSame(base64_encode($plain), $response->data->transmitted(), 'the seal covers the encoded form');
    }

    public function test_it_reads_the_date_in_both_shapes_sips_uses(): void
    {
        foreach (['2022-11-14T11:21:12+01:00', '2023-03-15.00:39:04+0100'] as $raw) {
            $response = PaymentResponse::fromPost(['Data' => 'transactionDateTime='.$raw]);

            $this->assertNotNull($response->occurredAt(), $raw.' should be readable');
        }
    }

    /**
     * A valid seal says the message is authentic. Only this says it is about
     * the order in front of you.
     */
    public function test_it_compares_itself_to_the_order_the_shop_stored(): void
    {
        $response = PaymentResponse::fromPost([
            'Data' => 'merchantId=002001000000002|transactionReference=REF001|amount=2500|currencyCode=978|responseCode=00',
        ]);

        $reference = TransactionReference::of('REF001');

        $this->assertTrue($response->matches($reference, Amount::fromMinor(2500, 'EUR')));
        $this->assertTrue($response->matches($reference, Amount::fromMinor(2500, 'EUR'), '002001000000002'));

        $this->assertFalse($response->matches(TransactionReference::of('REF002'), Amount::fromMinor(2500, 'EUR')));
        $this->assertFalse($response->matches($reference, Amount::fromMinor(2501, 'EUR')));
        $this->assertFalse($response->matches($reference, Amount::fromMinor(2500, 'USD')));
        $this->assertFalse($response->matches($reference, Amount::fromMinor(2500, 'EUR'), '999999999999999'));
    }

    public function test_the_return_context_comes_back_as_it_was_sent(): void
    {
        $response = PaymentResponse::fromPost(['Data' => 'returnContext=cart=42']);

        $this->assertSame('cart=42', $response->returnContext());
    }
}
