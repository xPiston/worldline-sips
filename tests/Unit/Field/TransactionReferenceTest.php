<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Unit\Field;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Sips\Exception\InvalidField;
use Piston\Sips\Field\TransactionReference;

final class TransactionReferenceTest extends TestCase
{
    public function test_it_keeps_the_reference_as_given(): void
    {
        $this->assertSame('SIM20221114112037', TransactionReference::of('SIM20221114112037')->value());
        $this->assertSame('SIM20221114112037', (string) TransactionReference::of('SIM20221114112037'));
    }

    public function test_thirty_five_characters_are_allowed(): void
    {
        $this->assertSame(str_repeat('a', 35), TransactionReference::of(str_repeat('a', 35))->value());
    }

    #[DataProvider('refusedReferences')]
    public function test_it_refuses_what_sips_refuses(string $value): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('letters or digits');

        TransactionReference::of($value);
    }

    /**
     * Letters and digits only. A dash or a slash in an order reference is the
     * most natural thing in the world and this field does not take it.
     *
     * @return iterable<string, array{string}>
     */
    public static function refusedReferences(): iterable
    {
        yield 'empty' => [''];
        yield 'thirty-six characters' => [str_repeat('a', 36)];
        yield 'with a dash' => ['CMD-2026-0042'];
        yield 'with a slash' => ['CMD/42'];
        yield 'with a space' => ['order 42'];
        yield 'with an underscore' => ['order_42'];
        yield 'with an accent' => ['réf42'];
        yield 'with a line break' => ["ref\n42"];
    }

    public function test_two_identical_references_are_the_same_transaction(): void
    {
        $this->assertTrue(TransactionReference::of('REF1')->equals(TransactionReference::of('REF1')));
        $this->assertFalse(TransactionReference::of('REF1')->equals(TransactionReference::of('ref1')));
    }
}
