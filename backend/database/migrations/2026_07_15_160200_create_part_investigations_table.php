<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * part_investigations — the admin accountability record raised when the intelligence layer detects a
 * repeated spend or a repeated fault. Mirrors the existing MaintenanceIncident acknowledgement-gate
 * pattern (an exception that opens automatically and is held open until a human resolves it).
 *
 * Two triggers:
 *   duplicate_purchase — the same part was bought for the same vehicle within a class-dependent window
 *   fault_recurrence   — a previously-fixed fault came back within a short window
 *
 * The record carries a full context snapshot (previous date / cost / technician / days-between) so an
 * admin can adjudicate without chasing joins, and walks Open → Under Review → Reason Provided →
 * Approved/Rejected → Closed. It NEVER blocks the underlying purchase; it forces the reason to be
 * captured and routes a HIGH alert to the admins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_investigations', function (Blueprint $table) {
            $table->id();

            $table->string('type', 20);                          // 'duplicate_purchase' | 'fault_recurrence'
            $table->string('priority', 10)->default('medium');   // 'low' | 'medium' | 'high'
            $table->string('status', 20)->default('open')->index();
            //   open → under_review → reason_provided → approved|rejected → closed

            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();

            // Duplicate-purchase linkage.
            $table->foreignId('part_purchase_id')->nullable()->constrained('part_purchases')->nullOnDelete();
            $table->foreignId('previous_purchase_id')->nullable()->constrained('part_purchases')->nullOnDelete();

            // Fault-recurrence linkage.
            $table->foreignId('maintenance_task_id')->nullable()->constrained('maintenance_tasks')->nullOnDelete();
            $table->foreignId('previous_task_id')->nullable()->constrained('maintenance_tasks')->nullOnDelete();

            // The reason the second spend / repeat happened (captured from the buyer or admin).
            $table->string('reason_code', 40)->nullable();
            //   previous_part_failed | wrong_diagnosis | customer_requested | accident_damage | other
            $table->text('reason_note')->nullable();

            // Context snapshot for the admin (prev date, prev cost, prev tech, days-between, window, class …).
            $table->json('context')->nullable();

            // --- audit stamps (nullable actor + name snapshot; opened_by null = system-raised) ---
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('opened_by_name')->nullable();
            $table->timestamp('opened_at')->nullable();

            $table->foreignId('reason_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason_by_name')->nullable();
            $table->timestamp('reason_at')->nullable();

            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolved_by_name')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 20)->nullable();        // 'approved' | 'rejected'

            $table->timestamps();

            $table->index(['vehicle_id', 'status']);
            $table->index(['status', 'priority']);
            $table->index(['type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_investigations');
    }
};
