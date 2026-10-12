<?php

declare(strict_types=1);

namespace Piston\Sips\Exception;

use LogicException;

/**
 * The Office services need an HTTP client; Paypage does not.
 *
 * Rendering a sealed form and verifying a response installs no transport. The
 * server-to-server cash-management calls are the exception, and this is what
 * they say when nothing was wired.
 */
final class MissingHttpClient extends LogicException implements SipsException
{
    public static function create(): self
    {
        return new self(
            'The Sips Office services need a PSR-18 client. Pass one to Sips::__construct(), '
            .'or install php-http/discovery together with a client (symfony/http-client, guzzlehttp/guzzle) and it will be found.',
        );
    }
}
