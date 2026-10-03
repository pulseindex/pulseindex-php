<?php

declare(strict_types=1);

namespace PulseIndex\Text;

/**
 * What a tokenizer-version check found.
 *
 * `indexVersion` is null when the tenant carries no text-indexed records at
 * all, which is not a mismatch and is not refused: an index that has been given
 * nothing has nothing to be wrong about, and refusing there would fail a first
 * query before a first write.
 */
final class TokenizerCheck
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $indexVersion,
        public readonly int $sdkVersion,
    ) {
    }
}
