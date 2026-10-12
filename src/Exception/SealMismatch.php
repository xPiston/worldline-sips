<?php

declare(strict_types=1);

namespace Piston\Sips\Exception;

use RuntimeException;

/**
 * The seal on an incoming response is not the one we computed.
 *
 * Which means the message was altered, or it was not sent by Sips, or it was
 * sealed with a key other than the one configured - a key rotated on the Sips
 * Download extranet and not deployed everywhere does exactly this, and shows
 * up as answer code 34 on the way out.
 *
 * None of them is a payment.
 */
final class SealMismatch extends RuntimeException implements SipsException
{
    public static function create(): self
    {
        return new self('The seal of this response does not match the one computed from its Data element: the message is not trustworthy.');
    }

    public static function missing(string $field): self
    {
        return new self(sprintf('This response carries no %s field, so there is nothing to verify.', $field));
    }

    public static function wrongMerchant(string $expected, string $given): self
    {
        return new self(sprintf('This response is for merchant %s, not %s.', $given, $expected));
    }
}
