<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WORKFLOW VERSION 1 — the process this fleet actually runs, not the one that was coded.
 *
 * Described by the owner:
 *
 *   "After the report the customer gets charged if he was the one at fault. Then an inspection is
 *    sent to the insurance company — they inspect the car and photograph it. Then Abu Maroof does a
 *    test drive if it can move, then maintenance. If it cannot move — recovery, then maintenance."
 *
 * Three things in that sentence were NOT in the hard-coded ladder, and each is why this table exists:
 *
 *  1. CHARGING COMES EARLY, right after fault is established — not parked at a "settlement" stage at
 *     the end. The renter is billed while the car is still being looked at.
 *  2. "INSURANCE" IS A PHYSICAL VISIT. The insurer comes, inspects, photographs. That is a different
 *     event from the claim decision, and the old ladder only modelled the decision.
 *  3. THE PROCESS FORKS. Drivable → the inspector test-drives it. Not drivable → recovery. Both ends
 *     rejoin at repair. Expressed with `applies_when` rather than a routing graph, because it
 *     branches on a FACT about the car.
 *
 * This is a SEED, not a specification. Every row below is editable, reorderable and disable-able from
 * the configuration screen, and doing so mints a new version rather than rewriting this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: a re-run on an environment that already has v1 must not mint a second.
        if (DB::table('accident_workflows')->where('version', 1)->exists()) {
            return;
        }

        $workflowId = DB::table('accident_workflows')->insertGetId([
            'version'           => 1,
            'name'              => 'Standard accident process',
            'status'            => 'active',
            'published_at'      => now(),
            'published_by_name' => 'System (initial configuration)',
            'notes'             => 'Seeded from the process as described by the operation: report → fault → charge the renter if theirs → insurer inspects and photographs → recovery or test drive depending on whether the car moves → repair.',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $stages = [
            [
                'key' => 'reported', 'label' => 'Accident Report', 'label_ar' => 'تقرير الحادث',
                'description' => 'The crash is on file: what happened, where, and who had the car.',
                // Nothing gates LEAVING the report — the report IS the act of creating the case.
                'requirement_key' => 'none', 'applies_when' => 'always',
                'is_initial' => true, 'blocks_rental' => true, 'tone' => '#e11d48',
            ],
            [
                // KEPT, even though the spoken description jumps straight from the report to the
                // charge. The police report is the document every insurer and every dispute asks for
                // first, and the gate that guards it is the most load-bearing rule in the feature.
                // If the office genuinely does not want it they can disable or move it in one click —
                // which is exactly the difference this whole change was made to create.
                'key' => 'police_report', 'label' => 'Police Report', 'label_ar' => 'تقرير الشرطة',
                'description' => 'The report is on file and has been read against the case — or waived, with a reason.',
                'requirement_key' => 'police_report', 'applies_when' => 'always',
                'blocks_rental' => true, 'tone' => '#f59e0b',
            ],
            [
                'key' => 'liability', 'label' => 'Liability', 'label_ar' => 'تحديد المسؤولية',
                'description' => 'Whose fault was it? Decided by a named person, never inferred from who was driving.',
                'requirement_key' => 'liability_decision', 'applies_when' => 'always',
                'blocks_rental' => true, 'tone' => '#8b5cf6',
            ],
            [
                // Only appears when the verdict actually put the money on the renter. On a case the
                // other party caused, this rung does not exist — which is what `applies_when` is for.
                'key' => 'charge_customer', 'label' => 'Charge the Customer', 'label_ar' => 'تحميل العميل',
                'description' => 'Bill the renter for the share the liability verdict put on them.',
                'requirement_key' => 'customer_charged', 'applies_when' => 'customer_liable',
                'blocks_rental' => true, 'tone' => '#f43f5e',
            ],
            [
                'key' => 'insurance_inspection', 'label' => 'Insurance Inspection', 'label_ar' => 'معاينة شركة التأمين',
                'description' => 'The insurer comes out, inspects the car and photographs the damage.',
                // The gate is the PHOTOS. A visit with no evidence filed is a visit nobody can prove
                // happened, and the insurer's own file is built from those images.
                'requirement_key' => 'document_uploaded', 'applies_when' => 'always',
                'requirement_config' => ['kinds' => ['damage_photo', 'insurance_claim', 'insurance_decision'], 'min' => 1],
                'blocks_rental' => true, 'tone' => '#3b82f6',
            ],
            [
                // THE FORK, half one. A car that cannot move is towed.
                'key' => 'recovery', 'label' => 'Recovery', 'label_ar' => 'سحب المركبة',
                'description' => 'The car cannot be driven — arrange recovery to the workshop.',
                'requirement_key' => 'manual_confirmation', 'applies_when' => 'vehicle_not_drivable',
                'blocks_rental' => true, 'tone' => '#f97316',
            ],
            [
                // THE FORK, half two. A car that still moves gets driven and judged.
                'key' => 'test_drive', 'label' => 'Test Drive', 'label_ar' => 'تجربة القيادة',
                'description' => 'The inspector drives it and says what it needs.',
                'requirement_key' => 'manual_confirmation', 'applies_when' => 'vehicle_drivable',
                'blocks_rental' => true, 'tone' => '#d946ef',
            ],
            [
                'key' => 'repair', 'label' => 'Repair', 'label_ar' => 'الإصلاح',
                'description' => 'The car is in the workshop — an ordinary maintenance ticket, parented to this case.',
                'requirement_key' => 'repair_linked', 'applies_when' => 'always',
                'blocks_rental' => true, 'tone' => '#6366f1',
            ],
            [
                // Optional by design: an accident whose money never fully settles is a real and common
                // ending, and holding the case open for an insurer would fill the board with rows
                // nobody can act on. @see is_mandatory
                'key' => 'settlement', 'label' => 'Financial Settlement', 'label_ar' => 'التسوية المالية',
                'description' => 'What was recovered, what was written off, what is still owed.',
                'requirement_key' => 'none', 'applies_when' => 'always',
                'is_mandatory' => false, 'blocks_rental' => false, 'tone' => '#06b6d4',
            ],
            [
                'key' => 'closed', 'label' => 'Closed', 'label_ar' => 'مغلق',
                'description' => 'Finished — whatever the answer turned out to be.',
                'requirement_key' => 'none', 'applies_when' => 'always',
                'is_terminal' => true, 'blocks_rental' => false, 'tone' => '#64748b',
            ],
        ];

        $position = 1;
        foreach ($stages as $s) {
            DB::table('accident_workflow_stages')->insert([
                'workflow_id'        => $workflowId,
                'key'                => $s['key'],
                'label'              => $s['label'],
                'label_ar'           => $s['label_ar'],
                'description'        => $s['description'],
                'position'           => $position++,
                'is_enabled'         => true,
                'is_initial'         => $s['is_initial']   ?? false,
                'is_terminal'        => $s['is_terminal']  ?? false,
                'is_mandatory'       => $s['is_mandatory'] ?? true,
                'blocks_rental'      => $s['blocks_rental'],
                'requirement_key'    => $s['requirement_key'],
                'requirement_config' => isset($s['requirement_config']) ? json_encode($s['requirement_config']) : null,
                'applies_when'       => $s['applies_when'],
                'tone'               => $s['tone'],
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }

        // ── EXISTING CASES ─────────────────────────────────────────────────────────────────────
        //
        // Pin every case that predates versioning to v1, and translate the old hard-coded stage keys
        // onto the new ones. Two of the old keys are gone (`awaiting_police` and `assessment` are no
        // longer separate rungs in the process as described), so they are mapped to the nearest rung
        // that means the same thing operationally rather than left dangling.
        $map = [
            'reported'        => 'reported',
            'awaiting_police' => 'police_report',         // the rung that still chases the paperwork
            'assessment'      => 'insurance_inspection',  // "somebody is looking at the damage"
            'liability'       => 'liability',
            'insurance'       => 'insurance_inspection',
            'repair'          => 'repair',
            'settlement'      => 'settlement',
            'closed'          => 'closed',
        ];

        DB::table('accident_cases')->update(['workflow_id' => $workflowId]);
        foreach ($map as $old => $new) {
            if ($old !== $new) {
                DB::table('accident_cases')->where('stage', $old)->update(['stage' => $new]);
            }
        }
    }

    public function down(): void
    {
        $id = DB::table('accident_workflows')->where('version', 1)->value('id');
        if ($id) {
            DB::table('accident_cases')->where('workflow_id', $id)->update(['workflow_id' => null]);
            DB::table('accident_workflow_stages')->where('workflow_id', $id)->delete();
            DB::table('accident_workflows')->where('id', $id)->delete();
        }
    }
};
