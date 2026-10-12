<?php

declare(strict_types=1);

namespace Piston\Sips\Field;

use Piston\Sips\Exception\InvalidField;
use Stringable;

/**
 * `transactionReference`: what identifies a transaction for the rest of its
 * life, together with the merchant id.
 *
 * Unique per shop, forever - a reused reference is refused, and that refusal
 * is permanent rather than something to retry. Up to 35 characters, letters
 * and digits only.
 *
 * And it is **optional**, which almost nothing says out loud: a shop can be
 * configured to let Sips generate the reference itself, and then sending one
 * is an error - `code=12 Merchant provided transaction key, but is configured
 * for auto generated transaction key`. That is not a validation failure a
 * library can predict from the request, so both shapes are supported and the
 * one the shop is set up for is the shop's own business. When Sips generates
 * it, the reference comes back in the response.
 */
final class TransactionReference implements Stringable
{
    private function __construct(private readonly string $value) {}

    public static function of(string $value): self
    {
        if (preg_match('/^[A-Za-z0-9]{1,35}$/', $value) !== 1) {
            throw InvalidField::because(
                'transactionReference',
                'expected 1 to 35 letters or digits, and nothing else',
                $value,
            );
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
