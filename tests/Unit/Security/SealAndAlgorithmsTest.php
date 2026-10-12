<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Sips\Exception\InvalidField;
use Piston\Sips\Security\HmacSha256Seal;
use Piston\Sips\Security\Seal;
use Piston\Sips\Security\SealAlgorithm;
use Piston\Sips\Security\SecretKey;
use Piston\Sips\Security\Sha256Seal;

final class SealAndAlgorithmsTest extends TestCase
{
    public function test_a_seal_is_sixty_four_hexadecimal_characters(): void
    {
        $seal = Seal::fromHex(str_repeat('AB', 32));

        $this->assertSame(str_repeat('ab', 32), $seal->value(), 'normalised to lower case');
        $this->assertSame(str_repeat('ab', 32), (string) $seal);
    }

    public function test_two_seals_written_in_different_cases_are_equal(): void
    {
        $this->assertTrue(Seal::fromHex(str_repeat('AB', 32))->equals(Seal::fromHex(str_repeat('ab', 32))));
    }

    public function test_a_seal_differing_by_one_character_is_not_equal(): void
    {
        $this->assertFalse(
            Seal::fromHex(str_repeat('a', 64))->equals(Seal::fromHex(str_repeat('a', 63).'b')),
        );
    }

    #[DataProvider('notSeals')]
    public function test_it_refuses_what_is_not_a_seal(string $value): void
    {
        $this->expectException(InvalidField::class);

        Seal::fromHex($value);
    }

    /** @return iterable<string, array{string}> */
    public static function notSeals(): iterable
    {
        yield 'empty' => [''];
        yield 'a 40-character seal from another gateway' => [str_repeat('a', 40)];
        yield 'too short' => [str_repeat('a', 63)];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'not hexadecimal' => [str_repeat('g', 64)];
    }

    public function test_it_tolerates_surrounding_whitespace(): void
    {
        $this->assertSame(str_repeat('a', 64), Seal::fromHex(' '.str_repeat('a', 64)."\n")->value());
    }

    public function test_the_hmac_algorithm_keys_the_hash_with_the_secret(): void
    {
        $algorithm = new HmacSha256Seal;
        $key = SecretKey::of('secret123');

        $this->assertSame('HMAC-SHA-256', $algorithm->wireValue());
        $this->assertSame(
            hash_hmac('sha256', 'some data', 'secret123'),
            $algorithm->compute('some data', $key)->value(),
        );
    }

    public function test_the_sha256_algorithm_concatenates_the_secret(): void
    {
        $algorithm = new Sha256Seal;
        $key = SecretKey::of('secret123');

        $this->assertSame('SHA-256', $algorithm->wireValue());
        $this->assertSame(
            hash('sha256', 'some datasecret123'),
            $algorithm->compute('some data', $key)->value(),
        );
    }

    public function test_the_two_algorithms_never_agree(): void
    {
        $key = SecretKey::of('secret123');

        $this->assertNotSame(
            (new HmacSha256Seal)->compute('x', $key)->value(),
            (new Sha256Seal)->compute('x', $key)->value(),
        );
    }

    public function test_matching_is_the_same_question_as_computing(): void
    {
        $key = SecretKey::of('secret123');
        $algorithm = new HmacSha256Seal;

        $this->assertTrue($algorithm->matches('data', $algorithm->compute('data', $key), $key));
        $this->assertFalse($algorithm->matches('other', $algorithm->compute('data', $key), $key));
        $this->assertFalse($algorithm->matches('data', $algorithm->compute('data', SecretKey::of('another')), $key));
    }

    /**
     * Sips spells its own algorithm two ways - `HMAC-SHA-256` in a request,
     * `sha256` in a documented JSON response - so reading the field has to
     * accept both without deciding which is the typo.
     */
    #[DataProvider('wireValues')]
    public function test_it_reads_the_algorithm_out_of_a_field_value(string $value, ?string $expected): void
    {
        $algorithm = SealAlgorithm::fromWireValue($value);

        $this->assertSame($expected, $algorithm === null ? null : $algorithm::class);
    }

    /** @return iterable<string, array{string, class-string|null}> */
    public static function wireValues(): iterable
    {
        yield 'as a request spells it' => ['HMAC-SHA-256', HmacSha256Seal::class];
        yield 'without the dashes' => ['HMACSHA256', HmacSha256Seal::class];
        yield 'lower case' => ['hmac-sha-256', HmacSha256Seal::class];
        yield 'as a response spells it' => ['sha256', Sha256Seal::class];
        yield 'with the dash' => ['SHA-256', Sha256Seal::class];
        yield 'with whitespace' => [' SHA-256 ', Sha256Seal::class];
        yield 'unknown' => ['SHA-512', null];
        yield 'empty' => ['', null];
    }
}
