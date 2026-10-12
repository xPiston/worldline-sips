<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Piston\Sips\Security\DataElement;
use Piston\Sips\Security\HmacSha256Seal;
use Piston\Sips\Security\SealVerifier;
use Piston\Sips\Security\SecretKey;
use Piston\Sips\Security\Sha256Seal;
use Piston\Sips\Tests\Support\Vectors;

/**
 * The seal, against the numbers Worldline publishes.
 */
final class DocumentedVectorsTest extends TestCase
{
    public function test_the_documented_request_seal_in_sha256(): void
    {
        $seal = (new Sha256Seal)->compute(Vectors::REQUEST_DATA, SecretKey::of(Vectors::KEY));

        $this->assertSame(Vectors::REQUEST_SEAL_SHA256, $seal->value());
    }

    public function test_the_documented_response_seal_in_hmac_sha256(): void
    {
        $seal = (new HmacSha256Seal)->compute(Vectors::responseData(), SecretKey::of(Vectors::KEY));

        $this->assertSame(Vectors::RESPONSE_SEAL_HMAC, $seal->value());
    }

    /**
     * The documented response carries 104 fields in an order no sort would
     * reproduce - which is the whole reason the Data element is kept as a
     * string rather than rebuilt from an array.
     */
    public function test_the_documented_response_is_not_in_alphabetical_order(): void
    {
        $data = DataElement::received(Vectors::responseData());
        $names = array_keys($data->fields());

        $this->assertCount(104, $names);

        $sorted = $names;
        sort($sorted, SORT_STRING);

        $this->assertNotSame($sorted, $names, 'if this ever sorts, the fixture is not a real response');
        $this->assertSame('captureDay', $names[0]);
        $this->assertSame('abortedProcessingLocation', $names[103]);
    }

    public function test_rebuilding_the_documented_response_would_break_its_seal(): void
    {
        $received = DataElement::received(Vectors::responseData());
        $key = SecretKey::of(Vectors::KEY);
        $algorithm = new HmacSha256Seal;

        $this->assertTrue($algorithm->matches($received->transmitted(), $algorithm->compute(Vectors::responseData(), $key), $key));

        // The same fields, sorted, as a careless implementation would hash.
        $sorted = $received->fields();
        ksort($sorted, SORT_STRING);
        $rebuilt = DataElement::of($sorted);

        $this->assertFalse(
            $algorithm->matches($rebuilt->transmitted(), $algorithm->compute(Vectors::responseData(), $key), $key),
            'sorting the response is the mistake this library is shaped to prevent',
        );
    }

    public function test_the_verifier_finds_the_algorithm_of_each_documented_vector(): void
    {
        $key = SecretKey::of(Vectors::KEY);
        $verifier = new SealVerifier;

        $this->assertInstanceOf(
            Sha256Seal::class,
            $verifier->matching(Vectors::REQUEST_DATA, (new Sha256Seal)->compute(Vectors::REQUEST_DATA, $key), $key),
        );

        $this->assertInstanceOf(
            HmacSha256Seal::class,
            $verifier->matching(Vectors::responseData(), (new HmacSha256Seal)->compute(Vectors::responseData(), $key), $key),
        );
    }

    /**
     * The two algorithms over the same data give different seals - stated here
     * because a shop that sets `sealAlgorithm` and a shop that forgets to are
     * both sure they are using HMAC.
     */
    public function test_the_two_algorithms_do_not_agree(): void
    {
        $key = SecretKey::of(Vectors::KEY);

        $this->assertNotSame(
            (new Sha256Seal)->compute(Vectors::REQUEST_DATA, $key)->value(),
            (new HmacSha256Seal)->compute(Vectors::REQUEST_DATA, $key)->value(),
        );
    }
}
