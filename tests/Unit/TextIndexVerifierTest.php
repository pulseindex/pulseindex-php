<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PulseIndex\ClientInterface;
use PulseIndex\Entity;
use PulseIndex\QueryBuilder;
use PulseIndex\SearchResult;
use PulseIndex\Text\Text;
use PulseIndex\Text\TextIndexVerifier;
use PulseIndex\Text\TokenizerMismatch;

/**
 * `Text::indexTokens` puts `tv:1` on each record so that an index built by one
 * tokenizer and queried by another is named rather than silently returning
 * nothing. These tests hold the decision the verifier makes from it.
 */
final class TextIndexVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        // The cache is static and keyed by client instance, so a sibling test
        // must not be able to answer this one's question.
        TextIndexVerifier::forget();
    }

    /**
     * A fake that knows only which tag was asked for.
     *
     * The verifier asks exactly one question, "does any record carry tv:n", so
     * a fake that answers it is the whole surface.
     *
     * @param  list<int>  $versions
     */
    private function engineHolding(array $versions): ClientInterface
    {
        return new class($versions) implements ClientInterface {
            /** @var list<string> */
            public array $calls = [];

            /** @param list<int> $versions */
            public function __construct(private array $versions)
            {
            }

            public function query(): QueryBuilder
            {
                return new QueryBuilder($this);
            }

            public function search(QueryBuilder $query): SearchResult
            {
                $tag = $query->toArray()['filters'][0]['attribute'] ?? '';
                $this->calls[] = $tag;
                $n = (int) str_replace(Text::VERSION_PREFIX, '', $tag);

                return new SearchResult(
                    in_array($n, $this->versions, true) ? ['1'] : [],
                    0,
                    0,
                    true
                );
            }

            public function searchWithTotal(QueryBuilder $query): SearchResult
            {
                return $this->search($query);
            }

            public function indexEntity(
                int $entityId,
                array $categories = [],
                array $numbers = [],
                array $points = [],
                string $tenantId = '',
            ): bool {
                return true;
            }

            public function index(Entity $entity): bool
            {
                return true;
            }

            public function batchIndex(array $entities): int
            {
                return 0;
            }

            public function deleteEntity(int $entityId, string $tenantId = ''): bool
            {
                return true;
            }

            public function batchDelete(array $entityIds, string $tenantId = ''): int
            {
                return 0;
            }

            public function servingStatus(string $service = ''): int
            {
                return 1;
            }

            public function health(): bool
            {
                return true;
            }
        };
    }

    public function testPassesWhenTheIndexWasWrittenByThisSameTokenizer(): void
    {
        $engine = $this->engineHolding([Text::TOKENIZER_VERSION]);
        $check = TextIndexVerifier::verify($engine);

        self::assertTrue($check->ok);
        self::assertSame(Text::TOKENIZER_VERSION, $check->indexVersion);
        // Agreement happens on every boot, so it must cost one query.
        self::assertSame([Text::VERSION_TAG], $engine->calls);
    }

    public function testRefusesAnIndexWrittenByANewerTokenizer(): void
    {
        // Only the newer direction is testable while TOKENIZER_VERSION is 1,
        // because nothing older than 1 exists to hold. The mechanism is one
        // loop and does not care which side of the current version it finds.
        $engine = $this->engineHolding([Text::TOKENIZER_VERSION + 2]);

        $this->expectException(TokenizerMismatch::class);
        $this->expectExceptionMessageMatches('/version ' . (Text::TOKENIZER_VERSION + 2) . '/');
        TextIndexVerifier::verify($engine);
    }

    public function testTheRefusalCarriesBothVersionsAsNumbers(): void
    {
        // A caller deciding whether to re-index needs to branch on something
        // better than a substring of a sentence.
        try {
            TextIndexVerifier::verify($this->engineHolding([Text::TOKENIZER_VERSION + 1]));
            self::fail('expected a TokenizerMismatch');
        } catch (TokenizerMismatch $e) {
            self::assertSame(Text::TOKENIZER_VERSION + 1, $e->indexVersion);
            self::assertSame(Text::TOKENIZER_VERSION, $e->sdkVersion);
        }
    }

    public function testDoesNotRefuseATenantThatCarriesNoTextRecordsAtAll(): void
    {
        // An index given nothing has nothing to be wrong about, and refusing
        // here would fail a first query before a first write.
        $check = TextIndexVerifier::verify($this->engineHolding([]));

        self::assertTrue($check->ok);
        self::assertNull($check->indexVersion);
    }

    public function testAsksOncePerClientAndTenantBecauseBootCodeCallsItInALoop(): void
    {
        $engine = $this->engineHolding([Text::TOKENIZER_VERSION]);
        TextIndexVerifier::verify($engine);
        TextIndexVerifier::verify($engine);
        TextIndexVerifier::verify($engine);
        self::assertCount(1, $engine->calls);

        // A different tenant on the same client is a different index.
        TextIndexVerifier::verify($engine, 'another-tenant');
        self::assertCount(2, $engine->calls);
    }

    public function testTheTagItLooksForIsTheTagIndexTokensWrites(): void
    {
        // Two constants and a probe built separately. If they ever disagree the
        // check answers confidently about a tag nothing carries, which is the
        // silent pass this whole file exists to prevent.
        $engine = $this->engineHolding([Text::TOKENIZER_VERSION]);
        TextIndexVerifier::verify($engine);

        self::assertContains($engine->calls[0], Text::indexTokens('Müller'));
    }
}
