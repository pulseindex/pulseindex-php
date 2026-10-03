<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PulseIndex\Client;
use PulseIndex\Entity;
use PulseIndex\Exception\GrpcException;

/**
 * Requires a reachable PulseIndex endpoint on PULSEINDEX_HOST (default
 * localhost:50051), and skips itself when nothing answers.
 *
 * docker compose run --rm php composer test:integration
 */
final class ClientIntegrationTest extends TestCase
{
    private Client $client;

    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('grpc')) {
            self::markTestSkipped('ext-grpc is not loaded');
        }

        $host = getenv('PULSEINDEX_HOST') ?: 'localhost:50051';
        $apiKey = getenv('PULSEINDEX_API_KEY') ?: 'dev-key';

        $this->client = Client::create($host, $apiKey);
        $this->tenant = 'php-sdk-' . bin2hex(random_bytes(4));

        try {
            $this->client->indexEntity(
                entityId: 1,
                categories: ['feature:warmup'],
                tenantId: $this->tenant,
            );
        } catch (GrpcException $e) {
            self::markTestSkipped('PulseIndex gRPC server unreachable: ' . $e->getMessage());
        } catch (\Throwable $e) {
            self::markTestSkipped('PulseIndex gRPC server unreachable: ' . $e->getMessage());
        }
    }

    public function testIndexSearchAndDeleteRoundTrip(): void
    {
        $indexed = $this->client->batchIndex([
            new Entity(
                entityId: 1001,
                categories: ['feature:pool', 'amenity:parking'],
                numbers: ['price' => 1500],
                tenantId: $this->tenant,
            ),
            new Entity(
                entityId: 1002,
                categories: ['feature:garden'],
                numbers: ['price' => 900],
                tenantId: $this->tenant,
            ),
            [
                'entity_id' => 1003,
                'categories' => ['feature:pool'],
                'numbers' => ['price' => 2000],
                'tenant_id' => $this->tenant,
            ],
        ]);

        self::assertSame(3, $indexed);

        $result = $this->client->search(
            $this->client->query()
                ->tenant($this->tenant)
                ->must('feature:pool')
                ->should('amenity:parking')
                ->mustNot('feature:garden')
                ->range('price', 1000, 1800)
                ->limit(50)
        );

        self::assertContains(1001, $result->matchedEntityIds);
        self::assertNotContains(1002, $result->matchedEntityIds);
        self::assertGreaterThan(0, $result->totalMatches);

        self::assertTrue($this->client->deleteEntity(1001, $this->tenant));

        $afterDelete = $this->client->search(
            $this->client->query()
                ->tenant($this->tenant)
                ->must('feature:pool')
                ->limit(50)
        );

        self::assertNotContains(1001, $afterDelete->matchedEntityIds);
        self::assertContains(1003, $afterDelete->matchedEntityIds);
    }

    public function testBatchDeleteClearsTheTenantAndReportsRowsChanged(): void
    {
        $ids = range(2001, 2050);
        $entities = [];
        foreach ($ids as $id) {
            $entities[] = new Entity(
                entityId: $id,
                categories: ['feature:clearme'],
                numbers: ['price' => 100],
                tenantId: $this->tenant,
            );
        }

        self::assertSame(50, $this->client->batchIndex($entities));

        self::assertSame(
            50,
            $this->client->batchDelete($ids, $this->tenant),
            'every row that was live is reported',
        );

        $after = $this->client->search(
            $this->client->query()
                ->tenant($this->tenant)
                ->must('feature:clearme')
                ->limit(50)
        );
        self::assertSame([], $after->matchedEntityIds);

        // A retry of a page that already applied is not an error, and reports
        // the smaller number rather than failing on ids that are already gone.
        self::assertSame(0, $this->client->batchDelete($ids, $this->tenant));

        // Ids that were never indexed are skipped the same way.
        self::assertSame(0, $this->client->batchDelete([9_000_001, 9_000_002], $this->tenant));
    }

    /**
     * A paged total may be a lower bound, and anything printing "page 1 of N"
     * needs the real one.
     */
    public function testPagedTotalIsMarkedInexactAndSearchWithTotalFixesIt(): void
    {
        $entities = [];
        foreach (range(3001, 3600) as $id) {
            $entities[] = new Entity(
                entityId: $id,
                categories: ['bulk:yes'],
                numbers: ['price' => 100],
                tenantId: $this->tenant,
            );
        }
        self::assertSame(600, $this->client->batchIndex($entities));

        $paged = $this->client->search(
            $this->client->query()->tenant($this->tenant)->must('bulk:yes')->limit(10)
        );
        self::assertCount(10, $paged->matchedEntityIds);
        self::assertFalse($paged->totalIsExact, 'a paged total must not claim to be exact');
        self::assertNull($paged->exactTotal(), 'an inexact total must not be handed out as a number');

        $counted = $this->client->search(
            $this->client->query()->tenant($this->tenant)->must('bulk:yes')->limit(0)
        );
        self::assertTrue($counted->totalIsExact);
        self::assertSame(600, $counted->totalMatches);

        $both = $this->client->searchWithTotal(
            $this->client->query()->tenant($this->tenant)->must('bulk:yes')->limit(10)
        );
        self::assertCount(10, $both->matchedEntityIds, 'still one page of ids');
        self::assertTrue($both->totalIsExact);
        self::assertSame(600, $both->exactTotal(), 'and the real total beside it');
    }

    /** The generated stub carries exactly the RPCs this client uses. */
    public function testTheStubCarriesExactlyTheClientRpcs(): void
    {
        $stub = new \ReflectionClass(\PulseIndex\Engine\V1\SearchEngineServiceClient::class);
        $rpcs = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            array_filter(
                $stub->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $stub->getName()
                    && $m->getName() !== '__construct',
            ),
        );
        sort($rpcs);

        self::assertSame(
            ['BatchDeleteEntities', 'BatchIndexEntities', 'DeleteEntity', 'IndexEntity', 'Search'],
            $rpcs,
            'the generated stub gained or lost an RPC',
        );
    }
}
