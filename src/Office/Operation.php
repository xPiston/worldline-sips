<?php

declare(strict_types=1);

namespace Piston\Sips\Office;

/**
 * The cash-management operations this library implements.
 *
 * Three verbs on one endpoint family, and the difference between them is the
 * difference between a shop that balances and one that does not:
 *
 * - `Validate` captures a transaction that was only authorised. The money
 *   moves now. Only while the authorisation is alive - a week or so - and only
 *   for a payment taken in `VALIDATION` capture mode.
 * - `Cancel` gives up an authorisation, or a capture that has not been settled
 *   yet. Nothing is taken and nothing is charged for it.
 * - `Refund` sends settled money back. It is a new movement with its own fees,
 *   which is why cancelling is always the better answer when both are still
 *   possible.
 *
 * `duplicate` - charging a stored card again - is deliberately absent. Its
 * field set could not be established: the platform answers `12` to every
 * shape tried, and unlike its answers for the three above it names no field.
 * A payment library should not ship a guess about which fields move money.
 */
enum Operation: string
{
    case Validate = 'validate';
    case Cancel = 'cancel';
    case Refund = 'refund';

    public function path(): string
    {
        return 'cashManagement/'.$this->value;
    }

    /** Does this operation move money that was already settled? */
    public function isRefund(): bool
    {
        return $this === self::Refund;
    }
}
