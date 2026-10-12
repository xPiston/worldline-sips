<?php

declare(strict_types=1);

namespace Piston\Sips\Response;

/**
 * `responseCode`: what happened, in two digits.
 *
 * Only `00` is a payment. Everything else is a refusal, and the reason to
 * model them one by one is that the right thing to show a customer differs
 * wildly: `90` and `97` mean "try again in a minute", `51` means "that card
 * has not got it", `17` means they changed their mind, and `34` means the
 * shop's own seal is wrong and no customer should ever have seen a payment
 * page at all.
 *
 * The codes follow the acquirer's own table, which is ISO 8583 lineage - the
 * same numbers a terminal in a shop shows. Values this library has not seen
 * are read as null rather than guessed, and `isAccepted()` is false for them,
 * because the failure mode of guessing the other way is shipping an order
 * nobody paid for.
 */
enum ResponseCode: string
{
    case Accepted = '00';
    case AuthorisationByPhone = '02';
    case InvalidMerchantContract = '03';
    case Refused = '05';
    case InvalidTransaction = '12';
    case InvalidPaymentMean = '14';
    case CancelledByCustomer = '17';
    case OperationNotAllowed = '24';
    case TransactionNotFound = '25';
    case FormatError = '30';
    case SuspectedFraudSeal = '34';
    case FunctionNotSupported = '40';
    case AmountTooHigh = '51';
    case CardExpired = '54';
    case IncorrectSecretCode = '55';
    case CardNotInFile = '56';
    case TransactionNotAllowedToCardholder = '57';
    case TransactionNotAllowedToTerminal = '58';
    case SuspectedFraud = '59';
    case ContactAcquirer = '60';
    case SecurityRulesUnsatisfied = '63';
    case ResponseTimeout = '68';
    case TooManyAttempts = '75';
    case AcquirerUnavailable = '90';
    case DuplicateTransaction = '94';
    case SessionExpired = '97';
    case PlatformUnavailable = '99';

    public static function tryFromWire(string $value): ?self
    {
        $value = trim($value);

        if ($value === '') {
            // Padding an empty string to two characters would make it "00",
            // and "00" is a payment. Worth stating out loud: the first draft
            // of this method did exactly that, and a response with no
            // responseCode at all read as accepted.
            return null;
        }

        // A single digit is padded, because a connector sending `5` for `05`
        // is a connector sending a refusal.
        if (preg_match('/^\d$/', $value) === 1) {
            $value = '0'.$value;
        }

        return self::tryFrom($value);
    }

    public function isAccepted(): bool
    {
        return $this === self::Accepted;
    }

    /** Did the customer walk away rather than be refused? */
    public function isCancelledByCustomer(): bool
    {
        return $this === self::CancelledByCustomer;
    }

    /**
     * Is another attempt with the same card worth offering?
     *
     * These are the codes that describe a moment rather than a card: an
     * acquirer that is down, a session that expired, a response that did not
     * arrive in time. Telling that customer their card was declined is how a
     * shop loses a sale it had.
     */
    public function isWorthRetrying(): bool
    {
        return match ($this) {
            self::AcquirerUnavailable, self::SessionExpired, self::ResponseTimeout, self::PlatformUnavailable => true,
            default => false,
        };
    }

    /**
     * Is this the shop's own fault rather than the customer's?
     *
     * `34` is a bad seal, `03` a misconfigured contract, `30` a malformed
     * request, `58` a terminal that is not allowed to do this. A customer
     * seeing any of these should be shown an apology, and somebody should be
     * paged - not offered another card.
     */
    public function isMerchantsFault(): bool
    {
        return match ($this) {
            self::SuspectedFraudSeal, self::InvalidMerchantContract, self::FormatError,
            self::InvalidTransaction, self::TransactionNotAllowedToTerminal, self::FunctionNotSupported => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'accepted',
            self::AuthorisationByPhone => 'the issuer wants to be telephoned',
            self::InvalidMerchantContract => 'invalid merchant contract',
            self::Refused => 'refused by the issuer',
            self::InvalidTransaction => 'invalid transaction or malformed request',
            self::InvalidPaymentMean => 'invalid card number or payment mean',
            self::CancelledByCustomer => 'cancelled by the customer',
            self::OperationNotAllowed => 'operation not allowed in this state',
            self::TransactionNotFound => 'transaction not found',
            self::FormatError => 'format error in the request',
            self::SuspectedFraudSeal => 'suspected fraud: the seal or the key version is wrong',
            self::FunctionNotSupported => 'function not supported by the contract',
            self::AmountTooHigh => 'amount above the card ceiling',
            self::CardExpired => 'card expired',
            self::IncorrectSecretCode => 'incorrect secret code',
            self::CardNotInFile => 'card not in the issuer file',
            self::TransactionNotAllowedToCardholder => 'transaction not allowed to this cardholder',
            self::TransactionNotAllowedToTerminal => 'transaction not allowed to this terminal',
            self::SuspectedFraud => 'suspected fraud',
            self::ContactAcquirer => 'the acquirer must be contacted',
            self::SecurityRulesUnsatisfied => 'security rules not satisfied',
            self::ResponseTimeout => 'the issuer did not answer in time',
            self::TooManyAttempts => 'too many card attempts',
            self::AcquirerUnavailable => 'the acquirer is temporarily unavailable',
            self::DuplicateTransaction => 'duplicate transaction reference',
            self::SessionExpired => 'the payment session expired',
            self::PlatformUnavailable => 'the payment platform is temporarily unavailable',
        };
    }
}
