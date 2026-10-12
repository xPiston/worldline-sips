<?php

declare(strict_types=1);

namespace Piston\Sips\Security;

use Piston\Sips\Exception\InvalidField;
use SensitiveParameter;

/**
 * The merchant secret key, and the version it was issued under.
 *
 * The two are one object on purpose. Sips sends the key's version in the
 * request (`keyVersion`) so that it knows which of the merchant's keys to
 * verify the seal with, and a key paired with the wrong version fails in a way
 * that looks like neither: `Unable to find a valid key for the following
 * keyVersion=0` if the version is unknown, and a plain invalid-signature error
 * if the version exists but belongs to another key. Rotating a key on the Sips
 * Download extranet without bumping the version in the code is the documented
 * path to answer code 34, "suspected fraud".
 *
 * Unlike some gateways, the key is **not** hexadecimal: it is an arbitrary
 * string - the simulation key is literally `002001000000002_KEY1` - and it is
 * used as the HMAC key, or concatenated to the data, exactly as issued. There
 * is nothing to decode, and decoding it would be the bug.
 */
final class SecretKey
{
    private readonly string $value;

    private function __construct(#[SensitiveParameter] string $value, public readonly int $version)
    {
        $this->value = $value;
    }

    public static function of(#[SensitiveParameter] string $value, int $version = 1): self
    {
        if (trim($value) === '') {
            throw InvalidField::secretKey('must not be empty');
        }

        if ($value !== trim($value)) {
            // A key copied out of the extranet with a trailing newline would
            // hash to something else entirely, and the only symptom is an
            // invalid signature on every single payment.
            throw InvalidField::secretKey('must not begin or end with whitespace, and the one you pasted does');
        }

        if ($version < 1) {
            throw InvalidField::because('keyVersion', 'expected the version the key was issued under, which starts at 1', (string) $version);
        }

        return new self($value, $version);
    }

    public function value(): string
    {
        return $this->value;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => '*** not shown ***', 'version' => (string) $this->version];
    }
}
