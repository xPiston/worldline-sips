<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Unit\Response;

use PHPUnit\Framework\TestCase;
use Piston\Sips\Configuration\Credentials;
use Piston\Sips\Exception\SealMismatch;
use Piston\Sips\Response\ResponseVerifier;
use Piston\Sips\Security\Encoding;
use Piston\Sips\Security\HmacSha256Seal;
use Piston\Sips\Security\SealVerifier;
use Piston\Sips\Security\Sha256Seal;
use Piston\Sips\Tests\Support\Platform;

final class ResponseVerifierTest extends TestCase
{
    public function test_it_accepts_a_response_sealed_with_the_merchant_key(): void
    {
        $post = Platform::sealedResponse(['responseCode' => '00', 'amount' => '2500']);

        $response = $this->verifier()->verify($post);

        $this->assertTrue($response->isAccepted());
        $this->assertInstanceOf(HmacSha256Seal::class, $response->sealedWith());
    }

    /**
     * Without this one, the test above would pass for a verifier that accepts
     * everything.
     */
    public function test_it_refuses_a_response_whose_amount_was_changed(): void
    {
        $post = Platform::sealedResponse(['responseCode' => '00', 'amount' => '2500']);
        $post['Data'] = str_replace('amount=2500', 'amount=250000', $post['Data']);

        $this->expectException(SealMismatch::class);
        $this->expectExceptionMessage('not trustworthy');

        $this->verifier()->verify($post);
    }

    /**
     * The mistake this library is shaped to prevent: the fields are the same,
     * only their order changed, and Sips says the order is part of the seal.
     */
    public function test_it_refuses_a_response_whose_fields_were_reordered(): void
    {
        $post = Platform::sealedResponse(['responseCode' => '00', 'amount' => '2500', 'currencyCode' => '978']);
        $post['Data'] = 'amount=2500|currencyCode=978|responseCode=00';

        $this->expectException(SealMismatch::class);

        $this->verifier()->verify($post);
    }

    public function test_it_accepts_a_response_sealed_with_the_other_algorithm(): void
    {
        $post = Platform::sealedResponse(['responseCode' => '00'], new Sha256Seal);

        $response = $this->verifier()->verify($post);

        $this->assertInstanceOf(Sha256Seal::class, $response->sealedWith());
    }

    public function test_a_shop_can_insist_on_one_algorithm(): void
    {
        $post = Platform::sealedResponse(['responseCode' => '00'], new Sha256Seal);

        $verifier = new ResponseVerifier(Platform::credentials(), SealVerifier::only(new HmacSha256Seal));

        $this->expectException(SealMismatch::class);

        $verifier->verify($post);
    }

    public function test_it_refuses_a_response_for_another_merchant(): void
    {
        $other = Credentials::of('999999999999999', Platform::SECRET_KEY);
        $post = Platform::sealedResponse(['merchantId' => '999999999999999', 'responseCode' => '00'], null, $other);

        $this->expectException(SealMismatch::class);
        $this->expectExceptionMessage('This response is for merchant 999999999999999');

        $this->verifier()->verify($post);
    }

    public function test_it_refuses_a_response_with_no_data(): void
    {
        $this->expectException(SealMismatch::class);
        $this->expectExceptionMessage('carries no Data field');

        $this->verifier()->verify(['Seal' => str_repeat('a', 64)]);
    }

    public function test_it_refuses_a_response_with_no_seal(): void
    {
        $this->expectException(SealMismatch::class);
        $this->expectExceptionMessage('carries no Seal field');

        $this->verifier()->verify(['Data' => 'responseCode=00']);
    }

    public function test_it_refuses_a_seal_that_is_not_sixty_four_hexadecimal_characters(): void
    {
        $this->expectException(SealMismatch::class);
        $this->expectExceptionMessage('carries no Seal field');

        $this->verifier()->verify(['Data' => 'responseCode=00', 'Seal' => 'nonsense']);
    }

    public function test_a_seal_sent_in_upper_case_is_accepted(): void
    {
        $post = Platform::sealedResponse(['responseCode' => '00']);
        $post['Seal'] = strtoupper($post['Seal']);

        $this->assertTrue($this->verifier()->verify($post)->isAccepted());
    }

    public function test_a_refused_payment_with_a_valid_seal_verifies(): void
    {
        // Verification is about authenticity, not about the payment's result.
        $post = Platform::sealedResponse(['responseCode' => '05', 'acquirerResponseCode' => '05']);

        $response = $this->verifier()->verify($post);

        $this->assertFalse($response->isAccepted());
        $this->assertNotNull($response->sealedWith());
    }

    public function test_an_encoded_response_is_verified_against_its_encoded_form(): void
    {
        $post = Platform::sealedResponse(['responseCode' => '00', 'amount' => '2500'], null, null, Encoding::Base64);

        $response = $this->verifier()->verify($post);

        $this->assertTrue($response->isAccepted());
        $this->assertSame('2500', $response->field('amount'));
    }

    public function test_attempt_returns_null_instead_of_throwing(): void
    {
        $post = Platform::sealedResponse(['responseCode' => '00']);

        $this->assertNotNull($this->verifier()->attempt($post));

        $post['Data'] .= '|tampered=1';

        $this->assertNull($this->verifier()->attempt($post));
    }

    /**
     * The field Sips adds to a response that this library has never heard of
     * must not break verification - the seal covers the string, not a list of
     * names.
     */
    public function test_a_field_nobody_has_heard_of_does_not_break_verification(): void
    {
        $post = Platform::sealedResponse([
            'responseCode' => '00',
            'someFieldFromTheFuture' => 'surprise',
        ]);

        $response = $this->verifier()->verify($post);

        $this->assertTrue($response->isAccepted());
        $this->assertSame('surprise', $response->field('someFieldFromTheFuture'));
    }

    private function verifier(): ResponseVerifier
    {
        return new ResponseVerifier(Platform::credentials());
    }
}
