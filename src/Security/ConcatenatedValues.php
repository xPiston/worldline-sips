<?php

declare(strict_types=1);

namespace Piston\Sips\Security;

/**
 * The string an Office message is sealed over: its field *values*,
 * concatenated, in the alphabetical order of their *names*.
 *
 * A third shape, after Paypage's `name=value|name=value` and the plain
 * concatenation of a legacy connector. Worldline's own words: "Concatenation
 * of data field values in the alphabetical order of field names (in accordance
 * with ASCII character codes), without integrating the keyVersion and
 * sealAlgorithm fields."
 *
 * Two things in that sentence are easy to miss, and both were confirmed
 * against the live test server rather than taken on trust - a cancel request
 * that answers `25` (transaction not found, so the request was read) with the
 * right string, and `34` (suspected fraud) with every variation:
 *
 * - **Only the values.** No names, no separator. `978` + `CR_WS_2.17` +
 *   `201040040170001` + `1000` + `NOSUCHTRANS000001` run together. It makes
 *   the string ambiguous - two fields could always have produced it - which is
 *   why the field *names* must be exactly the documented ones: a typo moves a
 *   value to another position in the sort and the seal changes.
 * - **`keyVersion` and `sealAlgorithm` are sent but not sealed.** Including
 *   them is the single most likely mistake, and it is indistinguishable from a
 *   wrong key: answer code 34, every time.
 *
 * The seal itself is never part of what it seals.
 */
final class ConcatenatedValues
{
    /** Sent with the message, deliberately outside the seal. */
    private const EXCLUDED = ['keyVersion', 'sealAlgorithm', 'seal'];

    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @param  array<string, string|int|float|bool|null>  $fields
     */
    public static function of(array $fields): string
    {
        $sealed = array_filter(
            $fields,
            static fn (int|string $name): bool => ! in_array((string) $name, self::EXCLUDED, true),
            ARRAY_FILTER_USE_KEY,
        );

        uksort($sealed, static fn (int|string $a, int|string $b): int => strcmp((string) $a, (string) $b));

        $values = '';

        foreach ($sealed as $value) {
            // A boolean field would otherwise contribute "1" or "", and Sips
            // spells its booleans out.
            $values .= match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => '',
                default => (string) $value,
            };
        }

        return $values;
    }
}
