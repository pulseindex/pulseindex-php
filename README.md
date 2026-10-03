# PulseIndex PHP SDK

Official PHP client for **PulseIndex**: hosted search and filtering for large entity sets.

You send attributes to index and queries to run; PulseIndex returns matching entity IDs, which you hydrate from your own database. Your records stay in your primary store. The service holds only what it needs to answer queries.

Plain PHP, no framework. Two runtime dependencies: `grpc/grpc` and `google/protobuf`.

[![Version](https://img.shields.io/packagist/v/pulseindex/pulseindex-php.svg)](https://packagist.org/packages/pulseindex/pulseindex-php)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](./LICENSE)

## Key Features

- **Microsecond-Class Retrieval:** A filter query runs in microseconds on the server and returns matching entity IDs over gRPC. From PHP each request is also a network round trip, and that decides most of what you see end to end.
- **Your Own Schema:** Numbers and positions go under names you choose: any name, any whole number, any count. A range or an order on a name nothing in your tenant carries is refused rather than answered with an empty page.
- **Exact Geography:** An exact radius, and `nearest()` orders by distance to the centimetre.
- **Totals That Say What They Are:** `totalIsExact` tells you whether the number you got is the whole set or a lower bound.
- **A Fluent, Immutable Query Builder:** Every chained call returns a new builder, so a base query is safe to share.
- **Typeahead With No Text Stored:** Names and titles are found from the first three letters of any word, and are not stored as text.

---

## Installation

Install the package via Composer:

```bash
composer require pulseindex/pulseindex-php
```

Requires PHP 8.2+ and `ext-grpc`.

```bash
pecl install grpc && docker-php-ext-enable grpc
```

Settings are arguments, not a config file:

```php
$client = \PulseIndex\Client::create('engine.example.com:443', 'your-api-key', ssl: true);

// Or the full form, which is the one to use when you need a timeout or a
// larger receive cap.
$client = new \PulseIndex\Client([
    'host' => 'engine.example.com:443',
    'api_key' => 'your-api-key',
    'ssl' => true,
    'timeout_us' => 5_000_000,
    'max_recv_bytes' => 33_554_432,
]);
```

Three environment variables are read **only as fallbacks**, when the matching
argument is absent: `PULSEINDEX_HOST` (default `localhost:50051`),
`PULSEINDEX_API_KEY`, and `PULSEINDEX_SSL`. Pass the value and the environment is
not consulted.

**Production must set `ssl: true`.** The default is plaintext, for local development. Do not treat CIDR isolation as encryption.

### Using a framework?

There is no framework integration in this package, and that is deliberate: it is
a client, and a client that knows about one framework's models, migrations and
console commands is a client that is wrong for everyone else. Bind
`PulseIndex\Client` into whatever container you already have and use it
directly. Syncing on save, rebuilding an index and reconciling drift are your
application's ordinary write path calling `index()`, `batchIndex()` and
`batchDelete()`.

---

## Health

Readiness is read from the standard `grpc.health.v1.Health` protocol, which
requires no particular scope and sends no credential.

```php
use Grpc\Health\V1\HealthCheckResponse\ServingStatus;

$client->health();                       // bool: reachable and serving
$client->servingStatus();                // int:  '' is the whole server
$client->servingStatus('pulseindex.engine.v1.SearchEngineService');
```

`health()` returns `false` rather than throwing, so unreachable and
not-currently-serving look alike; use `servingStatus()` to tell them apart.

## Usage

```php
use PulseIndex\Client;
use PulseIndex\Entity;

$client = Client::create('localhost:50051', 'your-api-key');
// Production, over TLS:
// $client = Client::create('engine.example.com:443', getenv('PULSEINDEX_API_KEY'), true);

$client->index(new Entity(
    entityId: 1001,
    categories: ['feature:pool', 'amenity:parking'],
    numbers: ['price' => 1500, 'bedrooms' => 3],
    points: ['where' => ['lat' => 41.0082, 'lon' => 28.9784]],
    tenantId: 'acme',
));

$result = $client->search(
    $client->query()
        ->tenant('acme')
        ->must('feature:pool')
        ->range('price', 1000, 5000)
        ->withinRadius(41.0082, 28.9784, 5, field: 'where')
        ->limit(50)
);

$ids = $result->matchedEntityIds;

// The nearest ten, drawn from whatever the other filters left.
$closest = $client->search(
    $client->query()->must('status:available')->nearest('where', 41.0082, 28.9784)->limit(10)
);

// The page and the real count in one round trip.
$counted = $client->searchWithTotal($client->query()->must('feature:pool')->limit(20));

// Clearing many rows: send ids in pages of up to 10,000. A larger page is
// refused by name rather than truncated. The return value is how many rows
// actually changed, so ids that were already gone are skipped, not fatal.
foreach (array_chunk($idsToRemove, 10000) as $page) {
    $deleted = $client->batchDelete($page, 'acme');
}
```

**You send a position once.** Every entry in `points` is tagged at precisions 5
and 6 as it is indexed, and a radius query covers at one of those two. Both come
from `GeoHash`, so the two sides cannot drift apart.

**Name the position field and the radius is exact.** Without it, `withinRadius`
expands the circle into geohash cells. Cells are rectangles, so on their own they
reach past the edge of the circle. Pass `field` and only records truly inside the
circle are returned.

**`nearest()` orders by distance.** It returns the closest first, drawn from
whatever the other filters left, with no radius to guess. Ordering is to the
centimetre. An entity with no position sorts last; a circle excludes it.

**Read `totalIsExact` before showing `totalMatches`.** On a page, the total may be
a lower bound. `searchWithTotal()` asks for the page and the exact total in one
request, and `SearchResult::exactTotal()` returns the number or `null` rather
than making you check.

**Every number and position is yours to name.** `numbers` takes any name, any
64-bit whole number, any number of them, and each one takes a range and an
order. A range, an order or a circle on a name no entity in your tenant carries
is **refused by name** rather than answered with an empty page.

**A key `Entity::fromArray()` does not read is refused.** It is a DTO, not an
attribute flattener, so an unrecognised key could only ever be dropped. A
top-level `'price' => 1500` belongs in `numbers`. A fraction in `numbers` is
refused too: numeric fields are whole numbers, so scale it yourself, a price in
cents or a rating out of 100.

**Install `ext-protobuf` if your queries carry many predicates.** The pure-PHP
protobuf implementation is the fallback, and it is the larger cost once a request
grows, such as a large `withinRadius`.
`pecl install protobuf && docker-php-ext-enable protobuf`. Small queries are
barely affected.

---

## QueryBuilder

`$client->query()` returns a builder bound to the client, so `execute()` runs the
search. It is **immutable**: every chained call returns a new builder, so a base
query is safe to keep and branch from.

| Method | Effect |
| --- | --- |
| `tenant($id)` | Set `tenant_id` |
| `must($attribute)` | MUST filter |
| `should($attribute, $group = 0)` | SHOULD filter; one OR group per `$group` |
| `mustNot($attribute)` | MUST_NOT filter |
| `range($field, $min, $max)` | Inclusive range on a number you named. Whole numbers only |
| `sortAsc($field)` / `sortDesc($field)` / `sortBy($field, $descending)` | Order by a number you named |
| `withinRadius($lat, $lon, $km, $precision = null, $field = null)` | Geohash covering, and an exact circle when `$field` is given |
| `within($field, $lat, $lon, $km)` | The circle alone, measured, with no covering to narrow it |
| `nearest($field, $lat, $lon)` | Order by distance, nearest first |
| `whereGeoHash($hash)` / `inGeoHash($hash)` | MUST an exact geo cell |
| `exactTotal($enabled = true)` | Count every match instead of stopping when the page fills |
| `typeahead($typed)` | Records whose text starts with what was typed, every word, any order. See [Typeahead](#typeahead) |
| `limit($n)` / `offset($n)` | Pagination. `limit(0)` asks for the count with no ids |
| `toRequest()` | The proto message, if you want to inspect or send it yourself |
| `execute()` | Search via the bound client |

Each `withinRadius` takes an OR group of its own, so two circles are AND'd rather
than merged, and "within 5 km and (red or blue)" means exactly that.

## Client

| Method | Returns | Description |
| --- | --- | --- |
| `Client::create($host, $apiKey = null, $ssl = null)` | `Client` | Convenience constructor; a null `$ssl` leaves the environment to decide |
| `new Client($config)` | `Client` | Full form: `host`, `api_key`, `ssl`, `timeout_us`, `max_recv_bytes` |
| `$client->query()` | `QueryBuilder` | A builder bound to this client |
| `$client->index(Entity $entity)` | `bool` | Upsert one entity |
| `$client->indexEntity($id, $categories, $numbers, $points, $tenantId)` | `bool` | The same without building an `Entity` |
| `$client->batchIndex(array $entities)` | `int` | Upsert up to 10,000 in one request |
| `$client->deleteEntity($id, $tenantId)` | `bool` | Delete one entity |
| `$client->batchDelete(array $ids, $tenantId)` | `int` | Delete up to 10,000 |
| `$client->search(QueryBuilder $q)` | `SearchResult` | Run `Search` |
| `$client->searchWithTotal(QueryBuilder $q)` | `SearchResult` | The page and the true count in one request |
| `$client->health()` | `bool` | Reachable **and** serving |
| `$client->servingStatus($service = '')` | `int` | Readiness when a boolean is not enough |

`SearchResult` carries `matchedEntityIds`, `totalMatches`, `totalIsExact` and
`executionTimeUs`, plus `count()`, `isEmpty()` and `exactTotal()`, which gives
you the number or `null` rather than making you check the flag yourself.

## Errors

Everything this package throws extends `PulseIndex\Exception\PulseIndexException`.
An RPC that fails arrives as `PulseIndex\Exception\GrpcException`, which carries
the gRPC status code so you can tell a bad query from an unreachable service:

```php
use PulseIndex\Exception\GrpcException;
use PulseIndex\Exception\PulseIndexException;

try {
    $result = $client->search($client->query()->must('feature:pool')->limit(20));
} catch (GrpcException $e) {
    // $e->grpcStatusCode (also the exception code) is the gRPC status:
    // 14 UNAVAILABLE, 16 UNAUTHENTICATED, 3 INVALID_ARGUMENT,
    // 8 RESOURCE_EXHAUSTED (the per-key rate limit). $e->grpcDetails is
    // the server's message, which for a refusal names the field.
} catch (PulseIndexException $e) {
    // Refused before it left this process: an empty field name, a negative
    // radius, a fractional number.
}
```

## Typeahead

Find a record by the first letters of any word in a name or a title, while
someone is still typing. At write time `Text` turns each value into tags such as
`t:andreas` and `p:and`, and at query time it turns what was typed into the tags
to look for. The tags reach PulseIndex like any other filter value, and no name
or title is stored as text. It needs `ext-intl`, and refuses
to run without it rather than produce tags the TypeScript SDK would not match.

**Writing.** Add the tags beside the record's own:

```php
use PulseIndex\Entity;
use PulseIndex\Text\Text;

$client->batchIndex(array_map(fn (array $d) => new Entity(
    entityId: $d['id'],
    categories: [...Text::indexTokensFor([$d['name'], $d['specialty']]), 'city:' . $d['city']],
    numbers: ['popularity' => $d['popularity']],
    tenantId: 'acme',
), $doctors));
```

**Searching.** Pass what was typed, and combine it with any other filter:

```php
$result = $client->search(
    $client->query()
        ->tenant('acme')
        ->typeahead('andreas mue')      // Dr. Andreas Müller
        ->must('city:berlin')
        ->sortDesc('popularity')
        ->limit(10)
);
```

**Once at boot**, check that the records were written by the tokenizer this
version of the SDK speaks. A different one would match nothing, silently, so
this throws `PulseIndex\Text\TokenizerMismatch` instead. The answer is cached
per client and tenant:

```php
use PulseIndex\Text\TextIndexVerifier;

TextIndexVerifier::verify($client, 'acme');
```

What it matches:

| Typed | Finds | Why |
| --- | --- | --- |
| `mue`, `mul`, `mül` | Müller | German umlauts are indexed both ways, `ü` as `ue` and as `u` |
| `andreas mue`, `mue andreas` | Dr. Andreas Müller | Every word is required, in any order |
| `dr mue` | Dr. Andreas Müller, Dr. Thomas Mueller | A finished word under three letters is matched whole |
| `m` | whatever the rest of the query finds | A last word under three letters adds nothing yet. Skip the search while `Text::typeaheadGroups($typed) === []` |
| `gastroenterologe` | Gastroenterologe | Past twelve letters a word is matched whole, which is what was typed |
| `محم`, `моск`, `οδο` | محمد, Москва, ΟΔΟΣ | Every script with spaces between words, accents and tashkeel folded |

Chinese, Japanese and Korean have no spaces to split words on, so they are
matched from the start of each unbroken run of characters.

There is no relevance score. The order is whatever number you supply, as
above, which is usually what a directory wants anyway: the most booked doctor
first. For one typo per word, add each of `Text::spellingTags($word)` with
`should()`.

**What it costs.** Text adds tags to every record, and a plan counts records by
weight (150 bytes is one). What decides the weight is how many different words
the field holds, not how many records you have. Words that repeat, such as
specialties, cities or brands, stay at one record each. People's names repeat
far less than you would expect.

Measured on a doctor directory, name plus specialty, with real surnames: the
2010 United States census, 162,253 surnames drawn by how many people carry
each. About a tenth of people have a surname too rare for that list, so the
last column counts each of those as a name seen nowhere else, which is the
most it can cost.

| Records | Each counts as, common names | With the rarest tenth |
| --- | --- | --- |
| 10,000 | 1.00 | 1.00 |
| 100,000 | 1.38 | 1.73 |
| 500,000 | 1.38 | 1.49 |
| 1,000,000 | 1.27 | 1.43 |
| 2,500,000 | 1.20 | 1.33 |

Under about ten thousand records the fixed allowance every plan carries covers
the names. The same directory without the name, specialty only, counts as one
record each at every size.

Changing the tokenizer is a breaking change: `Text::TOKENIZER_VERSION` moves,
and an index written under the old one has to be written again.

## GeoHash

`PulseIndex\Geo\GeoHash` is usable on its own. Entities are tagged at
precisions **5 and 6**, and a radius query covers at whichever of those two is
coarsest while still tight enough, and never any other precision. A circle too large to cover within 2,048 cells is refused by
name rather than half-covered.

```php
use PulseIndex\Geo\GeoHash;

GeoHash::encode(41.0082, 28.9784, 5);      // 'sxk97'
GeoHash::tag('sxk97');                      // 'geo:5:sxk97'
GeoHash::encodeMultiTags(41.0082, 28.9784); // the geo:5 and geo:6 tags for a point
GeoHash::getCoveringHashes(41.0082, 28.9784, 5.0);
GeoHash::optimalPrecisionForRadius(5.0, 41.0082, 28.9784);
GeoHash::haversineKm(41.0082, 28.9784, 41.05, 29.0); // great-circle km
```

Also available: `decode`, `decodeBounds`, `neighbor`, `neighbors`,
`precisionForRadius`, `encodeTag`.

The PHP and TypeScript SDKs produce the same covering for the same call.

## gRPC contract

Service: `pulseindex.engine.v1.SearchEngineService`

| RPC | Request | Response |
| --- | --- | --- |
| `IndexEntity` | `IndexEntityRequest` | `IndexEntityResponse` |
| `BatchIndexEntities` | `BatchIndexEntitiesRequest` | `BatchIndexEntitiesResponse` |
| `DeleteEntity` | `DeleteEntityRequest` | `DeleteEntityResponse` |
| `BatchDeleteEntities` | `BatchDeleteEntitiesRequest` | `BatchDeleteEntitiesResponse` |
| `Search` | `SearchQueryRequest` | `SearchQueryResponse` |

Works with the PulseIndex cloud service and with a self-hosted PulseIndex from
v2.0.0.

## License

MIT
