<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Support;

use RuntimeException;

/**
 * The seal examples printed in Sips' own documentation.
 *
 * They are the only test in this suite that is not this library's opinion:
 * Worldline publishes the Data string, the secret key and the seal it expects,
 * for both algorithms and in both directions. If these pass, the seal is
 * right; if they fail, nothing else matters.
 *
 * Source: Sips Paypage POST user guide (WLSIPS.317), "Seal calculation
 * validation" and the HMAC-SHA256 examples.
 */
final class Vectors
{
    public const KEY = 'secret123';

    /** A request, sealed with the SHA-256 concatenation. */
    public const REQUEST_DATA = 'automaticResponseURL=https://automatic-response-url.fr/'
        .'|normalReturnURL=https://normal-return-url/'
        .'|captureDay=0|captureMode=AUTHOR_CAPTURE|merchantId=011223344550000|amount=2500'
        .'|orderId=ORD101|currencyCode=978|transactionReference=TREFEXA2012|keyVersion=1'
        .'|transactionOrigin=SO_WEBAPPLI|returnContext=ReturnContext|orderChannel=INTERNET'
        .'|customerContact.email=customer@email.com';

    public const REQUEST_SEAL_SHA256 = 'ac2332b57a674aba5b28a03dae677fa2f4c1ae8a349ebbdd6772a098c7f29861';

    /** A response of 104 fields, sealed with HMAC-SHA256. */
    public const RESPONSE_SEAL_HMAC = 'c946655cce0059124b4ad3eb62c0922c51a0a7d8d28a3cf223e4c0da41bbc5b9';

    /** @codeCoverageIgnore */
    private function __construct() {}

    /** The documented response's Data element, kept verbatim in a file. */
    public static function responseData(): string
    {
        $path = __DIR__.'/documented-response.txt';
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('missing fixture: '.$path);
        }

        return trim($contents);
    }
}
