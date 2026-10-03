<?php

declare(strict_types=1);

namespace PulseIndex;

final class SearchResult
{
    /**
     * @param list<int> $matchedEntityIds
     * @param bool $totalIsExact Whether $totalMatches is the real number of
     *        matches or a lower bound from a paged search.
     */
    public function __construct(
        public readonly array $matchedEntityIds,
        public readonly int $totalMatches,
        public readonly int $executionTimeUs,
        public readonly bool $totalIsExact = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->matchedEntityIds === [];
    }

    public function count(): int
    {
        return count($this->matchedEntityIds);
    }

    /**
     * The total, but only when it can be relied on.
     *
     * Returns null rather than a number that looks right and is not, so
     * "page 1 of N" cannot be built out of a lower bound by accident.
     * Use {@see \PulseIndex\Client::searchWithTotal()} to get one.
     */
    public function exactTotal(): ?int
    {
        return $this->totalIsExact ? $this->totalMatches : null;
    }
}
