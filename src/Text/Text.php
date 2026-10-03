<?php

declare(strict_types=1);

namespace PulseIndex\Text;

use Normalizer;
use PulseIndex\Exception\PulseIndexException;

/**
 * Text search tokens: the tags a record carries so its text can be found by
 * typeahead, by whole word, and despite one typo.
 *
 * Tags are matched exactly, so what `indexTokens` writes must be what
 * `prefixTags`, `termTags` and `spellingTags` ask for, byte for byte. A prefix
 * is written at index time; misspellings are generated at query time and sent
 * as one disjunction. The TypeScript SDK produces the same tokens, and both
 * assert against the shared vectors in `tests/Fixtures/text-token-vectors.json`.
 */
final class Text
{
    /**
     * Bumped whenever normalisation, the prefix range, or the tag shape changes.
     *
     * An index built by one version and queried by another produces no error and
     * no results, which is the worst failure this SDK can have. Indexing this as
     * a tag turns it into a refusal the caller can read; see `verifyIndex`.
     */
    public const TOKENIZER_VERSION = 1;

    public const TERM_PREFIX = 't:';

    public const PREFIX_PREFIX = 'p:';

    public const VERSION_PREFIX = 'tv:';

    public const VERSION_TAG = self::VERSION_PREFIX . self::TOKENIZER_VERSION;

    /**
     * Shortest prefix a typeahead searches on.
     */
    public const MIN_PREFIX = 3;

    /**
     * Longest prefix written per term. A query longer than this matches the
     * full term instead.
     */
    public const MAX_PREFIX = 12;

    /**
     * Longest term for which `spellingTags` will expand.
     *
     * Each spelling is one filter, and a query may carry at most 4,096, so a
     * longer term is matched exactly.
     */
    public const MAX_FUZZY_TERM = 20;

    /**
     * Letters Unicode does not decompose, folded to the Latin a person types
     * for them, so "Yıldız" matches "yildiz" and "Søren" matches "soren".
     */
    private const UNDECOMPOSED = [
        'ı' => 'i', 'ł' => 'l', 'ø' => 'o', 'đ' => 'd', 'ð' => 'd',
        'þ' => 'th', 'æ' => 'ae', 'œ' => 'oe', 'ħ' => 'h',
    ];

    /**
     * Arabic, as search engines normalise it (Lucene's ArabicNormalizer): the
     * diacritics go with the other combining marks; this drops the tatweel and
     * folds the letters people write interchangeably. Persian ی and ک fold to
     * their Arabic forms, and both kinds of Eastern digit to 0-9.
     */
    private const ARABIC = [
        "\u{0640}" => '', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ی' => 'ي', 'ک' => 'ك',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /** One-edit spellings need an alphabet; each script gets its own. */
    private const ALPHABETS = [
        '/\p{Arabic}/u' => 'ابتثجحخدذرزسشصضطظعغفقكلمنهويء',
        '/\p{Cyrillic}/u' => 'абвгдежзиклмнопрстуфхцчшщъыьэюяієґ',
        '/\p{Greek}/u' => 'αβγδεζηθικλμνξοπρστυφχψω',
        '/\p{Latin}|^[0-9]+$/u' => 'abcdefghijklmnopqrstuvwxyz',
    ];

    /** Lowercase, strip accents, and keep only letters, digits and spaces. */
    public static function normalize(string $input): string
    {
        return self::fold($input, false);
    }

    /**
     * Both ways German folds to ASCII, when they differ.
     *
     * Stripping the diaeresis turns "Müller" into "muller". Transliterating it
     * turns the same name into "mueller". Both are what a real person types.
     *
     * So both are indexed and both are asked for. Only a word actually carrying
     * one of these characters pays for the second set of tokens.
     *
     * @return list<string>
     */
    public static function foldings(string $input): array
    {
        $plain = self::fold($input, false);
        $german = self::fold($input, true);

        return $plain === $german ? [$plain] : [$plain, $german];
    }

    private static function fold(string $input, bool $german): string
    {
        // Refused rather than approximated. Without intl this would still
        // produce tokens, just different ones from the TypeScript SDK's, and a
        // missing extension is a thing an installer can fix while a wrong token
        // is not.
        if (!class_exists(Normalizer::class)) {
            throw new PulseIndexException(
                'PulseIndex\\Text\\Text needs ext-intl: without it the accent folding here '
                . 'would differ from the TypeScript SDK and the same name would be indexed '
                . 'under one token and queried under another. Install ext-intl'
            );
        }

        // Greek final sigma to sigma first: before PHP 8.3 mb_strtolower turns Σ
        // into σ everywhere, and JavaScript into ς at the end of a word.
        $s = str_replace('ς', 'σ', mb_strtolower($input, 'UTF-8'));
        $s = $german
            ? strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'])
            : strtr($s, ['ß' => 'ss']);
        $s = strtr($s, self::UNDECOMPOSED);

        $s = Normalizer::normalize($s, Normalizer::FORM_KD);
        if ($s === false) {
            throw new PulseIndexException('text could not be normalised: not valid UTF-8');
        }

        $s = (string) preg_replace('/\p{M}+/u', '', $s);
        $s = strtr($s, self::ARABIC);

        // Letters and digits of every script stay.
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s));
    }

    /**
     * The words of a value, every folding, without duplicates.
     *
     * @return list<string>
     */
    public static function terms(string $value): array
    {
        $seen = [];
        foreach (self::foldings($value) as $folded) {
            foreach (explode(' ', $folded) as $w) {
                if ($w !== '') {
                    $seen[$w] = true;
                }
            }
        }

        // As strings: PHP turns an array key like '2024' into the integer 2024,
        // and termTag takes a string.
        return array_map('strval', array_keys($seen));
    }

    /** The tag an exact term match asks for. */
    public static function termTag(string $term): string
    {
        return self::TERM_PREFIX . str_replace(' ', '', self::normalize($term));
    }

    /**
     * The exact-match tags for a term, one per folding. Send as one group.
     *
     * @return list<string>
     */
    public static function termTags(string $term): array
    {
        $out = [];
        foreach (self::foldings($term) as $folded) {
            $t = str_replace(' ', '', $folded);
            if ($t !== '') {
                $out[self::TERM_PREFIX . $t] = true;
            }
        }

        return array_keys($out);
    }

    /** The first tag a typeahead asks for. Prefer `prefixTags`. */
    public static function prefixTag(string $typed): string
    {
        return self::prefixTags($typed)[0] ?? self::PREFIX_PREFIX;
    }

    /**
     * The tags a typeahead asks for, one per folding, to be sent as one SHOULD
     * group.
     *
     * Past MAX_PREFIX there is no stored prefix, so the full term is the right
     * question: exact, cheaper, and what the user has finished typing anyway.
     *
     * @return list<string>
     */
    public static function prefixTags(string $typed): array
    {
        $out = [];
        foreach (self::foldings($typed) as $folded) {
            $t = str_replace(' ', '', $folded);
            if ($t === '') {
                continue;
            }
            $tag = mb_strlen($t, 'UTF-8') > self::MAX_PREFIX
                ? self::TERM_PREFIX . $t
                : self::PREFIX_PREFIX . $t;
            $out[$tag] = true;
        }

        return array_keys($out);
    }

    /**
     * What a typeahead asks for when more than one word is typed: one group per
     * word, each group any of that word's tags, and the groups all required.
     *
     * `prefixTags` treats what it is given as one word. Per word, order does not
     * matter either: "mue andreas" finds the same record as "andreas mue".
     *
     * A word the person has finished, one before the last, is matched by prefix
     * when it has at least MIN_PREFIX letters and exactly when shorter, so "dr"
     * still narrows. The last word is still being typed: under MIN_PREFIX it
     * adds nothing yet. Both foldings are asked for, which is why "mü" already
     * finds Müller and Mueller: German folding makes it "mue".
     *
     * An empty result means nothing typed is long enough to search on yet.
     *
     * @return list<list<string>>
     */
    public static function typeaheadGroups(string $typed): array
    {
        $foldings = array_map(
            static fn (string $f): array => array_values(array_filter(explode(' ', $f), static fn ($w) => $w !== '')),
            self::foldings($typed),
        );
        $words = max(array_map('count', $foldings));
        $groups = [];
        for ($i = 0; $i < $words; $i++) {
            $last = $i === $words - 1;
            $tags = [];
            foreach ($foldings as $folded) {
                $word = $folded[$i] ?? null;
                if ($word === null) {
                    continue;
                }
                $n = mb_strlen($word, 'UTF-8');
                if ($n >= self::MIN_PREFIX) {
                    $tags[$n > self::MAX_PREFIX ? self::TERM_PREFIX . $word : self::PREFIX_PREFIX . $word] = true;
                } elseif (!$last) {
                    $tags[self::TERM_PREFIX . $word] = true;
                }
            }
            if ($tags !== []) {
                $groups[] = array_map('strval', array_keys($tags));
            }
        }

        return $groups;
    }

    /**
     * Every tag one value contributes at write time.
     *
     * Expressed through `prefixTags` and `termTag` rather than building strings
     * again, so the two directions cannot drift apart.
     *
     * @return list<string>
     */
    public static function indexTokens(string $value): array
    {
        $out = [self::VERSION_TAG => true];
        foreach (self::terms($value) as $term) {
            $out[self::termTag($term)] = true;
            $len = mb_strlen($term, 'UTF-8');
            $upper = min($len, self::MAX_PREFIX);
            for ($n = self::MIN_PREFIX; $n <= $upper; $n++) {
                foreach (self::prefixTags(mb_substr($term, 0, $n, 'UTF-8')) as $tag) {
                    $out[$tag] = true;
                }
            }
        }

        return array_keys($out);
    }

    /**
     * Index tokens for several values at once, deduplicated across them.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    public static function indexTokensFor(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            foreach (self::indexTokens($v) as $t) {
                $out[$t] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Every spelling within one edit, as the tags a SHOULD group would carry.
     *
     * Deletions, transpositions, substitutions and insertions, in characters
     * and in the alphabet of the word's own script. A script with no alphabet
     * to substitute from, Han, kana, Hangul, gets the exact term: a typo there
     * is not a one-letter edit. The term itself is included, so a correctly
     * spelled query never loses to its own misspellings.
     *
     * @return list<string>
     */
    public static function spellingTags(string $term): array
    {
        $t = str_replace(' ', '', self::normalize($term));
        if ($t === '') {
            return [];
        }
        $c = mb_str_split($t, 1, 'UTF-8');
        if (count($c) > self::MAX_FUZZY_TERM) {
            return [self::termTag($t)];
        }

        $alphabet = null;
        foreach (self::ALPHABETS as $script => $letters) {
            if (preg_match($script, $t) === 1) {
                $alphabet = mb_str_split($letters, 1, 'UTF-8');
                break;
            }
        }
        if ($alphabet === null) {
            return [self::termTag($t)];
        }

        $n = count($c);
        $out = [$t => true];
        for ($i = 0; $i < $n; $i++) {
            $out[implode('', array_merge(array_slice($c, 0, $i), array_slice($c, $i + 1)))] = true;
        }
        for ($i = 0; $i < $n - 1; $i++) {
            $swap = $c;
            [$swap[$i], $swap[$i + 1]] = [$c[$i + 1], $c[$i]];
            $out[implode('', $swap)] = true;
        }
        for ($i = 0; $i < $n; $i++) {
            foreach ($alphabet as $l) {
                if ($l !== $c[$i]) {
                    $out[implode('', array_merge(array_slice($c, 0, $i), [$l], array_slice($c, $i + 1)))] = true;
                }
            }
        }
        for ($i = 0; $i <= $n; $i++) {
            foreach ($alphabet as $l) {
                $out[implode('', array_merge(array_slice($c, 0, $i), [$l], array_slice($c, $i)))] = true;
            }
        }

        $tags = [];
        foreach (array_keys($out) as $spelling) {
            $tags[self::termTag((string) $spelling)] = true;
        }

        return array_keys($tags);
    }

    /** The tag that says which tokenizer built a record. */
    public static function versionTag(): string
    {
        return self::VERSION_TAG;
    }

    /**
     * The versions `verifyIndex` looks for when its own is not present.
     *
     * Every earlier version, because an index that predates an SDK upgrade is
     * the common case, and a short way past the current one, because two
     * services on different SDK versions writing and reading one tenant is the
     * case nobody plans for and it reads as "newer", not "older".
     *
     * @return list<int>
     */
    public static function probeVersions(): array
    {
        return range(1, self::TOKENIZER_VERSION + 4);
    }
}
