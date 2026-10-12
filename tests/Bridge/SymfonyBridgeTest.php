<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Bridge;

use PHPUnit\Framework\TestCase;
use Piston\Sips\Bridge\Symfony\ResponseRequest;
use Piston\Sips\Bridge\Symfony\SipsBundle;
use Piston\Sips\Configuration\Environment;
use Piston\Sips\Field\Amount;
use Piston\Sips\Paypage\PaymentRequest;
use Piston\Sips\Response\ResponseVerifier;
use Piston\Sips\Security\HmacSha256Seal;
use Piston\Sips\Security\Sha256Seal;
use Piston\Sips\Sips;
use Piston\Sips\Tests\Support\Platform;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Symfony bundle, compiled by Symfony's own container.
 *
 * The extension is loaded and the container compiled for real, so a service
 * definition with an argument that cannot be resolved fails here rather than
 * on the first request of an application that installed the bundle. That is
 * not a hypothetical: the equivalent bundle in this author's Monetico package
 * shipped a `->factory(null)` that broke every installation, and this is the
 * test that caught it.
 */
final class SymfonyBridgeTest extends TestCase
{
    public function test_it_builds_a_configured_client(): void
    {
        $sips = $this->sips();

        $this->assertSame(Platform::MERCHANT_ID, $sips->credentials->merchantId);
        $this->assertSame(Environment::Test, $sips->platform());
        $this->assertInstanceOf(HmacSha256Seal::class, $sips->algorithm());
    }

    public function test_the_same_instance_is_shared(): void
    {
        $container = $this->container();

        $this->assertSame($container->get(Sips::class), $container->get(Sips::class));
        $this->assertNotNull($container->get(Sips::class));
    }

    public function test_the_verifier_is_a_service_of_its_own(): void
    {
        $verifier = $this->container()->get(ResponseVerifier::class);

        $this->assertInstanceOf(ResponseVerifier::class, $verifier);
        $this->assertTrue($verifier->verify(Platform::sealedResponse(['responseCode' => '00']))->isAccepted());
    }

    public function test_production_is_a_configuration_choice(): void
    {
        $this->assertSame(Environment::Production, $this->sips(['platform' => 'production'])->platform());
    }

    public function test_a_platform_that_is_none_of_the_three_is_refused_by_the_configuration_tree(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->container(['platform' => 'staging']);
    }

    public function test_an_unknown_seal_algorithm_is_refused_by_the_configuration_tree(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->container(['seal_algorithm' => 'sha512']);
    }

    public function test_a_key_version_below_one_is_refused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->container(['key_version' => 0]);
    }

    public function test_the_seal_algorithm_can_be_chosen(): void
    {
        $this->assertInstanceOf(Sha256Seal::class, $this->sips(['seal_algorithm' => 'sha256'])->algorithm());
    }

    public function test_without_credentials_it_falls_back_to_the_published_simulation_shop(): void
    {
        $sips = $this->sips(['merchant_id' => null, 'secret_key' => null, 'platform' => 'simulation']);

        $this->assertSame('002001000000002', $sips->credentials->merchantId);
    }

    public function test_a_bank_host_can_be_configured(): void
    {
        $sips = $this->sips(['paypage_host' => 'paiement.ma-banque.fr', 'office_host' => 'office.ma-banque.fr']);

        $form = $sips->form(new PaymentRequest(
            amount: Amount::fromMinor(100, 'EUR'),
            normalReturnUrl: 'https://shop.test/retour',
        ));

        $this->assertSame('https://paiement.ma-banque.fr/paymentInit', $form->action());
    }

    public function test_the_extension_is_called_sips(): void
    {
        $this->assertSame('sips', (new SipsBundle)->getContainerExtension()?->getAlias());
    }

    public function test_the_response_fields_come_from_the_body_only(): void
    {
        $post = Platform::sealedResponse(['responseCode' => '00']);
        $request = Request::create('/sips/retour?order=42', 'POST', $post);

        $fields = ResponseRequest::fields($request);

        $this->assertArrayNotHasKey('order', $fields);
        $this->assertTrue($this->sips()->response($fields)->isAccepted());
    }

    /** @param array<string, mixed> $overrides */
    private function sips(array $overrides = []): Sips
    {
        $service = $this->container($overrides)->get(Sips::class);

        $this->assertInstanceOf(Sips::class, $service);

        return $service;
    }

    /** @param array<string, mixed> $overrides */
    private function container(array $overrides = []): ContainerBuilder
    {
        $config = [
            'merchant_id' => Platform::MERCHANT_ID,
            'secret_key' => Platform::SECRET_KEY,
            'platform' => 'test',
            ...$overrides,
        ];

        foreach ($config as $name => $value) {
            if ($value === null) {
                unset($config[$name]);
            }
        }

        // The parameters any Symfony kernel has when a bundle is loaded.
        $builder = new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'test',
            'kernel.debug' => false,
            'kernel.project_dir' => dirname(__DIR__, 2),
            'kernel.build_dir' => sys_get_temp_dir(),
            'kernel.cache_dir' => sys_get_temp_dir(),
        ]));

        $extension = (new SipsBundle)->getContainerExtension();

        $this->assertNotNull($extension);

        $extension->load([$config], $builder);

        // Private by default, like any Symfony service; public here so the
        // test can reach in the way an autowired controller would.
        foreach ([Sips::class, ResponseVerifier::class] as $service) {
            $builder->getDefinition($service)->setPublic(true);
        }

        $builder->compile();

        return $builder;
    }
}
