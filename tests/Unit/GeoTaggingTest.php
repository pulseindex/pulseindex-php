<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PulseIndex\Client;
use PulseIndex\Entity;
use PulseIndex\Engine\V1\IndexEntityRequest;
use PulseIndex\Geo\GeoHash;

/**
 * A position must carry the geo tags withinRadius() asks for, or a radius
 * search matches nothing.
 */
final class GeoTaggingTest extends TestCase
{
    private const LAT = 41.0082;
    private const LON = 28.9784;

    public function test_a_single_write_tags_every_position_it_carries(): void
    {
        $request = self::requestFor(new Entity(
            entityId: 1,
            categories: ['status:available'],
            points: ['where' => ['lat' => self::LAT, 'lon' => self::LON]],
        ));

        $categories = self::categories($request);
        self::assertContains('status:available', $categories);
        foreach (GeoHash::encodeMultiTags(self::LAT, self::LON) as $tag) {
            self::assertContains($tag, $categories, 'a position was indexed without its covering tag');
        }
    }

    /**
     * batchIndex() built its own request field for field, so a step added to
     * one path was simply absent from the other. Both go through one builder
     * now, and this is what says so.
     */
    public function test_a_batched_write_is_tagged_the_same_as_a_single_one(): void
    {
        $entity = new Entity(
            entityId: 7,
            categories: ['status:available'],
            numbers: ['price' => 100],
            points: ['where' => ['lat' => self::LAT, 'lon' => self::LON]],
            tenantId: 'acme',
        );

        $single = self::categories(self::requestFor($entity));
        $batched = self::categories(self::batchRequestFor($entity));

        self::assertSame($single, $batched);
    }

    public function test_two_positions_are_both_tagged(): void
    {
        $categories = self::categories(self::requestFor(new Entity(
            entityId: 2,
            points: [
                'pickup' => ['lat' => self::LAT, 'lon' => self::LON],
                'dropoff' => ['lat' => 41.0082, 'lon' => 28.9784],
            ],
        )));

        foreach ([[self::LAT, self::LON], [41.0082, 28.9784]] as [$lat, $lon]) {
            foreach (GeoHash::encodeMultiTags($lat, $lon) as $tag) {
                self::assertContains($tag, $categories);
            }
        }
    }

    /** Someone who tagged by hand as well as sending the position is not wrong to. */
    public function test_a_tag_the_caller_already_added_is_not_added_twice(): void
    {
        $tags = GeoHash::encodeMultiTags(self::LAT, self::LON);

        $categories = self::categories(self::requestFor(new Entity(
            entityId: 3,
            categories: $tags,
            points: ['where' => ['lat' => self::LAT, 'lon' => self::LON]],
        )));

        self::assertSame(array_values(array_unique($categories)), $categories);
        self::assertCount(count($tags), $categories);
    }

    public function test_a_record_with_no_position_gains_no_tag(): void
    {
        $categories = self::categories(self::requestFor(new Entity(
            entityId: 4,
            categories: ['status:available'],
        )));

        self::assertSame(['status:available'], $categories);
    }

    /**
     * The tags the index carries and the cells a query covers with have to be
     * the same two precisions, or a radius matches nothing. They come from one
     * class for that reason; this asserts the pair rather than trusting it.
     */
    public function test_the_indexed_precisions_are_the_ones_a_radius_covers_at(): void
    {
        $indexed = [];
        foreach (GeoHash::encodeMultiTags(self::LAT, self::LON) as $tag) {
            self::assertSame(1, preg_match('/^geo:(\d+):/', $tag, $m));
            $indexed[] = (int) $m[1];
        }
        sort($indexed);

        $covered = [];
        foreach ([0.5, 2.0, 5.0, 30.0] as $radiusKm) {
            $covered[] = GeoHash::optimalPrecisionForRadius($radiusKm, self::LAT, self::LON);
        }

        self::assertSame([5, 6], $indexed);
        self::assertEmpty(array_diff(array_unique($covered), $indexed));
    }

    private static function requestFor(Entity $entity): IndexEntityRequest
    {
        $stub = self::recordingStub();
        (new Client(['stub' => $stub]))->index($entity);

        return $stub->lastIndexRequest;
    }

    private static function batchRequestFor(Entity $entity): IndexEntityRequest
    {
        $stub = self::recordingStub();
        (new Client(['stub' => $stub]))->batchIndex([$entity]);

        return $stub->lastBatch->getEntities()[0];
    }

    /** @return list<string> */
    private static function categories(IndexEntityRequest $request): array
    {
        return array_values(iterator_to_array($request->getCategories()));
    }

    /**
     * A stub that records instead of dialling.
     *
     * It has to extend the generated client, because that is what Client
     * accepts, anything else is silently ignored and the call goes to a real
     * socket. The parent constructor is deliberately not run: it would build a
     * channel, and nothing here sends anything.
     */
    private static function recordingStub(): RecordingStub
    {
        return (new \ReflectionClass(RecordingStub::class))->newInstanceWithoutConstructor();
    }
}

final class RecordingStub extends \PulseIndex\Engine\V1\SearchEngineServiceClient
{
    public ?IndexEntityRequest $lastIndexRequest = null;

    public ?\PulseIndex\Engine\V1\BatchIndexEntitiesRequest $lastBatch = null;

    public function IndexEntity($argument, $metadata = [], $options = [])
    {
        $this->lastIndexRequest = $argument;

        return new RecordedCall(new \PulseIndex\Engine\V1\IndexEntityResponse());
    }

    public function BatchIndexEntities($argument, $metadata = [], $options = [])
    {
        $this->lastBatch = $argument;

        return new RecordedCall(new \PulseIndex\Engine\V1\BatchIndexEntitiesResponse());
    }
}

final class RecordedCall
{
    public function __construct(private readonly object $response)
    {
    }

    /** @return array{0: object, 1: object} */
    public function wait(): array
    {
        return [$this->response, (object) ['code' => 0, 'details' => '']];
    }
}
