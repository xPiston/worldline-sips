<?php

declare(strict_types=1);

namespace Piston\Sips\Bridge\Laravel;

use Illuminate\Http\Request;

/**
 * The three fields of a Sips response, read out of a Laravel request.
 *
 * It exists because `$request->all()` is the wrong call and the obvious one.
 * That method merges the query string into the body, and a return URL with a
 * query parameter is not just possible here - it is the normal shape, because
 * `normalReturnUrl` is where a shop puts its own order id. Sips posts `Data`,
 * `Seal`, `InterfaceVersion` and sometimes `Encode`; anything else in the
 * array is the shop's own and has no business near the seal.
 *
 * @see \Piston\Sips\Response\PaymentResponse::fromPost()
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
