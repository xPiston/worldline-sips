<?php

declare(strict_types=1);

namespace Piston\Sips;

use Piston\Sips\Configuration\Credentials;
use Piston\Sips\Configuration\Environment;
use Piston\Sips\Configuration\Platform;
use Piston\Sips\Exception\MissingHttpClient;
use Piston\Sips\Office\OfficeClient;
use Piston\Sips\Office\OfficeRequest;
use Piston\Sips\Office\OfficeResult;
use Piston\Sips\Paypage\PaymentForm;
use Piston\Sips\Paypage\PaymentRequest;
use Piston\Sips\Response\PaymentResponse;
use Piston\Sips\Response\ResponseVerifier;
use Piston\Sips\Security\DataElement;
use Piston\Sips\Security\Encoding;
use Piston\Sips\Security\HmacSha256Seal;
use Piston\Sips\Security\SealAlgorithm;
use Piston\Sips\Security\SealVerifier;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;

/**
 * Worldline Sips, in one object.
 *
 * Two verbs for now - seal a request, verify a response - and the Office
 * operations to come. Nothing holds state between calls, so one instance per
 * shop, built once, is the intended shape.
 *
 * The seal algorithm is a constructor choice and defaults to HMAC-SHA256: it
 * is not the platform's default, it is the one the platform recommends, and a
 * library that silently used the weaker one because the protocol does would be
 * doing its reader a disservice.
 */
final class Sips
{
    /** The interface version this library speaks, as documented for Paypage. */
    public const INTERFACE_VERSION = 'HP_2.57';

    private readonly SealVerifier $seals;

    public function __construct(
        public readonly Credentials $credentials,
        private readonly Environment|Platform $platform = Environment::Simulation,
        private readonly SealAlgorithm $algorithm = new HmacSha256Seal,
        private readonly Encoding $encoding = Encoding::Plain,
        ?SealVerifier $seals = null,
        private readonly ?ClientInterface $http = null,
        private readonly ?RequestFactoryInterface $requests = null,
        private readonly ?StreamFactoryInterface $streams = null,
    ) {
        $this->seals = $seals ?? new SealVerifier;
    }

    /** The open simulation platform, with the credentials Worldline publishes. */
    public static function simulation(): self
    {
        return new self(Credentials::simulation(), Environment::Simulation);
    }

    public static function production(
        string $merchantId,
        #[SensitiveParameter] string $secretKey,
        int $keyVersion = 1,
    ): self {
        return new self(Credentials::of($merchantId, $secretKey, $keyVersion), Environment::Production);
    }

    /**
     * Seal a payment request into a form.
     *
     * The Data element is built once, in one order, and that same string is
     * what gets sealed and what gets posted. See DataElement.
     */
    public function form(PaymentRequest $request): PaymentForm
    {
        $data = DataElement::of($request->fields($this->credentials, $this->algorithm), $this->encoding);
        $seal = $this->algorithm->compute($data->transmitted(), $this->credentials->key);

        $fields = [
            'Data' => $data->transmitted(),
            'InterfaceVersion' => self::INTERFACE_VERSION,
            'Seal' => $seal->value(),
        ];

        if ($this->encoding !== Encoding::Plain) {
            $fields['Encode'] = $this->encoding->value;
        }

        return new PaymentForm($fields, $this->platform->paypageUrl());
    }

    public function verifier(): ResponseVerifier
    {
        return new ResponseVerifier($this->credentials, $this->seals);
    }

    /**
     * @param  array<string, mixed>  $post
     *
     * @throws Exception\SealMismatch
     */
    public function response(array $post): PaymentResponse
    {
        return $this->verifier()->verify($post);
    }

    /** @param array<string, mixed> $post */
    public function attemptResponse(array $post): ?PaymentResponse
    {
        return $this->verifier()->attempt($post);
    }

    /** Capture a transaction that was only authorised. */
    public function validate(OfficeRequest $request): OfficeResult
    {
        return $this->office()->send($request);
    }

    /** Give up an authorisation, or a capture not yet settled. */
    public function cancel(OfficeRequest $request): OfficeResult
    {
        return $this->office()->send($request);
    }

    /** Send settled money back. */
    public function refund(OfficeRequest $request): OfficeResult
    {
        return $this->office()->send($request);
    }

    /**
     * The cash-management client.
     *
     * The three methods above are the same call with different requests -
     * they exist so that a reader of the calling code can see which of the
     * three a line does without following the request object.
     */
    public function office(): OfficeClient
    {
        $http = $this->http ?? Discovery::client();
        $requests = $this->requests ?? Discovery::requestFactory();
        $streams = $this->streams ?? Discovery::streamFactory();

        if ($http === null || $requests === null || $streams === null) {
            throw MissingHttpClient::create();
        }

        return new OfficeClient(
            $this->credentials,
            $this->platform,
            $http,
            $requests,
            $streams,
            $this->algorithm,
            $this->seals,
        );
    }

    public function platform(): Environment|Platform
    {
        return $this->platform;
    }

    public function algorithm(): SealAlgorithm
    {
        return $this->algorithm;
    }
}
