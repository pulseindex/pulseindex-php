<?php

declare(strict_types=1);

namespace PulseIndex;

use Grpc\ChannelCredentials;
use PulseIndex\Engine\V1\BatchDeleteEntitiesRequest;
use PulseIndex\Engine\V1\BatchIndexEntitiesRequest;
use PulseIndex\Engine\V1\DeleteEntityRequest;
use PulseIndex\Engine\V1\GeoPoint;
use Grpc\Health\V1\HealthCheckRequest;
use Grpc\Health\V1\HealthCheckResponse\ServingStatus;
use Grpc\Health\V1\HealthClient;
use PulseIndex\Engine\V1\IndexEntityRequest;
use PulseIndex\Engine\V1\SearchEngineServiceClient;
use PulseIndex\Exception\GrpcException;
use PulseIndex\Exception\PulseIndexException;

/**
 * Fluent PHP wrapper around the PulseIndex gRPC SearchEngineService.
 */
final class Client implements ClientInterface
{
    private SearchEngineServiceClient $stub;

    private ?HealthClient $healthStub = null;

    /** @var string|null */
    private $healthHost = null;

    /** @var array<string,mixed> */
    private $healthOptions = [];

    /** @var array<string, array<int, string>> */
    private array $metadata;

    /**
     * @param array{
     *     host?: string,
     *     api_key?: string|null,
     *     ssl?: bool,
     *     timeout_us?: int,
     *     stub?: SearchEngineServiceClient|null
     * } $config
     */
    public function __construct(array $config = [])
    {
        $host = $config['host'] ?? getenv('PULSEINDEX_HOST') ?: 'localhost:50051';
        $apiKey = $config['api_key'] ?? (getenv('PULSEINDEX_API_KEY') ?: null);
        $timeoutUs = (int) ($config['timeout_us'] ?? 5_000_000);

        $this->metadata = [];
        if (is_string($apiKey) && $apiKey !== '') {
            $this->metadata['x-api-key'] = [$apiKey];
        }

        if (isset($config['healthStub']) && $config['healthStub'] instanceof HealthClient) {
            $this->healthStub = $config['healthStub'];
        }

        if (isset($config['stub']) && $config['stub'] instanceof SearchEngineServiceClient) {
            $this->stub = $config['stub'];

            return;
        }

        if (!class_exists(SearchEngineServiceClient::class)) {
            throw new PulseIndexException(
                'SearchEngineServiceClient not found. Run `composer build-proto` first.'
            );
        }

        $ssl = self::sslEnabled($config);
        $credentials = $ssl
            ? ChannelCredentials::createSsl()
            : ChannelCredentials::createInsecure();

        $options = [
            'credentials' => $credentials,
            'timeout' => $timeoutUs,
        ];
        if (isset($config['max_recv_bytes']) && (int) $config['max_recv_bytes'] > 0) {
            // Paging a large id set needs more than grpc's 4 MiB default.
            $options['grpc.max_receive_message_length'] = (int) $config['max_recv_bytes'];
        }

        $this->stub = new SearchEngineServiceClient($host, $options);

        // Kept so the health stub can be built on demand against exactly the
        // same host and options.
        $this->healthHost = $host;
        $this->healthOptions = $options;
    }

    /**
     * Serving status from `grpc.health.v1.Health`.
     *
     * An empty $service asks for the health of the server as a whole, which is
     * what the health spec defines. The service answers for both that key and
     * its own name, and reports whether it can currently answer queries.
     *
     * No credential is sent, and none is needed.
     *
     * @return int one of \Grpc\Health\V1\HealthCheckResponse\ServingStatus
     */
    public function servingStatus(string $service = ''): int
    {
        if (!isset($this->healthStub)) {
            if (!isset($this->healthHost)) {
                throw new PulseIndexException(
                    'No health stub available. Pass `healthStub` when injecting a custom `stub`.'
                );
            }
            $this->healthStub = new HealthClient($this->healthHost, $this->healthOptions);
        }

        $request = new HealthCheckRequest();
        $request->setService($service);

        /** @var \Grpc\Health\V1\HealthCheckResponse $response */
        $response = $this->unary($this->healthStub->Check($request, $this->metadata));

        return $response->getStatus();
    }

    /**
     * True only when the service can serve reads.
     *
     * Returns false rather than throwing, so unreachable and not serving look
     * the same here; call {@see servingStatus()} to tell them apart.
     */
    public function health(): bool
    {
        try {
            return $this->servingStatus() === ServingStatus::SERVING;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function create(string $host, ?string $apiKey = null, ?bool $ssl = null): self
    {
        $config = [
            'host' => $host,
            'api_key' => $apiKey,
        ];

        if ($ssl !== null) {
            $config['ssl'] = $ssl;
        }

        return new self($config);
    }

    /**
     * Production customer gRPC must use TLS (`ssl: true` / `PULSEINDEX_SSL=true`).
     * The default is plaintext for local Docker. String `"false"` is not treated as true.
     */
    private static function sslEnabled(array $config): bool
    {
        if (array_key_exists('ssl', $config)) {
            return filter_var($config['ssl'], FILTER_VALIDATE_BOOLEAN);
        }

        $env = getenv('PULSEINDEX_SSL');
        if ($env === false || $env === '') {
            return false;
        }

        return filter_var($env, FILTER_VALIDATE_BOOLEAN);
    }

    public function query(): QueryBuilder
    {
        return new QueryBuilder($this);
    }

    /**
     * @param list<string> $categories
     */
    public function indexEntity(
        int $entityId,
        array $categories = [],
        array $numbers = [],
        array $points = [],
        string $tenantId = '',
    ): bool {
        $request = self::indexRequest($entityId, $categories, $numbers, $points, $tenantId);

        /** @var \PulseIndex\Engine\V1\IndexEntityResponse $response */
        $response = $this->unary($this->stub->IndexEntity($request, $this->metadata));

        return (bool) $response->getSuccess();
    }

    /**
     * The one place a write is turned into a request, so a single write and a
     * batched one cannot disagree.
     *
     * @param list<string>       $categories
     * @param array<string, int> $numbers
     * @param array<string, array{lat: float|string, lon: float|string}> $points
     */
    private static function indexRequest(
        int $entityId,
        array $categories,
        array $numbers,
        array $points,
        string $tenantId,
    ): IndexEntityRequest {
        $request = new IndexEntityRequest();
        $request->setEntityId($entityId);
        $request->setCategories(self::withGeoTags($categories, $points));
        $request->setNumbers($numbers);
        $request->setPoints(self::geoPoints($points));
        $request->setTenantId($tenantId);

        return $request;
    }

    /**
     * Every position carries the geo tags `withinRadius` asks for.
     *
     * It belongs here rather than in `Entity`, because `indexEntity()` is the
     * other way in. The query side builds its cells from `GeoHash` too, so one
     * piece of code generates both and they cannot drift.
     *
     * @param  list<string> $categories
     * @param  array<string, array{lat: float|string, lon: float|string}> $points
     * @return list<string>
     */
    private static function withGeoTags(array $categories, array $points): array
    {
        $tags = array_values($categories);
        foreach ($points as $point) {
            foreach (Geo\GeoHash::encodeMultiTags((float) $point['lat'], (float) $point['lon']) as $tag) {
                $tags[] = $tag;
            }
        }

        // A caller who tagged by hand as well as sending the position is not
        // wrong to, and the tag is sent once.
        return array_values(array_unique($tags));
    }

    /**
     * Positions are sent in degrees.
     *
     * @param  array<string, array{lat: float, lon: float}> $points
     * @return array<string, GeoPoint>
     */
    private static function geoPoints(array $points): array
    {
        $out = [];
        foreach ($points as $name => $point) {
            $message = new GeoPoint();
            $message->setLat((float) $point['lat']);
            $message->setLon((float) $point['lon']);
            $out[(string) $name] = $message;
        }

        return $out;
    }

    public function index(Entity $entity): bool
    {
        return $this->indexEntity(
            $entity->entityId,
            $entity->categories,
            $entity->numbers,
            $entity->points,
            $entity->tenantId,
        );
    }

    /**
     * @param list<Entity|array<string, mixed>> $entities
     */
    public function batchIndex(array $entities): int
    {
        $messages = [];
        foreach ($entities as $entity) {
            if (is_array($entity)) {
                $entity = Entity::fromArray($entity);
            }
            if (!$entity instanceof Entity) {
                throw new PulseIndexException('batchIndex expects Entity instances or associative arrays.');
            }

            $messages[] = self::indexRequest(
                $entity->entityId,
                $entity->categories,
                $entity->numbers,
                $entity->points,
                $entity->tenantId,
            );
        }

        $batch = new BatchIndexEntitiesRequest();
        $batch->setEntities($messages);

        /** @var \PulseIndex\Engine\V1\BatchIndexEntitiesResponse $response */
        $response = $this->unary($this->stub->BatchIndexEntities($batch, $this->metadata));

        return (int) $response->getIndexedCount();
    }

    public function deleteEntity(int $entityId, string $tenantId = ''): bool
    {
        $request = new DeleteEntityRequest();
        $request->setEntityId($entityId);
        $request->setTenantId($tenantId);

        /** @var \PulseIndex\Engine\V1\DeleteEntityResponse $response */
        $response = $this->unary($this->stub->DeleteEntity($request, $this->metadata));

        return (bool) $response->getSuccess();
    }

    /**
     * Delete many entities in one call.
     *
     * `deleteEntity()` takes a single id, so clearing a catalogue that way is
     * one round trip per row. Send ids in pages of up to 10,000; a larger page
     * is refused by name rather than truncated, so it fails loudly instead of
     * deleting part of itself.
     *
     * Ids that are unknown or already deleted are skipped, so retrying a page
     * that half-applied is safe. The return value is the number of rows that
     * actually changed, which is lower than count($entityIds) whenever some of
     * them were already gone.
     *
     * @param list<int> $entityIds
     */
    public function batchDelete(array $entityIds, string $tenantId = ''): int
    {
        $request = new BatchDeleteEntitiesRequest();
        $request->setEntityIds(self::normaliseEntityIds($entityIds));
        $request->setTenantId($tenantId);

        /** @var \PulseIndex\Engine\V1\BatchDeleteEntitiesResponse $response */
        $response = $this->unary($this->stub->BatchDeleteEntities($request, $this->metadata));

        return (int) $response->getDeletedCount();
    }

    /**
     * Reject an unusable id before anything is sent, and name which one.
     *
     * A page of ten thousand ids that fails on one of them has to say which,
     * or the caller is left bisecting their own input against a server error.
     *
     * @param list<int> $entityIds
     * @return list<int>
     */
    private static function normaliseEntityIds(array $entityIds): array
    {
        $ids = [];
        foreach (array_values($entityIds) as $i => $entityId) {
            if (!is_int($entityId)) {
                throw new PulseIndexException(
                    sprintf('batchDelete expects integer entity ids; entityIds[%d] is %s.', $i, get_debug_type($entityId)),
                );
            }
            if ($entityId < 0) {
                throw new PulseIndexException(
                    sprintf('batchDelete entity ids must not be negative; entityIds[%d] is %d.', $i, $entityId),
                );
            }
            $ids[] = $entityId;
        }

        return $ids;
    }

    public function search(QueryBuilder $query): SearchResult
    {
        /** @var \PulseIndex\Engine\V1\SearchQueryResponse $response */
        $response = $this->unary($this->stub->Search($query->toRequest(), $this->metadata));

        $ids = [];
        foreach ($response->getMatchedEntityIds() as $id) {
            $ids[] = (int) $id;
        }

        return new SearchResult(
            matchedEntityIds: $ids,
            totalMatches: (int) $response->getTotalMatches(),
            executionTimeUs: (int) $response->getExecutionTimeUs(),
            totalIsExact: (bool) $response->getTotalIsExact(),
        );
    }

    /**
     * A page of ids together with the real number of matches.
     *
     * On a page, the total may be a lower bound, and anything that prints
     * "page 1 of N" needs the real one. This is one request with exactTotal
     * set, and the total it returns can be divided by a page size.
     */
    public function searchWithTotal(QueryBuilder $query): SearchResult
    {
        return $this->search($query->exactTotal());
    }


    /**
     * @param mixed $call Result of a *_simpleRequest unary call (array{0: Message, 1: object}|object)
     */
    private function unary(mixed $call): object
    {
        if (is_array($call) && isset($call[0], $call[1])) {
            [$response, $status] = $call;
        } elseif (is_object($call) && method_exists($call, 'wait')) {
            [$response, $status] = $call->wait();
        } else {
            throw new PulseIndexException('Unexpected gRPC call return type.');
        }

        $code = is_object($status) ? (int) ($status->code ?? 0) : 0;
        $details = is_object($status) ? (string) ($status->details ?? '') : '';

        if ($code !== 0) {
            throw new GrpcException(
                message: $details !== '' ? $details : "gRPC call failed with status {$code}",
                grpcStatusCode: $code,
                grpcDetails: $details,
            );
        }

        if (!is_object($response)) {
            throw new PulseIndexException('Empty gRPC response.');
        }

        return $response;
    }
}
