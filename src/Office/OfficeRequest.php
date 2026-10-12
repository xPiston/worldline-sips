<?php

declare(strict_types=1);

namespace Piston\Sips\Office;

use Piston\Sips\Configuration\Credentials;
use Piston\Sips\Exception\InvalidField;
use Piston\Sips\Field\Amount;
use Piston\Sips\Field\TransactionReference;
use Piston\Sips\Security\SealAlgorithm;

/**
 * A cash-management call: an operation, a transaction and an amount.
 *
 * The field set is small and it is **exactly** this. Cash management answers
 * `12` with `errorFieldName` for anything it does not expect, which is how
 * this list was settled rather than assembled from hope: `comment` is refused,
 * `operationOriginalReference` is refused, `s10TransactionReference` is
 * refused on its own. The transaction being acted on is named by
 * `transactionReference`, and that is all.
 *
 * `operationAmount` is the amount of *this* operation, not of the original
 * payment: a partial refund sends what is being refunded now. The platform
 * keeps the running total and answers with `newAmount`, which is the figure a
 * shop should store rather than its own arithmetic.
 */
final class OfficeRequest
{
    /**
     * The interface version family for cash management.
     *
     * `CR_WS_`, not the `IR_WS_` of a card order - sending the latter answers
     * `12` with `errorFieldName: interfaceVersion`, which is how this was
     * pinned down. Every minor version from 2.0 to 2.17 is accepted; the
     * latest returns the most fields.
     */
    public const INTERFACE_VERSION = 'CR_WS_2.17';

    private function __construct(
        public readonly Operation $operation,
        public readonly TransactionReference $reference,
        public readonly Amount $amount,
        /** @var array<string, string|int> */
        public readonly array $extra,
    ) {}

    /**
     * Capture an authorised payment.
     *
     * The amount may be lower than the one authorised - two items of three
     * shipped - and never higher.
     *
     * @param  array<string, string|int>  $extra
     */
    public static function validate(TransactionReference $reference, Amount $amount, array $extra = []): self
    {
        return new self(Operation::Validate, $reference, $amount, $extra);
    }

    /** @param array<string, string|int> $extra */
    public static function cancel(TransactionReference $reference, Amount $amount, array $extra = []): self
    {
        return new self(Operation::Cancel, $reference, $amount, $extra);
    }

    /** @param array<string, string|int> $extra */
    public static function refund(TransactionReference $reference, Amount $amount, array $extra = []): self
    {
        if ($amount->isZero()) {
            throw InvalidField::because('operationAmount', 'a refund of nothing is not a refund', '0');
        }

        return new self(Operation::Refund, $reference, $amount, $extra);
    }

    /**
     * The JSON body, before the seal is added.
     *
     * @return array<string, string>
     */
    public function fields(Credentials $credentials, SealAlgorithm $algorithm): array
    {
        $fields = [
            'merchantId' => $credentials->merchantId,
            'keyVersion' => (string) $credentials->key->version,
            'sealAlgorithm' => $algorithm->wireValue(),
            'interfaceVersion' => self::INTERFACE_VERSION,
            'transactionReference' => $this->reference->value(),
            'operationAmount' => $this->amount->wireValue(),
            'currencyCode' => $this->amount->currency()->wireValue(),
        ];

        foreach ($this->extra as $name => $value) {
            if (isset($fields[$name])) {
                throw InvalidField::because(
                    'extra',
                    sprintf('"%s" is already built from the request itself', $name),
                    (string) $name,
                );
            }

            $fields[(string) $name] = (string) $value;
        }

        return $fields;
    }
}
