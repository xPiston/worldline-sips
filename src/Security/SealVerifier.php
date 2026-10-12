<?php

declare(strict_types=1);

namespace Piston\Sips\Security;

use SensitiveParameter;

/**
 * Tries each seal algorithm until one matches.
 *
 * A response is sealed with the algorithm its *request* asked for, so a shop
 * knows which one to expect - and gets it wrong exactly once, on the day it
 * migrates. Both are therefore accepted by default, with the configured one
 * tried first, and `only()` exists for a shop that wants the other refused.
 *
 * Returning the algorithm rather than a boolean lets an application log which
 * one carried a payment: the way to know a migration to HMAC is complete is to
 * see the SHA-256 line stop appearing.
 */
final class SealVerifier
{
    /** @var list<SealAlgorithm> */
    private readonly array $algorithms;

    public function __construct(SealAlgorithm ...$algorithms)
    {
        $this->algorithms = $algorithms === []
            ? [new HmacSha256Seal, new Sha256Seal]
            : array_values($algorithms);
    }

    public static function only(SealAlgorithm $algorithm): self
    {
        return new self($algorithm);
    }

    /** The algorithm whose seal matches the data, or null if none does. */
    public function matching(
        string $data,
        Seal $received,
        #[SensitiveParameter] SecretKey $key,
    ): ?SealAlgorithm {
        foreach ($this->algorithms as $algorithm) {
            if ($algorithm->matches($data, $received, $key)) {
                return $algorithm;
            }
        }

        return null;
    }

    /** @return list<SealAlgorithm> */
    public function algorithms(): array
    {
        return $this->algorithms;
    }
}
