<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Live;

use PHPUnit\Framework\TestCase;
use Piston\Sips\Field\Amount;
use Piston\Sips\Paypage\CaptureMode;
use Piston\Sips\Paypage\OrderChannel;
use Piston\Sips\Paypage\PaymentRequest;
use Piston\Sips\Security\Sha256Seal;
use Piston\Sips\Sips;

/**
 * Against Worldline's own simulation server.
 *
 * This is the test that cannot be faked: a request this library seals is
 * posted to the real Sips platform, and the platform either accepts the seal
 * and hands back a payment page or says why not. Everything else in the suite
 * proves internal consistency; this proves the protocol.
 *
 * It needs no contract. The simulation merchant id and secret key are printed
 * in Sips' quick start guide and the platform is connected to no banking
 * server.
 */
final class PaypageTest extends TestCase
{
    private const SIMULATION_HOST = 'payment-webinit.simu.sips-services.com';

    public function test_the_simulator_accepts_a_request_sealed_with_hmac(): void
    {
        $page = $this->post(Sips::simulation());

        $this->assertStringContainsString('Redirection to the payment page', $page);
        $this->assertStringNotContainsString('Invalid signature', $page);
    }

    /**
     * The other algorithm, which half the merchants on the platform still use.
     */
    public function test_the_simulator_accepts_a_request_sealed_with_plain_sha256(): void
    {
        $sips = new Sips(
            \Piston\Sips\Configuration\Credentials::simulation(),
            \Piston\Sips\Configuration\Environment::Simulation,
            new Sha256Seal,
        );

        $page = $this->post($sips);

        $this->assertStringContainsString('Redirection to the payment page', $page);
    }

    /**
     * A seal computed with the wrong key must be refused by the platform -
     * otherwise the test above proves nothing at all.
     */
    public function test_the_simulator_refuses_a_request_sealed_with_the_wrong_key(): void
    {
        $sips = new Sips(
            \Piston\Sips\Configuration\Credentials::of('002001000000002', 'not-the-right-key'),
            \Piston\Sips\Configuration\Environment::Simulation,
        );

        $page = $this->post($sips);

        $this->assertMatchesRegularExpression('/Invalid signature|ERROR/i', $page);
        $this->assertStringNotContainsString('Redirection to the payment page', $page);
    }

    public function test_the_payment_page_shows_the_amount_and_the_brands(): void
    {
        $redirect = $this->post(Sips::simulation());

        $action = $this->attribute($redirect, 'action');
        $fields = [];

        foreach (self::inputs($redirect) as $name => $value) {
            $fields[$name] = $value;
        }

        $page = $this->request($action, $fields);

        // The page renders the amount with the symbol, not the code:
        // `<dd class="amount">€25.00</dd>`. Which is the assertion worth
        // making - it is the proof that 2500 minor units and currencyCode=978
        // were read as twenty-five euros and not two thousand five hundred.
        $this->assertStringContainsString('€25.00', $page);

        // The brand chooser, not the card form: the card number is asked for
        // on the next page, once a brand is picked.
        $this->assertStringContainsString('Select payment method', $page);
        $this->assertStringContainsString('VISA', $page);
        $this->assertStringContainsString('MASTERCARD', $page);
    }

    private function post(Sips $sips): string
    {
        $form = $sips->form(new PaymentRequest(
            amount: Amount::fromMinor(2500, 'EUR'),
            normalReturnUrl: 'https://example.test/retour',
            automaticResponseUrl: 'https://example.test/notification',
            orderId: 'LIVE'.date('ymdHis'),
            orderChannel: OrderChannel::Internet,
            captureMode: CaptureMode::AuthorAndCapture,
        ));

        $this->assertSame('https://'.self::SIMULATION_HOST.'/paymentInit', $form->action());

        return $this->request($form->action(), $form->fields());
    }

    /** @var non-empty-string|null */
    private ?string $cookieJar = null;

    protected function tearDown(): void
    {
        if ($this->cookieJar !== null && is_file($this->cookieJar)) {
            unlink($this->cookieJar);
        }

        $this->cookieJar = null;

        parent::tearDown();
    }

    /**
     * One POST, keeping the session cookie.
     *
     * The redirection page and the payment page are two requests in one
     * conversation, and the second has to carry the cookie the first set - as
     * a browser would. curl rather than `file_get_contents`: it keeps the
     * cookies itself, and reading response headers through
     * `$http_response_header` is deprecated in PHP 8.5 while its replacement
     * does not exist before 8.4, which this package still supports.
     *
     * @param  array<string, string>  $fields
     */
    private function request(string $url, array $fields): string
    {
        if ($this->cookieJar === null) {
            $jar = tempnam(sys_get_temp_dir(), 'sips-cookies-');

            if ($jar === false) {
                $this->markTestSkipped('no writable temporary directory for the cookie jar');
            }

            $this->cookieJar = $jar;
        }

        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
        ]);

        $body = curl_exec($curl);
        $error = curl_error($curl);

        // No curl_close(): it has done nothing since PHP 8.0 and is
        // deprecated in 8.5. The handle is freed when it goes out of scope.

        if (! is_string($body) || $body === '') {
            $this->markTestSkipped('the Sips platform could not be reached: '.$error);
        }

        return $body;
    }

    private function attribute(string $html, string $name): string
    {
        preg_match('/(?i)<form[^>]*'.$name.'="([^"]+)"/', $html, $m);

        $this->assertNotEmpty($m, 'no form '.$name.' in the redirection page');

        return $m[1];
    }

    /** @return array<string, string> */
    private static function inputs(string $html): array
    {
        preg_match_all('/(?is)<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"/', $html, $m, PREG_SET_ORDER);

        $fields = [];

        foreach ($m as $input) {
            $fields[$input[1]] = $input[2];
        }

        return $fields;
    }
}
