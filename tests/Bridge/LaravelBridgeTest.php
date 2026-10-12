<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Bridge;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\TestCase;
use Piston\Sips\Bridge\Laravel\ResponseRequest;
use Piston\Sips\Bridge\Laravel\SipsServiceProvider;
use Piston\Sips\Configuration\Environment;
use Piston\Sips\Field\Amount;
use Piston\Sips\Field\TransactionReference;
use Piston\Sips\Paypage\PaymentRequest;
use Piston\Sips\Response\ResponseVerifier;
use Piston\Sips\Security\HmacSha256Seal;
use Piston\Sips\Security\Sha256Seal;
use Piston\Sips\Sips;
use Piston\Sips\Tests\Support\Platform;
use RuntimeException;

/**
 * The Laravel bridge, in a real Laravel application.
 *
 * Testbench boots the framework rather than a mock of it, because what is
 * being tested is wiring - and a provider that registers a singleton the
 * container cannot build is not something a unit test notices.
 */
final class LaravelBridgeTest extends TestCase
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [SipsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->make(Repository::class)->set([
            'sips.merchant_id' => Platform::MERCHANT_ID,
            'sips.secret_key' => Platform::SECRET_KEY,
            'sips.platform' => 'test',
        ]);
    }

    public function test_it_resolves_a_configured_client(): void
    {
        $sips = $this->laravel()->make(Sips::class);

        $this->assertSame(Platform::MERCHANT_ID, $sips->credentials->merchantId);
        $this->assertSame(Environment::Test, $sips->platform());
        $this->assertInstanceOf(HmacSha256Seal::class, $sips->algorithm());
    }

    public function test_it_is_a_singleton(): void
    {
        $this->assertSame($this->laravel()->make(Sips::class), $this->laravel()->make(Sips::class));
    }

    public function test_the_verifier_resolves_on_its_own(): void
    {
        $verifier = $this->laravel()->make(ResponseVerifier::class);

        $this->assertTrue($verifier->verify(Platform::sealedResponse(['responseCode' => '00']))->isAccepted());
    }

    public function test_the_default_config_is_merged(): void
    {
        $this->assertSame(1, config('sips.key_version'));
        $this->assertSame('hmac-sha-256', config('sips.seal_algorithm'));
    }

    public function test_the_config_file_can_be_published(): void
    {
        $published = $this->laravel()->configPath('sips.php');

        // Deleted first: a file left behind by an earlier run would make this
        // pass without publishing anything.
        @unlink($published);

        $command = $this->artisan('vendor:publish', ['--tag' => 'sips-config']);

        $this->assertInstanceOf(PendingCommand::class, $command);

        // run() is what executes the command; assertSuccessful() only records
        // the expected exit code, and the command would otherwise not run
        // until the PendingCommand is destructed - after the assertions here.
        $this->assertSame(0, $command->run());

        $this->assertFileExists($published);
        $this->assertStringContainsString('SIPS_MERCHANT_ID', (string) file_get_contents($published));

        @unlink($published);
    }

    /**
     * No credentials is the state a developer is in five minutes after
     * installing, and the simulation platform is open - so it boots.
     */
    public function test_without_credentials_it_falls_back_to_the_published_simulation_shop(): void
    {
        config(['sips.merchant_id' => null, 'sips.secret_key' => null, 'sips.platform' => 'simulation']);
        $this->laravel()->forgetInstance(Sips::class);

        $sips = $this->laravel()->make(Sips::class);

        $this->assertSame('002001000000002', $sips->credentials->merchantId);
    }

    /** But not for a platform that moves money. */
    public function test_without_credentials_production_refuses_to_boot(): void
    {
        config(['sips.merchant_id' => null, 'sips.platform' => 'production']);
        $this->laravel()->forgetInstance(Sips::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SIPS_MERCHANT_ID');

        $this->laravel()->make(Sips::class);
    }

    public function test_a_platform_that_is_none_of_the_three_is_refused(): void
    {
        config(['sips.platform' => 'staging']);
        $this->laravel()->forgetInstance(Sips::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be "simulation", "test" or "production", got "staging"');

        $this->laravel()->make(Sips::class);
    }

    public function test_the_seal_algorithm_can_be_chosen_from_the_config(): void
    {
        config(['sips.seal_algorithm' => 'sha256']);
        $this->laravel()->forgetInstance(Sips::class);

        $this->assertInstanceOf(Sha256Seal::class, $this->laravel()->make(Sips::class)->algorithm());
    }

    public function test_an_unknown_seal_algorithm_is_refused(): void
    {
        config(['sips.seal_algorithm' => 'sha512']);
        $this->laravel()->forgetInstance(Sips::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be "hmac-sha-256" or "sha256"');

        $this->laravel()->make(Sips::class);
    }

    public function test_a_bank_host_can_be_configured(): void
    {
        config(['sips.paypage_host' => 'paiement.ma-banque.fr', 'sips.office_host' => 'office.ma-banque.fr']);
        $this->laravel()->forgetInstance(Sips::class);

        $form = $this->laravel()->make(Sips::class)->form(new PaymentRequest(
            amount: Amount::fromMinor(2500, 'EUR'),
            normalReturnUrl: 'https://shop.test/retour',
            reference: TransactionReference::of('REF001'),
        ));

        $this->assertSame('https://paiement.ma-banque.fr/paymentInit', $form->action());
    }

    /**
     * The trap the helper exists for: a return URL with a query parameter is
     * the normal shape, and `$request->all()` would put it in the seal.
     */
    public function test_the_response_fields_come_from_the_body_and_not_the_query_string(): void
    {
        $post = Platform::sealedResponse(['responseCode' => '00', 'transactionReference' => 'REF001']);

        $request = Request::create('/sips/retour?order=42&utm_source=mail', 'POST', $post);

        $fields = ResponseRequest::fields($request);

        $this->assertArrayNotHasKey('order', $fields);
        $this->assertArrayNotHasKey('utm_source', $fields);
        $this->assertSame($post, $fields);

        // And it still verifies, which it would not have with $request->all().
        $this->assertTrue($this->laravel()->make(Sips::class)->response($fields)->isAccepted());
    }

    public function test_the_provider_declares_what_it_provides(): void
    {
        $provider = new SipsServiceProvider($this->laravel());

        $this->assertSame([Sips::class, ResponseVerifier::class], $provider->provides());
    }

    /** A controller-sized example, end to end. */
    public function test_a_controller_sized_example(): void
    {
        $post = Platform::sealedResponse([
            'responseCode' => '00',
            'transactionReference' => 'REF001',
            'amount' => '2500',
            'currencyCode' => '978',
        ]);

        $request = Request::create('/sips/notification', 'POST', $post);

        $response = $this->laravel()->make(ResponseVerifier::class)->attempt(ResponseRequest::fields($request));

        $this->assertNotNull($response);
        $this->assertTrue($response->isAccepted());
        $this->assertTrue($response->matches(
            TransactionReference::of('REF001'),
            Amount::fromMinor(2500, 'EUR'),
            Platform::MERCHANT_ID,
        ));
    }

    /** Testbench's application property is nullable between tests. */
    private function laravel(): Application
    {
        $app = $this->app;

        $this->assertInstanceOf(Application::class, $app);

        return $app;
    }
}
