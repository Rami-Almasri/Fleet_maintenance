<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE REQUESTER'S STATEMENT — what the person who sent the car in actually said, kept structured.
 *
 * Until now a request carried one free-text field (`customer_complaint`). A sentence is fine for a
 * human to read and useless for everything else: you cannot count it, you cannot match it against the
 * fault this car was in the shop for last month, and you cannot tell "brake noise" typed by a driver
 * from "brakes" typed by a controller. These three columns record the SAME statement as data:
 *
 *   request_detail_mode  — HOW they said it, and it is exclusive by design (see Maintenance::REPORT_MODES).
 *                          A person names a fault, OR picks an operational reason, OR writes a note —
 *                          never two at once. Two answers to "why is this car going in?" is two stories.
 *   reported_faults      — mode `fault`: the picked fault types, each row
 *                          { text, slug?, fault_catalog_id?, category_key?, severity?, repeat_of_ticket_id?,
 *                            last_seen_at? }. `repeat_of_ticket_id` is the whole point of the picker: it
 *                          is the requester saying "this is the same thing you fixed on ticket #812",
 *                          which is a CLAIM by the requester, never a confirmed recurrence — only the
 *                          workshop confirms that (see RecurringFaultService).
 *   request_reason_code  — mode `reason`: a CODE from the `request_reasons` table, never English. (That
 *                          list was Maintenance::REQUEST_REASON_CODES until 2026-09-06, when it became
 *                          editable data so this column could be joined and filtered on.)
 *                          The label beside it is presentation and may be reworded or translated
 *                          without rewriting history (see [[reason-code-contract]]).
 *
 * `customer_complaint` is UNCHANGED and still written on every path — the structured statement is
 * rendered into it so every existing surface (the inspector's screen, the board card, the audit log,
 * the notification body) keeps reading one field and needs no change. These columns are the fact;
 * customer_complaint is its sentence.
 *
 * Null on every row created before this existed, which reads correctly as "we only ever had the note".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->string('request_detail_mode', 12)->nullable()->after('customer_complaint');
            $table->json('reported_faults')->nullable()->after('request_detail_mode');
            $table->string('request_reason_code', 40)->nullable()->after('reported_faults');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn(['request_detail_mode', 'reported_faults', 'request_reason_code']);
        });
    }
};
