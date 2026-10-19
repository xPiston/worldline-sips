# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the versioning
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html) — which for
a payment library means the seal rules, the field names and the shape of a
verified response are the public surface, not just the method signatures.

## [Unreleased]

### Changed

- **The development constraints now allow Laravel 13, Symfony 8 and
  PHPUnit 13.** `composer.json` claimed support for Laravel 13, but
  `orchestra/testbench: ^10.0` pinned the resolver to Laravel 12 - the major
  of testbench follows the major of Laravel - so the `highest` CI job had
  never once run against the version the package advertised. Symfony had to
  come along with it, since Laravel 13 depends on Symfony 8. Nothing in
  `require` moved: this is still PHP 8.2 and PSR interfaces, and no consumer's
  resolution changes.

## [0.1.0] - 2026-10-12

First release. `0.x` on purpose, and for a narrower reason than usual: the
protocol itself is verified against Worldline's own servers - the seals in
both directions, the field sets, the interface versions, and a complete
payment taken through a browser with a card and a 3-D Secure step. What has
not happened is a payment on a *merchant's* terminal, where the contract
decides the capture mode, the seal algorithm and whether the shop names its
own transactions. Until this package has met one of those, the version
number should not promise that no name has to move.

### Added

- Paypage requests: the Data element built once and sealed as sent, as hidden
  inputs or a whole form, with both seal algorithms and the optional base64
  encoding.
- Response verification over the Data element **exactly as it arrived**, for
  both the browser return and the server-to-server notification.
- The three cash-management operations - validate, cancel, refund - with the
  Office seal rule (values concatenated in the alphabetical order of their
  names, without `keyVersion` and `sealAlgorithm`) and a response seal that is
  verified rather than assumed.
- Typed response codes, with the three questions a shop asks of a refusal:
  is it worth retrying, is it the shop's own fault, did the customer walk away.
- Laravel service provider and Symfony bundle, each with the request helper
  that keeps a query parameter out of a signature.
- A live test suite against Worldline's simulation and Office test platforms,
  needing no contract - their credentials are published in Sips' own guides.

[0.1.0]: https://github.com/xPiston/worldline-sips/releases/tag/v0.1.0
