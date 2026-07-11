<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact Reminders — a to-do tied to a garage / vendor: "Call {garage} about {thing}".
 * The Fleetio "Reminders" analogue for people you owe a follow-up (chase an invoice,
 * confirm a quote, collect parts, …).
 *
 * The reminder surfaces the vendor's name + phone so you know exactly who to call, and
 * can LINK to the specific invoice and/or maintenance ticket it's about — so the row is
 * click-through to the exact record instead of a hunt. Linked ids are nullable (a
 * reminder can be a plain note) and nullOnDelete so removing the target just unlinks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_reminders', function (Blueprint $table) {
            $table->id();

            // WHO to call. Nullable/nullOnDelete to survive a vendor purge; required at the
            // request layer (a contact reminder without a contact is meaningless).
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();

            $table->string('subject');                       // e.g. "Chase invoice #M-1042"
            $table->text('body')->nullable();                // free-text detail

            $table->timestamp('due_at')->nullable();         // when to be reminded
            $table->string('status', 12)->default('open');   // open | done | snoozed

            // Click-through targets — jump straight to the record this is about.
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('maintenance_id')->nullable()->constrained('maintenances')->nullOnDelete();

            // Ownership / assignment (snapshot who to follow up).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('vendor_id');
            $table->index('status');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_reminders');
    }
};
