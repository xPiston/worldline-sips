<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Unit\Response;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Sips\Response\ResponseCode;

final class ResponseCodeTest extends TestCase
{
    public function test_it_reads_the_two_digit_codes(): void
    {
        $this->assertSame(ResponseCode::Accepted, ResponseCode::tryFromWire('00'));
        $this->assertSame(ResponseCode::Refused, ResponseCode::tryFromWire('05'));
        $this->assertSame(ResponseCode::SessionExpired, ResponseCode::tryFromWire('97'));
    }

    /** A single digit, which some connectors send unpadded. */
    public function test_it_pads_a_single_digit(): void
    {
        $this->assertSame(ResponseCode::Accepted, ResponseCode::tryFromWire('0'));
        $this->assertSame(ResponseCode::Refused, ResponseCode::tryFromWire('5'));
    }

    public function test_an_unknown_code_is_null(): void
    {
        $this->assertNull(ResponseCode::tryFromWire('A7'));
        $this->assertNull(ResponseCode::tryFromWire('1234'));
    }

    /**
     * The one that would have shipped. Padding an empty string to two
     * characters gives "00", and "00" is a payment - so a response with no
     * response code at all would have read as accepted. This test exists
     * because the first draft did that.
     */
    public function test_an_empty_code_is_not_an_acceptance(): void
    {
        $this->assertNull(ResponseCode::tryFromWire(''));
        $this->assertNull(ResponseCode::tryFromWire('   '));
        $this->assertNull(ResponseCode::tryFromWire("\n"));
    }

    public function test_only_zero_zero_is_accepted(): void
    {
        $this->assertTrue(ResponseCode::Accepted->isAccepted());

        foreach (ResponseCode::cases() as $case) {
            if ($case !== ResponseCode::Accepted) {
                $this->assertFalse($case->isAccepted(), $case->value.' must not be an acceptance');
            }
        }
    }

    /**
     * The three questions a shop actually asks of a refusal, and they have
     * different answers.
     */
    #[DataProvider('categories')]
    public function test_it_sorts_the_refusals_into_what_to_do_about_them(
        ResponseCode $code,
        bool $retry,
        bool $merchantsFault,
        bool $cancelled,
    ): void {
        $this->assertSame($retry, $code->isWorthRetrying(), $code->value.' retry');
        $this->assertSame($merchantsFault, $code->isMerchantsFault(), $code->value.' fault');
        $this->assertSame($cancelled, $code->isCancelledByCustomer(), $code->value.' cancelled');
    }

    /** @return iterable<string, array{ResponseCode, bool, bool, bool}> */
    public static function categories(): iterable
    {
        yield 'accepted' => [ResponseCode::Accepted, false, false, false];
        yield 'refused by the issuer' => [ResponseCode::Refused, false, false, false];
        yield 'the customer walked away' => [ResponseCode::CancelledByCustomer, false, false, true];
        yield 'the acquirer is down' => [ResponseCode::AcquirerUnavailable, true, false, false];
        yield 'the session expired' => [ResponseCode::SessionExpired, true, false, false];
        yield 'the platform is down' => [ResponseCode::PlatformUnavailable, true, false, false];
        yield 'the issuer timed out' => [ResponseCode::ResponseTimeout, true, false, false];
        yield 'our seal is wrong' => [ResponseCode::SuspectedFraudSeal, false, true, false];
        yield 'our contract is wrong' => [ResponseCode::InvalidMerchantContract, false, true, false];
        yield 'our request is malformed' => [ResponseCode::FormatError, false, true, false];
        yield 'our terminal may not do this' => [ResponseCode::TransactionNotAllowedToTerminal, false, true, false];
        yield 'the card has no money' => [ResponseCode::AmountTooHigh, false, false, false];
        yield 'the card expired' => [ResponseCode::CardExpired, false, false, false];
    }

    public function test_every_code_has_a_label(): void
    {
        foreach (ResponseCode::cases() as $case) {
            $this->assertNotSame('', $case->label(), $case->value.' needs a label');
        }
    }

    public function test_the_codes_seen_against_the_live_platform_are_known(): void
    {
        // 25 and 34 came back from the Office test server, 12 from a malformed
        // request, 00 from the documented response.
        foreach (['00', '12', '25', '34'] as $code) {
            $this->assertInstanceOf(ResponseCode::class, ResponseCode::tryFromWire($code));
        }
    }
}
