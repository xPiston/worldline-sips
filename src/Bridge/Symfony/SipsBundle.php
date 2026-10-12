<?php

declare(strict_types=1);

namespace Piston\Sips\Bridge\Symfony;

use Piston\Sips\Configuration\Credentials;
use Piston\Sips\Configuration\Environment;
use Piston\Sips\Configuration\Platform;
use Piston\Sips\Response\ResponseVerifier;
use Piston\Sips\Security\Encoding;
use Piston\Sips\Security\HmacSha256Seal;
use Piston\Sips\Security\Sha256Seal;
use Piston\Sips\Sips;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Wires Sips into a Symfony application.
 *
 * ```yaml
 * # config/packages/sips.yaml
 * sips:
 *     merchant_id: '%env(SIPS_MERCHANT_ID)%'
 *     secret_key: '%env(SIPS_SECRET_KEY)%'
 *     platform: '%env(SIPS_PLATFORM)%'
 * ```
 *
 * `Sips` and `ResponseVerifier` are then autowired like anything else, and the
 * PSR-18 client comes from the container as soon as symfony/http-client is
 * installed - `nullOnInvalid()` so its absence is not a container error but a
 * clear exception the day somebody calls a refund.
 */
final class SipsBundle extends AbstractBundle
{
    protected string $extensionAlias = 'sips';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('merchant_id')->defaultNull()
            ->info('The merchant id Worldline issued. Left empty, the published simulation shop is used.')->end()
            ->scalarNode('secret_key')->defaultNull()
            ->info('The secret key from the Sips Download extranet. Left empty, the published simulation key is used.')->end()
            ->integerNode('key_version')->defaultValue(1)->min(1)
            ->info('The version the key was issued under. Rotating a key without bumping this gives answer code 34 on every payment.')->end()
            ->enumNode('platform')->values(['simulation', 'test', 'production'])->defaultValue('simulation')
            ->info('Never derived from the kernel environment: a staging server pointed at production takes real money.')->end()
            ->scalarNode('paypage_host')->defaultNull()
            ->info('For a bank that fronts the payment page on its own domain.')->end()
            ->scalarNode('office_host')->defaultNull()
            ->info('The Office services are another machine, overridden separately.')->end()
            ->enumNode('seal_algorithm')->values(['hmac-sha-256', 'sha256'])->defaultValue('hmac-sha-256')
            ->info('HMAC is what Worldline recommends; plain SHA-256 is its default and its documentation advises against it.')->end()
            ->end();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{merchant_id: ?string, secret_key: ?string, key_version: int, platform: string, paypage_host: ?string, office_host: ?string, seal_algorithm: string} $config */
        $services = $container->services();

        $environment = Environment::from($config['platform']);

        $hasCredentials = ($config['merchant_id'] ?? '') !== '' && ($config['secret_key'] ?? '') !== '';

        if ($hasCredentials) {
            $services->set(Credentials::class)
                ->factory([Credentials::class, 'of'])
                ->args([$config['merchant_id'], $config['secret_key'], $config['key_version']]);
        } else {
            // The published simulation shop, so an application that has not
            // been given credentials yet still boots.
            $services->set(Credentials::class)->factory([Credentials::class, 'simulation']);
        }

        // Symfony's configurator takes scalars, enums, references and inline
        // services - not arbitrary objects. An Environment is an enum and
        // passes as a value; a Platform and a seal algorithm are objects and
        // have to be declared as services built in place.
        $platform = ($config['paypage_host'] ?? '') === ''
            ? $environment
            : inline_service(Platform::class)->args([
                $environment,
                (string) $config['paypage_host'],
                ($config['office_host'] ?? '') === '' ? null : (string) $config['office_host'],
            ]);

        $services->set(Sips::class)
            ->args([
                service(Credentials::class),
                $platform,
                inline_service($config['seal_algorithm'] === 'sha256' ? Sha256Seal::class : HmacSha256Seal::class),
                Encoding::Plain,
                null,
                service(ClientInterface::class)->nullOnInvalid(),
                service(RequestFactoryInterface::class)->nullOnInvalid(),
                service(StreamFactoryInterface::class)->nullOnInvalid(),
            ]);

        $services->set(ResponseVerifier::class)
            ->factory([service(Sips::class), 'verifier']);
    }
}
