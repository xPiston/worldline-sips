<div align="center">

# 🏦 Worldline Sips for PHP

**One connector, five bank brands.**

Sealed Paypage requests, verified responses, capture, cancel and refund —
for Laravel, Symfony, or no framework at all.

[![PHP](https://img.shields.io/badge/PHP-8.2%20%E2%86%92%208.5-777BB4?logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![Symfony](https://img.shields.io/badge/Symfony-6.4%20%7C%207-000000?logo=symfony&logoColor=white)](https://symfony.com)
[![Tests](https://img.shields.io/badge/tests-238%20%2B%2010%20live-22C55E)](#-tests)
[![PHPStan](https://img.shields.io/badge/PHPStan-max-2563EB)](#-tests)
[![License](https://img.shields.io/badge/license-MIT-blue)](#license)

</div>

---

[Worldline Sips](https://docs.sips.worldline-solutions.com) is the payment
platform behind a great many French bank brands: **Sogenactif** (Société
Générale), **Mercanet** (BNP Paribas), **Scellius** (La Banque Postale),
**Sherlock's** (LCL), **ElysNet** (HSBC). One protocol, one contract shape, one
set of seal rules — and a different name on every merchant agreement.

This package implements that protocol: the sealed form that sends a customer to
the payment page, the two responses that come back, and the three
cash-management calls that move the money afterwards. Nothing else. It has no
opinion about your orders and touches the network only when you ask it to
capture, cancel or refund.

> **Everything below was checked against Worldline's own servers.** The
> simulation platform's credentials are published in its quick start guide, and
> the Office test platform's in the Office guide — so this package has a live
> test suite that needs no contract, and every claim in this README that could
> be wrong was measured rather than read. The places where the documentation
> contradicts itself are called out, with what the server actually does.

## ✨ What's inside

- 🔒 **Both seal algorithms** — `HMAC-SHA256` over the Data element, and the
  plain `SHA256(Data + key)` that is still the platform's default and that its
  own documentation advises against
- 📏 **The order rule, enforced by design** — a response is verified against
  the string that arrived, never one rebuilt from its fields
- 🧾 **Paypage requests** as hidden inputs or a whole `<form>`, escaped after
  sealing and never before
- 📬 **Both response channels** — the browser's return and the
  server-to-server notification, same fields, same seal, different meaning
- 💳 **Capture, cancel and refund**, with the field set the platform actually
  accepts and a response seal that is *checked*
- 🧮 **Money as integers and a numeric currency code** — `amount=2500`,
  `currencyCode=978`, and the exponent that says which one means what
- 🏷️ **`transactionReference` optional**, because a shop can be configured to
  let Sips generate it — and then sending one is an error
- 🧩 **Laravel and Symfony bridges**, a hundred lines each, both optional
- ✅ **238 tests** with no network, plus **10 live tests** against Worldline

## 🚀 Quick start

```sh
composer require xpiston/worldline-sips
```

No contract yet? The simulation platform is open, and its credentials are
published:

```php
use Piston\Sips\Field\Amount;
use Piston\Sips\Paypage\PaymentRequest;
use Piston\Sips\Sips;

$sips = Sips::simulation();          // merchantId 002001000000002, published key

$form = $sips->form(new PaymentRequest(
    amount: Amount::fromMinor(2500, 'EUR'),   // 25,00 €, in cents
    normalReturnUrl: 'https://shop.test/retour',
    automaticResponseUrl: 'https://shop.test/notification',
    orderId: 'ORD101',
));

echo $form->toHtml('Payer 25,00 €');
```

That form posts three fields — `Data`, `InterfaceVersion`, `Seal` — and the
simulator answers with a real payment page. Its test cards are deterministic:
the result is decided by the last two digits of the card number, and the 3-D
Secure step is simulated so you choose the outcome.

For production, the three values Worldline issued:

```php
$sips = Sips::production('011223344550000', $secretKey, keyVersion: 1);
```

### Reading the response

Sips posts the result twice: to `normalReturnUrl`, which the customer's
browser carries, and to `automaticResponseUrl`, server to server. **Same
fields, same seal.** The documentation is blunt about the difference and so is
this README: a customer can close the tab, so business logic belongs on the
second one and the first is for showing them a page.

```php
use Piston\Sips\Response\ResponseVerifier;

$response = $sips->attemptResponse($_POST);   // null if the seal does not match

if ($response?->isAccepted() === true
    && $response->matches($order->reference, $order->total, $sips->credentials->merchantId)) {
    $order->markPaid($response->authorisationId());
}
```

`matches()` is three comparisons — reference, amount, merchant id — and it is
not optional thinking. A valid seal proves the message is authentic and
unaltered. It does not prove it is about the order you are holding.

### Capturing, cancelling, refunding

```php
use Piston\Sips\Office\OfficeRequest;

// Taken in VALIDATION mode and now shipping: capture it.
$result = $sips->validate(OfficeRequest::validate($reference, $order->total));

// Out of stock before settlement: cancel, which costs nothing.
$result = $sips->cancel(OfficeRequest::cancel($reference, $order->total));

// Settled and coming back: refund, which is a new movement with its own fees.
$result = $sips->refund(OfficeRequest::refund($reference, Amount::fromMinor(1000, 'EUR')));

$result->isDone();                 // carried out, and the answer was sealed by our key
$result->isTransactionUnknown();   // 25: no such reference
$result->isStateInvalid();         // 24: it exists, but not in a state that allows this
$result->isWorthRetrying();        // 90, 97, 99: the platform, not the request
$result->newAmount();              // what the platform now says is left
```

These three call Worldline over HTTPS, so they need a **PSR-18 client** — pass
one, or let [`php-http/discovery`](https://github.com/php-http/discovery) find
the one your application already has:

```sh
composer require php-http/discovery symfony/http-client   # or guzzlehttp/guzzle
```

Rendering a form and verifying a response need no HTTP client at all, which is
why the constructor does not ask for one.

## 🔐 The seal, in one page

Every message in both directions carries one, and **Sips has three different
rules for building the string it is computed over**. Not two algorithms in one
shape — three shapes.

**Paypage** concatenates `name=value` pairs with a `|`:

```
merchantId=002001000000002|keyVersion=1|amount=2500|currencyCode=978|…
```

**Office** concatenates the *values only*, sorted by field name, and leaves
out `keyVersion` and `sealAlgorithm`:

```
978CR_WS_2.172010400401700011000NOSUCHTRANS000001
```

**And on top of that, two algorithms.** `HMAC-SHA256(Data, key)`, or
`SHA256(Data + key)` — a plain hash with the key glued on the end, which is the
platform's default and which its own documentation calls "no longer
recommended". Getting the better one takes one field: `sealAlgorithm`.

### The rule that decides the design

> *"It is important not to sort the content of the Data field to calculate the
> Seal of the response. Keep the fields in the sequence you received them."*

A Sips response has around a hundred fields in an order only the platform
knows — the documented example has 104, starting at `captureDay` and ending at
`abortedProcessingLocation`. Sort them, drop one you do not recognise, or
re-encode a value, and every payment reads as a forgery.

So this package never rebuilds a response. `DataElement::received()` keeps the
string that arrived and parses it only for reading; the seal is computed over
`transmitted()`. The same class builds an outgoing message once, and that one
string is both what is sealed and what is posted. There is no path through an
array and back.

### What was measured

Both documented vectors reproduce exactly — `ac2332b5…` for a SHA-256 request,
`c946655c…` for an HMAC response of 104 fields. Then, against the live Office
test platform, one cancel request sent six ways:

| the seal was computed… | the platform answered |
| :-- | :-- |
| over the values, sorted by name, without `keyVersion` and `sealAlgorithm` | **25** — transaction not found: the request was *read* |
| over `name=value\|…` instead, the Paypage way | 34 |
| over the values **including `keyVersion`** | 34 |
| with the right shape and the wrong key | 34 |
| as `SHA256(data+key)` while declaring HMAC | 34 |
| as sixty-four `f`s | 34 |

Five negative controls and one positive, which is a stronger statement than any
documentation. The response seal was confirmed the same way: a reply of
`{"responseCode":"12"}` is sealed as the HMAC of the string `12`.

## 🧰 Laravel

The provider is discovered automatically.

```dotenv
SIPS_MERCHANT_ID=011223344550000
SIPS_SECRET_KEY=…
SIPS_KEY_VERSION=1
SIPS_PLATFORM=test        # simulation | test | production
```

Leave the credentials empty and it falls back to the published simulation shop,
so the package boots five minutes after `composer require`. On `production` it
refuses to boot without them, which is the one place where an exception is
kinder than a default.

```php
use Illuminate\Http\Request;
use Piston\Sips\Bridge\Laravel\ResponseRequest;
use Piston\Sips\Sips;

final class SipsNotificationController
{
    public function __construct(private readonly Sips $sips)
    {
    }

    public function __invoke(Request $request)
    {
        $response = $this->sips->attemptResponse(ResponseRequest::fields($request));

        if ($response?->isAccepted() === true) {
            ProcessPayment::dispatch($response->fields());
        }

        return response('', 200);
    }
}
```

```php
// routes/web.php
Route::post('/sips/notification', SipsNotificationController::class);

// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: ['sips/notification', 'sips/retour']);
})
```

`ResponseRequest::fields()` is not decoration. `$request->all()` merges the
query string into the body — and a return URL with a query parameter is the
*normal* shape here, because `normalReturnUrl` is where a shop puts its own
order id. One `?order=42` and the seal is computed over a field Sips never
sent.

`php artisan vendor:publish --tag=sips-config` writes the config file, with the
reasoning for each option in it.

## 🧰 Symfony

```php
// config/bundles.php
Piston\Sips\Bridge\Symfony\SipsBundle::class => ['all' => true],
```

```yaml
# config/packages/sips.yaml
sips:
    merchant_id: '%env(SIPS_MERCHANT_ID)%'
    secret_key: '%env(SIPS_SECRET_KEY)%'
    platform: '%env(SIPS_PLATFORM)%'
```

`Sips` and `ResponseVerifier` are then autowired, and the PSR-18 client comes
from the container as soon as `symfony/http-client` is installed. The
notification route has to be reachable without a session and without
authentication — if a firewall covers the path, give it `security: false`.

## 🧱 How it's built

```
src/
  Sips.php                  the verbs: form, response, validate, cancel, refund
  Discovery.php             finds a PSR-18 client, if the application has one
  Configuration/
    Credentials.php         merchant id + secret key + its version
    Environment.php         simulation, test, production - and their URLs
    Platform.php            the same, on a bank's own hostnames
  Security/
    SecretKey.php           the key and its version, kept out of dumps
    Seal.php                64 hex characters, compared in constant time
    SealAlgorithm.php       how a string becomes a seal
    HmacSha256Seal.php        the one to use
    Sha256Seal.php            the platform's default, and why it is weaker
    DataElement.php         the Data string - built once, never rebuilt
    ConcatenatedValues.php  the Office string: values, sorted by name
    Encoding.php            base64 / base64url, and what the seal then covers
    SealVerifier.php        tries each algorithm, says which one matched
  Field/                    Amount, Currency (numeric code), TransactionReference
  Paypage/
    PaymentRequest.php      one order, as fields, in a fixed order
    PaymentForm.php         the three fields, as inputs or HTML
    CaptureMode.php         when the money actually moves
    OrderChannel.php        and who carries the fraud risk
  Response/
    PaymentResponse.php     a response, read - and nothing in it throws
    ResponseVerifier.php    merchant id, then seal, then you may act on it
    ResponseCode.php        00, and the refusals worth telling apart
  Office/
    OfficeClient.php        the cash-management calls, over PSR-18
    OfficeRequest.php       validate, cancel, refund
    OfficeResult.php        what the platform answered, seal included
  Bridge/Laravel/           a service provider, a config file, one helper
  Bridge/Symfony/           a bundle, a configuration tree, one helper
```

### Decisions visible in the code

- **A response is verified against the string that arrived.** The single most
  important line in the package, and the reason `DataElement` exists.
- **Nothing in `PaymentResponse` throws.** The server-to-server channel is a
  POST Sips expects to succeed; an unfamiliar brand, an unreadable date or a
  response code added last quarter each yield `null` from their own accessor
  and change nothing else.
- **An empty `responseCode` is not a payment.** Said out loud because the first
  draft of this package padded it to two characters, `00` is an acceptance, and
  a response with no code at all read as accepted. There is a test named after
  it.
- **An unsealed `00` from an Office call is not done.** `isDone()` wants both
  an accepted code *and* a verified seal: Sips seals its answers, so an answer
  that cannot be verified came from something else.
- **Money is an integer and a currency.** `2500` is twenty-five euros and two
  thousand five hundred yen; the exponent is what says which. A currency whose
  numeric code this library does not know is refused by name rather than
  guessed at two decimals.
- **`transactionReference` is optional.** A shop can be set up to let Sips
  generate it, and then sending one answers `code=12 Merchant provided
  transaction key, but is configured for auto generated transaction key` -
  which is how this was found, on the simulator, in the first five minutes.
- **The field set of an Office call is exactly what the platform accepts.**
  `comment` is refused. `operationOriginalReference` is refused. Both were
  invented here first and both came back as `12` with `errorFieldName` naming
  them. The platform is a better reviewer than a documentation.
- **`CR_WS_` for cash management, not `IR_WS_`.** Sending a card-order
  interface version to a refund answers `12 errorFieldName: interfaceVersion`.
- **The key travels with its version.** Rotating a key on the Sips Download
  extranet without bumping `keyVersion` is the documented road to answer code
  34 on every payment, so the two are one object.
- **A key with a trailing newline is refused, not trimmed.** It is the single
  most likely thing to be wrong about a key pasted out of an extranet, and
  trimming it silently would hide the day somebody pastes two.
- **Escaping happens after sealing**, once, in `PaymentForm`.
- **The platforms are three, and the hostnames are two per platform.** The
  payment page is on `payment-webinit.*`, the Office services on
  `office-server.*`. An integration that guesses the second from the first
  finds out on the day of its first refund.

## ✅ Tests

```sh
composer install
composer test        # 238 tests, no network
composer analyse     # PHPStan, level max
composer test:lint   # Pint

vendor/bin/phpunit -c phpunit.live.xml   # 10 tests, against Worldline
```

The default suite needs nothing but PHP 8.2 and Composer, and runs in under
half a second.

The **live** suite is separate on purpose: it talks to somebody else's server,
so a red run there means "Worldline is down" as often as "this is broken", and
that is not what a merge gate is for. It runs nightly in CI and by hand, and it
needs no credentials. What it proves cannot be proved any other way — that the
seal, the field names and the interface versions are what the platform expects,
and that a wrong key is refused, which is the control that makes the rest mean
something.

Three groups in the default suite are worth naming:

**Against the documentation.** Worldline publishes a Data string, a key and the
seal it expects, for both algorithms. Those are in the suite verbatim, together
with a test that *sorting* the documented 104-field response breaks its seal.

**Against a fake platform.** `tests/Support/Platform` seals a response the way
Sips does, so the pair that means something can be asserted: a response sealed
correctly is accepted, and the same response with its fields reordered is not.

**Against the real frameworks.** The Laravel bridge runs in a Laravel
application booted by Testbench; the Symfony bundle compiles a real Symfony
container. The second earned its place immediately — it caught two service
definitions that would have broken every installation, one of them an object
where Symfony only accepts scalars, enums and services.

## 🤔 Why another package?

Worldline publishes its own PHP SDK. Measured on Packagist rather than guessed
at:

| | |
| :-- | :-- |
| `worldline/sips-payment-sdk` | last stable **1.0.7, March 2021**, marked **abandoned**, `php >=7.0` |
| downloads on that dead package | ~99,000 |
| `kptive/payment-sips-bundle` | last stable January **2015** |
| `ambroisemaupate/omnipay-sips2` | last stable January **2018** |

So the official connector for a platform that serves five bank brands has been
abandoned since 2021, and the community alternatives predate PHP 8 entirely.
That is the gap. Beyond the dates: the three seal shapes, the order rule, the
two response channels, the field sets that only the platform will tell you, and
framework wiring that does not quietly put a query parameter into a signature —
those are where the afternoons go, and they are what this package is.

## ⚠️ Not included

- **`duplicate`** — charging a stored card again. Its field set could not be
  established: the platform answers `12` to every shape tried and, unlike its
  answers for the three implemented operations, names no field. A payment
  library should not ship a guess about which fields move money.
- **Sips In-App, Walletpage and the SOAP interfaces.** Different connectors
  with their own guides.
- **`cardOrder`** and the other direct-card Office services, which mean holding
  card numbers and a PCI DSS scope most shops should not want.
- **The summary file** and the 3-D Secure emulation service.
- **No order model, no persistence, no events, no retries.** The results say
  what is worth retrying; your application's own tooling does the rest.
- **SHA-1 is nowhere near this protocol**, and the SHA-256 concatenation that
  is remains supported because merchants are on it — with a docblock saying
  why HMAC is the better shape.

## 📚 The documentation this was built from

- [Sips Paypage POST](https://docs.sips.worldline-solutions.com/en/WLSIPS.317-UG-Sips-Paypage-POST.html)
  — the fields, the seal, the response, and the two test vectors.
- [Quick start guide](https://docs.sips.worldline-solutions.com/en/WLSIPS.326-UG-Quick-Start-guide.html)
  — the simulation credentials and the test cards.
- [Sips Office JSON](https://documentation.sips.worldline.com/en/WLSIPS.310-UG-Sips-Office-JSON.html)
  — the cash-management services and their own seal rule.
- RFC 2104 (HMAC).

Where the documentation contradicts itself, the code says so and a test pins
the behaviour: the default algorithm is documented as SHA-256 on the Paypage
POST page and as HMAC on the JSON one (this package declares it explicitly
either way), and `sealAlgorithm` is spelled `HMAC-SHA-256` in a request and
`sha256` in a documented response (both are read).

## License

MIT.
