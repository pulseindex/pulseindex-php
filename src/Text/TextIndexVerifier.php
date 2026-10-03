<?php

declare(strict_types=1);

namespace PulseIndex\Text;

use PulseIndex\ClientInterface;
use WeakMap;

/**
 * Confirm that this SDK generates the tokens an index was built with.
 *
 * `Text::indexTokens` writes `tv:<n>` on every record so a mismatch can be
 * named. PulseIndex holds no schema, so the SDKs are the side that checks it.
 *
 * # What it catches
 *
 * An index built by one tokenizer and queried by another. Normalisation, the
 * prefix range and the tag shape all decide which tags are written, so a change
 * to any of them means every query built by the new version misses every record
 * written by the old one. No error, no warning, an empty page.
 *
 * # How to use it
 *
 * Once, at boot, not per query. The cost is one search when the versions agree,
 * and the answer is cached per client and tenant, so calling it again is free.
 */
final class TextIndexVerifier
{
    /**
     * Keyed by client and weak, so a client that goes out of scope is collected
     * rather than pinned here for the life of the process by its own cache
     * entry. Same shape as the TypeScript side's WeakMap, deliberately.
     *
     * @var WeakMap<object, array<string, TokenizerCheck>>|null
     */
    private static ?WeakMap $cache = null;

    /**
     * @throws TokenizerMismatch when the index really was built by another version
     */
    public static function verify(ClientInterface $client, ?string $tenantId = null): TokenizerCheck
    {
        self::$cache ??= new WeakMap();
        $key = $tenantId ?? '';
        $perTenant = self::$cache[$client] ?? [];
        if (isset($perTenant[$key])) {
            return $perTenant[$key];
        }

        // Mine first, because agreement is the case that happens on every boot
        // and it costs exactly one query.
        if (self::present($client, Text::TOKENIZER_VERSION, $tenantId)) {
            return self::remember($client, $key, new TokenizerCheck(
                true,
                Text::TOKENIZER_VERSION,
                Text::TOKENIZER_VERSION
            ));
        }

        // Older and newer both happen. Older is an index that predates an SDK
        // upgrade; newer is two services on different SDK versions writing and
        // reading one tenant, which is the case nobody plans for.
        foreach (Text::probeVersions() as $v) {
            if ($v === Text::TOKENIZER_VERSION) {
                continue;
            }
            if (self::present($client, $v, $tenantId)) {
                throw new TokenizerMismatch($v, Text::TOKENIZER_VERSION);
            }
        }

        // No version present anywhere: the tenant carries no text-indexed
        // records. Not a mismatch, and refusing here would fail a first query
        // before a first write.
        return self::remember($client, $key, new TokenizerCheck(
            true,
            null,
            Text::TOKENIZER_VERSION
        ));
    }

    /** Forget what was cached, for a test or a tenant that was re-indexed. */
    public static function forget(): void
    {
        self::$cache = null;
    }

    private static function present(ClientInterface $client, int $version, ?string $tenantId): bool
    {
        $query = $client->query()
            ->must(Text::VERSION_PREFIX . $version)
            ->limit(1);
        if ($tenantId !== null) {
            $query = $query->tenant($tenantId);
        }

        return $client->search($query)->matchedEntityIds !== [];
    }

    private static function remember(
        ClientInterface $client,
        string $key,
        TokenizerCheck $check,
    ): TokenizerCheck {
        $perTenant = self::$cache[$client] ?? [];
        $perTenant[$key] = $check;
        self::$cache[$client] = $perTenant;

        return $check;
    }
}
