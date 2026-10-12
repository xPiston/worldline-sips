<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Piston\Sips\Configuration\Credentials;
use Piston\Sips\Configuration\Environment;
use Piston\Sips\Exception\SealMismatch;
use Piston\Sips\Field\Amount;
use Piston\Sips\Field\TransactionReference;
use Piston\Sips\Paypage\PaymentRequest;
use Piston\Sips\Response\ResponseVerifier;
use Piston\Sips\Security\Encoding;
use Piston\Sips\Security\HmacSha256Seal;
use Piston\Sips\Security\SealVerifier;
use Piston\Sips\Security\Sha256Seal;
use Piston\Sips\Sips;
use Piston\Sips\Tests\Support\Platform;

final class SipsTest extends TestCase
{
    public function test_the_interface_version_is_the_documented_one(): void
    {
        $this->assertSame('HP_2.57', Sips::INTERFACE_VERSION);
    }

    /**
     * HMAC by default, which is *not* the platform's default - it is the one
     * the platform recommends.
     */
    public function test_the_default_algorithm_is_the_recommended_one_and_not_the_platform_default(): void
    {
        $this->assertInstanceOf(HmacSha256Seal::class, Sips::simulation()->algorithm());
    }

    public function test_the_simulation_shortcut_uses_the_published_credentials(): void
    {
        $sips = Sips::simulation();

        $this->assertSame('002001000000002', $sips->credentials->merchantId);
        $this->assertSame(Environment::Simulation, $sips->platform());
    }

    public function test_the_production_shortcut(): void
    {
        $sips = Sips::production('011223344550000', 'a-key', 2);

        $this->assertSame(Environment::Production, $sips->platform());
        $this->assertSame(2, $sips->credentials->key->version);
    }

    public function test_the_form_carries_the_three_fields_and_goes_to_the_platform(): void
    {
        $form = Sips::simulation()->form($this->request());

        $this->assertSame(['Data', 'InterfaceVersion', 'Seal'], array_keys($form->fields()));
        $this->assertSame('https://payment-webinit.simu.sips-services.com/paymentInit', $form->action());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $form->seal());
    }

    /**
     * The seal is of the Data string that is sent - computed here a second
     * time, by hand, from the field that travels.
     */
    public function test_the_seal_is_the_seal_of_what_is_sent(): void
    {
        $form = Sips::simulation()->form($this->request());

        $this->assertSame(
            hash_hmac('sha256', $form->data(), '002001000000002_KEY1'),
            $form->seal(),
        );
    }

    public function test_the_other_algorithm_is_declared_in_the_data_and_used_for_the_seal(): void
    {
        $sips = new Sips(Credentials::simulation(), Environment::Simulation, new Sha256Seal);
        $form = $sips->form($this->request());

        $this->assertStringContainsString('sealAlgorithm=SHA-256', $form->data());
        $this->assertSame(hash('sha256', $form->data().'002001000000002_KEY1'), $form->seal());
    }

    public function test_an_encoded_form_declares_its_encoding_and_seals_the_encoded_data(): void
    {
        $sips = new Sips(Credentials::simulation(), Environment::Simulation, new HmacSha256Seal, Encoding::Base64);
        $form = $sips->form($this->request());

        $this->assertSame('base64', $form->fields()['Encode']);
        $this->assertSame(base64_decode($form->data(), true), (string) base64_decode($form->data(), true));
        $this->assertSame(hash_hmac('sha256', $form->data(), '002001000000002_KEY1'), $form->seal());
    }

    public function test_it_hands_out_a_verifier(): void
    {
        $this->assertInstanceOf(ResponseVerifier::class, Sips::simulation()->verifier());
    }

    public function test_it_verifies_a_response(): void
    {
        $post = Platform::sealedResponse(['responseCode' => '00', 'transactionReference' => 'REF001']);

        $response = Sips::simulation()->response($post);

        $this->assertTrue($response->isAccepted());
        $this->assertSame('REF001', $response->reference()?->value());
    }

    public function test_response_throws_and_attempt_does_not(): void
    {
        $post = Platform::sealedResponse(['responseCode' => '00']);
        $post['Data'] .= '|tampered=1';

        $this->assertNull(Sips::simulation()->attemptResponse($post));

        $this->expectException(SealMismatch::class);

        Sips::simulation()->response($post);
    }

    public function test_the_verifier_honours_a_restricted_algorithm_list(): void
    {
        $post = Platform::sealedResponse(['responseCode' => '00'], new Sha256Seal);

        $lenient = new Sips(Credentials::simulation());
        $strict = new Sips(
            Credentials::simulation(),
            Environment::Simulation,
            new HmacSha256Seal,
            Encoding::Plain,
            SealVerifier::only(new HmacSha256Seal),
        );

        $this->assertNotNull($lenient->attemptResponse($post));
        $this->assertNull($strict->attemptResponse($post));
    }

    /** A bank fronting Sips on its own domain. */
    public function test_a_custom_platform_changes_where_the_form_goes(): void
    {
        $sips = new Sips(
            Credentials::simulation(),
            Environment::Production->withHost('paiement.ma-banque.fr'),
        );

        $this->assertSame('https://paiement.ma-banque.fr/paymentInit', $sips->form($this->request())->action());
    }

    private function request(): PaymentRequest
    {
        return new PaymentRequest(
            amount: Amount::fromMinor(2500, 'EUR'),
            normalReturnUrl: 'https://shop.test/retour',
            reference: TransactionReference::of('REF001'),
        );
    }
}
