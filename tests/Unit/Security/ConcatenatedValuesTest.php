<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Piston\Sips\Security\ConcatenatedValues;

/**
 * The Office seal string.
 *
 * Every rule below was confirmed against Worldline's test platform: with this
 * string a cancel answers `25` (transaction not found - request read), and
 * with any variation it answers `34` (suspected fraud - seal rejected).
 */
final class ConcatenatedValuesTest extends TestCase
{
    public function test_it_concatenates_the_values_sorted_by_field_name(): void
    {
        $string = ConcatenatedValues::of([
            'merchantId' => '201040040170001',
            'interfaceVersion' => 'CR_WS_2.17',
            'operationAmount' => '1000',
            'currencyCode' => '978',
            'transactionReference' => 'NOSUCHTRANS000001',
        ]);

        // currencyCode, interfaceVersion, merchantId, operationAmount,
        // transactionReference - and nothing between them.
        $this->assertSame('978CR_WS_2.172010400401700011000NOSUCHTRANS000001', $string);
    }

    /**
     * The mistake that is indistinguishable from a wrong key.
     */
    public function test_the_key_version_and_the_seal_algorithm_are_sent_but_not_sealed(): void
    {
        $withThem = ConcatenatedValues::of([
            'merchantId' => '1234',
            'keyVersion' => '1',
            'sealAlgorithm' => 'HMAC-SHA-256',
            'seal' => str_repeat('a', 64),
        ]);

        $this->assertSame('1234', $withThem);
    }

    public function test_the_order_of_the_input_array_does_not_matter(): void
    {
        $fields = ['b' => '2', 'a' => '1', 'c' => '3'];
        $reversed = array_reverse($fields, true);

        $this->assertSame('123', ConcatenatedValues::of($fields));
        $this->assertSame(ConcatenatedValues::of($fields), ConcatenatedValues::of($reversed));
    }

    /** ASCII order, so upper case sorts before lower case. */
    public function test_the_sort_is_on_bytes(): void
    {
        $this->assertSame('ABab', ConcatenatedValues::of([
            'b' => 'b',
            'B' => 'B',
            'a' => 'a',
            'A' => 'A',
        ]));
    }

    public function test_a_numeric_value_contributes_its_digits(): void
    {
        $this->assertSame('1000978', ConcatenatedValues::of(['amount' => 1000, 'currency' => 978]));
    }

    public function test_a_boolean_is_spelled_out_rather_than_cast(): void
    {
        // (string) true is "1" and (string) false is "", neither of which is
        // what Sips writes in JSON.
        $this->assertSame('falsetrue', ConcatenatedValues::of(['a' => false, 'b' => true]));
    }

    public function test_a_null_contributes_nothing(): void
    {
        $this->assertSame('ab', ConcatenatedValues::of(['a' => 'a', 'middle' => null, 'z' => 'b']));
    }

    public function test_no_fields_is_an_empty_string(): void
    {
        $this->assertSame('', ConcatenatedValues::of([]));
    }

    /**
     * The real response from the platform: `{"responseCode":"12"}` is sealed
     * as the HMAC of the string `12`.
     */
    public function test_the_documented_response_shape(): void
    {
        $this->assertSame('12', ConcatenatedValues::of([
            'responseCode' => '12',
            'seal' => 'f80042c6f1b7aef54ef35e1bc90f35886aa42b0d5a51c3c62095bd4e7f5a32d5',
        ]));
    }
}
