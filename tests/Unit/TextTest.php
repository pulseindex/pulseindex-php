<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PulseIndex\Text\Text;

/**
 * The PHP half of a contract whose other half is in the TypeScript SDK.
 *
 * Two implementations of one token format cannot be checked against each other
 * by either one of them, so both are checked against the same fixture: `tests/Fixtures/text-token-vectors.json`, generated from the
 * TypeScript side and copied here unchanged.
 *
 * The cases in it are not decoration. "Müller" is the fold that decides whether
 * a German directory works, "İstanbul" is a capital I that lowercases to two
 * code points, and "O’Brien" is a typographic apostrophe that is not an ASCII
 * one. Each was a way for two implementations to quietly disagree.
 */
final class TextTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function vectors(): array
    {
        $raw = file_get_contents(__DIR__ . '/../Fixtures/text-token-vectors.json');
        self::assertIsString($raw);
        /** @var list<array<string, mixed>> $vectors */
        $vectors = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $vectors;
    }

    public function testTheFixtureIsActuallyPresentAndNotEmpty(): void
    {
        // A fixture-driven test whose fixture failed to load passes every
        // assertion by running none of them, which is the way this whole family
        // of test fails without anyone noticing.
        self::assertGreaterThanOrEqual(10, count(self::vectors()));
    }

    public function testNormalisationMatchesTheTypeScriptSdkExactly(): void
    {
        foreach (self::vectors() as $v) {
            self::assertSame(
                $v['normalized'],
                Text::normalize($v['input']),
                sprintf('normalize(%s)', var_export($v['input'], true))
            );
        }
    }

    public function testTermsMatchTheTypeScriptSdkExactly(): void
    {
        foreach (self::vectors() as $v) {
            self::assertSame(
                $v['terms'],
                Text::terms($v['input']),
                sprintf('terms(%s)', var_export($v['input'], true))
            );
        }
    }

    public function testTheTagsAQueryAsksForMatchTheTypeScriptSdk(): void
    {
        foreach (self::vectors() as $v) {
            self::assertSame($v['termTag'], Text::termTag($v['input']));
            self::assertSame($v['prefixTag'], Text::prefixTag($v['input']));
        }
    }

    public function testEveryTokenWrittenAtIndexTimeMatchesTheTypeScriptSdk(): void
    {
        foreach (self::vectors() as $v) {
            $mine = Text::indexTokens($v['input']);
            sort($mine);
            self::assertSame(
                $v['indexTokens'],
                $mine,
                sprintf('indexTokens(%s)', var_export($v['input'], true))
            );
        }
    }

    public function testTheSpellingExpansionIsTheSameSize(): void
    {
        // The set itself is up to 960 tags, so the fixture carries its size
        // rather than its contents. A difference in the expansion rule moves
        // this number; a difference in one tag is caught by the round trip
        // below instead.
        foreach (self::vectors() as $v) {
            self::assertSame(
                $v['spellingCount'],
                count(Text::spellingTags($v['input'])),
                sprintf('spellingTags(%s)', var_export($v['input'], true))
            );
        }
    }

    /**
     * The one sentence the whole file exists for: what the write side stores,
     * the read side must ask for.
     */
    public function testEveryPrefixOfAnIndexedTermIsFoundByTheTagATypeaheadSends(): void
    {
        $stored = Text::indexTokens('Dr. Andreas Müller');
        foreach (['mue', 'muel', 'muell', 'mul', 'mull', 'andr', 'andrea'] as $typed) {
            $asked = Text::prefixTags($typed);
            self::assertNotEmpty(array_intersect($asked, $stored), "typing '$typed' must match");
        }
    }

    public function testACorrectlySpelledTermIsAmongItsOwnMisspellings(): void
    {
        // Without this the exact spelling can lose to an edit of itself, which
        // is a ranking bug that looks like a matching bug.
        self::assertContains(Text::termTag('zahnarzt'), Text::spellingTags('zahnarzt'));
    }

    public function testAWordPastTheCeilingIsAskedForExactlyRatherThanByPrefix(): void
    {
        self::assertSame(
            [Text::TERM_PREFIX . 'gastroenterologe'],
            Text::prefixTags('gastroenterologe')
        );
    }

    public function testTheVersionTagIsOnEveryRecord(): void
    {
        self::assertContains(Text::VERSION_TAG, Text::indexTokens('Müller'));
        self::assertContains(Text::VERSION_TAG, Text::indexTokensFor(['a', 'b']));
    }

    public function testTheTwoSdksAgreeOnTheVersionTagSpelling(): void
    {
        // Separate constants in separate languages, so the fixture is what
        // compares them. A tag spelled 'tv1' here and 'tv:1' there would make
        // every version check answer about a tag nothing carries.
        $tokens = self::vectors()[0]['indexTokens'];
        self::assertContains(Text::VERSION_TAG, $tokens);
    }

    /**
     * A word made only of digits came back from terms() as an int, because
     * PHP turns the array key '2024' into 2024, and termTag's string parameter
     * threw. Any record carrying a year, a model number or a postcode failed
     * to index. The shared vectors with Eastern digits are what found it.
     */
    public function testARecordCarryingAYearOrAPostcodeIndexes(): void
    {
        $tokens = Text::indexTokens('Model 2024 Berlin 10115');
        self::assertContains('t:2024', $tokens);
        self::assertContains('p:101', $tokens);
        self::assertSame(['model', '2024', 'berlin', '10115'], Text::terms('Model 2024 Berlin 10115'));
    }

    /**
     * Arabic, Cyrillic, Greek and CJK must produce tokens, and letters Unicode
     * does not decompose must not be cut out of a word. The shared vectors hold
     * the exact bytes; these hold what a person searching needs from them.
     */
    public function testEveryScriptIsFoundByItsFirstLetters(): void
    {
        $found = fn (string $typed, string $stored): bool =>
            array_intersect(Text::prefixTags($typed), Text::indexTokens($stored)) !== [];

        self::assertTrue($found('yil', 'Yıldız Çelik'));
        self::assertTrue($found('محم', 'محمد الخطيب'));
        self::assertTrue($found('моск', 'Москва'));
        self::assertTrue($found('οδο', 'ΟΔΟΣ'));
        self::assertContains('p:𠮷野家', Text::indexTokens('𠮷野家'));
    }

    public function testTheArabicSpellingsWritersUseInterchangeablyAreOne(): void
    {
        self::assertSame(Text::normalize('محمد'), Text::normalize('مُحَمَّد'));
        self::assertSame(Text::normalize('احمد'), Text::normalize('أحمد'));
        self::assertSame(Text::normalize('مدرسه'), Text::normalize('مدرسة'));
        self::assertSame(Text::normalize('مصطفي'), Text::normalize('مصطفى'));
        self::assertSame('محمد', Text::normalize('محـــمد'));
        self::assertSame('2024 1403', Text::normalize('٢٠٢٤ ۱۴۰۳'));
    }

    public function testATypoIsCorrectedInItsOwnScriptAndNotInCjk(): void
    {
        self::assertContains(Text::termTag('محمد'), Text::spellingTags('محمذ'));
        self::assertContains(Text::termTag('москва'), Text::spellingTags('москба'));
        self::assertSame([Text::termTag('東京')], Text::spellingTags('東京'));
    }

    /**
     * `prefixTags` reads what it is given as one word, so "andreas mue" asked
     * for "p:andreasmue", which no record carries: an empty page for the most
     * ordinary thing typed into a search box. The expectations here are the
     * TypeScript SDK's, literally, because the two are one contract.
     */
    public function testAPhraseUsedToBeOnePrefixNoRecordCarries(): void
    {
        self::assertSame(['p:andreasmue'], Text::prefixTags('andreas mue'));
    }

    public function testATypeaheadAsksForEveryWordEachByItsOwnPrefix(): void
    {
        self::assertSame([['p:andreas'], ['p:mue']], Text::typeaheadGroups('andreas mue'));
        self::assertSame([['p:andreas'], ['p:mul', 'p:muel']], Text::typeaheadGroups('andreas mül'));
    }

    public function testAShortFinishedWordIsExactAndAShortLastOneWaits(): void
    {
        self::assertSame([['t:dr'], ['p:mue']], Text::typeaheadGroups('dr mue'));
        self::assertSame([['p:andreas']], Text::typeaheadGroups('andreas m'));
        self::assertSame([], Text::typeaheadGroups('m'));
        self::assertSame([], Text::typeaheadGroups('  '));
        self::assertSame([['t:gastroenterologe'], ['p:ber']], Text::typeaheadGroups('gastroenterologe ber'));
    }

    public function testATypeaheadOfOneWordAgreesWithPrefixTags(): void
    {
        foreach (['mue', 'müll', 'Özdemir', 'straße', 'Москва', 'محمد', '2024'] as $w) {
            self::assertSame([Text::prefixTags($w)], Text::typeaheadGroups($w), $w);
        }
    }
}
