<?php

namespace App\Services\Knowledge;

/**
 * Resolves free-text repair notes to the CANONICAL REPAIR SIGNATURE vocabulary.
 *
 * This is the substrate of the maintenance intelligence loop: nothing downstream — similar-case
 * retrieval, comeback detection, garage scoring, seasonality — can fire until a case carries a
 * signature. `maintenances.service_main` carries a human label on only ~30% of events (7,370 of
 * 26,838) across 322 free-typed strings; this class covers the rest from `maintenance_notes`,
 * which is populated on 95.4% of events.
 *
 * MEASURED, NOT ASSUMED. Validated against the human labels with `intelligence:validate-classifier`:
 * agreement was 80.5% on the 6,544 events carrying both, lifting usable coverage from 30.5% to
 * ~78.7%. That number is the reason this is deterministic PHP and not a model — an 80% rules
 * baseline removes ML from the critical path of the first learning loop. See
 * docs/Fleet-Knowledge-Engine-Discovery-Log.md (D1).
 *
 * TWO RULES THAT LOOK LIKE DETAILS AND ARE NOT
 *
 *  1. SPAN CONSUMPTION. Each pattern removes the text it matched before later patterns are tried.
 *     Without this, "check engine light" matches CHECK_ENGINE *and* LIGHTS, and the pair then shows
 *     up as the strongest fault bundle in the fleet (n=481, lift 6.13) — an artifact of the
 *     classifier reading its own output, not a finding. 591 notes contain that phrase. Ordering is
 *     therefore load-bearing: specific signatures are declared before the generic ones that would
 *     swallow their vocabulary.
 *
 *  2. LEAK_OTHER IS A FALLBACK, NEVER A PEER. "leak" is present in radiator, gearbox, fuel and oil
 *     notes alike; it only survives when nothing more specific matched, otherwise it would dilute
 *     every real signature.
 *
 * The classifier is intentionally explainable: classify() returns the terms that fired for each
 * signature, so a Decision Card can always show why a case was labelled the way it was.
 */
class RepairSignatureClassifier
{
    /**
     * The vocabulary's version. Stamped onto every projected signature row AND onto every
     * recommendation built from one, so a card can always be traced to the exact vocabulary that
     * labelled its evidence.
     *
     * BUMP THIS whenever a pattern is added, removed or reordered. Order is significant here (span
     * consumption), so a reorder changes labels even when the pattern set is identical.
     */
    public const VERSION = 'v1';

    /**
     * The 22 canonical signatures, replacing 322 raw `service_main` strings.
     *
     * ORDER IS SIGNIFICANT — see rule 1 above. Alternatives inside a pattern are ordered
     * longest-first so the regex engine consumes the full phrase ("check engine light") rather
     * than a prefix of it ("check engine"), which would leave " light" for LIGHTS to claim.
     */
    private const SIGNATURES = [
        'COOLING'      => '/overheat|over heat|radiator|coolant|water pump|thermostat|cooling (system|fan)|temperature (high|warning)|water leak|hot engine/i',
        'AC'           => '/air ?condition(ing|er)?|\ba\/c\b|\bac\b|aircon|cooling gas|freon|compressor|not cool(ing)?\b|blower/i',
        'TRANSMISSION' => '/gear ?box|gearbox|transmission|clutch|gear (slip|problem|issue|change|shift)|differential|drive ?shaft|cv joint/i',
        'CHECK_ENGINE' => '/check engine light|check engine|engine light|warning light|fault code|diagnostic|scan(ner)?\b|\bobd\b/i',
        'ENGINE_MECH'  => '/engine (mech|problem|issue|noise|knock|repair|replace|overhaul|empty|belt)|new engine|piston|cylinder head|timing (belt|chain)|valve cover|head gasket|engine oil leak|turbo|misfire/i',
        'SUSPENSION'   => '/suspension|shock ?absorber|\bshocks?\b|strut|lower arm|upper arm|control arm|link (rod|assy)|stabiliz|stablis|ball joint|tie rod|coil spring|bush(ing|es)?\b|hub bearing|wheel bearing/i',
        'STEERING'     => '/steering|alignment|align|rack (and|&) pinion|power steering/i',
        'BRAKES'       => '/brake|brek|brack pad|disc turning|turning disc|rotor|caliper|hand ?brake|abs (sensor|light|problem)/i',
        'TYRE'         => '/\btyres?\b|\btires?\b|puncture|flat tyre|flat tire|kumho|balanc(e|ing)/i',
        'RIM'          => '/\brims?\b|wheel (scratch|paint|repair)|ringat|alloy/i',
        'BATTERY'      => '/batt?er(y|ies)|batery|jump start/i',
        'ELECTRICAL'   => '/electric|wiring|\becu\b|computer|sensor|airbag|air bag|alternator|starter|spark plug|ignition|fuse|relay|immobiliz|programming|module|short circuit/i',
        'LIGHTS'       => '/head ?light|\blights?\b|\blamps?\b|indicator|signal light|fog light|tail light/i',
        'GLASS'        => '/wind ?shield|windscreen|\bglass\b|mirror|wiper/i',
        'BODY'         => '/scratch|scrach|dent|bumper|bumber|fender|bonnet|body (damage|work|shop)|paint|polish|door (damage|scratch|handle)|panel|accident|\bhit\b|crash|quarter panel|trunk|dicki/i',
        'INTERIOR'     => '/interior|seat|chair|dash ?board|carpet|upholster|leather|console|roof lining/i',
        'OIL_SERVICE'  => '/oil (and|\+|&)? ?filter|oil chang|change oil|periodic (maintenance|service)|routine service|lube|service contract|oil serv/i',
        'EXHAUST'      => '/exhaust|silencer|muffler|catalytic/i',
        'FUEL_SYS'     => '/fuel (pump|filter|tank|injector)|injector|petrol pump/i',
        'KEY'          => '/\bkeys?\b|remote|key ?less|\bfob\b/i',
        'ACCESSORY'    => '/radar|parking sensor|camera|screen|\bgps\b|\bdvd\b|navigation|blind spot|accessor|tint/i',
        'LEAK_OTHER'   => '/leak(age|ing)?\b/i',
    ];

    /** Signatures that describe CUSTOMER EXPOSURE, not workshop quality. */
    public const EXPOSURE_SIGNATURES = ['BODY', 'RIM'];

    /**
     * Signatures that describe SCHEDULED WORK, not a failure — planned maintenance on a cadence.
     *
     * ── WHY THIS LIST IS ONE ENTRY LONG ──────────────────────────────────────────────────────────
     * A recurring oil change is the service working. Counting it as "the fault came back" charged
     * 1,073 fully-observed scheduled services to garages as repair failures, at 47.72% — slightly
     * ABOVE the 46.38% that real faults recur at — so the pollution was pushing every garage's
     * comeback rate up, not averaging out.
     *
     * The proper home for this is `maintenance_tasks.kind`, decided per event by
     * EventClassificationService against the service catalog. That layer exists and is correct, and
     * it reaches 28 of 12,608 recurrence pairs: it covers the new workflow, not the 26,942-ticket
     * history this corpus is built from. Until the history is classified, the SIGNATURE is the only
     * kind signal available at this grain.
     *
     * So this list is deliberately conservative — one signature that is unambiguously scheduled.
     * `TYRE` is NOT here despite the catalog carrying tyre_rotation and wheel_alignment as services:
     * a puncture and a rotation share the signature and nothing in the corpus separates them, and
     * silently reclassifying 996 pairs on a guess would trade a known bias for an unmeasurable one.
     * When maintenance_tasks.kind covers the history, this list retires in favour of it.
     */
    public const SERVICE_SIGNATURES = ['OIL_SERVICE'];

    /** Is this signature planned work rather than a failure? */
    public static function isService(string $signature): bool
    {
        return in_array($signature, self::SERVICE_SIGNATURES, true);
    }

    /**
     * Progress chatter that carries no fault information. Matching one of these AND nothing else is
     * how a note is recognised as workflow noise (2,645 events) rather than an unclassifiable fault —
     * the two must not be confused when reporting coverage.
     */
    private const WORKFLOW_NOISE = '/^(car is ready|ready|in (the )?garage|at (the )?garage|still (in|at)|waiting|no update|nothing|delivered|received|delay|ok|done|new car|testing|under test)\b/i';

    /**
     * Classify free text into canonical signatures.
     *
     * @return array{signatures: string[], matches: array<string, string[]>, noise: bool}
     */
    public function classify(?string $text): array
    {
        $normalized = $this->normalize($text);

        if ($normalized === '') {
            return ['signatures' => [], 'matches' => [], 'noise' => false];
        }

        $remaining = $normalized;
        $signatures = [];
        $matches = [];

        foreach (self::SIGNATURES as $signature => $pattern) {
            if (! preg_match_all($pattern, $remaining, $found)) {
                continue;
            }

            $terms = array_values(array_unique(array_filter($found[0], fn ($t) => trim($t) !== '')));

            // LEAK_OTHER is a fallback: it only counts when nothing specific has claimed the note.
            if ($signature === 'LEAK_OTHER' && $signatures !== []) {
                continue;
            }

            $signatures[] = $signature;
            $matches[$signature] = array_map('trim', $terms);

            // Span consumption — see rule 1. Without this the classifier double-counts shared
            // vocabulary and manufactures correlations between its own signatures.
            $remaining = (string) preg_replace($pattern, ' ', $remaining);
        }

        return [
            'signatures' => $signatures,
            'matches'    => $matches,
            'noise'      => $signatures === [] && preg_match(self::WORKFLOW_NOISE, trim($normalized)) === 1,
        ];
    }

    /** Convenience wrapper when only the labels are needed. @return string[] */
    public function signaturesFor(?string $text): array
    {
        return $this->classify($text)['signatures'];
    }

    /**
     * Map a human `service_main` string onto the same canonical vocabulary.
     *
     * Used for two things: validating the classifier against human judgement, and preferring the
     * human label over the derived one when both exist. The 322 raw strings include workflow states
     * ("Ready", "NEW CAR", "Testing", "Main reason") which are deliberately NOT faults and resolve
     * to an empty array.
     *
     * @return string[]
     */
    public function fromHumanLabel(?string $serviceMain): array
    {
        $label = $this->normalize($serviceMain);

        if ($label === '') {
            return [];
        }

        $out = [];
        $add = function (string $sig) use (&$out) {
            if (! in_array($sig, $out, true)) {
                $out[] = $sig;
            }
        };

        // Ordered like SIGNATURES: the specific checks run before the generic ones they overlap.
        if (preg_match('/cooling|overheat|coolant|radiator|water/i', $label))          $add('COOLING');
        if (preg_match('/\bac\b|a\/c|air cond/i', $label))                             $add('AC');
        if (preg_match('/gear|transmission|clutch/i', $label))                         $add('TRANSMISSION');
        if (preg_match('/check engine|engine light/i', $label))                        $add('CHECK_ENGINE');
        if (preg_match('/engine mech|^engine$|engine,|mechanical/i', $label))          $add('ENGINE_MECH');
        if (preg_match('/suspension/i', $label))                                       $add('SUSPENSION');
        if (preg_match('/steering|alignment/i', $label))                               $add('STEERING');
        if (preg_match('/brak/i', $label))                                             $add('BRAKES');
        if (preg_match('/tire|tyre/i', $label))                                        $add('TYRE');
        if (preg_match('/rim/i', $label))                                              $add('RIM');
        if (preg_match('/batter/i', $label))                                           $add('BATTERY');
        if (preg_match('/electric|airbag|abs/i', $label))                              $add('ELECTRICAL');
        if (preg_match('/light/i', $label) && ! preg_match('/engine light/i', $label)) $add('LIGHTS');
        if (preg_match('/glass|windshield|mirror/i', $label))                          $add('GLASS');
        if (preg_match('/body|exterior|damage|paint|scratch/i', $label))               $add('BODY');
        if (preg_match('/interior|chair|seat/i', $label))                              $add('INTERIOR');
        if (preg_match('/oil|fillter|filter|periodic/i', $label))                      $add('OIL_SERVICE');
        if (preg_match('/exhaust/i', $label))                                          $add('EXHAUST');
        if (preg_match('/fuel/i', $label))                                             $add('FUEL_SYS');
        if (preg_match('/\bkey/i', $label))                                            $add('KEY');
        if (preg_match('/accessor|radar|camera/i', $label))                            $add('ACCESSORY');
        if ($out === [] && preg_match('/leak/i', $label))                              $add('LEAK_OTHER');

        return $out;
    }

    /** @return string[] The canonical vocabulary, in declaration order. */
    public function vocabulary(): array
    {
        return array_keys(self::SIGNATURES);
    }

    /** Exposure signatures must never enter a workshop-quality metric. See D3. */
    public function isExposure(string $signature): bool
    {
        return in_array($signature, self::EXPOSURE_SIGNATURES, true);
    }

    private function normalize(?string $text): string
    {
        $t = mb_strtolower(trim((string) $text));
        $t = (string) preg_replace('/\s+/u', ' ', $t);

        return $t;
    }
}
