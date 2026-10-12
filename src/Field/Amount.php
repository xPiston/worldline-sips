<?php

declare(strict_types=1);

namespace Piston\Sips\Field;

use Piston\Sips\Exception\InvalidField;
use Stringable;

/**
 * An amount: an integer number of the currency's smallest units.
 *
 * Which is what Sips sends and what a database should hold. Never a float -
 * `25.00` is not representable in binary floating point, and the field is an
 * integer anyway, so a float can only arrive here having already lost
 * something.
 *
 * The pairing with the currency is the point: 2500 means twenty-five euros and
 * two thousand five hundred yen, and the only thing that says which is the
 * currency's exponent.
 */
final class Amount implements Stringable
{
    private function __construct(
        private readonly int $minor,
        private readonly Currency $currency,
    ) {}

    public static function fromMinor(int $minor, Currency|string $currency): self
    {
        $currency = $currency instanceof Currency ? $currency : Currency::of($currency);

        if ($minor < 0) {
            throw InvalidField::because('amount', 'an amount cannot be negative', (string) $minor);
        }

        if ($minor > 999_999_999_999) {
            // N12 on the wire.
            throw InvalidField::because('amount', 'expected at most 12 digits', (string) $minor);
        }

        return new self($minor, $currency);
    }

    public static function zero(Currency|string $currency): self
    {
        return self::fromMinor(0, $currency);
    }

    public function minor(): int
    {
        return $this->minor;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    /** The value of the `amount` field. */
    public function wireValue(): string
    {
        return (string) $this->minor;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::fromMinor($this->minor - $other->minor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency->equals($other->currency);
    }

    public function isAtMost(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor <= $other->minor;
    }

    public function isSameCurrencyAs(self $other): bool
    {
        return $this->currency->equals($other->currency);
    }

    /** For a screen or a log, never for the wire. */
    public function toDecimal(): string
    {
        if ($this->currency->decimals() === 0) {
            return (string) $this->minor;
        }

        $divisor = 10 ** $this->currency->decimals();

        return sprintf(
            '%d.%0'.$this->currency->decimals().'d',
            intdiv($this->minor, $divisor),
            $this->minor % $divisor,
        );
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->currency->equals($other->currency)) {
            throw InvalidField::because(
                'amount',
                sprintf('one operation carries one currency, and this one mixes %s with %s', $this->currency->code(), $other->currency->code()),
                $other->currency->code(),
            );
        }
    }

    public function __toString(): string
    {
        return $this->toDecimal().' '.$this->currency->code();
    }
}
