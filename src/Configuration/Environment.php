<?php

declare(strict_types=1);

namespace Piston\Sips\Configuration;

/**
 * Which Sips platform to talk to, and the URLs that follow.
 *
 * Three, not two. `Simulation` is open to everyone - its merchant id and
 * secret key are printed in the quick start guide - and answers with
 * deterministic results chosen by the card number, which is what makes an
 * integration testable before a contract exists. `Test` is the merchant's own
 * pre-production shop, with their real credentials against a simulated
 * acquirer. `Production` takes money.
 *
 * The hostnames also exist under the older `sips-atos.com` domain, which some
 * banks still hand out; `withHost()` is there for those, and for a bank that
 * fronts Sips on its own domain (several do - the brand on the contract is not
 * always the hostname).
 */
enum Environment: string
{
    case Simulation = 'simulation';
    case Test = 'test';
    case Production = 'production';

    private const PAYPAGE_HOSTS = [
        'simulation' => 'payment-webinit.simu.sips-services.com',
        'test' => 'payment-webinit.test.sips-services.com',
        'production' => 'payment-webinit.sips-services.com',
    ];

    private const OFFICE_HOSTS = [
        'simulation' => 'office-server.simu.sips-services.com',
        'test' => 'office-server.test.sips-services.com',
        'production' => 'office-server.sips-services.com',
    ];

    public function paypageUrl(): string
    {
        return 'https://'.self::PAYPAGE_HOSTS[$this->value].'/paymentInit';
    }

    /** The Office JSON endpoint for one operation, such as `cashManagement/refund`. */
    public function officeUrl(string $operation): string
    {
        return 'https://'.self::OFFICE_HOSTS[$this->value].'/rs-services/v2/'.ltrim($operation, '/');
    }

    public function isProduction(): bool
    {
        return $this === self::Production;
    }

    /**
     * The same environment on another host, for a bank that fronts Sips under
     * a domain of its own - or for the legacy `sips-atos.com` names.
     */
    public function withHost(string $paypageHost, ?string $officeHost = null): Platform
    {
        return new Platform($this, $paypageHost, $officeHost);
    }
}
