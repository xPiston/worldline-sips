<?php

declare(strict_types=1);

namespace Piston\Sips\Response;

use Piston\Sips\Configuration\Credentials;
use Piston\Sips\Exception\SealMismatch;
use Piston\Sips\Security\SealVerifier;

/**
 * Turns an incoming Sips response into one you may act on - or refuses it.
 *
 * Two checks, and the first is the one nobody writes: **the merchant id**. A
 * response naming another shop was sealed with another key, and a shop that
 * runs several merchant ids through one endpoint - which is the normal shape
 * for a platform - would otherwise accept its neighbour's payments.
 *
 * Then the seal, computed over the Data element **exactly as it arrived**.
 */
final class ResponseVerifier
{
    public function __construct(
        private readonly Credentials $credentials,
        private readonly SealVerifier $seals = new SealVerifier,
    ) {}

    /**
     * @param  array<string, mixed>  $post
     *
     * @throws SealMismatch
     */
    public function verify(array $post): PaymentResponse
    {
        $response = PaymentResponse::fromPost($post);

        if ($response->data->transmitted() === '') {
            throw SealMismatch::missing('Data');
        }

        $seal = $response->seal;

        if ($seal === null) {
            throw SealMismatch::missing('Seal');
        }

        $merchantId = $response->merchantId();

        if ($merchantId !== null && $merchantId !== $this->credentials->merchantId) {
            throw SealMismatch::wrongMerchant($this->credentials->merchantId, $merchantId);
        }

        $algorithm = $this->seals->matching(
            $response->data->transmitted(),
            $seal,
            $this->credentials->key,
        );

        if ($algorithm === null) {
            throw SealMismatch::create();
        }

        return $response->withSealAlgorithm($algorithm);
    }

    /**
     * The same without the exception.
     *
     * @param  array<string, mixed>  $post
     */
    public function attempt(array $post): ?PaymentResponse
    {
        try {
            return $this->verify($post);
        } catch (SealMismatch) {
            return null;
        }
    }
}
