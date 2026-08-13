<?php

namespace Tests\Foundation;

use App\Services\PartCatalogMatcher;

/**
 * The matcher turns typed wording into a catalog part. Its whole value is that it NEVER GUESSES.
 *
 * A required part linked to the wrong catalog entry orders the wrong part and corrupts every count
 * built on the catalog afterwards. An unlinked line is a question a human can answer; a mislinked
 * one is a defect nobody notices. Every test here defends that asymmetry.
 */
class PartCatalogMatcherTest extends FoundationTestCase
{
    private PartCatalogMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new PartCatalogMatcher();
    }

    private function resolveName(string $text): ?string
    {
        return $this->matcher->resolve($text)['candidate'];
    }

    public function test_exact_english_name_matches(): void
    {
        $hit = $this->matcher->resolve('Alternator');

        $this->assertSame('Alternator', $hit['candidate']);
        $this->assertSame(PartCatalogMatcher::MATCH_EXACT, $hit['matched_by']);
    }

    public function test_exact_arabic_name_matches(): void
    {
        $this->assertSame('Alternator', $this->resolveName('دينمو'));
    }

    /** "Brake Pads (set)" — the parenthetical is our bookkeeping, not part of the part's name. */
    public function test_the_parenthetical_free_core_matches(): void
    {
        $hit = $this->matcher->resolve('Brake pads');

        $this->assertSame('Brake Pads (set)', $hit['candidate']);
    }

    /** A position word is the inspector saying which end of the car, not a different part. */
    public function test_a_leading_position_word_still_resolves(): void
    {
        $hit = $this->matcher->resolve('Front brake pads (set)');

        $this->assertSame('Brake Pads (set)', $hit['candidate']);
        $this->assertSame(PartCatalogMatcher::MATCH_PHRASE, $hit['matched_by']);
    }

    /** A slash between single letters is an abbreviation: "A/C" is one word, not "a c". */
    public function test_slash_abbreviations_normalise(): void
    {
        $this->assertSame('AC Compressor', $this->resolveName('A/C compressor (reman)'));
    }

    /**
     * A curated OTHER NAME links, because it is a name. 'dynamo' is what half the workshop calls the
     * alternator, and refusing it left every such record unattached forever — so the repeat-buy check
     * could never see that the car already had one.
     */
    public function test_an_identity_alias_links(): void
    {
        foreach (['dynamo', 'generator', 'دينامو'] as $name) {
            $hit = $this->matcher->resolve($name);

            $this->assertSame('Alternator', $hit['candidate'], "'{$name}' is the alternator");
            $this->assertSame(PartCatalogMatcher::MATCH_EXACT, $hit['matched_by']);
        }
    }

    /**
     * Wording claimed by TWO catalog rows resolves to nothing. 'fan motor' is said of the radiator fan
     * and of the A/C blower; the config keeps it out of both identity lists by hand, and the matcher
     * refuses it independently. Two guards, because the failure mode of one slip is a silent mislink.
     */
    public function test_wording_shared_by_two_parts_is_refused(): void
    {
        foreach (['fan motor', 'bumper'] as $ambiguous) {
            $this->assertNull(
                $this->matcher->resolve($ambiguous)['catalog_id'],
                "'{$ambiguous}' fits two catalog rows and therefore identifies neither"
            );
        }
    }

    /**
     * THE RULE THAT MATTERS MOST. The SEARCH alias list carries symptom wording, and a symptom does not
     * identify a part: "brake noise" is as true of the discs and the caliper as of the pads. The
     * picker's search MAY match it (a human then chooses); linking never may, because nobody is
     * choosing. This is why the catalog carries two synonym lists instead of one.
     */
    public function test_symptom_aliases_never_link(): void
    {
        foreach (['brake noise', 'ac not cooling', 'battery not charging', 'ما يشحن'] as $symptom) {
            $hit = $this->matcher->resolve($symptom);

            $this->assertNull(
                $hit['catalog_id'],
                "'{$symptom}' is a complaint, not a part — linking it would attach a specific part to a symptom that never named one"
            );
        }
    }

    /** The same words DO find the part through the picker, where a human makes the call. */
    public function test_the_search_path_still_finds_parts_by_symptom(): void
    {
        $names = \App\Models\ComponentCatalog::search('battery not charging')->pluck('name');

        $this->assertContains('Alternator', $names);
    }

    public function test_unrecognised_wording_stays_unlinked(): void
    {
        foreach (['break', 'xyz nonsense', ''] as $text) {
            $this->assertNull($this->matcher->resolve($text)['catalog_id']);
        }
    }

    /** Two words joined by a slash are two words — "front/rear" must not become one token. */
    public function test_a_slash_between_words_is_not_an_abbreviation(): void
    {
        $this->assertNull($this->matcher->resolve('front/rear pads')['catalog_id']);
    }

    /** Longest match wins, so a shared word cannot pull a phrase onto the wrong part. */
    public function test_longest_phrase_wins_over_a_shared_word(): void
    {
        $this->assertSame('Brake Discs (set)', $this->resolveName('Front brake discs (pair)'));
        $this->assertSame('Wheel Bearing', $this->resolveName('front wheel bearing'));
    }

    /** Matching is case- and punctuation-insensitive; the same part, however it is typed. */
    public function test_normalisation_is_case_and_punctuation_insensitive(): void
    {
        foreach (['ALTERNATOR', 'alternator!!', '  Alternator  '] as $text) {
            $this->assertSame('Alternator', $this->resolveName($text), "failed for '{$text}'");
        }
    }

    /** A retired part must stop being linked to — it is no longer something we fit. */
    public function test_retired_parts_are_not_matched(): void
    {
        $part = \App\Models\ComponentCatalog::where('slug', 'alternator')->first();
        $part->update(['is_active' => false]);

        $fresh = new PartCatalogMatcher(); // index is built once per instance
        $this->assertNull($fresh->resolve('Alternator')['catalog_id']);
    }
}
