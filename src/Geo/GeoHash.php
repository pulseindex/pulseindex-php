<?php

declare(strict_types=1);

namespace PulseIndex\Geo;

use InvalidArgumentException;

/**
 * Lightweight GeoHash encoder/decoder for categorical geo tags.
 *
 * Index entities with {@see encodeMultiTags()} (e.g. `geo:5:ezs42`, `geo:6:ezs42e`)
 * and query via {@see \PulseIndex\QueryBuilder::whereGeoHash()} or
 * {@see \PulseIndex\QueryBuilder::withinRadius()}.
 *
 * Cell size at the equator (approx.): precision 6 ≈ 1.2×0.6km, precision 5 ≈ 4.9×4.9km,
 * precision 4 ≈ 39×19km.
 */
final class GeoHash
{
    public const TAG_PREFIX = 'geo:';

    public const MIN_PRECISION = 1;

    public const MAX_PRECISION = 12;

    /** Index both of these so radius queries can pick a matching granularity. */
    public const INDEX_PRECISIONS = [5, 6];

    private const BASE32 = '0123456789bcdefghjkmnpqrstuvwxyz';

    private const EARTH_RADIUS_KM = 6371.0;

    /**
     * Most cells one radius query may expand into. The precision is chosen so
     * the covering fits, so a covering is always complete or the request is
     * refused. Large enough for a 50 km radius up to 80 degrees of latitude.
     */
    public const COVERING_CELL_BUDGET = 2048;

    /**
     * How much area outside the circle a covering may carry before a finer
     * precision is worth its larger cell count.
     */
    public const ACCEPTABLE_COVER_RATIO = 3.0;

    /**
     * Neighbor charset keyed by direction then even/odd hash length (0 = even).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const NEIGHBORS = [
        'n' => ['p0r21436x8zb9dcf5h7kjnmqesgutwvy', 'bc01fg45238967deuvhjyznpkmstqrwx'],
        's' => ['14365h7k9dcfesgujnmqp0r2twvyx8zb', '238967debc01fg45kmstqrwxuvhjyznp'],
        'e' => ['bc01fg45238967deuvhjyznpkmstqrwx', 'p0r21436x8zb9dcf5h7kjnmqesgutwvy'],
        'w' => ['238967debc01fg45kmstqrwxuvhjyznp', '14365h7k9dcfesgujnmqp0r2twvyx8zb'],
    ];

    /**
     * Border charset keyed by direction then even/odd hash length (0 = even).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const BORDERS = [
        'n' => ['prxz', 'bcfguvyz'],
        's' => ['028b', '0145hjnp'],
        'e' => ['bcfguvyz', 'prxz'],
        'w' => ['0145hjnp', '028b'],
    ];

    /**
     * Encode coordinates to a GeoHash of the given precision (1–12).
     */
    public static function encode(float $lat, float $lon, int $precision = 6): string
    {
        self::assertLatitude($lat);
        self::assertLongitude($lon);
        self::assertPrecision($precision);

        $latMin = -90.0;
        $latMax = 90.0;
        $lonMin = -180.0;
        $lonMax = 180.0;
        $hash = '';
        $bit = 0;
        $ch = 0;
        $even = true;

        while (strlen($hash) < $precision) {
            if ($even) {
                $mid = ($lonMin + $lonMax) / 2.0;
                if ($lon >= $mid) {
                    $ch |= 1 << (4 - $bit);
                    $lonMin = $mid;
                } else {
                    $lonMax = $mid;
                }
            } else {
                $mid = ($latMin + $latMax) / 2.0;
                if ($lat >= $mid) {
                    $ch |= 1 << (4 - $bit);
                    $latMin = $mid;
                } else {
                    $latMax = $mid;
                }
            }

            $even = !$even;

            if ($bit < 4) {
                $bit++;
            } else {
                $hash .= self::BASE32[$ch];
                $bit = 0;
                $ch = 0;
            }
        }

        return $hash;
    }

    /**
     * Decode a GeoHash to the centre of its cell.
     *
     * @return array{lat: float, lon: float}
     */
    public static function decode(string $hash): array
    {
        $bounds = self::decodeBounds($hash);

        return [
            'lat' => ($bounds['latMin'] + $bounds['latMax']) / 2.0,
            'lon' => ($bounds['lonMin'] + $bounds['lonMax']) / 2.0,
        ];
    }

    /**
     * Decode a GeoHash to its bounding box.
     *
     * @return array{latMin: float, latMax: float, lonMin: float, lonMax: float}
     */
    public static function decodeBounds(string $hash): array
    {
        $hash = self::normalizeHash($hash);

        $latMin = -90.0;
        $latMax = 90.0;
        $lonMin = -180.0;
        $lonMax = 180.0;
        $even = true;

        $length = strlen($hash);
        for ($i = 0; $i < $length; $i++) {
            $cd = strpos(self::BASE32, $hash[$i]);
            if ($cd === false) {
                throw new InvalidArgumentException(sprintf('Invalid GeoHash character "%s".', $hash[$i]));
            }

            for ($mask = 16; $mask > 0; $mask >>= 1) {
                if ($even) {
                    $mid = ($lonMin + $lonMax) / 2.0;
                    if (($cd & $mask) !== 0) {
                        $lonMin = $mid;
                    } else {
                        $lonMax = $mid;
                    }
                } else {
                    $mid = ($latMin + $latMax) / 2.0;
                    if (($cd & $mask) !== 0) {
                        $latMin = $mid;
                    } else {
                        $latMax = $mid;
                    }
                }
                $even = !$even;
            }
        }

        return [
            'latMin' => $latMin,
            'latMax' => $latMax,
            'lonMin' => $lonMin,
            'lonMax' => $lonMax,
        ];
    }

    /**
     * Adjacent hash in a cardinal direction: n, s, e, or w.
     */
    public static function neighbor(string $hash, string $direction): string
    {
        $direction = strtolower($direction);
        if (!isset(self::NEIGHBORS[$direction])) {
            throw new InvalidArgumentException('Direction must be one of: n, s, e, w.');
        }

        return self::adjacent(self::normalizeHash($hash), $direction);
    }

    /**
     * Eight cells surrounding $hash (N, NE, E, SE, S, SW, W, NW).
     *
     * @return list<string>
     */
    public static function neighbors(string $hash): array
    {
        $hash = self::normalizeHash($hash);
        $north = self::adjacent($hash, 'n');
        $south = self::adjacent($hash, 's');
        $east = self::adjacent($hash, 'e');
        $west = self::adjacent($hash, 'w');

        return [
            $north,
            self::adjacent($north, 'e'),
            $east,
            self::adjacent($south, 'e'),
            $south,
            self::adjacent($south, 'w'),
            $west,
            self::adjacent($north, 'w'),
        ];
    }

    /**
     * The precision a radius query should cover at, at this point on the globe.
     *
     * Always one of {@see INDEX_PRECISIONS}, the precisions records are tagged
     * at. Of those it returns the coarsest whose complete covering fits
     * {@see COVERING_CELL_BUDGET} and is tight enough
     * ({@see ACCEPTABLE_COVER_RATIO}), falling back to the finest that fits.
     *
     * Latitude matters: a cell keeps its width in degrees, so it narrows in
     * kilometres toward the poles and the same radius needs more of them.
     *
     * @throws InvalidArgumentException when no indexed precision can cover the
     *         radius within the budget, refused rather than half-covered.
     */
    public static function optimalPrecisionForRadius(float $radiusKm, float $lat = 0.0, float $lon = 0.0): int
    {
        if ($radiusKm < 0.0) {
            throw new InvalidArgumentException('Radius must be non-negative.');
        }

        $precisions = self::INDEX_PRECISIONS;
        sort($precisions);           // coarsest first
        $budget = self::COVERING_CELL_BUDGET;

        $fallback = null;
        foreach ($precisions as $precision) {
            // One past the budget is enough to know it does not fit, and stops
            // a 100 km radius from walking six thousand cells to find out.
            $cells = self::walkCovering($lat, $lon, $radiusKm, $precision, $budget + 1);
            if (count($cells) > $budget) {
                continue;
            }

            // Coarsest first, and stop at the first one that is accurate
            // enough. The finer cell only earns its cost when the coarser one
            // is genuinely loose.
            if ($radiusKm > 0.0 && self::coveredRatio($cells, $radiusKm) <= self::ACCEPTABLE_COVER_RATIO) {
                return $precision;
            }
            $fallback = $precision;   // fits, but looser than we would like
        }

        // Nothing hit the target; the finest that fits is the tightest on offer.
        if ($fallback !== null) {
            return $fallback;
        }

        throw new InvalidArgumentException(sprintf(
            'A %s km radius at latitude %.1f needs more than %d geohash cells at every indexed '
            . 'precision (%s). Geohash cells narrow toward the poles, so the same radius costs '
            . 'more cells the further from the equator it is asked. Use a smaller radius, move '
            . 'the search nearer the equator, or index a coarser precision.',
            rtrim(rtrim(number_format($radiusKm, 2, '.', ''), '0'), '.'),
            $lat,
            $budget,
            implode(', ', self::INDEX_PRECISIONS),
        ));
    }

    /**
     * @see optimalPrecisionForRadius()
     */
    public static function precisionForRadius(float $radiusKm, float $lat = 0.0, float $lon = 0.0): int
    {
        return self::optimalPrecisionForRadius($radiusKm, $lat, $lon);
    }

    /**
     * GeoHashes whose cells cover the search circle.
     *
     * The covering is always complete, or the call refuses.
     *
     * Passing $precision explicitly overrides the choice, and is checked
     * against {@see INDEX_PRECISIONS}: entities carry tags only at those, so
     * any other precision matches nothing at all rather than matching loosely.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException on a negative radius, a precision
     *         nothing is indexed at, or a radius too large to cover.
     */
    public static function getCoveringHashes(float $lat, float $lon, float $radiusKm, ?int $precision = null): array
    {
        if ($radiusKm < 0.0) {
            throw new InvalidArgumentException('Radius must be non-negative.');
        }
        self::assertLatitude($lat);
        self::assertLongitude($lon);

        if ($precision === null) {
            $precision = self::optimalPrecisionForRadius($radiusKm, $lat, $lon);
        } else {
            self::assertPrecision($precision);
            if (!in_array($precision, self::INDEX_PRECISIONS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Precision %d is not indexed, so a covering at it matches nothing. Indexed precisions: %s.',
                    $precision,
                    implode(', ', self::INDEX_PRECISIONS),
                ));
            }
        }

        return self::walkCovering($lat, $lon, $radiusKm, $precision, null);
    }

    /**
     * Every cell at $precision that intersects the circle, breadth-first from
     * the centre and expanding only through cells that intersect.
     *
     * $limit exists only so the precision chooser can stop early once a
     * precision is known not to fit; a null limit walks the covering to
     * completion, which is what every caller that wants an answer passes.
     *
     * @return list<string>
     */
    private static function walkCovering(float $lat, float $lon, float $radiusKm, int $precision, ?int $limit): array
    {
        $covering = [];
        $visited = [];
        $queue = [self::encode($lat, $lon, $precision)];

        while ($queue !== []) {
            $hash = array_shift($queue);
            if (isset($visited[$hash])) {
                continue;
            }
            $visited[$hash] = true;

            if (!self::cellIntersectsCircle($hash, $lat, $lon, $radiusKm)) {
                continue;
            }

            $covering[] = $hash;
            if ($limit !== null && count($covering) >= $limit) {
                return $covering;
            }

            foreach (self::neighbors($hash) as $neighbor) {
                if (!isset($visited[$neighbor])) {
                    $queue[] = $neighbor;
                }
            }
        }

        return $covering;
    }

    /**
     * Categorical tag `geo:{precision}:{hash}`. Idempotent if already prefixed.
     */
    public static function tag(string $geohash): string
    {
        $hash = self::normalizeHash($geohash);

        return self::TAG_PREFIX . strlen($hash) . ':' . $hash;
    }

    /**
     * Encode coordinates and return the namespaced categorical tag used at index and query time.
     */
    public static function encodeTag(float $lat, float $lon, int $precision = 6): string
    {
        return self::tag(self::encode($lat, $lon, $precision));
    }

    /**
     * Dual-granularity index tags (precision 5 and 6) so radius queries can match
     * without colliding with other `geo:` namespaces.
     *
     * @return list<string>
     */
    public static function encodeMultiTags(float $lat, float $lon): array
    {
        $tags = [];
        foreach (self::INDEX_PRECISIONS as $precision) {
            $tags[] = self::encodeTag($lat, $lon, $precision);
        }

        return $tags;
    }

    private static function cellIntersectsCircle(string $hash, float $lat, float $lon, float $radiusKm): bool
    {
        $bounds = self::decodeBounds($hash);
        $closestLat = min(max($lat, $bounds['latMin']), $bounds['latMax']);
        $closestLon = self::closestLongitude($lon, $bounds['lonMin'], $bounds['lonMax']);

        return self::haversineKm($lat, $lon, $closestLat, $closestLon) <= $radiusKm;
    }

    /**
     * The longitude in [$lonMin, $lonMax] nearest to $lon, going the short way
     * round the globe.
     *
     * A plain clamp is wrong at the antimeridian, because -180 and +180 are the
     * same meridian and a numeric comparison does not know it.
     *
     * Working in deltas normalised to +/-180 removes the discontinuity: the
     * cell either straddles the query meridian, or it lies wholly to one side
     * of it and the nearer edge is the answer.
     */
    private static function closestLongitude(float $lon, float $lonMin, float $lonMax): float
    {
        $toMin = self::normalizeLonDelta($lonMin - $lon);
        $toMax = self::normalizeLonDelta($lonMax - $lon);

        // Straddles the query's own meridian, so that is the closest point.
        // A geohash cell never spans more than 180 degrees, so this reads
        // correctly on either side of the line.
        if ($toMin <= 0.0 && $toMax >= 0.0) {
            return $lon;
        }

        return abs($toMin) <= abs($toMax) ? $lonMin : $lonMax;
    }

    /**
     * Covered area divided by the circle's, so a precision can be judged on
     * what it wastes rather than only on what it costs.
     *
     * @param list<string> $cells
     */
    private static function coveredRatio(array $cells, float $radiusKm): float
    {
        $covered = 0.0;
        foreach ($cells as $hash) {
            $b = self::decodeBounds($hash);
            $covered += (self::EARTH_RADIUS_KM * deg2rad($b['latMax'] - $b['latMin']))
                * (self::EARTH_RADIUS_KM
                    * cos(deg2rad(($b['latMax'] + $b['latMin']) / 2.0))
                    * deg2rad($b['lonMax'] - $b['lonMin']));
        }

        return $covered / (M_PI * $radiusKm ** 2);
    }

    /** A longitude difference folded into [-180, 180]. */
    private static function normalizeLonDelta(float $delta): float
    {
        $delta = fmod($delta + 180.0, 360.0);
        if ($delta < 0.0) {
            $delta += 360.0;
        }

        return $delta - 180.0;
    }

    /**
     * Great-circle distance in kilometres.
     *
     * Public because its TypeScript twin is, and these two are one contract
     * with two implementations. A caller checking a returned id against the
     * circle they asked for could do it in JavaScript and not in PHP.
     */
    public static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2.0) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2.0) ** 2;

        return 2.0 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }

    private static function adjacent(string $hash, string $direction): string
    {
        if ($hash === '') {
            throw new InvalidArgumentException('GeoHash must not be empty.');
        }

        $lastChar = $hash[strlen($hash) - 1];
        $type = strlen($hash) % 2;
        $parent = substr($hash, 0, -1);

        if ($parent !== '' && str_contains(self::BORDERS[$direction][$type], $lastChar)) {
            $parent = self::adjacent($parent, $direction);
        }

        $index = strpos(self::NEIGHBORS[$direction][$type], $lastChar);
        if ($index === false) {
            throw new InvalidArgumentException(sprintf('Invalid GeoHash character "%s".', $lastChar));
        }

        return $parent . self::BASE32[$index];
    }

    private static function normalizeHash(string $hash): string
    {
        $normalized = strtolower(trim($hash));
        if (str_starts_with($normalized, self::TAG_PREFIX)) {
            $normalized = substr($normalized, strlen(self::TAG_PREFIX));
        }

        if (preg_match('/^([1-9]|1[0-2]):([' . self::BASE32 . ']+)$/', $normalized, $matches) === 1) {
            $normalized = $matches[2];
        }

        if ($normalized === '') {
            throw new InvalidArgumentException('GeoHash must not be empty.');
        }

        if (strspn($normalized, self::BASE32) !== strlen($normalized)) {
            throw new InvalidArgumentException(sprintf('Invalid GeoHash "%s".', $normalized));
        }

        return $normalized;
    }

    private static function assertLatitude(float $lat): void
    {
        if ($lat < -90.0 || $lat > 90.0) {
            throw new InvalidArgumentException('Latitude must be between -90 and 90.');
        }
    }

    private static function assertLongitude(float $lon): void
    {
        if ($lon < -180.0 || $lon > 180.0) {
            throw new InvalidArgumentException('Longitude must be between -180 and 180.');
        }
    }

    private static function assertPrecision(int $precision): void
    {
        if ($precision < self::MIN_PRECISION || $precision > self::MAX_PRECISION) {
            throw new InvalidArgumentException(sprintf(
                'GeoHash precision must be between %d and %d.',
                self::MIN_PRECISION,
                self::MAX_PRECISION
            ));
        }
    }
}
