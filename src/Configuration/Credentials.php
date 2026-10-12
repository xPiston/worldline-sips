<?php

declare(strict_types=1);

namespace Piston\Sips\Configuration;

use Piston\Sips\Exception\InvalidField;
use Piston\Sips\Security\SecretKey;
use SensitiveParameter;

/**
 * What Worldline issues per shop: a merchant id, and a secret key with its
 * version.
 *
 * One object because they are issued together, rotated together, and differ
 * between the simulation, test and production platforms - all three of them.
 * Mixing a production merchant id with a test key is a seal error, and
 * configuring them separately is how that happens.
 */
final class Credentials
{
    public function __construct(
        public readonly string $merchantId,
        public readonly SecretKey $key,
    ) {
        if (preg_match('/^[0-9]{1,15}$/', $merchantId) !== 1) {
            throw InvalidField::because('merchantId', 'expected up to 15 digits', $merchantId);
        }
    }

    /** The shape a configuration file has: three scalars, validated here. */
    public static function of(
        string $merchantId,
        #[SensitiveParameter] string $secretKey,
        int $keyVersion = 1,
    ): self {
        return new self($merchantId, SecretKey::of($secretKey, $keyVersion));
    }

    /**
     * The credentials Worldline publishes for its simulation platform.
     *
     * In the quick start guide, for everyone, on purpose: the simulator is not
     * connected to any banking server. Having them here means the examples in
     * this package's own README run, and that its integration tests reach a
     * real Sips server without a contract.
     */
    public static function simulation(): self
    {
        return self::of('002001000000002', '002001000000002_KEY1', 1);
    }
}
