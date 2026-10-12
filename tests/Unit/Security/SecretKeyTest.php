<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Sips\Exception\InvalidField;
use Piston\Sips\Security\SecretKey;

final class SecretKeyTest extends TestCase
{
    public function test_it_keeps_the_key_exactly_as_issued(): void
    {
        // Not hexadecimal, not base64: whatever Worldline printed. The
        // simulation key is literally this.
        $key = SecretKey::of('002001000000002_KEY1');

        $this->assertSame('002001000000002_KEY1', $key->value());
        $this->assertSame(1, $key->version);
    }

    public function test_the_version_travels_with_the_key(): void
    {
        $this->assertSame(3, SecretKey::of('whatever', 3)->version);
    }

    public function test_an_empty_key_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('must not be empty');

        SecretKey::of('   ');
    }

    /**
     * The one that costs an afternoon: a key copied out of the extranet with
     * a trailing newline hashes to something else, and every payment fails
     * with an invalid signature.
     */
    #[DataProvider('paddedKeys')]
    public function test_a_key_with_surrounding_whitespace_is_refused_rather_than_trimmed(string $value): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('must not begin or end with whitespace');

        SecretKey::of($value);
    }

    /** @return iterable<string, array{string}> */
    public static function paddedKeys(): iterable
    {
        yield 'trailing newline' => ["key\n"];
        yield 'leading space' => [' key'];
        yield 'trailing tab' => ["key\t"];
        yield 'trailing carriage return' => ["key\r"];
    }

    public function test_a_version_below_one_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('starts at 1');

        SecretKey::of('key', 0);
    }

    public function test_the_error_never_carries_the_key(): void
    {
        try {
            SecretKey::of("s3cr3t-k3y-value\n");
            $this->fail('a padded key should have been refused');
        } catch (InvalidField $e) {
            $this->assertStringNotContainsString('s3cr3t', $e->getMessage());
        }
    }

    public function test_it_hides_itself_from_a_dump(): void
    {
        $dumped = print_r(SecretKey::of('s3cr3t-k3y-value', 2), true);

        $this->assertStringNotContainsString('s3cr3t', $dumped);
        $this->assertStringContainsString('not shown', $dumped);
        $this->assertStringContainsString('2', $dumped, 'the version is not a secret and is worth seeing');
    }
}
