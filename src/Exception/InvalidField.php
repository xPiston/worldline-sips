<?php

declare(strict_types=1);

namespace Piston\Sips\Exception;

use InvalidArgumentException;

/**
 * A value does not match what Sips accepts.
 *
 * Thrown before anything is sent, and it names the field and the rule -
 * because the alternative is the platform's own answer, which on the
 * production server is deliberately vague: "Error when processing the payment
 * request. Please contact your dealer". The simulation server is chattier, and
 * that asymmetry is exactly why a library should catch what it can first.
 */
final class InvalidField extends InvalidArgumentException implements SipsException
{
    public static function because(string $field, string $rule, string $given): self
    {
        return new self(sprintf('%s: %s, got "%s".', $field, $rule, $given));
    }

    public static function secretKey(string $rule): self
    {
        // Never with the value: this one ends up in logs.
        return new self(sprintf('Secret key: %s.', $rule));
    }

    public static function separatorInValue(string $field, string $separator): self
    {
        return new self(sprintf(
            'The value of %s contains the "%s" character, which separates the fields of the Data element. '
            .'Sips would read one field as two and the seal would not match.',
            $field,
            $separator,
        ));
    }
}
