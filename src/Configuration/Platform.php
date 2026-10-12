<?php

declare(strict_types=1);

namespace Piston\Sips\Configuration;

/**
 * An environment whose hostnames were overridden.
 *
 * Worth a type rather than a pair of strings threaded through: a bank that
 * fronts Sips on its own domain is common, the two hosts are different
 * machines, and a shop that overrides one and forgets the other finds out when
 * it tries its first refund.
 */
final class Platform
{
    public function __construct(
        public readonly Environment $environment,
        private readonly string $paypageHost,
        private readonly ?string $officeHost = null,
    ) {}

    public function paypageUrl(): string
    {
        return 'https://'.$this->paypageHost.'/paymentInit';
    }

    public function officeUrl(string $operation): string
    {
        if ($this->officeHost === null) {
            return $this->environment->officeUrl($operation);
        }

        return 'https://'.$this->officeHost.'/rs-services/v2/'.ltrim($operation, '/');
    }

    public function isProduction(): bool
    {
        return $this->environment->isProduction();
    }
}
