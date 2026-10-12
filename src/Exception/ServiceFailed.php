<?php

declare(strict_types=1);

namespace Piston\Sips\Exception;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * An Office service could not be reached, or answered something that is not a
 * Sips response.
 *
 * Kept apart from a *refused* operation: a refusal is an answer and comes back
 * as a result object. This is the absence of one, and it is the only case
 * worth retrying.
 */
final class ServiceFailed extends RuntimeException implements SipsException
{
    public static function transport(ClientExceptionInterface $previous): self
    {
        return new self('The Sips Office service could not be reached: '.$previous->getMessage(), 0, $previous);
    }

    public static function status(int $status, string $body): self
    {
        return new self(sprintf('The Sips Office service answered HTTP %d: %s', $status, self::excerpt($body)));
    }

    public static function unreadableReply(string $body): self
    {
        return new self(sprintf('The Sips Office service answered something that is not JSON: %s', self::excerpt($body)));
    }

    private static function excerpt(string $body): string
    {
        $body = trim($body);

        return mb_strlen($body) > 300 ? mb_substr($body, 0, 300).'...' : $body;
    }
}
