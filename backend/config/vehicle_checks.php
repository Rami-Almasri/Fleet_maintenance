<?php

/**
 * THE CHECK CATALOG — what the platform may ask an inspector to look at, and what he may answer.
 *
 * This file is the whole reason [[VehicleCheckRequirement]] is a generic mechanism rather than five
 * hard-coded branches. The LIFECYCLE (raise → attach → inspect → decide → act → resolve) lives in
 * VehicleCheckService and never changes. What changes per check type is only:
 *
 *   • which RESULTS an inspector may pick    ("OK · Monitor · Replace")
 *   • which of those results imply an ACTION ("Replace" does; "OK" does not)
 *   • which DECISIONS follow an action result ("Approved · Deferred · Not required")
 *   • which findings-catalog KEYWORD an approved action becomes
 *
 * Adding Coolant, Brake Fluid, Transmission Oil or Air Filter tomorrow is a block in `types` below
 * plus a line in `condition_map`. No migration, no service change, no new workflow.
 *
 * ── THE ONE RULE THAT MAKES THIS WORTH BUILDING ────────────────────────────────────────────────
 * A result with `creates_action => false` RESOLVES the requirement and creates NOTHING. "Check the
 * oil" answered "checked, level fine" must not manufacture a fault — a recommendation is a request
 * to LOOK, and looking is not evidence that anything is wrong. That distinction is enforced here, in
 * data, so no future check type can quietly reintroduce the fake-fault behaviour.
 *
 * ── VOCABULARY, AND THE MISTAKE IT IS GUARDING AGAINST ─────────────────────────────────────────
 * Every action-bearing result declares BOTH the `kind` of work it produces and the `finding_keyword`
 * that names it, and the two must agree:
 *
 *   kind => 'service'  the keyword must be a `service_catalog` name/slug   → MaintenanceTask kind=service
 *   kind => 'fault'    the keyword must be a `maintenance_findings` keyword → MaintenanceTask kind=fault
 *
 * THIS IS NOT BOOKKEEPING. Brake pads wearing out on a scheduled check is planned upkeep; a grinding
 * noise is a fault. They go to different places, are budgeted differently, and read completely
 * differently on a car's history. The first cut of this file pointed brakes and tyres at fault
 * vocabulary because the only guard was "is it in the findings catalog" — which brakes passed while
 * being wrong, and which tyres passed while matching NEITHER catalog (the findings catalog spells it
 * "Tire Rotation", the service catalog "Tyre Rotation"), producing tasks with no kind at all.
 *
 * So the guard is now the real question — "does this keyword classify to the kind we claim?" —
 * checked through EventClassificationService itself, the same resolver the workflow uses. See
 * VehicleCheckCatalog::vocabularyViolations(), locked by test_every_catalog_keyword_classifies_as_declared.
 *
 * ── LANGUAGE ───────────────────────────────────────────────────────────────────────────────────
 * `label` / `label_ar` are catalog DATA (the noun on screen), not UI strings — the same split
 * VehicleSuggestedChecksService uses. The sentence AROUND them is composed by the UI from reason
 * codes ([[reason-code-contract]]); this file never authors an English sentence for the engine.
 */

return [

    /**
     * Bumped whenever a result/decision option changes meaning. Stamped onto every requirement at
     * creation, so a two-year-old resolution is still readable against the options that existed then
     * rather than against today's list.
     */
    'version' => 'v1',

    // ── Check types ────────────────────────────────────────────────────────────────────────────
    //
    // result option keys:
    //   creates_action   false ⇒ answering it RESOLVES the requirement, and creates no fault. Ever.
    //                    true  ⇒ the inspector must then pick a decision from `decisions`.
    //   resolution_code  why the requirement ended, when the result itself ends it.
    //   reraise_after_days  a "monitor" answer is not a permanent all-clear: the condition may be
    //                    raised again after this many days even if the underlying rule has not
    //                    re-fired. Null ⇒ the ordinary cycle_key rules apply.
    //
    // decision option keys:
    //   action  open_task ⇒ becomes a real fault through the EXISTING MaintenanceTask pipeline
    //           defer     ⇒ resolved now, deliberately not done
    //           none      ⇒ resolved now, inspector overruled the system
    'types' => [

        'oil_change' => [
            'label'    => 'Engine Oil',
            'label_ar' => 'زيت المحرك',
            'severity' => 'moderate',
            'results'  => [
                'ok' => [
                    'label'           => 'OK — level and condition fine',
                    'label_ar'        => 'سليم — المستوى والحالة جيدة',
                    'creates_action'  => false,
                    'resolution_code' => 'confirmed_ok',
                ],
                'change_required' => [
                    'label'          => 'Oil change required',
                    'label_ar'       => 'يلزم تغيير الزيت',
                    'creates_action' => true,
                    'kind'            => 'service',
                    'finding_keyword' => 'Oil Change',
                    'decisions'      => [
                        'approved'     => ['label' => 'Approved — do it now',   'label_ar' => 'معتمد — ينفذ الآن',  'action' => 'open_task'],
                        'deferred'     => ['label' => 'Deferred',               'label_ar' => 'مؤجل',                'action' => 'defer'],
                        'not_required' => ['label' => 'Not required after all', 'label_ar' => 'غير مطلوب',           'action' => 'none'],
                    ],
                ],
            ],
        ],

        'battery' => [
            'label'    => 'Battery',
            'label_ar' => 'البطارية',
            'severity' => 'routine',
            'results'  => [
                'ok' => [
                    'label'           => 'OK — holds charge',
                    'label_ar'        => 'سليمة — تحتفظ بالشحن',
                    'creates_action'  => false,
                    'resolution_code' => 'confirmed_ok',
                ],
                'monitor' => [
                    'label'              => 'Monitor — weak but serviceable',
                    'label_ar'           => 'للمراقبة — ضعيفة لكنها تعمل',
                    'creates_action'     => false,
                    'resolution_code'    => 'monitoring',
                    'reraise_after_days' => 30,
                ],
                'replace' => [
                    'label'          => 'Replace',
                    'label_ar'       => 'تحتاج استبدال',
                    'creates_action' => true,
                    'kind'            => 'service',
                    'finding_keyword' => 'Battery Replacement',
                    'decisions'      => [
                        'approved'     => ['label' => 'Replacement approved', 'label_ar' => 'الاستبدال معتمد', 'action' => 'open_task'],
                        'deferred'     => ['label' => 'Replacement deferred', 'label_ar' => 'الاستبدال مؤجل',  'action' => 'defer'],
                        'not_required' => ['label' => 'Not required',         'label_ar' => 'غير مطلوب',        'action' => 'none'],
                    ],
                ],
            ],
        ],

        'tyres' => [
            'label'    => 'Tyres',
            'label_ar' => 'الإطارات',
            'severity' => 'moderate',
            'results'  => [
                'ok' => [
                    'label'           => 'OK — tread and pressure fine',
                    'label_ar'        => 'سليمة — العمق والضغط جيد',
                    'creates_action'  => false,
                    'resolution_code' => 'confirmed_ok',
                ],
                'rotate' => [
                    'label'          => 'Rotation needed',
                    'label_ar'       => 'يلزم تدوير',
                    'creates_action' => true,
                    'kind'            => 'service',
                    // "Tire", the PLATFORM's canonical spelling — ServiceReminder::TYPES, the reminder
                    // sync and the diagnostic gate all write it, and config `routine_service_types`
                    // keys the reminder roll-forward on it. The service catalog's "Tyre Rotation" is
                    // the outlier; config/catalog_aliases.php bridges the two, so this classifies as a
                    // service AND rolls the tyre reminder when the ticket closes. Using the catalog's
                    // own spelling here classified correctly and silently never rolled the reminder,
                    // leaving the car overdue again the moment it was serviced.
                    'finding_keyword' => 'Tire Rotation',
                    'decisions'      => [
                        'approved'     => ['label' => 'Approved',     'label_ar' => 'معتمد',     'action' => 'open_task'],
                        'deferred'     => ['label' => 'Deferred',     'label_ar' => 'مؤجل',      'action' => 'defer'],
                        'not_required' => ['label' => 'Not required', 'label_ar' => 'غير مطلوب', 'action' => 'none'],
                    ],
                ],
                'replace' => [
                    'label'          => 'Replacement needed',
                    'label_ar'       => 'يلزم استبدال',
                    'creates_action' => true,
                    'kind'            => 'service',
                    'finding_keyword' => 'Tire Change',   // see the note above — canonical spelling
                    'decisions'      => [
                        'approved'     => ['label' => 'Approved',     'label_ar' => 'معتمد',     'action' => 'open_task'],
                        'deferred'     => ['label' => 'Deferred',     'label_ar' => 'مؤجل',      'action' => 'defer'],
                        'not_required' => ['label' => 'Not required', 'label_ar' => 'غير مطلوب', 'action' => 'none'],
                    ],
                ],
            ],
        ],

        'brakes' => [
            'label'    => 'Brakes',
            'label_ar' => 'الفرامل',
            'severity' => 'moderate',
            'results'  => [
                'ok' => [
                    'label'           => 'OK — pads and pedal fine',
                    'label_ar'        => 'سليمة — الفحمات والدواسة جيدة',
                    'creates_action'  => false,
                    'resolution_code' => 'confirmed_ok',
                ],
                'monitor' => [
                    'label'              => 'Monitor — wearing but serviceable',
                    'label_ar'           => 'للمراقبة — بها تآكل لكنها تعمل',
                    'creates_action'     => false,
                    'resolution_code'    => 'monitoring',
                    'reraise_after_days' => 30,
                ],
                // WEAR — planned upkeep, and a SERVICE. Pads reaching the end of their life on a
                // scheduled check is the system working, not the car breaking.
                'service_due' => [
                    'label'          => 'Pads due for replacement',
                    'label_ar'       => 'الفحمات تحتاج استبدال دوري',
                    'creates_action' => true,
                    'kind'            => 'service',
                    'finding_keyword' => 'Brake Pads (service)',
                    'decisions'      => [
                        'approved'     => ['label' => 'Approved',     'label_ar' => 'معتمد',     'action' => 'open_task'],
                        'deferred'     => ['label' => 'Deferred',     'label_ar' => 'مؤجل',      'action' => 'defer'],
                        'not_required' => ['label' => 'Not required', 'label_ar' => 'غير مطلوب', 'action' => 'none'],
                    ],
                ],
                // …and the other thing a brake check can find, which is genuinely a FAULT. Kept as a
                // separate result rather than folded into the one above, because "pads are worn" and
                // "something is wrong with the brakes" are different findings that route, cost and read
                // differently — and collapsing them would push every brake fault through a service row.
                'fault_found' => [
                    'label'          => 'Fault found (noise / pulling / warning)',
                    'label_ar'       => 'يوجد عطل (صوت / انحراف / تحذير)',
                    'creates_action' => true,
                    'kind'            => 'fault',
                    'finding_keyword' => 'Brake noise (squeal / grind)',
                    'decisions'      => [
                        'approved'     => ['label' => 'Approved',     'label_ar' => 'معتمد',     'action' => 'open_task'],
                        'deferred'     => ['label' => 'Deferred',     'label_ar' => 'مؤجل',      'action' => 'defer'],
                        'not_required' => ['label' => 'Not required', 'label_ar' => 'غير مطلوب', 'action' => 'none'],
                    ],
                ],
            ],
        ],

        'fluids' => [
            'label'    => 'Fluids',
            'label_ar' => 'السوائل',
            'severity' => 'routine',
            'results'  => [
                'ok' => [
                    'label'           => 'OK — all levels fine',
                    'label_ar'        => 'سليمة — جميع المستويات جيدة',
                    'creates_action'  => false,
                    'resolution_code' => 'confirmed_ok',
                ],
                // A level that has dropped is an abnormal condition, not a scheduled item — there is no
                // "fluid top-up" row in the service catalog and inventing one would be guessing at
                // vocabulary the workshop does not use. Deliberately a fault; say so if you disagree.
                'low' => [
                    'label'          => 'Low — needs topping up',
                    'label_ar'       => 'منخفضة — تحتاج تعبئة',
                    'creates_action' => true,
                    'kind'            => 'fault',
                    'finding_keyword' => 'Low fluid level',
                    'decisions'      => [
                        'approved'     => ['label' => 'Approved',     'label_ar' => 'معتمد',     'action' => 'open_task'],
                        'deferred'     => ['label' => 'Deferred',     'label_ar' => 'مؤجل',      'action' => 'defer'],
                        'not_required' => ['label' => 'Not required', 'label_ar' => 'غير مطلوب', 'action' => 'none'],
                    ],
                ],
                'leak' => [
                    'label'          => 'Leak found',
                    'label_ar'       => 'يوجد تسريب',
                    'creates_action' => true,
                    'kind'            => 'fault',
                    'finding_keyword' => 'Oil leak',
                    'decisions'      => [
                        'approved'     => ['label' => 'Approved',     'label_ar' => 'معتمد',     'action' => 'open_task'],
                        'deferred'     => ['label' => 'Deferred',     'label_ar' => 'مؤجل',      'action' => 'defer'],
                        'not_required' => ['label' => 'Not required', 'label_ar' => 'غير مطلوب', 'action' => 'none'],
                    ],
                ],
            ],
        ],

        /**
         * The catch-all, and deliberately the plainest one.
         *
         * A Service Reminder can be for anything an operator typed ("cabin filter", "wiper blades"),
         * so there is no domain-specific option list to offer. What the mechanism still guarantees is
         * the thing that matters: the check cannot be silently skipped, and "looked, fine" is a
         * recordable answer that creates no fault. An approved action here has no catalog keyword of
         * its own, so it carries the requirement's own label into the findings picker for the
         * inspector to place — the ONE case where the engine cannot pre-fill the vocabulary for him.
         */
        /**
         * THE PRE-EXPIRY WARRANTY INSPECTION — look at the car while somebody else is still paying.
         *
         * The single most valuable check in this catalog, and the only one whose deadline is set by a
         * contract rather than by wear. A defect found the week before cover ends is somebody else's
         * bill; the same defect found the week after is ours, and nothing about the car changed in
         * between. That asymmetry is the entire justification for asking.
         *
         * WHY IT LIVES HERE rather than as a bespoke reminder: it is an OBLIGATION — the system asked
         * a specific question about a specific car and a named human must answer it — which is
         * precisely what a check requirement is ([[check-requirement-three-way-contract]]). It
         * therefore inherits the whole lifecycle for free: it attaches to the next inspection that
         * looks at the car, it cannot be raised twice for the same warranty (the cycle key is the
         * warranty id), and an answer of "looked, nothing found" RESOLVES it and creates no fault.
         *
         * That last part is load-bearing. The temptation with a warranty inspection is to treat a
         * clean result as a wasted trip; it is the opposite. "We looked before cover ended and there
         * was nothing" is the answer that makes the next expiry defensible, and it must not
         * manufacture work to justify itself.
         *
         * `finding_keyword` is null on purpose — like `general`. A warranty inspection can turn up
         * anything at all, from a gearbox to a door seal, so the inspector names the fault from the
         * findings picker and the ordinary classification runs on their pick. Hard-coding a keyword
         * here would force every warranty discovery into one category and destroy the one report this
         * feature exists to produce: what manufacturers actually turn out to owe us.
         */
        'warranty_expiry' => [
            'label'    => 'Warranty expiry inspection',
            'label_ar' => 'فحص قبل انتهاء الضمان',
            // Moderate, not routine: the window closes on a date and does not reopen.
            'severity' => 'moderate',
            'results'  => [
                'ok' => [
                    'label'           => 'Inspected — nothing to claim',
                    'label_ar'        => 'تم الفحص — لا يوجد ما يُطالب به',
                    // Creates NOTHING. See the note above: a clean pre-expiry inspection is a
                    // successful one, and must never invent a fault to look useful.
                    'creates_action'  => false,
                    'resolution_code' => 'confirmed_ok',
                ],
                'claimable_found' => [
                    'label'          => 'Found something that should be claimed',
                    'label_ar'       => 'تم العثور على عطل يستحق مطالبة الضمان',
                    'creates_action' => true,
                    // Null on purpose — the inspector names the fault. See the note above.
                    'finding_keyword' => null,
                    'decisions'      => [
                        'approved'     => ['label' => 'Claim it',            'label_ar' => 'قدّم المطالبة', 'action' => 'open_task'],
                        'deferred'     => ['label' => 'Deferred',            'label_ar' => 'مؤجل',           'action' => 'defer'],
                        'not_required' => ['label' => 'Not required',        'label_ar' => 'غير مطلوب',      'action' => 'none'],
                    ],
                ],
            ],
        ],

        'general' => [
            'label'    => 'Scheduled check',
            'label_ar' => 'فحص مجدول',
            'severity' => 'routine',
            'results'  => [
                'ok' => [
                    'label'           => 'Checked — nothing needed',
                    'label_ar'        => 'تم الفحص — لا يلزم شيء',
                    'creates_action'  => false,
                    'resolution_code' => 'confirmed_ok',
                ],
                'action_required' => [
                    'label'          => 'Work needed',
                    'label_ar'       => 'يلزم عمل صيانة',
                    'creates_action' => true,
                    // No keyword: the inspector picks one in the findings picker (see the note above).
                    'finding_keyword' => null,
                    'decisions'      => [
                        'approved'     => ['label' => 'Approved',     'label_ar' => 'معتمد',     'action' => 'open_task'],
                        'deferred'     => ['label' => 'Deferred',     'label_ar' => 'مؤجل',      'action' => 'defer'],
                        'not_required' => ['label' => 'Not required', 'label_ar' => 'غير مطلوب', 'action' => 'none'],
                    ],
                ],
            ],
        ],
    ],

    /**
     * DiagnosticGateService condition `key` → check type.
     *
     * `reminder:{id}` keys resolve through `reminder_map` instead (they carry a service_type, not a
     * fixed key), and `downtime` fans OUT into several requirements — see `expansions`. Anything
     * unmapped degrades to `general`, which is why a new gate rule cannot go unchecked just because
     * nobody remembered to update this file.
     */
    'condition_map' => [
        'oil_change' => 'oil_change',
        'battery'    => 'battery',
        'inactivity' => 'general',
    ],

    /** ServiceReminder::service_type → check type, for `reminder:{id}` conditions. */
    'reminder_map' => [
        'oil_change'    => 'oil_change',
        'battery'       => 'battery',
        'tire_rotation' => 'tyres',
        'tire_change'   => 'tyres',
        'brakes'        => 'brakes',
        'brake_pads'    => 'brakes',
        'coolant'       => 'fluids',
    ],

    /**
     * Conditions that are an AGENDA rather than a single check, and the checks they become.
     *
     * The post-downtime rule says "go look at Battery, Fluids and Brakes" — that is three questions,
     * and collapsing them into one row would let an inspector answer "checked" while having looked at
     * one of the three. Each becomes its own requirement with its own answer, sharing the parent
     * condition's cycle so they raise and expire together.
     */
    'expansions' => [
        'downtime' => ['battery', 'fluids', 'brakes'],
    ],

    /**
     * How long a raised-but-unanswered requirement stays live before the raiser may replace it.
     * A check nobody answered in this long is stale evidence: the car has moved, been rented, maybe
     * been repaired. It is EXPIRED (an event, with its reason) rather than deleted — "nobody ever
     * checked it" is exactly the fact this whole feature exists to keep.
     */
    'stale_after_days' => 90,
];
