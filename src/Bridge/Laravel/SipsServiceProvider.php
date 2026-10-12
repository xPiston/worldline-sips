<?php

declare(strict_types=1);

namespace Piston\Sips\Bridge\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Piston\Sips\Configuration\Credentials;
use Piston\Sips\Configuration\Environment;
use Piston\Sips\Response\ResponseVerifier;
use Piston\Sips\Security\HmacSha256Seal;
use Piston\Sips\Security\SealAlgorithm;
use Piston\Sips\Sips;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * Wires Sips into a Laravel application.
 *
 * One singleton, built from config, plus the response verifier on its own for
 * a controller that only needs to check a seal.
 *
 * With no credentials configured it falls back to the published simulation
 * shop rather than throwing, because that is the state a developer is in five
 * minutes after `composer require` - and a package whose first act is an
 * exception teaches nothing. Production credentials are checked properly: see
 * credentials().
 */
final class SipsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/sips.php', 'sips');

        $this->app->singleton(Sips::class, function (Container $app): Sips {
            $repository = $app->make(Repository::class);
            $config = $repository->get('sips', []);

            /** @var array<string, mixed> $config */
            $config = is_array($config) ? $config : [];

            $environment = self::environment($config);

            return new Sips(
                credentials: self::credentials($config, $environment),
                platform: self::platform($config, $environment),
                algorithm: self::algorithm($config),
                http: self::optional($app, ClientInterface::class),
                requests: self::optional($app, RequestFactoryInterface::class),
                streams: self::optional($app, StreamFactoryInterface::class),
            );
        });

        $this->app->singleton(
            ResponseVerifier::class,
            static fn (Container $app): ResponseVerifier => $app->make(Sips::class)->verifier(),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/config/sips.php' => $this->app->configPath('sips.php'),
            ], 'sips-config');
        }
    }

    /** @return list<string> */
    public function provides(): array
    {
        return [Sips::class, ResponseVerifier::class];
    }

    /** @param array<string, mixed> $config */
    private static function credentials(array $config, Environment $environment): Credentials
    {
        $merchantId = $config['merchant_id'] ?? null;
        $secretKey = $config['secret_key'] ?? null;

        if (! is_string($merchantId) || $merchantId === '' || ! is_string($secretKey) || $secretKey === '') {
            if ($environment !== Environment::Simulation) {
                throw new RuntimeException(
                    'Sips is not configured: set SIPS_MERCHANT_ID and SIPS_SECRET_KEY. '
                    .'Publish the config with `php artisan vendor:publish --tag=sips-config` to see every option.',
                );
            }

            // The simulation shop, whose credentials Worldline publishes.
            return Credentials::simulation();
        }

        $version = $config['key_version'] ?? 1;

        return Credentials::of($merchantId, $secretKey, is_numeric($version) ? (int) $version : 1);
    }

    /** @param array<string, mixed> $config */
    private static function environment(array $config): Environment
    {
        $value = $config['platform'] ?? Environment::Simulation->value;

        if (! is_string($value) || Environment::tryFrom($value) === null) {
            throw new RuntimeException(sprintf(
                'sips.platform must be "simulation", "test" or "production", got "%s".',
                is_scalar($value) ? (string) $value : get_debug_type($value),
            ));
        }

        return Environment::from($value);
    }

    /** @param array<string, mixed> $config */
    private static function platform(array $config, Environment $environment): Environment|\Piston\Sips\Configuration\Platform
    {
        $paypage = $config['paypage_host'] ?? null;
        $office = $config['office_host'] ?? null;

        if (! is_string($paypage) || $paypage === '') {
            return $environment;
        }

        return $environment->withHost($paypage, is_string($office) && $office !== '' ? $office : null);
    }

    /** @param array<string, mixed> $config */
    private static function algorithm(array $config): SealAlgorithm
    {
        $value = $config['seal_algorithm'] ?? null;

        if (! is_string($value) || $value === '') {
            return new HmacSha256Seal;
        }

        $algorithm = SealAlgorithm::fromWireValue($value);

        if ($algorithm === null) {
            throw new RuntimeException(sprintf(
                'sips.seal_algorithm must be "hmac-sha-256" or "sha256", got "%s".',
                $value,
            ));
        }

        return $algorithm;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $abstract
     * @return T|null
     */
    private static function optional(Container $app, string $abstract): ?object
    {
        return $app->bound($abstract) ? $app->make($abstract) : null;
    }
}
