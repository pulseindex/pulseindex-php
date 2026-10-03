# Changelog

## 7.0.0

The first release from this repository's new history. Earlier versions are
abandoned on Packagist and their notes are not carried over.

The API is the same as 6.1.3: code written against 6.x runs unchanged, and
upgrading is a version constraint. What 7.0.0 contains:

- `Entity` with categories, numbers and positions under names you choose, and
  a refusal by name for a key `Entity::fromArray()` does not read.
- MUST, SHOULD and MUST_NOT filters, groups of alternatives, ranges and orders
  on any number you indexed.
- An exact radius, and `nearest()` for the nearest K.
- `searchWithTotal()`, and `totalIsExact` on every result.
- Typeahead on names and titles in every script with spaces between words,
  with `TextIndexVerifier` to check the index was written by the same tokenizer.
- Batch index and batch delete, up to 10,000 entities a call.
- `health()` and `servingStatus()` on the standard gRPC health protocol.

Requires PHP 8.2 or later and `ext-grpc`. Typeahead needs `ext-intl`.
