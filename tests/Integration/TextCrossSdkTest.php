<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PulseIndex\Client;
use PulseIndex\Exception\GrpcException;
use PulseIndex\Text\Text;
use PulseIndex\Text\TextIndexVerifier;
use PulseIndex\Text\TokenizerMismatch;

/**
 * The two SDKs' tokenizers, checked against each other through a real server.
 *
 * `TextTest` asserts this SDK against a fixture, and the TypeScript SDK asserts
 * itself against the same one. Both can pass while the pair is broken: a
 * fixture regenerated from one side records whatever that side does.
 *
 * So this one writes records with the TypeScript tokenizer's own output and
 * reads them back with tokens this SDK generates. If the two ever fold a name
 * differently, the query returns nothing and this fails.
 *
 * The TypeScript-written tokens are taken from the shared fixture rather than
 * by running node, so this needs a server and not a second toolchain.
 *
 *   PULSEINDEX_HOST=127.0.0.1:50055 vendor/bin/phpunit --testsuite Integration
 */
final class TextCrossSdkTest extends TestCase
{
    private Client $client;

    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('grpc')) {
            self::markTestSkipped('ext-grpc is not loaded');
        }
        if (!extension_loaded('intl')) {
            self::markTestSkipped('ext-intl is not loaded, which Text refuses to run without');
        }

        $host = getenv('PULSEINDEX_HOST') ?: 'localhost:50051';
        $apiKey = getenv('PULSEINDEX_API_KEY') ?: 'dev-key';

        $this->client = Client::create($host, $apiKey);
        $this->tenant = 'php-text-' . bin2hex(random_bytes(4));

        try {
            $this->client->indexEntity(
                entityId: 1,
                categories: ['feature:warmup'],
                tenantId: $this->tenant,
            );
        } catch (GrpcException $e) {
            self::markTestSkipped('PulseIndex gRPC server unreachable: ' . $e->getMessage());
        }

        TextIndexVerifier::forget();
    }

    /** @return list<array<string, mixed>> */
    private function vectors(): array
    {
        $raw = (string) file_get_contents(__DIR__ . '/../Fixtures/text-token-vectors.json');

        /** @var list<array<string, mixed>> $v */
        $v = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $v;
    }

    public function testTokensThisSdkGeneratesFindRecordsTheTypeScriptSdkWrote(): void
    {
        // Written with the TypeScript SDK's tokens, verbatim from the fixture.
        $id = 10;
        $written = [];
        foreach ($this->vectors() as $v) {
            /** @var list<string> $tokens */
            $tokens = $v['indexTokens'];
            $this->client->indexEntity(
                entityId: $id,
                categories: $tokens,
                tenantId: $this->tenant,
            );
            $written[$v['input']] = $id;
            $id++;
        }

        // Read back with tokens this SDK generates from the original strings.
        //
        // Per term, because that is what a typeahead is: someone types one
        // word. `prefixTags` on a whole phrase strips the spaces and asks for
        // the phrase as one long term, which is correct and is not a typeahead.
        foreach ($this->vectors() as $v) {
            $input = (string) $v['input'];
            $expected = $written[$input];

            foreach (Text::terms($input) as $term) {
                $len = mb_strlen($term, 'UTF-8');
                // Every prefix length the write side stores, plus the exact
                // term. Each one is a separate chance for the two SDKs to have
                // folded the same word differently.
                $lengths = range(min(Text::MIN_PREFIX, $len), min($len, Text::MAX_PREFIX));
                foreach ($lengths as $n) {
                    if ($n < Text::MIN_PREFIX) {
                        continue;
                    }
                    $this->assertFinds(
                        $expected,
                        Text::prefixTags(mb_substr($term, 0, $n, 'UTF-8')),
                        sprintf('typing %d letters of %s from %s', $n, $term, var_export($input, true))
                    );
                }
                $this->assertFinds(
                    $expected,
                    Text::termTags($term),
                    sprintf('the exact term %s from %s', $term, var_export($input, true))
                );
            }
        }
    }

    /**
     * @param  list<string>  $tags
     */
    private function assertFinds(int $expected, array $tags, string $what): void
    {
        self::assertNotEmpty($tags, "$what produced no tags at all");

        $query = $this->client->query()->tenant($this->tenant)->limit(50);
        foreach ($tags as $tag) {
            $query = $query->should($tag);
        }

        $ids = array_map('intval', $this->client->search($query)->matchedEntityIds);
        self::assertContains(
            $expected,
            $ids,
            sprintf('%s: PHP asked for %s and found nothing the TypeScript SDK wrote', $what, implode(',', $tags))
        );
    }

    public function testATypeaheadFindsAGermanNameHoweverTheUmlautWasTyped(): void
    {
        // The bug the shared fixture caught before it shipped: folding "Müller"
        // to "muller" only, so anyone typing "mueller" on a keyboard without
        // umlauts got an empty page.
        $this->client->indexEntity(
            entityId: 100,
            categories: Text::indexTokens('Dr. Andreas Müller'),
            tenantId: $this->tenant,
        );

        foreach (['mul', 'mue', 'muell', 'muller', 'mueller'] as $typed) {
            $query = $this->client->query()->tenant($this->tenant)->limit(20);
            foreach (Text::prefixTags($typed) as $tag) {
                $query = $query->should($tag);
            }
            $ids = array_map('intval', $this->client->search($query)->matchedEntityIds);
            self::assertContains(100, $ids, "typing '$typed' must find the name");
        }
    }

    public function testTheVersionCheckPassesAgainstRecordsARealEngineHolds(): void
    {
        $this->client->indexEntity(
            entityId: 200,
            categories: Text::indexTokens('Zahnarzt'),
            tenantId: $this->tenant,
        );

        $check = TextIndexVerifier::verify($this->client, $this->tenant);
        self::assertTrue($check->ok);
        self::assertSame(Text::TOKENIZER_VERSION, $check->indexVersion);
    }

    public function testTheVersionCheckRefusesRecordsReallyWrittenUnderAnotherVersion(): void
    {
        // Not a fake and not a stub: records carrying a version tag this SDK
        // does not generate, which is byte for byte what an SDK upgrade leaves
        // behind.
        $future = Text::TOKENIZER_VERSION + 1;
        $tokens = array_values(array_filter(
            Text::indexTokens('Müller'),
            static fn (string $t): bool => $t !== Text::VERSION_TAG
        ));
        $tokens[] = Text::VERSION_PREFIX . $future;

        $other = 'php-text-wrong-' . bin2hex(random_bytes(4));
        $this->client->indexEntity(entityId: 1, categories: $tokens, tenantId: $other);

        $this->expectException(TokenizerMismatch::class);
        TextIndexVerifier::verify($this->client, $other);
    }

    public function testTheVersionCheckDoesNotRefuseATenantHoldingNoTextRecords(): void
    {
        $virgin = 'php-text-empty-' . bin2hex(random_bytes(4));
        $this->client->indexEntity(entityId: 1, categories: ['city:berlin'], tenantId: $virgin);

        $check = TextIndexVerifier::verify($this->client, $virgin);
        self::assertTrue($check->ok);
        self::assertNull($check->indexVersion);
    }

    public function testATypeaheadFindsARecordFromMoreThanOneTypedWordInAnyOrder(): void
    {
        foreach ([1 => 'Dr. Andreas Müller', 2 => 'Dr. Thomas Mueller', 3 => 'Dr. Anna Schröder'] as $id => $name) {
            $this->client->indexEntity(
                entityId: 200 + $id,
                categories: Text::indexTokens($name),
                tenantId: $this->tenant,
            );
        }
        $found = function (string $typed): array {
            $query = $this->client->query()->tenant($this->tenant)->limit(20)->typeahead($typed);
            $ids = array_map('intval', $this->client->search($query)->matchedEntityIds);
            sort($ids);

            return $ids;
        };

        self::assertSame([201], $found('andreas mue'));
        self::assertSame([201], $found('mue andreas'));
        self::assertSame([202], $found('dr thomas mü'));
        self::assertSame([201, 202], $found('dr mue'));
        self::assertSame([], $found('andreas sch'));
    }
}
