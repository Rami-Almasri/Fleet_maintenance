<?php

namespace App\Support;

/**
 * The concept names authored in `database/seeders/ontology/*.php`, normalised.
 *
 * READ FROM THE FILES, NOT THE DATABASE — deliberately, and the reason matters. The question these
 * names answer is "has anyone written words for this fault", and the seeder files are where those
 * words are authored and reviewed. Reading `finding_keywords` instead would answer "has this been
 * seeded", which is a different question and passes on a machine where the seeder ran once and the
 * file has since lost the concept.
 *
 * Extracted so the vocabulary check and the Fault Types admin page cannot answer it differently: the
 * page tells a curator "this fault has no words yet" and the check FAILS the same condition. Two
 * copies of a glob would eventually disagree about which is true.
 */
class OntologyConcepts
{
    /** @var array<string, string>|null normalised key → authored concept name */
    private static ?array $names = null;

    /** @return array<string, string> */
    public static function names(): array
    {
        if (self::$names !== null) {
            return self::$names;
        }

        $out = [];

        foreach (glob(database_path('seeders/ontology/*.php')) ?: [] as $path) {
            foreach ((array) require $path as $concept) {
                if (isset($concept['name'])) {
                    $out[TextNormalizer::key($concept['name'])] = $concept['name'];
                }
            }
        }

        return self::$names = $out;
    }

    /** @return array<string, string> normalised key → the category the ontology files it under */
    public static function categories(): array
    {
        $out = [];

        foreach (glob(database_path('seeders/ontology/*.php')) ?: [] as $path) {
            foreach ((array) require $path as $concept) {
                if (isset($concept['name'], $concept['category'])) {
                    $out[TextNormalizer::key($concept['name'])] = $concept['category'];
                }
            }
        }

        return $out;
    }

    /** Does a word have meaning behind it, or does it only match its own name? */
    public static function has(?string $word): bool
    {
        $key = TextNormalizer::key($word);

        return $key !== '' && isset(self::names()[$key]);
    }

    /** Test seam — the file list is read once per process and cached. */
    public static function flush(): void
    {
        self::$names = null;
    }
}
