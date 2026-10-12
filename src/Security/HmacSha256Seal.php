<?php

declare(strict_types=1);

namespace Piston\Sips\Security;

use SensitiveParameter;

/**
 * HMAC-SHA256 over the Data element, keyed with the secret key.
 *
 *     HMAC-SHA256( UTF-8(Data), UTF-8(secretKey) )
 *
 * The one to use. It is not the default, so a request that wants it has to
 * carry `sealAlgorithm=HMAC-SHA-256` - and the response then comes back sealed
 * the same way.
 *
 * Why it matters beyond ticking a box: the alternative concatenates the key to
 * the end of the data and hashes the lot, which is the construction HMAC
 * exists to replace. A bare `H(data || key)` is open to a length-extension
 * attack on Merkle-Damgård hashes like SHA-256 - an attacker who has one valid
 * (data, seal) pair can append fields and produce a valid seal for the longer
 * message without knowing the key. Sips fields are separated by `|`, so the
 * appended part would have to survive the platform's own parsing to do damage,
 * and the request is checked against what the merchant sent. It is not a
 * demonstrated hole in Sips; it is a reason not to choose that shape when
 * the better one is one field away.
 */
final class HmacSha256Seal extends SealAlgorithm
{
    public function wireValue(): string
    {
        return 'HMAC-SHA-256';
    }

    protected function hash(string $data, #[SensitiveParameter] SecretKey $key): string
    {
        return hash_hmac('sha256', $data, $key->value());
    }
}
