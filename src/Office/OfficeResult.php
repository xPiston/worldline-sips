<?php

declare(strict_types=1);

namespace Piston\Sips\Office;

use DateTimeImmutable;
use Piston\Sips\Field\Amount;
use Piston\Sips\Field\Currency;
use Piston\Sips\Response\ResponseCode;
use Throwable;

/**
 * What a cash-management call answered.
 *
 * `responseCode` 00 means the operation was carried out; everything else means
 * it was not, and the useful ones are specific: `25` the transaction does not
 * exist, `24` it exists but not in a state that allows this (refunding
 * something never captured), `40` the contract does not carry this function,
 * `34` the seal is wrong, `90` and `99` try again later.
 *
 * `newAmount` is the one figure worth storing: after a partial refund it is
 * what the platform now considers refundable, and it is authoritative in a way
 * a shop's own subtraction is not.
 */
final class OfficeResult
{
    /** @param array<string, mixed> $fields */
    private function __construct(
        public readonly array $fields,
        public readonly bool $sealVerified,
    ) {}

    /** @param array<string, mixed> $fields */
    public static function from(array $fields, bool $sealVerified): self
    {
        return new self($fields, $sealVerified);
    }

    public function responseCode(): ?ResponseCode
    {
        $code = $this->field('responseCode');

        return $code === null ? null : ResponseCode::tryFromWire($code);
    }

    public function rawResponseCode(): string
    {
        return $this->field('responseCode') ?? '';
    }

    /**
     * Was the operation carried out?
     *
     * Requires both an accepted response code **and** a verified seal. An
     * unsealed `00` is not an answer from Sips; it is an answer from whatever
     * is between you and Sips.
     */
    public function isDone(): bool
    {
        return $this->sealVerified && ($this->responseCode()?->isAccepted() ?? false);
    }

    public function isWorthRetrying(): bool
    {
        return $this->responseCode()?->isWorthRetrying() ?? false;
    }

    /** The transaction was not found: a reference that was never ours, or a typo. */
    public function isTransactionUnknown(): bool
    {
        return $this->responseCode() === ResponseCode::TransactionNotFound;
    }

    /** It exists, but not in a state where this operation is allowed. */
    public function isStateInvalid(): bool
    {
        return $this->responseCode() === ResponseCode::OperationNotAllowed;
    }

    /** What the platform says is left on the transaction, in its currency. */
    public function newAmount(?Currency $currency = null): ?Amount
    {
        $raw = $this->field('newAmount');

        if ($raw === null || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        try {
            return Amount::fromMinor((int) $raw, $currency ?? Currency::euro());
        } catch (Throwable) {
            return null;
        }
    }

    public function occurredAt(): ?DateTimeImmutable
    {
        $raw = $this->field('operationDateTime');

        if ($raw === null) {
            return null;
        }

        foreach ([DATE_ATOM, 'Y-m-d.H:i:sO', 'Y-m-d\TH:i:sO'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $raw);

            if ($parsed instanceof DateTimeImmutable) {
                return $parsed;
            }
        }

        return null;
    }

    /** The field Sips names when it refuses a request for one. */
    public function errorFieldName(): ?string
    {
        return $this->field('errorFieldName');
    }

    public function field(string $name): ?string
    {
        $value = $this->fields[$name] ?? null;

        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }
}
