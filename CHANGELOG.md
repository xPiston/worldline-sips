# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the versioning
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html) — which for
a payment library means the seal rules, the field names and the shape of a
verified response are the public surface, not just the method signatures.

## [Unreleased]

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
