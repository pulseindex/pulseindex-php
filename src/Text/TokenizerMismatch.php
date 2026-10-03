<?php

declare(strict_types=1);

namespace PulseIndex\Text;

use PulseIndex\Exception\PulseIndexException;

/**
 * An index built by one tokenizer and queried by another.
 *
 * Its own class rather than a message on the base exception, because the only
 * useful reaction to it is a re-index or a version pin, and a caller deciding
 * that has to branch on something more reliable than string matching.
 */
final class TokenizerMismatch extends PulseIndexException
{
    public function __construct(
        public readonly int $indexVersion,
        public readonly int $sdkVersion,
    ) {
        parent::__construct(sprintf(
            'this index was built by text tokenizer version %d and this SDK generates '
            . 'version %d; the tokens do not match, so every text query would return an '
            . 'empty page. Re-index with this SDK, or pin the SDK version that wrote it',
            $indexVersion,
            $sdkVersion
        ));
    }
}
