<?php

namespace App\Support;

/**
 * The ONE way the keyword ontology turns human text into a comparison key.
 *
 * Every surface form in `keyword_terms.normalized` is written through `key()`, and every free-text
 * query is read through the same method — if the two ever disagreed, a term that looks identical on
 * screen would silently fail to match. Same rule as [[FaultVocabulary]]: one normaliser, no
 * per-engine variants.
 *
 * What it does, and why each step earns its place:
 *  - lower-cases and strips punctuation, so "A/C", "AC" and "a c" collapse together;
 *  - strips Arabic diacritics (tashkeel) and tatweel, which are typed inconsistently or not at all;
 *  - folds the alef family (أ إ آ ٱ → ا), ى → ي, ة → ه, ؤ → و, ئ → ي — the four spellings people
 *    genuinely mix up when typing Arabic quickly on a phone;
 *  - maps Arabic-Indic digits to ASCII, so "٥w٣٠" oil grades match "5w30";
 *  - collapses whitespace.
 *
 * It deliberately does NOT stem or de-pluralise: "brake" vs "brakes" is handled by the term list
 * itself (the AI generates both wordings), which is more accurate than a Porter stemmer on a corpus
 * that is half Arabic and half workshop slang.
 */
final class TextNormalizer
{
    /** Arabic combining marks (tashkeel) + tatweel — pure decoration, always stripped. */
    private const ARABIC_MARKS = '/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u';

    /** Letter foldings for the spellings people mix up. Order matters only in that all are 1:1. */
    private const ARABIC_FOLD = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ى' => 'ي', 'ئ' => 'ي',
        'ؤ' => 'و',
        'ة' => 'ه',
        'گ' => 'ك', 'ک' => 'ك',
        'پ' => 'ب', 'چ' => 'ج', 'ژ' => 'ز', 'ڤ' => 'ف',
    ];

    /** Arabic-Indic and Eastern Arabic-Indic digits → ASCII. */
    private const DIGIT_FOLD = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * Words that carry no fault meaning and would otherwise create spurious token overlap between
     * unrelated concepts ("the car makes a noise" vs "the car pulls to one side"). Kept short and
     * obvious on purpose — an over-eager stop list throws away real signal like "light" or "hot".
     */
    private const STOPWORDS = [
        // English filler
        'the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'at', 'is', 'are', 'was', 'were',
        'it', 'its', 'this', 'that', 'there', 'when', 'while', 'with', 'from', 'for', 'my', 'i',
        'has', 'have', 'had', 'be', 'been', 'get', 'gets', 'got', 'very', 'some', 'any', 'car',
        'vehicle', 'auto', 'please', 'issue', 'problem', 'customer', 'driver', 'says', 'said',

        // Positional and temporal filler — carries no fault meaning, and DID cause real false
        // positives. "car is under test by abo marof" scored 45 against Oil leak purely because
        // both it and the phrase "oil under the car" contain "under"; one generic token in common
        // was enough to pull a workshop status note onto a fluid-leak concept.
        //
        // These are the words that appear in fault phrases only because English sentences need
        // them. Removing them costs nothing — no fault is identified by "under" or "still" — and it
        // stops a whole class of coincidental single-token matches.
        //
        // NOT included, deliberately: front, rear, left, right, side. They are filler in isolation
        // but genuinely locate a fault ("front right tyre", "rear brake"), and dropping them would
        // lose information a technician actually wrote down.
        'under', 'over', 'still', 'now', 'today', 'tomorrow', 'yesterday', 'again', 'also',
        'need', 'needs', 'needed', 'going', 'went', 'goes', 'take', 'takes', 'took',
        'done', 'ready', 'new', 'old', 'other', 'another', 'same', 'about', 'after', 'before',
        // Arabic filler
        'في', 'من', 'على', 'الى', 'إلى', 'عن', 'مع', 'ان', 'أن', 'هذا', 'هذه', 'يوجد', 'فيه',
        'السياره', 'السيارة', 'سياره', 'سيارة', 'العربيه', 'العربية', 'الزبون', 'مشكله', 'مشكلة',

        // Gulf/Levantine words for "the car". Same defect as English "car" and "under": a generic
        // word carrying no fault meaning was scoring real matches. "الموتر يسخن" was pulling in
        // Stalling ("الموتر يطفي") and Pulling/drifting ("الموتر يميل") at 48 each, purely because
        // all three contain "الموتر".
        //
        // NOT stopworded: المكينة / الماتور / المحرك — those mean the ENGINE and genuinely locate a
        // fault. Only the words that mean "the vehicle" are dropped.
        'الموتر', 'موتر', 'الموتور', 'موتور', 'الترك', 'المركبه', 'المركبة',
    ];

    /** The comparison key for a term or a whole sentence. Empty string for anything meaningless. */
    public static function key(?string $text): string
    {
        $s = mb_strtolower(trim((string) $text), 'UTF-8');

        $s = strtr($s, self::DIGIT_FOLD);
        $s = preg_replace(self::ARABIC_MARKS, '', $s);
        $s = strtr($s, self::ARABIC_FOLD);

        // Keep letters (Latin + Arabic) and digits; everything else becomes a space. Slashes,
        // hyphens and parentheses are separators here, which is what makes "A/C" → "a c".
        $s = preg_replace('/[^\p{Arabic}a-z0-9]+/u', ' ', $s);

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * Meaningful tokens of a normalised string, stop-words removed and single characters dropped
     * (a lone letter never distinguishes one fault from another).
     *
     * @return array<int,string>
     */
    public static function tokens(?string $text): array
    {
        $tokens = [];
        foreach (explode(' ', self::key($text)) as $t) {
            if ($t === '' || mb_strlen($t) < 2 || in_array($t, self::STOPWORDS, true)) {
                continue;
            }
            $tokens[$t] = true;   // de-duplicate: "noise noise" is one signal
        }

        return array_keys($tokens);
    }

    /** True when the string is predominantly Arabic script — used to default a term's `lang`. */
    public static function isArabic(?string $text): bool
    {
        return (bool) preg_match('/\p{Arabic}/u', (string) $text);
    }
}
