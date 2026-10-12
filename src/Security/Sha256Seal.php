<?php

declare(strict_types=1);

namespace Piston\Sips\Security;

use SensitiveParameter;

/**
 * SHA-256 over the Data element with the secret key concatenated to it.
 *
 *     SHA256( UTF-8(Data + secretKey) )
 *
 * Sips' default, and its own documentation says of it: "although this is the
 * default value, to date, this algorithm is no longer recommended". It is here
 * because it is what shops integrated years ago still use, and because a
 * response is sealed with the algorithm its request asked for - so a library
 * that only knew HMAC could not read the mail of half the merchants on the
 * platform.
 *
 * New integrations should use HmacSha256Seal. See its docblock for why the
 * shape of this one is the weaker of the two.
 */
final class Sha256Seal extends SealAlgorithm
{
    public function wireValue(): string
    {
        return 'SHA-256';
    }

    protected function hash(string $data, #[SensitiveParameter] SecretKey $key): string
    {
        return hash('sha256', $data.$key->value());
    }
}
