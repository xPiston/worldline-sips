<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Piston\Sips\Exception\InvalidField;
use Piston\Sips\Security\DataElement;
use Piston\Sips\Security\Encoding;

final class DataElementTest extends TestCase
{
    public function test_it_joins_the_fields_with_a_pipe_in_the_order_given(): void
    {
        $data = DataElement::of(['merchantId' => '1234', 'amount' => 2500, 'currencyCode' => '978']);

        $this->assertSame('merchantId=1234|amount=2500|currencyCode=978', $data->transmitted());
    }

    public function test_the_order_given_is_the_order_kept(): void
    {
        // No sorting anywhere: the string that is sealed is the string that is
        // sent, and for a request the order is the caller's.
        $this->assertSame(
            'z=1|a=2',
            DataElement::of(['z' => '1', 'a' => '2'])->transmitted(),
        );
    }

    public function test_a_received_element_is_kept_byte_for_byte(): void
    {
        $raw = 'captureDay=0|captureMode=AUTHOR_CAPTURE|responseCode=00';

        $this->assertSame($raw, DataElement::received($raw)->transmitted());
    }

    public function test_it_parses_the_fields_it_was_given(): void
    {
        $data = DataElement::received('amount=2500|currencyCode=978|responseCode=00');

        $this->assertSame(['amount' => '2500', 'currencyCode' => '978', 'responseCode' => '00'], $data->fields());
        $this->assertSame('2500', $data->field('amount'));
        $this->assertTrue($data->has('responseCode'));
        $this->assertFalse($data->has('somethingElse'));
    }

    /**
     * A URL with a query string is the common case, and splitting on every `=`
     * would cut it in half.
     */
    public function test_a_value_containing_an_equals_sign_survives(): void
    {
        $data = DataElement::received('normalReturnUrl=https://shop.test/ok?ref=A1&b=2|amount=100');

        $this->assertSame('https://shop.test/ok?ref=A1&b=2', $data->field('normalReturnUrl'));
        $this->assertSame('100', $data->field('amount'));
    }

    /**
     * Sips fills absent fields with the four characters `null`, by the dozen.
     * Reading them as a value is how a customer ends up with the phone number
     * "null".
     */
    public function test_the_literal_null_is_read_as_absent(): void
    {
        $data = DataElement::received('customerMobilePhone=null|dccStatus=null|maskedPan=############0600');

        $this->assertNull($data->field('customerMobilePhone'));
        $this->assertNull($data->field('dccStatus'));
        $this->assertFalse($data->has('dccStatus'));
        $this->assertSame('############0600', $data->field('maskedPan'));

        // But they are still in the raw fields, because the seal covers them.
        $this->assertArrayHasKey('dccStatus', $data->fields());
        $this->assertSame('null', $data->fields()['dccStatus']);
    }

    public function test_an_empty_value_is_read_as_absent(): void
    {
        $this->assertNull(DataElement::received('authorisationId=|amount=100')->field('authorisationId'));
    }

    /**
     * A `|` inside a value would make Sips read one field as two, and the seal
     * would be computed over a string that means something else. Caught here
     * rather than at the platform.
     */
    public function test_a_value_containing_the_separator_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('separates the fields of the Data element');

        DataElement::of(['returnContext' => 'a|b']);
    }

    public function test_a_field_name_containing_a_separator_or_an_equals_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('cannot contain "|" or "="');

        DataElement::of(['a=b' => 'value']);
    }

    /**
     * Encoded, the separator is no longer a problem - which is the reason the
     * option exists.
     */
    public function test_a_value_containing_the_separator_is_allowed_once_encoded(): void
    {
        $data = DataElement::of(['returnContext' => 'a|b'], Encoding::Base64);

        $this->assertSame('returnContext=a|b', $data->plain());
        $this->assertNotSame('returnContext=a|b', $data->transmitted());
    }

    public function test_base64_round_trips_and_the_seal_covers_the_encoded_form(): void
    {
        $data = DataElement::of(['amount' => '2500', 'currencyCode' => '978'], Encoding::Base64);

        $this->assertSame(base64_encode('amount=2500|currencyCode=978'), $data->transmitted());
        $this->assertSame('amount=2500|currencyCode=978', $data->plain());
        $this->assertSame('2500', $data->field('amount'));
        $this->assertSame(Encoding::Base64, $data->encoding());
    }

    public function test_base64url_round_trips(): void
    {
        $data = DataElement::of(['url' => 'https://shop.test/a?b=c&d=e'], Encoding::Base64Url);

        $this->assertStringNotContainsString('+', $data->transmitted());
        $this->assertStringNotContainsString('/', $data->transmitted());
        $this->assertStringNotContainsString('=', $data->transmitted());
        $this->assertSame('url=https://shop.test/a?b=c&d=e', $data->plain());
    }

    public function test_an_encoded_element_that_is_not_valid_base64_reads_as_nothing(): void
    {
        $data = DataElement::received('!!! not base64 !!!', Encoding::Base64);

        $this->assertNull($data->plain());
        $this->assertSame([], $data->fields());
        $this->assertNull($data->field('amount'));
        $this->assertSame('!!! not base64 !!!', $data->transmitted(), 'the seal still covers what arrived');
    }

    public function test_an_empty_element_has_no_fields(): void
    {
        $this->assertSame([], DataElement::received('')->fields());
        $this->assertSame('', DataElement::of([])->transmitted());
    }

    public function test_a_fragment_without_an_equals_sign_is_skipped(): void
    {
        $this->assertSame(['amount' => '100'], DataElement::received('amount=100|rubbish')->fields());
    }

    public function test_an_integer_value_becomes_its_decimal_string(): void
    {
        $this->assertSame('captureDay=0|amount=2500', DataElement::of(['captureDay' => 0, 'amount' => 2500])->transmitted());
    }
}
