<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Unit\Field;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Sips\Exception\InvalidField;
use Piston\Sips\Field\Amount;
use Piston\Sips\Field\Currency;

final class CurrencyAndAmountTest extends TestCase
{
    /**
     * Sips wants the numeric code, which is the part nobody remembers.
     */
    #[DataProvider('numericCodes')]
    public function test_it_sends_the_numeric_code(string $code, string $wire, int $decimals): void
    {
        $currency = Currency::of($code);

        $this->assertSame($wire, $currency->wireValue());
        $this->assertSame($decimals, $currency->decimals());
        $this->assertSame($code, $currency->code());
        $this->assertSame($code, (string) $currency);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function numericCodes(): iterable
    {
        yield 'euro' => ['EUR', '978', 2];
        yield 'dollar' => ['USD', '840', 2];
        yield 'pound' => ['GBP', '826', 2];
        yield 'yen, no minor unit' => ['JPY', '392', 0];
        yield 'won, no minor unit' => ['KRW', '410', 0];
        yield 'CFP franc, no minor unit' => ['XPF', '953', 0];
        yield 'Tunisian dinar, three decimals' => ['TND', '788', 3];
        yield 'Australian dollar, zero-padded' => ['AUD', '036', 2];
    }

    public function test_it_reads_the_code_in_any_case(): void
    {
        $this->assertSame('978', Currency::of(' eur ')->wireValue());
        $this->assertTrue(Currency::of('eur')->equals(Currency::euro()));
    }

    /**
     * Guessing that an unknown code has two decimals is how an amount ends up
     * a hundred times wrong, so it is refused by name instead.
     */
    public function test_an_unknown_code_is_refused_rather_than_guessed(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('does not know the numeric code of "ZWL"');

        Currency::of('ZWL');
    }

    public function test_an_unknown_currency_can_be_given_explicitly(): void
    {
        $currency = Currency::numeric(932, 2, 'ZWL');

        $this->assertSame('932', $currency->wireValue());
        $this->assertSame('ZWL', $currency->code());
        $this->assertSame(2, $currency->decimals());
    }

    public function test_an_explicit_currency_without_a_code_names_itself_by_its_digits(): void
    {
        $this->assertSame('932', Currency::numeric(932, 2)->code());
    }

    #[DataProvider('impossibleCurrencies')]
    public function test_it_refuses_an_impossible_numeric_currency(int $numeric, int $decimals): void
    {
        $this->expectException(InvalidField::class);

        Currency::numeric($numeric, $decimals);
    }

    /** @return iterable<string, array{int, int}> */
    public static function impossibleCurrencies(): iterable
    {
        yield 'code zero' => [0, 2];
        yield 'code too high' => [1000, 2];
        yield 'negative decimals' => [978, -1];
        yield 'four decimals' => [978, 4];
    }

    public function test_an_amount_is_an_integer_of_the_smallest_unit(): void
    {
        $amount = Amount::fromMinor(2500, 'EUR');

        $this->assertSame('2500', $amount->wireValue());
        $this->assertSame(2500, $amount->minor());
        $this->assertSame('25.00', $amount->toDecimal());
        $this->assertSame('25.00 EUR', (string) $amount);
    }

    /**
     * The same 2500 is twenty-five euros and two thousand five hundred yen.
     */
    public function test_the_currency_decides_what_the_integer_means(): void
    {
        $this->assertSame('25.00', Amount::fromMinor(2500, 'EUR')->toDecimal());
        $this->assertSame('2500', Amount::fromMinor(2500, 'JPY')->toDecimal());
        $this->assertSame('2.500', Amount::fromMinor(2500, 'TND')->toDecimal());

        // And the wire value is the same in all three, which is exactly why
        // the currency code travels with it.
        $this->assertSame('2500', Amount::fromMinor(2500, 'JPY')->wireValue());
    }

    public function test_zero_is_an_amount(): void
    {
        $this->assertTrue(Amount::zero('EUR')->isZero());
        $this->assertSame('0', Amount::zero('EUR')->wireValue());
        $this->assertFalse(Amount::fromMinor(1, 'EUR')->isZero());
    }

    public function test_a_negative_amount_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('cannot be negative');

        Amount::fromMinor(-1, 'EUR');
    }

    public function test_an_amount_beyond_the_field_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('at most 12 digits');

        Amount::fromMinor(1_000_000_000_000, 'EUR');
    }

    public function test_it_adds_and_subtracts_within_one_currency(): void
    {
        $total = Amount::fromMinor(2500, 'EUR')->plus(Amount::fromMinor(500, 'EUR'));

        $this->assertSame(3000, $total->minor());
        $this->assertSame(2000, $total->minus(Amount::fromMinor(1000, 'EUR'))->minor());
    }

    public function test_subtracting_past_zero_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('cannot be negative');

        Amount::fromMinor(100, 'EUR')->minus(Amount::fromMinor(101, 'EUR'));
    }

    public function test_it_refuses_to_mix_currencies(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('mixes EUR with USD');

        Amount::fromMinor(100, 'EUR')->plus(Amount::fromMinor(100, 'USD'));
    }

    public function test_equality_is_on_the_amount_and_the_currency(): void
    {
        $this->assertTrue(Amount::fromMinor(100, 'EUR')->equals(Amount::fromMinor(100, 'EUR')));
        $this->assertFalse(Amount::fromMinor(100, 'EUR')->equals(Amount::fromMinor(100, 'USD')));
        $this->assertFalse(Amount::fromMinor(100, 'EUR')->equals(Amount::fromMinor(101, 'EUR')));
    }

    /** What a partial capture or refund has to satisfy. */
    public function test_it_compares_within_one_currency(): void
    {
        $this->assertTrue(Amount::fromMinor(100, 'EUR')->isAtMost(Amount::fromMinor(100, 'EUR')));
        $this->assertTrue(Amount::fromMinor(99, 'EUR')->isAtMost(Amount::fromMinor(100, 'EUR')));
        $this->assertFalse(Amount::fromMinor(101, 'EUR')->isAtMost(Amount::fromMinor(100, 'EUR')));
        $this->assertTrue(Amount::fromMinor(1, 'EUR')->isSameCurrencyAs(Amount::zero('EUR')));
    }

    public function test_a_total_built_up_from_lines_does_not_drift(): void
    {
        $total = Amount::zero('EUR');

        for ($line = 0; $line < 10; $line++) {
            $total = $total->plus(Amount::fromMinor(10, 'EUR'));
        }

        $this->assertSame(100, $total->minor());
        $this->assertSame('1.00', $total->toDecimal());
    }
}
