<?php

declare(strict_types=1);

namespace Piston\Sips\Security;

use Piston\Sips\Exception\InvalidField;

/**
 * The `Data` element: every field of a message, as one string.
 *
 * This class exists to make one mistake impossible. The seal covers the Data
 * string **exactly as it travels**, so a response must be verified against the
 * string that arrived - not against one rebuilt from its parsed fields. Sips
 * is explicit: *"It is important not to sort the content of the Data field to
 * calculate the Seal of the response. Keep the fields in the sequence you
 * received them."* A hundred-odd fields arrive in an order only the platform
 * knows; sorting them, or dropping one this library has never heard of, or
 * re-encoding a value, each turns every payment into a forgery.
 *
 * So `received()` keeps the raw string and parses it only for reading, and
 * `of()` builds the string once, from an ordered list, and that same string is
 * what gets sealed and sent. Nothing here can round-trip through an array and
 * come out different.
 */
final class DataElement
{
    public const SEPARATOR = '|';

    /** The literal Sips sends for a field that has no value. */
    private const ABSENT = 'null';

    private function __construct(
        private readonly string $transmitted,
        private readonly Encoding $encoding,
    ) {}

    /**
     * An outgoing message, in the order given.
     *
     * The order is the merchant's to choose for a request - Sips reads the
     * names, not the positions - but it must be the same order in the string
     * that is sealed and the string that is posted, which is why there is only
     * one string.
     *
     * @param  array<string, string|int>  $fields
     */
    public static function of(array $fields, Encoding $encoding = Encoding::Plain): self
    {
        $pairs = [];

        foreach ($fields as $name => $value) {
            $value = (string) $value;

            if (str_contains($value, self::SEPARATOR) && $encoding === Encoding::Plain) {
                throw InvalidField::separatorInValue((string) $name, self::SEPARATOR);
            }

            if (str_contains((string) $name, self::SEPARATOR) || str_contains((string) $name, '=')) {
                throw InvalidField::because('Data', 'a field name cannot contain "|" or "="', (string) $name);
            }

            $pairs[] = $name.'='.$value;
        }

        return new self($encoding->encode(implode(self::SEPARATOR, $pairs)), $encoding);
    }

    /** An incoming message: the string as it arrived, untouched. */
    public static function received(string $transmitted, Encoding $encoding = Encoding::Plain): self
    {
        return new self($transmitted, $encoding);
    }

    /** What goes in the `Data` element, and what the seal is computed over. */
    public function transmitted(): string
    {
        return $this->transmitted;
    }

    public function encoding(): Encoding
    {
        return $this->encoding;
    }

    /** The `name=value|name=value` form, decoded if it was encoded. */
    public function plain(): ?string
    {
        return $this->encoding->decode($this->transmitted);
    }

    /**
     * The fields, parsed, in the order they appear.
     *
     * Split on the *first* `=` of each pair, because values contain them: a
     * `normalReturnUrl` with a query string would otherwise lose everything
     * after the first parameter.
     *
     * @return array<string, string>
     */
    public function fields(): array
    {
        $plain = $this->plain();

        if ($plain === null || $plain === '') {
            return [];
        }

        $fields = [];

        foreach (explode(self::SEPARATOR, $plain) as $pair) {
            if (! str_contains($pair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);
            $fields[trim($name)] = $value;
        }

        return $fields;
    }

    /**
     * One field, or null.
     *
     * The literal string `null` is treated as absent, because that is what
     * Sips sends for an empty field - a response carries `dccStatus=null` and
     * `customerMobilePhone=null` by the dozen. Reading those as the four
     * characters they are is how a shop ends up with a customer whose phone
     * number is "null".
     */
    public function field(string $name): ?string
    {
        $value = $this->fields()[$name] ?? null;

        return $value === null || $value === '' || $value === self::ABSENT ? null : $value;
    }

    public function has(string $name): bool
    {
        return $this->field($name) !== null;
    }
}
