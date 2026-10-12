<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Support;

use Piston\Sips\Configuration\Credentials;
use Piston\Sips\Security\DataElement;
use Piston\Sips\Security\Encoding;
use Piston\Sips\Security\HmacSha256Seal;
use Piston\Sips\Security\SealAlgorithm;

/**
 * Sips' side of the exchange: seals a response the way the platform does.
 *
 * Which is the only honest way to test a verifier. "A wrong seal is refused"
 * proves nothing on its own - an implementation that refuses everything passes
 * it. The pair that means something is: a response sealed exactly as the
 * platform seals it is accepted, and the same response with one byte moved is
 * not.
 */
final class Platform
{
    public const MERCHANT_ID = '002001000000002';

    public const SECRET_KEY = '002001000000002_KEY1';

    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function credentials(): Credentials
    {
        return Credentials::of(self::MERCHANT_ID, self::SECRET_KEY);
    }

    /**
     * A response POST body, sealed over its Data element exactly as sent.
     *
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    public static function sealedResponse(
        array $fields,
        ?SealAlgorithm $algorithm = null,
        ?Credentials $credentials = null,
        Encoding $encoding = Encoding::Plain,
    ): array {
        $algorithm ??= new HmacSha256Seal;
        $credentials ??= self::credentials();

        if (! isset($fields['merchantId'])) {
            $fields = ['merchantId' => $credentials->merchantId, ...$fields];
        }

        $data = DataElement::of($fields, $encoding);

        $post = [
            'Data' => $data->transmitted(),
            'InterfaceVersion' => 'HP_2.57',
            'Seal' => $algorithm->compute($data->transmitted(), $credentials->key)->value(),
        ];

        if ($encoding !== Encoding::Plain) {
            $post['Encode'] = $encoding->value;
        }

        return $post;
    }
}
