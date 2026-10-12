<?php

declare(strict_types=1);

namespace Piston\Sips\Security;

use SensitiveParameter;

/**
 * How the seal is computed from the Data element and the secret key.
 *
 * Sips has two, and the default is the one it tells you not to use: plain
 * SHA-256 over the data with the key glued on the end. The other is a proper
 * HMAC, and getting it requires saying so in the request - `sealAlgorithm` -
 * which means the choice is part of the message and not just a setting.
 *
 * Both are implemented because both are in production: a shop that has been
 * live since before HMAC was recommended is still receiving SHA-256 responses
 * today, and the algorithm of a response is the algorithm of the request that
 * started it.
 */
abstract class SealAlgorithm
{
    /** The value of the `sealAlgorithm` field, as the request must spell it. */
    abstract public function wireValue(): string;

    abstract protected function hash(string $data, #[SensitiveParameter] SecretKey $key): string;

    final public function compute(string $data, #[SensitiveParameter] SecretKey $key): Seal
    {
        return Seal::fromHex($this->hash($data, $key));
    }

    final public function matches(string $data, Seal $received, #[SensitiveParameter] SecretKey $key): bool
    {
        return $this->compute($data, $key)->equals($received);
    }

    /**
     * Reads the algorithm out of a field value, whatever shape it arrives in.
     *
     * Sips is not consistent with its own spelling: a request must say
     * `HMAC-SHA-256`, while a JSON response has been documented carrying
     * `"sealAlgorithm":"sha256"`. Matching exactly would mean believing one
     * of the two is a typo; this accepts both and treats anything unknown as
     * unknown rather than guessing.
     */
    public static function fromWireValue(string $value): ?self
    {
        $normalised = strtolower(str_replace(['-', '_', ' '], '', trim($value)));

        return match ($normalised) {
            'hmacsha256' => new HmacSha256Seal,
            'sha256' => new Sha256Seal,
            default => null,
        };
    }
}
