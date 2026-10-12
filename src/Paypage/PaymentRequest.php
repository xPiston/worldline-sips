<?php

declare(strict_types=1);

namespace Piston\Sips\Paypage;

use Piston\Sips\Configuration\Credentials;
use Piston\Sips\Exception\InvalidField;
use Piston\Sips\Field\Amount;
use Piston\Sips\Field\TransactionReference;
use Piston\Sips\Security\SealAlgorithm;

/**
 * One payment request, as the fields of the Data element.
 *
 * The mandatory set is short - an amount, a currency, the merchant, a return
 * URL, the key version - and this adds the two that experience says should
 * never be left to a default: `orderChannel`, which decides whether 3-D Secure
 * runs at all, and `captureMode`, which decides when the money moves.
 *
 * `transactionReference` is deliberately nullable. A shop can be configured to
 * let Sips generate it, and then sending one is an error; see
 * TransactionReference.
 *
 * `automaticResponseUrl` is optional in the protocol and all but mandatory in
 * practice: without it the only response is the one the customer's browser
 * carries back, and a customer who closes the tab takes the payment result
 * with them.
 */
final class PaymentRequest
{
    public function __construct(
        public readonly Amount $amount,
        public readonly string $normalReturnUrl,
        public readonly ?TransactionReference $reference = null,
        public readonly ?string $automaticResponseUrl = null,
        public readonly ?string $orderId = null,
        public readonly OrderChannel $orderChannel = OrderChannel::Internet,
        public readonly CaptureMode $captureMode = CaptureMode::AuthorAndCapture,
        public readonly int $captureDay = 0,
        public readonly ?string $customerEmail = null,
        public readonly ?string $customerIpAddress = null,
        public readonly ?string $returnContext = null,
        public readonly ?string $statementReference = null,
        /** @var array<string, string|int> extra documented fields, sent as given */
        public readonly array $extra = [],
    ) {
        foreach (['normalReturnUrl' => $normalReturnUrl, 'automaticResponseUrl' => $automaticResponseUrl] as $field => $url) {
            if ($url !== null && preg_match('#^https?://#i', $url) !== 1) {
                throw InvalidField::because($field, 'expected an absolute http(s) URL', (string) $url);
            }
        }

        if ($customerEmail !== null && preg_match('/^.+@.+\..+$/', $customerEmail) !== 1) {
            throw InvalidField::because('customerContact.email', 'expected an email address', $customerEmail);
        }

        if ($captureDay < 0 || $captureDay > 99) {
            throw InvalidField::because('captureDay', 'expected 0 to 99 days', (string) $captureDay);
        }
    }

    /**
     * The fields of the Data element, in the order they will be sent.
     *
     * The order is this library's choice - Sips reads names, not positions -
     * but it is fixed and documented here rather than left to an array's
     * iteration order, because the string that is sealed and the string that
     * is posted have to be the same one, and a diff against a working request
     * is how an integration gets debugged.
     *
     * @return array<string, string>
     */
    public function fields(Credentials $credentials, SealAlgorithm $algorithm): array
    {
        $fields = [
            'merchantId' => $credentials->merchantId,
            'keyVersion' => (string) $credentials->key->version,
            'sealAlgorithm' => $algorithm->wireValue(),
            'amount' => $this->amount->wireValue(),
            'currencyCode' => $this->amount->currency()->wireValue(),
            'orderChannel' => $this->orderChannel->value,
            'captureMode' => $this->captureMode->value,
            'captureDay' => (string) $this->captureDay,
            'normalReturnUrl' => $this->normalReturnUrl,
        ];

        $optional = [
            'transactionReference' => $this->reference?->value(),
            'automaticResponseUrl' => $this->automaticResponseUrl,
            'orderId' => $this->orderId,
            'customerContact.email' => $this->customerEmail,
            'customerIpAddress' => $this->customerIpAddress,
            'returnContext' => $this->returnContext,
            'statementReference' => $this->statementReference,
        ];

        foreach ($optional as $name => $value) {
            if ($value !== null && $value !== '') {
                $fields[$name] = $value;
            }
        }

        foreach ($this->extra as $name => $value) {
            if (isset($fields[$name])) {
                throw InvalidField::because(
                    'extra',
                    sprintf('"%s" is already built from the request itself', $name),
                    (string) $name,
                );
            }

            $fields[$name] = (string) $value;
        }

        return $fields;
    }
}
