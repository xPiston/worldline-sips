<?php

declare(strict_types=1);

namespace Piston\Sips\Security;

use Piston\Sips\Exception\InvalidField;
use Stringable;

/**
 * A Sips seal: the hexadecimal form of a SHA-256 hash, so 64 characters.
 *
 * It is a type for the same reason it was one in every other connector:
 * comparison. Sips documents the seal in lower case and its own responses have
 * been seen in both, and comparing a MAC with `===` leaks how many leading
 * characters an attacker got right. `equals()` normalises, then uses
 * `hash_equals`.
 */
final class Seal implements Stringable
{
    private function __construct(private readonly string $hex) {}

    public static function fromHex(string $hex): self
    {
        $hex = trim($hex);

        if (preg_match('/^[0-9A-Fa-f]{64}$/', $hex) !== 1) {
            throw InvalidField::because('Seal', 'expected 64 hexadecimal characters', $hex);
        }

        return new self(strtolower($hex));
    }

    public function value(): string
    {
        return $this->hex;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->hex, $other->hex);
    }

    public function __toString(): string
    {
        return $this->hex;
    }
}
