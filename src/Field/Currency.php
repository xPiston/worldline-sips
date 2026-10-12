<?php

declare(strict_types=1);

namespace Piston\Sips\Field;

use Piston\Sips\Exception\InvalidField;
use Stringable;

/**
 * A currency, by its ISO 4217 *numeric* code - which is what Sips wants.
 *
 * `currencyCode=978`, not `EUR`. It catches people out because every other
 * field in the protocol is readable, and because 978 and 840 are not numbers
 * anyone remembers. So this takes the letters and sends the digits.
 *
 * The exponent matters as much as the code: `amount` is an integer in the
 * currency's smallest unit, so 25 euros is 2500 and 25 yen is 25. Getting it
 * wrong charges a hundred times too much or too little, and the platform
 * cannot tell.
 *
 * The list is the currencies Sips documents, plus the ones a French shop is
 * likely to be paid in. An unlisted currency can still be used, by giving the
 * numeric code and the exponent explicitly - this library refuses to guess
 * that a code it does not know has two decimals.
 */
final class Currency implements Stringable
{
    /** @var array<string, array{int, int}> alpha-3 => [numeric code, decimals] */
    private const KNOWN = [
        'EUR' => [978, 2],
        'USD' => [840, 2],
        'GBP' => [826, 2],
        'CHF' => [756, 2],
        'CAD' => [124, 2],
        'AUD' => [36, 2],
        'NZD' => [554, 2],
        'SEK' => [752, 2],
        'NOK' => [578, 2],
        'DKK' => [208, 2],
        'PLN' => [985, 2],
        'CZK' => [203, 2],
        'RON' => [946, 2],
        'BGN' => [975, 2],
        'HUF' => [348, 2],
        'MAD' => [504, 2],
        'TND' => [788, 3],
        'XPF' => [953, 0],
        'JPY' => [392, 0],
        'KRW' => [410, 0],
    ];

    private function __construct(
        private readonly string $code,
        private readonly int $numericCode,
        private readonly int $decimals,
    ) {}

    public static function of(string $code): self
    {
        $code = strtoupper(trim($code));

        if (! isset(self::KNOWN[$code])) {
            throw InvalidField::because(
                'currencyCode',
                sprintf(
                    'this library does not know the numeric code of "%s"; give it explicitly with Currency::numeric(code, decimals) rather than let it be guessed',
                    $code,
                ),
                $code,
            );
        }

        [$numeric, $decimals] = self::KNOWN[$code];

        return new self($code, $numeric, $decimals);
    }

    /** For a currency this library does not list, or a code it should not guess. */
    public static function numeric(int $numericCode, int $decimals, string $code = ''): self
    {
        if ($numericCode < 1 || $numericCode > 999) {
            throw InvalidField::because('currencyCode', 'expected an ISO 4217 numeric code between 1 and 999', (string) $numericCode);
        }

        if ($decimals < 0 || $decimals > 3) {
            throw InvalidField::because('currencyCode', 'expected 0 to 3 decimals', (string) $decimals);
        }

        return new self($code === '' ? sprintf('%03d', $numericCode) : strtoupper($code), $numericCode, $decimals);
    }

    public static function euro(): self
    {
        return self::of('EUR');
    }

    public function code(): string
    {
        return $this->code;
    }

    /** The value of the `currencyCode` field: three digits, zero-padded. */
    public function wireValue(): string
    {
        return sprintf('%03d', $this->numericCode);
    }

    public function decimals(): int
    {
        return $this->decimals;
    }

    public function equals(self $other): bool
    {
        return $this->numericCode === $other->numericCode;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
