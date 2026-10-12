<?php

declare(strict_types=1);

namespace Piston\Sips\Security;

/**
 * The optional `Encode` element: how the Data element is wrapped for
 * transport.
 *
 * It exists because Data is one long `name=value|name=value` string, and a
 * value containing a `|` or characters an intermediary might mangle would
 * break it. Encoding the whole thing in base64 makes it opaque.
 *
 * The part that decides the design: when an encoding is used, **the seal
 * covers the encoded form** - what travels, not what it means. Sips says it
 * twice, once per direction, and it is the reason DataElement keeps the
 * transmitted string rather than rebuilding one.
 */
enum Encoding: string
{
    case Plain = '';
    case Base64 = 'base64';
    case Base64Url = 'base64url';

    public static function fromWireValue(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }

    public function encode(string $plain): string
    {
        return match ($this) {
            self::Plain => $plain,
            self::Base64 => base64_encode($plain),
            self::Base64Url => rtrim(strtr(base64_encode($plain), '+/', '-_'), '='),
        };
    }

    public function decode(string $transmitted): ?string
    {
        if ($this === self::Plain) {
            return $transmitted;
        }

        $raw = $this === self::Base64Url
            ? strtr($transmitted, '-_', '+/')
            : $transmitted;

        $decoded = base64_decode($raw, true);

        return $decoded === false ? null : $decoded;
    }
}
