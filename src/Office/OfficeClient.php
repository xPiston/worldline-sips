<?php

declare(strict_types=1);

namespace Piston\Sips\Office;

use JsonException;
use Piston\Sips\Configuration\Credentials;
use Piston\Sips\Configuration\Environment;
use Piston\Sips\Configuration\Platform;
use Piston\Sips\Exception\ServiceFailed;
use Piston\Sips\Security\ConcatenatedValues;
use Piston\Sips\Security\Seal;
use Piston\Sips\Security\SealAlgorithm;
use Piston\Sips\Security\SealVerifier;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * The cash-management services, over PSR-18 and JSON.
 *
 * Two things here are not decoration.
 *
 * **The response is verified.** Unlike some gateways, Sips seals what it
 * answers - with the same rule, over the response's own fields - and this
 * checks it. A refund confirmation that cannot be verified is not a refund
 * confirmation, and `OfficeResult::isDone()` is false whatever the response
 * code says.
 *
 * **A transport failure is not a refusal.** It is the one case worth
 * retrying, and it is a different exception from a refused operation, because
 * a refund that timed out may well have happened: the only safe move is to ask
 * again by reference, never to send a second one blind.
 */
final class OfficeClient
{
    public function __construct(
        private readonly Credentials $credentials,
        private readonly Environment|Platform $platform,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly SealAlgorithm $algorithm,
        private readonly SealVerifier $seals = new SealVerifier,
    ) {}

    public function send(OfficeRequest $request): OfficeResult
    {
        $fields = $request->fields($this->credentials, $this->algorithm);
        $fields['seal'] = $this->algorithm
            ->compute(ConcatenatedValues::of($fields), $this->credentials->key)
            ->value();

        $answer = $this->post($this->platform->officeUrl($request->operation->path()), $fields);

        return OfficeResult::from($answer, $this->sealIsValid($answer));
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    private function post(string $url, array $fields): array
    {
        try {
            $body = json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw ServiceFailed::unreadableReply('the request could not be encoded: '.$e->getMessage());
        }

        $http = $this->requests->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streams->createStream($body));

        try {
            $response = $this->http->sendRequest($http);
        } catch (ClientExceptionInterface $e) {
            throw ServiceFailed::transport($e);
        }

        $contents = (string) $response->getBody();

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw ServiceFailed::status($response->getStatusCode(), $contents);
        }

        try {
            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ServiceFailed::unreadableReply($contents);
        }

        if (! is_array($decoded)) {
            throw ServiceFailed::unreadableReply($contents);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Is the answer sealed by the merchant's key?
     *
     * Same rule as the request, over the fields that came back. Confirmed
     * against the live platform: a reply of `{"responseCode":"12"}` is sealed
     * as the HMAC of the string `12`.
     *
     * @param  array<string, mixed>  $answer
     */
    private function sealIsValid(array $answer): bool
    {
        $received = $answer['seal'] ?? null;

        if (! is_string($received)) {
            return false;
        }

        try {
            $seal = Seal::fromHex($received);
        } catch (Throwable) {
            return false;
        }

        /** @var array<string, string|int|float|bool|null> $sealed */
        $sealed = array_filter($answer, static fn (mixed $value): bool => $value === null || is_scalar($value));

        return $this->seals->matching(
            ConcatenatedValues::of($sealed),
            $seal,
            $this->credentials->key,
        ) !== null;
    }
}
