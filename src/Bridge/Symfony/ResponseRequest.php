<?php

declare(strict_types=1);

namespace Piston\Sips\Bridge\Symfony;

use Symfony\Component\HttpFoundation\Request;

/**
 * The fields of a Sips response, read out of a Symfony request.
 *
 * `$request->request->all()` - the form body, and only the form body. A query
 * parameter on the return URL is the normal shape here, since
 * `normalReturnUrl` is where a shop puts its own order id, and it has no
 * business near the seal.
 */
final class ResponseRequest
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /** @return array<string, mixed> */
    public static function fields(Request $request): array
    {
        return $request->request->all();
    }
}
