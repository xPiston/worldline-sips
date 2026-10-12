<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Merchant id, secret key and key version
    |---------------------------------------------------------------------------
    |
    | The three values Worldline issues per shop, and they differ on all three
    | platforms - so they belong in the environment file and not in here.
    |
    | The key version is not decoration: Sips uses it to know which of your
    | keys to verify the seal with. Rotating a key on the Sips Download
    | extranet without bumping this is the documented path to answer code 34,
    | "suspected fraud", on every payment.
    |
    */

    'merchant_id' => env('SIPS_MERCHANT_ID'),

    'secret_key' => env('SIPS_SECRET_KEY'),

    // Read as a string and cast by the provider: env() returns mixed, and a
    // config file is not the place to argue with a static analyser about it.
    'key_version' => env('SIPS_KEY_VERSION', 1),

    /*
    |---------------------------------------------------------------------------
    | Platform
    |---------------------------------------------------------------------------
    |
    | "simulation", "test" or "production".
    |
    | `simulation` is open to everyone and needs no contract: its merchant id
    | and key are published, and leaving the three values above empty falls
    | back to them. `test` is your own pre-production shop. `production` takes
    | money.
    |
    | Deliberately not derived from the application's own environment: a
    | staging server pointed at production takes real payments, and a
    | production server pointed at the simulator takes none while reporting
    | success.
    |
    */

    'platform' => env('SIPS_PLATFORM', 'simulation'),

    /*
    |---------------------------------------------------------------------------
    | Hosts
    |---------------------------------------------------------------------------
    |
    | Several banks front Sips on a domain of their own, and the payment page
    | and the Office services are two different machines. Set either to
    | override it; leave both empty for Worldline's own hostnames.
    |
    */

    'paypage_host' => env('SIPS_PAYPAGE_HOST'),

    'office_host' => env('SIPS_OFFICE_HOST'),

    /*
    |---------------------------------------------------------------------------
    | Seal algorithm
    |---------------------------------------------------------------------------
    |
    | "hmac-sha-256" or "sha256". HMAC is what Worldline recommends and what
    | this package defaults to; plain SHA-256 is the platform's own default and
    | its documentation says of it, "to date, this algorithm is no longer
    | recommended".
    |
    | Whichever you send, responses to orders created the other way keep
    | arriving sealed the other way - so both are accepted on the way in.
    |
    */

    'seal_algorithm' => env('SIPS_SEAL_ALGORITHM', 'hmac-sha-256'),

];
