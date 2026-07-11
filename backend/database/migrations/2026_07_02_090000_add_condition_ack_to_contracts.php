<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Condition Acknowledgment (the "Omar Protocol" booking gate).
 *
 * A Red-graded car is a hard safety block and never reaches a contract. Green is clean.
 * Orange (cosmetic) and Yellow (needs service) cars STAY bookable — we maximise utilisation —
 * but the sales agent must confirm the customer was told about the car's condition before
 * handover. We record that acknowledgment here, snapshotting the grade + note as they were
 * at booking time, so the confirmation is auditable and tied to the contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('condition_ack_grade', 10)->nullable()->after('origin');   // orange | yellow at booking
            $table->text('condition_ack_note')->nullable()->after('condition_ack_grade'); // snapshot of the cosmetic note
            $table->string('condition_ack_by')->nullable()->after('condition_ack_note');   // who confirmed
            $table->timestamp('condition_ack_at')->nullable()->after('condition_ack_by');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['condition_ack_grade', 'condition_ack_note', 'condition_ack_by', 'condition_ack_at']);
        });
    }
};
