<?php

declare(strict_types=1);

namespace Piston\Sips\Exception;

use Throwable;

/**
 * Every exception this library throws implements this interface, so an
 * application can catch everything that comes from Sips without also
 * swallowing its own SPL exceptions - which the classes below deliberately
 * extend.
 */
interface SipsException extends Throwable {}
