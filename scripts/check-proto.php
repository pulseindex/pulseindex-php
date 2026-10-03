#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Check the proto against its generated stubs, and against the service's own
 * copy when PULSEINDEX_PROTO names it.
 *
 * Usage:
 *   composer check:proto                     # skip loudly without PULSEINDEX_PROTO
 *   composer check:proto -- --require-engine # no PULSEINDEX_PROTO is a FAILURE
 *   PULSEINDEX_PROTO=/path/to/engine.proto PULSEINDEX_PROTO_OMIT=RpcA,RpcB composer check:proto
 *
 * PULSEINDEX_PROTO_OMIT names the RPCs this copy leaves out on purpose. Any
 * other RPC missing from it is an error.
 */

$root = dirname(__DIR__);
$vendored = $root . '/proto/engine.proto';
$requireEngine = in_array('--require-engine', array_slice($argv, 1), true);

require $root . '/vendor/autoload.php';

use PulseIndex\Tests\Support\ProtoSchema;

/** php_* options are local to this SDK and are never present upstream. */
$normalise = static function (string $text, bool $forHash = false): string {
    // Comments never reach the generated stubs.
    $text = (string) preg_replace('~//.*~', '', $text);
    $lines = preg_split('~\R~', $text) ?: [];
    $kept = array_filter($lines, static function (string $l) use ($forHash): bool {
        if (preg_match('~^\s*option\s+php_~', $l) === 1) {
            return false;
        }

        // Blank lines go in both paths. Stripping comments above turns every
        // comment-only line into an empty one, and the two files carry
        // different amounts of prose by design, so keeping blanks would
        // compare documentation volume rather than declarations.
        return trim($l) !== '';
    });

    $joined = implode("\n", $kept);

    return $forHash ? $joined . "\n" : rtrim(preg_replace('~[ \t]+$~m', '', $joined) ?? '');
};

// ---------------------------------------------------------------------------
// (1) Stub consistency: does the vendored proto still match what was last
//     compiled into generated/? Neither the schema fixture nor the engine diff
//     covers this, a hand-edited proto leaves the generated stubs stale.
// ---------------------------------------------------------------------------
$hashFile = $root . '/proto/engine.proto.sha256';
$vendorText = (string) file_get_contents($vendored);

if (!is_file($hashFile)) {
    fwrite(STDERR, "error: {$hashFile} is missing, run 'composer build-proto'.\n");
    exit(1);
}

$have = hash('sha256', $normalise($vendorText, true));
$want = trim((string) file_get_contents($hashFile));

if ($have !== $want) {
    fwrite(STDERR, "error: proto/engine.proto was edited without regenerating.\n");
    fwrite(STDERR, "       The stubs in generated/ no longer correspond to it.\n");
    fwrite(STDERR, "       Run 'composer build-proto' and commit the result.\n");
    exit(1);
}

echo "ok: vendored proto matches the stubs generated from it\n";

// ---------------------------------------------------------------------------
// (2) Comparison with the service's own copy.
// ---------------------------------------------------------------------------
$source = getenv('PULSEINDEX_PROTO');

if (!is_string($source) || $source === '') {
    $message = "NOT VERIFIED: PULSEINDEX_PROTO is not set, so this copy was not compared\n"
        . "              against the service's. This check has established nothing about\n"
        . "              whether the service has moved ahead.";

    if ($requireEngine) {
        fwrite(STDERR, "error: --require-engine was given but PULSEINDEX_PROTO is not set.\n");
        fwrite(STDERR, $message . "\n");
        exit(1);
    }

    fwrite(STDERR, $message . "\n");
    fwrite(STDERR, "       Run with --require-engine to make this state an error.\n");
    exit(0);
}

// A configured path that does not exist is a misconfiguration, not a skip.
if (!is_file($source)) {
    fwrite(STDERR, "error: PULSEINDEX_PROTO points at a path that does not exist: {$source}\n");
    exit(1);
}

$omitted = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) getenv('PULSEINDEX_PROTO_OMIT')),
), static fn (string $name): bool => $name !== ''));

$engineText = (string) file_get_contents($source);

$diff = ProtoSchema::subsetDiff(
    ProtoSchema::parse($engineText),
    ProtoSchema::parse($vendorText),
    $omitted,
);

if ($diff !== []) {
    fwrite(STDERR, "error: proto/engine.proto is out of sync with the service.\n\n");
    fwrite(STDERR, "       schema differences (service -> this copy):\n");
    foreach ($diff as $line) {
        fwrite(STDERR, "         {$line}\n");
    }
    fwrite(STDERR, "\n       fix: bring the declarations in line, run composer build-proto,\n");
    fwrite(STDERR, "       then update the fixture in tests/Unit/ProtoSchemaTest.php so the\n");
    fwrite(STDERR, "       change is visible in review, and check whether the SDK must read\n");
    fwrite(STDERR, "       any new or renamed field.\n");
    exit(1);
}

echo "ok: every declaration in the proto matches the service\n";
