<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Piston\Sips\Configuration\Credentials;
use Piston\Sips\Configuration\Environment;
use Piston\Sips\Exception\InvalidField;

final class ConfigurationTest extends TestCase
{
    public function test_the_three_platforms_have_their_own_paypage_url(): void
    {
        $this->assertSame('https://payment-webinit.simu.sips-services.com/paymentInit', Environment::Simulation->paypageUrl());
        $this->assertSame('https://payment-webinit.test.sips-services.com/paymentInit', Environment::Test->paypageUrl());
        $this->assertSame('https://payment-webinit.sips-services.com/paymentInit', Environment::Production->paypageUrl());
    }

    /**
     * Paypage and Office are not the same machine, which integrations
     * discover on the day of their first refund.
     */
    public function test_the_office_services_are_on_another_host(): void
    {
        $this->assertSame(
            'https://office-server.test.sips-services.com/rs-services/v2/cashManagement/cancel',
            Environment::Test->officeUrl('cashManagement/cancel'),
        );

        $this->assertStringContainsString('payment-webinit', Environment::Test->paypageUrl());
        $this->assertStringContainsString('office-server', Environment::Test->officeUrl('x'));
    }

    public function test_the_operation_path_may_be_given_with_or_without_a_slash(): void
    {
        $this->assertSame(
            Environment::Production->officeUrl('cashManagement/refund'),
            Environment::Production->officeUrl('/cashManagement/refund'),
        );
    }

    public function test_only_production_is_production(): void
    {
        $this->assertTrue(Environment::Production->isProduction());
        $this->assertFalse(Environment::Test->isProduction());
        $this->assertFalse(Environment::Simulation->isProduction());
    }

    /**
     * Several banks front Sips on a domain of their own, and the two hosts
     * are overridden separately.
     */
    public function test_a_bank_can_front_sips_on_its_own_hosts(): void
    {
        $platform = Environment::Production->withHost('paiement.ma-banque.fr', 'office.ma-banque.fr');

        $this->assertSame('https://paiement.ma-banque.fr/paymentInit', $platform->paypageUrl());
        $this->assertSame('https://office.ma-banque.fr/rs-services/v2/cashManagement/refund', $platform->officeUrl('cashManagement/refund'));
        $this->assertTrue($platform->isProduction());
    }

    public function test_overriding_only_the_paypage_host_leaves_office_where_it_was(): void
    {
        $platform = Environment::Simulation->withHost('paiement.ma-banque.fr');

        $this->assertSame('https://paiement.ma-banque.fr/paymentInit', $platform->paypageUrl());
        $this->assertSame(Environment::Simulation->officeUrl('x'), $platform->officeUrl('x'));
    }

    public function test_the_credentials_validate_the_merchant_id(): void
    {
        $credentials = Credentials::of('002001000000002', 'key', 2);

        $this->assertSame('002001000000002', $credentials->merchantId);
        $this->assertSame(2, $credentials->key->version);
    }

    public function test_a_merchant_id_that_is_not_digits_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('expected up to 15 digits');

        Credentials::of('not-an-id', 'key');
    }

    public function test_a_merchant_id_longer_than_the_field_is_refused(): void
    {
        $this->expectException(InvalidField::class);

        Credentials::of('0020010000000021', 'key');
    }

    /** The ones Worldline prints in its quick start guide. */
    public function test_the_published_simulation_credentials(): void
    {
        $credentials = Credentials::simulation();

        $this->assertSame('002001000000002', $credentials->merchantId);
        $this->assertSame('002001000000002_KEY1', $credentials->key->value());
        $this->assertSame(1, $credentials->key->version);
    }
}
