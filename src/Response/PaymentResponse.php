<?php

declare(strict_types=1);

namespace Piston\Sips\Response;

use DateTimeImmutable;
use Piston\Sips\Field\Amount;
use Piston\Sips\Field\Currency;
use Piston\Sips\Field\TransactionReference;
use Piston\Sips\Security\DataElement;
use Piston\Sips\Security\Seal;
use Piston\Sips\Security\SealAlgorithm;
use Throwable;

/**
 * A Sips response, read into something an application can act on.
 *
 * **Nothing here throws.** A response arrives on two channels, one of them a
 * server-to-server POST that Sips expects to succeed, and it carries a hundred
 * fields of which this library names thirty. An unfamiliar brand, a date in a
 * shape nobody has seen, a response code added last quarter: each yields null
 * from its own accessor and changes nothing else. The Data element stays
 * available in full, exactly as it arrived.
 *
 * And it is the Data element, not a rebuilt one. The seal was verified against
 * the string that came in; these accessors only read it.
 */
final class PaymentResponse
{
    private function __construct(
        public readonly DataElement $data,
        public readonly ?Seal $seal,
        public readonly string $interfaceVersion,
        private readonly ?SealAlgorithm $sealedWith = null,
    ) {}

    /**
     * From the POST body of either response channel.
     *
     * @param  array<string, mixed>  $post
     */
    public static function fromPost(array $post): self
    {
        $read = static function (string $name) use ($post): string {
            $value = $post[$name] ?? '';

            return is_scalar($value) ? (string) $value : '';
        };

        $encoding = \Piston\Sips\Security\Encoding::fromWireValue($read('Encode')) ?? \Piston\Sips\Security\Encoding::Plain;

        $seal = null;

        try {
            $seal = Seal::fromHex($read('Seal'));
        } catch (Throwable) {
            // A malformed seal is simply no seal; the verifier refuses it.
        }

        return new self(
            DataElement::received($read('Data'), $encoding),
            $seal,
            $read('InterfaceVersion'),
        );
    }

    public function withSealAlgorithm(SealAlgorithm $algorithm): self
    {
        return new self($this->data, $this->seal, $this->interfaceVersion, $algorithm);
    }

    /** Which algorithm's seal matched, once a verifier has checked it. */
    public function sealedWith(): ?SealAlgorithm
    {
        return $this->sealedWith;
    }

    public function field(string $name): ?string
    {
        return $this->data->field($name);
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->data->fields();
    }

    public function responseCode(): ?ResponseCode
    {
        return ResponseCode::tryFromWire($this->field('responseCode') ?? '');
    }

    public function rawResponseCode(): string
    {
        return $this->field('responseCode') ?? '';
    }

    /**
     * Was the payment taken?
     *
     * False for a code this library does not know, because the alternative
     * failure is delivering goods nobody paid for.
     */
    public function isAccepted(): bool
    {
        return $this->responseCode()?->isAccepted() ?? false;
    }

    public function merchantId(): ?string
    {
        return $this->field('merchantId');
    }

    public function reference(): ?TransactionReference
    {
        $value = $this->field('transactionReference');

        if ($value === null) {
            return null;
        }

        try {
            return TransactionReference::of($value);
        } catch (Throwable) {
            return null;
        }
    }

    public function orderId(): ?string
    {
        return $this->field('orderId');
    }

    /**
     * What was authorised.
     *
     * Needs both `amount` and `currencyCode`, and gives up rather than assume
     * euros - a shop that sells in two currencies would otherwise compare the
     * right number against the wrong money.
     */
    public function amount(): ?Amount
    {
        $amount = $this->field('amount');
        $currency = $this->field('currencyCode');

        if ($amount === null || $currency === null || preg_match('/^\d+$/', $amount) !== 1) {
            return null;
        }

        try {
            return Amount::fromMinor((int) $amount, self::currencyFromNumeric($currency));
        } catch (Throwable) {
            return null;
        }
    }

    public function authorisationId(): ?string
    {
        return $this->field('authorisationId');
    }

    public function acquirerResponseCode(): ?string
    {
        return $this->field('acquirerResponseCode');
    }

    public function maskedPan(): ?string
    {
        return $this->field('maskedPan');
    }

    public function paymentMeanBrand(): ?string
    {
        return $this->field('paymentMeanBrand');
    }

    public function paymentMeanType(): ?string
    {
        return $this->field('paymentMeanType');
    }

    /** `panExpiryDate`, as `YYYYMM`. */
    public function cardExpiry(): ?string
    {
        return $this->field('panExpiryDate');
    }

    /**
     * The 3-D Secure programme the payment went through, if any.
     *
     * Worth storing with the order: together with `holderAuthentStatus` it is
     * what decides who carries a disputed payment months later.
     */
    public function authenticationProgram(): ?string
    {
        return $this->field('holderAuthentProgram');
    }

    public function authenticationStatus(): ?string
    {
        return $this->field('holderAuthentStatus');
    }

    /** `returnContext`: whatever the shop put in the request, handed back. */
    public function returnContext(): ?string
    {
        return $this->field('returnContext');
    }

    /** The date Sips stamped the transaction with, or null if unreadable. */
    public function occurredAt(): ?DateTimeImmutable
    {
        $raw = $this->field('transactionDateTime');

        if ($raw === null) {
            return null;
        }

        // Sips has been seen sending both `2022-11-14T11:21:12+01:00` and
        // `2023-03-15.00:39:04+0100` - the second in its own JSON examples.
        foreach ([DATE_ATOM, 'Y-m-d.H:i:sO', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:sO'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $raw);

            if ($parsed instanceof DateTimeImmutable) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Does this response describe the order the shop remembers?
     *
     * A valid seal proves the message is authentic and unaltered. It does not
     * prove it is about this order, for this money, on this merchant id -
     * which is three comparisons, and they close the gap between "Sips sent
     * this" and "this is my order".
     */
    public function matches(TransactionReference $reference, Amount $amount, ?string $merchantId = null): bool
    {
        $received = $this->amount();

        return $this->reference()?->equals($reference) === true
            && $received !== null
            && $received->equals($amount)
            && ($merchantId === null || $this->merchantId() === $merchantId);
    }

    private static function currencyFromNumeric(string $numeric): Currency
    {
        foreach (['EUR', 'USD', 'GBP', 'CHF', 'CAD', 'AUD', 'NZD', 'SEK', 'NOK', 'DKK',
            'PLN', 'CZK', 'RON', 'BGN', 'HUF', 'MAD', 'TND', 'XPF', 'JPY', 'KRW'] as $code) {
            $candidate = Currency::of($code);

            if ($candidate->wireValue() === str_pad($numeric, 3, '0', STR_PAD_LEFT)) {
                return $candidate;
            }
        }

        // A currency this library does not list: the code is kept, and the
        // exponent is the one the overwhelming majority of currencies have.
        // Only `toDecimal()` depends on it; the integer amount does not.
        return Currency::numeric((int) $numeric, 2);
    }
}
