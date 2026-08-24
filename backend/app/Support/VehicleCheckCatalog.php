<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * The one reader of config/vehicle_checks.php.
 *
 * Everything the check lifecycle needs to know that is DOMAIN-specific comes through here, so that
 * [[VehicleCheckService]] contains no knowledge of batteries, oil or tyres whatsoever. That split is
 * what the "adding coolant must not need a new workflow" requirement actually means in code: this
 * class is the only thing that reads the catalog, and the service only ever asks it generic
 * questions — does this result imply an action, which decisions follow it, what keyword does it
 * become.
 *
 * Unknown types degrade to `general` rather than throwing. A gate rule nobody remembered to map must
 * still produce an answerable obligation; silently dropping it would recreate exactly the "nobody
 * looked and nobody noticed" hole this feature closes.
 */
final class VehicleCheckCatalog
{
    public const FALLBACK_TYPE = 'general';

    public static function version(): string
    {
        return (string) config('vehicle_checks.version', 'v1');
    }

    /** Every type key the catalog defines. */
    public static function typeKeys(): array
    {
        return array_keys((array) config('vehicle_checks.types', []));
    }

    /** One type block, falling back to `general` for anything unmapped. */
    public static function type(string $checkType): array
    {
        $types = (array) config('vehicle_checks.types', []);

        return $types[$checkType]
            ?? $types[self::FALLBACK_TYPE]
            ?? ['label' => $checkType, 'severity' => 'routine', 'results' => []];
    }

    public static function label(string $checkType): string
    {
        return (string) (self::type($checkType)['label'] ?? $checkType);
    }

    public static function labelAr(string $checkType): ?string
    {
        return self::type($checkType)['label_ar'] ?? null;
    }

    public static function knows(string $checkType): bool
    {
        return array_key_exists($checkType, (array) config('vehicle_checks.types', []));
    }

    // ── Results ────────────────────────────────────────────────────────────────────────────────

    /** The raw result block, or null when the type does not offer that result. */
    public static function result(string $checkType, string $resultCode): ?array
    {
        return self::type($checkType)['results'][$resultCode] ?? null;
    }

    /**
     * Does this result mean work is needed?
     *
     * THE LOAD-BEARING QUESTION. False here is what stops "check the oil" answered "level fine" from
     * manufacturing a fault nobody found — the behaviour this whole entity was built to remove.
     */
    public static function createsAction(string $checkType, string $resultCode): bool
    {
        return (bool) (self::result($checkType, $resultCode)['creates_action'] ?? false);
    }

    /**
     * The findings-catalog keyword an approved action becomes, or null when the type has none and
     * the inspector must place it himself (see the `general` type's note in the config).
     */
    public static function findingKeyword(string $checkType, string $resultCode): ?string
    {
        $keyword = self::result($checkType, $resultCode)['finding_keyword'] ?? null;

        return is_string($keyword) && $keyword !== '' ? $keyword : null;
    }

    /** The resolution code a terminal (non-action) result ends on. */
    public static function resolutionCode(string $checkType, string $resultCode): ?string
    {
        return self::result($checkType, $resultCode)['resolution_code'] ?? null;
    }

    /** How many days a 'monitor'-style answer holds before the check may be raised again. */
    public static function reraiseAfterDays(string $checkType, string $resultCode): ?int
    {
        $days = self::result($checkType, $resultCode)['reraise_after_days'] ?? null;

        return $days !== null ? (int) $days : null;
    }

    // ── Decisions ──────────────────────────────────────────────────────────────────────────────

    /** The decision block behind one result+decision pair, or null when the pair is not offered. */
    public static function decision(string $checkType, string $resultCode, string $decisionCode): ?array
    {
        return self::result($checkType, $resultCode)['decisions'][$decisionCode] ?? null;
    }

    /**
     * What a decision DOES: open_task | defer | none. Unknown decisions are refused rather than
     * guessed — a decision the catalog does not define has no defined consequence, and inventing one
     * is how a "not required" quietly starts opening tickets.
     */
    public static function decisionAction(string $checkType, string $resultCode, string $decisionCode): string
    {
        $decision = self::decision($checkType, $resultCode, $decisionCode);

        if ($decision === null) {
            throw new InvalidArgumentException(
                "[$decisionCode] is not a decision offered for [$checkType → $resultCode]."
            );
        }

        return (string) ($decision['action'] ?? 'none');
    }

    // ── UI payloads ────────────────────────────────────────────────────────────────────────────

    /**
     * The result options for one check type, shaped for the Decide step: enough for the UI to render
     * radios and know, WITHOUT a round trip, whether picking one reveals the decision row.
     *
     * @return array<int,array{code:string,label:string,label_ar:?string,creates_action:bool,decisions:array}>
     */
    public static function resultOptions(string $checkType): array
    {
        $out = [];

        foreach (self::type($checkType)['results'] ?? [] as $code => $result) {
            $out[] = [
                'code'           => (string) $code,
                'label'          => (string) ($result['label'] ?? $code),
                'label_ar'       => $result['label_ar'] ?? null,
                'creates_action' => (bool) ($result['creates_action'] ?? false),
                // The keyword the inspector will see land in his findings list if he approves, and the
                // KIND of work it becomes — shown so the consequence of the answer is visible before
                // he commits to it. Brake pads due is a service; brake noise is a fault.
                'finding_keyword' => $result['finding_keyword'] ?? null,
                'kind'            => $result['kind'] ?? null,
                'decisions'      => self::decisionOptions($checkType, (string) $code),
            ];
        }

        return $out;
    }

    /** @return array<int,array{code:string,label:string,label_ar:?string,action:string}> */
    public static function decisionOptions(string $checkType, string $resultCode): array
    {
        $out = [];

        foreach (self::result($checkType, $resultCode)['decisions'] ?? [] as $code => $decision) {
            $out[] = [
                'code'     => (string) $code,
                'label'    => (string) ($decision['label'] ?? $code),
                'label_ar' => $decision['label_ar'] ?? null,
                'action'   => (string) ($decision['action'] ?? 'none'),
            ];
        }

        return $out;
    }

    // ── Mapping the rule engine's conditions onto check types ──────────────────────────────────

    /**
     * DiagnosticGateService condition key → check type.
     *
     * `reminder:{id}` conditions carry no fixed key, so the caller passes the reminder's
     * `service_type` and it resolves through `reminder_map`. Anything unmapped becomes `general`,
     * which is answerable and auditable even though it offers no domain-specific options.
     */
    public static function typeForCondition(string $conditionKey, ?string $serviceType = null): string
    {
        if (str_starts_with($conditionKey, 'reminder:') && $serviceType) {
            return (string) (config('vehicle_checks.reminder_map')[$serviceType] ?? self::FALLBACK_TYPE);
        }

        return (string) (config('vehicle_checks.condition_map')[$conditionKey] ?? self::FALLBACK_TYPE);
    }

    /**
     * The check types an AGENDA condition fans out into, or an empty array when it is a single check.
     *
     * Post-downtime is "go look at Battery, Fluids and Brakes" — three questions. Collapsing them into
     * one row would let an inspector tick "checked" having looked at one of the three, which is the
     * same silent gap as not asking at all.
     *
     * @return string[]
     */
    public static function expansionsFor(string $conditionKey): array
    {
        return array_values((array) (config('vehicle_checks.expansions')[$conditionKey] ?? []));
    }

    public static function staleAfterDays(): int
    {
        return (int) config('vehicle_checks.stale_after_days', 90);
    }

    // ── Integrity ──────────────────────────────────────────────────────────────────────────────

    /** The kind of work a result produces: 'service' (scheduled upkeep) or 'fault' (something wrong). */
    public static function kind(string $checkType, string $resultCode): ?string
    {
        return self::result($checkType, $resultCode)['kind'] ?? null;
    }

    /**
     * Does every keyword in the catalog actually classify as the kind its result claims?
     *
     * THE GUARD THAT MATTERS, and the second version of it. The first only asked "is this keyword in
     * config/maintenance_findings.php", which is far too weak:
     *
     *   • brakes pointed at "Worn pads / discs" — in the findings catalog, so it PASSED, while turning
     *     every scheduled brake check into a fault. Pads wearing out is upkeep, not a breakdown, and
     *     the two are budgeted, routed and read completely differently.
     *   • tyres pointed at "Tire Rotation" — also in the findings catalog, so it PASSED, while matching
     *     NEITHER resolver (the service catalog spells it "Tyre"), producing MaintenanceTask rows with
     *     no kind at all.
     *
     * Both are invisible to a membership test and obvious to this one, because this asks the question
     * the workflow actually asks: run the keyword through [[EventClassificationService]] — the same
     * resolver syncFromFindings() uses — and check the answer against what the catalog promised.
     *
     * @return array<int,string> readable descriptions of each mismatch; empty means valid
     */
    public static function vocabularyViolations(): array
    {
        $classifier = app(\App\Services\EventClassificationService::class);
        $violations = [];

        foreach ((array) config('vehicle_checks.types', []) as $type => $block) {
            foreach ($block['results'] ?? [] as $code => $result) {
                $createsAction = (bool) ($result['creates_action'] ?? false);
                $keyword       = $result['finding_keyword'] ?? null;
                $declared      = $result['kind'] ?? null;

                if (! $createsAction) {
                    // A result that needs no action must not carry work vocabulary — that is exactly
                    // how a clean check would quietly start producing tasks again.
                    if ($keyword || $declared) {
                        $violations[] = "$type → $code declares work but creates_action is false";
                    }
                    continue;
                }

                // The open-ended type carries no keyword of its own: the inspector names the fault and
                // the same classification runs on his pick instead. The only legitimate null.
                if ($keyword === null) {
                    continue;
                }

                if ($declared === null) {
                    $violations[] = "$type → $code → \"$keyword\" declares no kind";
                    continue;
                }

                $resolved = $classifier->classifyFromFinding(['text' => $keyword])['kind'] ?? null;

                if ($resolved === null) {
                    $violations[] = "$type → $code → \"$keyword\" matches NO catalog — would create an unclassified task";
                } elseif ($resolved !== $declared) {
                    $violations[] = "$type → $code → \"$keyword\" declares $declared but classifies as $resolved";
                }
            }
        }

        return $violations;
    }

    /** Retained for the old membership question, which is still the right one for a HUMAN's pick. */
    public static function isFindingsVocabulary(?string $keyword): bool
    {
        if (! $keyword) {
            return false;
        }

        $known = [];
        foreach ((array) config('maintenance_findings.categories', []) as $category) {
            foreach ($category['keywords'] ?? [] as $entry) {
                $known[FaultVocabulary::normalise($entry)] = true;
            }
        }

        return isset($known[FaultVocabulary::normalise($keyword)]);
    }
}
