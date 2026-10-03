<?php

declare(strict_types=1);

namespace PulseIndex;

use PulseIndex\Engine\V1\FilterPredicate;
use PulseIndex\Engine\V1\FilterPredicate\Operation;
use PulseIndex\Engine\V1\RangePredicate;
use PulseIndex\Engine\V1\SearchQueryRequest;
use PulseIndex\Engine\V1\GeoPredicate;
use PulseIndex\Exception\PulseIndexException;
use PulseIndex\Engine\V1\SortSpec;
use PulseIndex\Geo\GeoHash;
use PulseIndex\Text\Text;

/**
 * Fluent builder for PulseIndex Search RPCs.
 */
final class QueryBuilder
{
    /**
     * A page, for callers who never say otherwise. Nobody calling search()
     * without a limit means every matching id.
     */
    public const DEFAULT_LIMIT = 100;

    private string $tenantId = '';

    private int $limit = self::DEFAULT_LIMIT;

    private int $offset = 0;

    private bool $exactTotal = false;

    /** @var array{field: string, lat: float, lon: float, radiusKm: float}|null */
    private ?array $geo = null;

    /** @var list<array{op: int, attribute: string, group: int}> */
    private array $filters = [];

    /** @var list<array{field: string, min: int, max: int}> */
    private array $ranges = [];

    /** @var array{field: string, descending: bool}|null */
    private ?array $sort = null;

    /**
     * The next disjunction number to hand out.
     *
     * Group 0 is the default and belongs to plain should() calls. Anything
     * that builds a disjunction of its own, such as a radius, which becomes one
     * SHOULD per covering cell, takes a number from here, so it cannot merge
     * with a disjunction the caller wrote.
     */
    private int $nextGroup = 1;

    public function __construct(
        private readonly ?ClientInterface $client = null,
    ) {
    }

    public function tenant(string $tenantId): self
    {
        $clone = clone $this;
        $clone->tenantId = $tenantId;

        return $clone;
    }

    public function must(string $attribute): self
    {
        return $this->filter(Operation::MUST, $attribute);
    }

    /**
     * At least one of these has to match.
     *
     * Pass a $group to keep a disjunction separate from another one. Members
     * of a group are OR'd together and the groups are AND'd with each other,
     * so two groups ask for a red or blue shirt in small or medium. Left
     * unset it is 0, which is one disjunction.
     */
    public function should(string $attribute, int $group = 0): self
    {
        return $this->filter(Operation::SHOULD, $attribute, $group);
    }

    public function mustNot(string $attribute): self
    {
        return $this->filter(Operation::MUST_NOT, $attribute);
    }

    /**
     * Exact GeoHash cell match: MUST `geo:{precision}:{hash}` tag.
     */
    public function whereGeoHash(string $geohash): self
    {
        return $this->must(GeoHash::tag($geohash));
    }

    /**
     * Alias of {@see whereGeoHash()}.
     */
    public function inGeoHash(string $geohash): self
    {
        return $this->whereGeoHash($geohash);
    }

    /**
     * Radius coverage as SHOULD `geo:{precision}:{hash}` tags for cells that
     * intersect the circle.
     *
     * The cells go into a disjunction of their own, so "within 5 km and (red
     * or blue)" is not answered as "within 5 km or red or blue". Each further
     * radius takes another group, so two circles are AND'd.
     *
     * When $precision is omitted, {@see GeoHash::optimalPrecisionForRadius()} is used.
     *
     * @param string|null $field The position field, as named in Entity::points.
     *                           Given one, the answer holds only what is really
     *                           inside the circle. Without it the cells are the
     *                           whole answer, and cells are rectangles, so they
     *                           reach past the edge of the circle.
     */
    public function withinRadius(
        float $lat,
        float $lon,
        float $radiusKm,
        ?int $precision = null,
        ?string $field = null,
    ): self {
        $clone = clone $this;
        $group = $clone->nextGroup;
        $clone->nextGroup++;
        if ($field !== null && trim($field) !== '') {
            $clone->geo = ['field' => $field, 'lat' => $lat, 'lon' => $lon, 'radiusKm' => $radiusKm];
        }
        foreach (GeoHash::getCoveringHashes($lat, $lon, $radiusKm, $precision) as $hash) {
            $clone->filters[] = [
                'op' => Operation::SHOULD,
                'attribute' => GeoHash::tag($hash),
                'group' => $group,
            ];
        }

        return $clone;
    }

    /**
     * Narrow to records whose text starts with what the person has typed so
     * far, one or several words, in any order. The records have to have been
     * indexed with `Text::indexTokens` / `Text::indexTokensFor`.
     *
     * Each word takes a group of its own from the same counter a radius uses,
     * so a typeahead, a radius and your own `should()` groups never merge into
     * one OR. Adds nothing when nothing typed is long enough yet; check
     * `Text::typeaheadGroups($typed)` first if you would rather not search.
     */
    public function typeahead(string $typed): self
    {
        $clone = clone $this;
        foreach (Text::typeaheadGroups($typed) as $tags) {
            $group = $clone->nextGroup;
            $clone->nextGroup++;
            foreach ($tags as $attribute) {
                $clone->filters[] = [
                    'op' => Operation::SHOULD,
                    'attribute' => $attribute,
                    'group' => $group,
                ];
            }
        }

        return $clone;
    }

    /**
     * Filter on a numeric field's inclusive range.
     *
     * The field is one you named yourself in Entity::numbers.
     *
     * A range on a field no entity in your tenant carries is refused by name
     * rather than answered, because a field nothing carries can only match
     * nothing, and an empty page looks exactly like a real one.
     */
    public function range(string $field, int $min, int $max): self
    {
        $clone = clone $this;
        $clone->ranges[] = [
            'field' => $field,
            'min' => $min,
            'max' => $max,
        ];

        return $clone;
    }

    /**
     * How many ids to return. Zero asks for the total number of matches and no
     * ids at all, which is the cheap way to count.
     */
    public function limit(int $limit): self
    {
        $clone = clone $this;
        $clone->limit = max(0, $limit);

        return $clone;
    }

    public function offset(int $offset): self
    {
        $clone = clone $this;
        $clone->offset = max(0, $offset);

        return $clone;
    }

    /**
     * Order the page by a numeric field, smallest first.
     *
     * An ordered search costs more than the same filter unordered.
     * offset + limit is capped at 100,000.
     */
    public function sortAsc(string $field): self
    {
        return $this->sortBy($field, false);
    }

    /** Order the page by a numeric field, largest first. */
    public function sortDesc(string $field): self
    {
        return $this->sortBy($field, true);
    }

    /**
     * Order the page by a numeric field.
     *
     * Bounded exactly as range() is: the field is one you named in
     * Entity::numbers, and a name no entity in your tenant carries is refused
     * rather than silently ignored.
     */
    public function sortBy(string $field, bool $descending = false): self
    {
        if (trim($field) === '') {
            throw new Exception\PulseIndexException('Sort field must not be empty.');
        }

        $clone = clone $this;
        $clone->sort = ['field' => $field, 'descending' => $descending];

        return $clone;
    }

    /**
     * Count every match instead of stopping as soon as the page is full.
     *
     * On a page, totalMatches may be a lower bound. This makes the count exact
     * in the same request.
     */
    public function exactTotal(bool $enabled = true): self
    {
        $clone = clone $this;
        $clone->exactTotal = $enabled;

        return $clone;
    }

    /**
     * Keep only entities within $radiusKm of the point, measured exactly.
     *
     * This is the circle on its own. withinRadius() with a field adds the
     * geohash cells too, which makes it faster.
     */
    public function within(string $field, float $lat, float $lon, float $radiusKm): self
    {
        if (trim($field) === '') {
            throw new PulseIndexException('A position field name must not be empty.');
        }
        if ($radiusKm < 0.0) {
            throw new PulseIndexException(sprintf('A circle cannot have a radius of %s.', $radiusKm));
        }
        $clone = clone $this;
        $clone->geo = ['field' => $field, 'lat' => $lat, 'lon' => $lon, 'radiusKm' => $radiusKm];

        return $clone;
    }

    /**
     * Order the page by distance from the point, nearest first.
     *
     * Without a radius this is "the nearest K of whatever else matched".
     */
    public function nearest(string $field, float $lat, float $lon): self
    {
        if (trim($field) === '') {
            throw new PulseIndexException('A position field name must not be empty.');
        }
        $clone = clone $this;
        $clone->geo = [
            'field' => $field,
            'lat' => $lat,
            'lon' => $lon,
            'radiusKm' => $this->geo['radiusKm'] ?? 0.0,
        ];
        $clone->sort = ['field' => $field, 'descending' => false, 'byDistance' => true];

        return $clone;
    }

    public function toRequest(): SearchQueryRequest
    {
        $request = new SearchQueryRequest();
        $request->setTenantId($this->tenantId);
        $request->setLimit($this->limit);
        $request->setOffset($this->offset);
        $request->setExactTotal($this->exactTotal);

        $predicates = [];
        foreach ($this->filters as $filter) {
            $predicate = new FilterPredicate();
            $predicate->setOp($filter['op']);
            $predicate->setAttribute($filter['attribute']);
            $predicate->setGroup($filter['group']);
            $predicates[] = $predicate;
        }
        $request->setFilters($predicates);

        $rangeMessages = [];
        foreach ($this->ranges as $range) {
            $predicate = new RangePredicate();
            $predicate->setField($range['field']);
            $predicate->setMinVal($range['min']);
            $predicate->setMaxVal($range['max']);
            $rangeMessages[] = $predicate;
        }
        $request->setRanges($rangeMessages);

        if ($this->sort !== null) {
            $sort = new SortSpec();
            $sort->setField($this->sort['field']);
            $sort->setDescending($this->sort['descending']);
            $sort->setByDistance($this->sort['byDistance'] ?? false);
            $request->setSort($sort);
        }

        if ($this->geo !== null) {
            $geo = new GeoPredicate();
            $geo->setField($this->geo['field']);
            $geo->setLat($this->geo['lat']);
            $geo->setLon($this->geo['lon']);
            $geo->setRadiusKm($this->geo['radiusKm']);
            $request->setGeo($geo);
        }

        return $request;
    }

    /**
     * Execute via the bound Client when constructed from Client::query().
     */
    public function execute(): SearchResult
    {
        if ($this->client === null) {
            throw new Exception\PulseIndexException(
                'QueryBuilder has no Client; pass the builder to Client::search() or create it via Client::query().'
            );
        }

        return $this->client->search($this);
    }

    /**
     * @return array{
     *     tenant_id: string,
     *     limit: int,
     *     offset: int,
     *     filters: list<array{op: int, attribute: string, group: int}>,
     *     ranges: list<array{field: string, min: int, max: int}>,
     *     sort: array{field: string, descending: bool}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'exact_total' => $this->exactTotal,
            'geo' => $this->geo,
            'filters' => $this->filters,
            'ranges' => $this->ranges,
            'sort' => $this->sort,
        ];
    }

    private function filter(int $op, string $attribute, int $group = 0): self
    {
        $group = max(0, $group);

        $clone = clone $this;
        $clone->filters[] = [
            'op' => $op,
            'attribute' => $attribute,
            'group' => $group,
        ];
        // A caller naming their own group must not have it handed out again to
        // a radius later in the same chain.
        if ($group >= $clone->nextGroup) {
            $clone->nextGroup = $group + 1;
        }

        return $clone;
    }
}
