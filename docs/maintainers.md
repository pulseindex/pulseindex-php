# Maintainer notes

Not shipped in the Composer archive: `.gitattributes` leaves this directory out.

## Proto

`proto/engine.proto` is the service contract. Two checks keep it honest.

**`vendor/bin/phpunit --testsuite Unit`** pins the schema, every RPC, message, field
number and type, and enum value, in `tests/Unit/ProtoSchemaTest.php`. Any change to the
proto fails the suite until the fixture is updated, so the change lands in the diff
instead of passing unseen.

**`composer check:proto`** checks two more things:

1. the proto still matches the stubs generated from it (`engine.proto.sha256`)
2. with `PULSEINDEX_PROTO` set, its declarations match the service's own copy,
   apart from the RPCs named in `PULSEINDEX_PROTO_OMIT`

Without `PULSEINDEX_PROTO` it exits 0 but prints `NOT VERIFIED`, never `ok`.
`composer check:proto:ci` (`--require-engine`) makes that state an error.

Regenerate with `composer build-proto` (it rewrites the stubs and the hash), update the
fixture, and commit.

## Releasing

Update `CHANGELOG.md`, then push a version tag. Packagist picks the tag up.

```bash
git tag v7.0.0
git push origin v7.0.0
```
