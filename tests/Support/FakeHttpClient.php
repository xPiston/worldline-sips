<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers from a queue and keeps what it was asked.
 *
 * The Office services are reachable from a test suite - the test platform's
 * credentials are published and the live suite does exactly that - but not
 * from a unit test that must not depend on somebody else's uptime. So this
 * covers everything up to the socket: the URL, the method, the headers, the
 * JSON body and the seal inside it, plus the failures a network produces and
 * a live test cannot summon on demand.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    public static function replying(string $body, int $status = 200): self
    {
        $client = new self;
        $client->queue[] = new Response($status, ['Content-Type' => 'application/json'], $body);

        return $client;
    }

    public static function failing(ClientExceptionInterface $exception): self
    {
        $client = new self;
        $client->queue[] = $exception;

        return $client;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue);

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next ?? new Response(200, [], '{"responseCode":"00"}');
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[count($this->requests) - 1];
    }

    /**
     * The JSON body, decoded.
     *
     * @return array<string, mixed>
     */
    public function lastBodyFields(): array
    {
        $decoded = json_decode((string) $this->lastRequest()->getBody(), true);

        /** @var array<string, mixed> $decoded */
        return is_array($decoded) ? $decoded : [];
    }
}
