<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The car's own paperwork, scanned — starting with the Mulkiya (the UAE Vehicle Licence /
 * رخصة مركبة card that carries the plate, chassis no., owner, registration + insurance expiry
 * and the mortgage holder).
 *
 * WHY A TABLE AND NOT A COLUMN ON `vehicles`. A Mulkiya is re-issued: every renewal, plate
 * change or ownership edit produces a NEW card, and the old one is what the previous period
 * was operated under. So "change the photo" must never mean "lose the last one" — a replacement
 * stamps `superseded_at` on the outgoing row and inserts a new current one. At most ONE row per
 * (vehicle, kind) has `superseded_at = NULL`; that row is the card in force today, and the rest
 * are the card's history, with who uploaded each and when.
 *
 * This table holds ONLY the pointer to the bytes + metadata. The image itself goes to the local
 * `public` disk (or S3 when configured), exactly like the cleaning/inspection photo trail.
 *
 * It is deliberately NOT the source of truth for the DATES on the card — `vehicle_registrations`
 * owns expiry_date / insurance_expiry, synced from OfficeManager. This is the scan a manager or
 * a traffic officer needs to actually see; the numbers stay where they already live.
 *
 * `kind` is open (default 'mulkiya') so the same trail can carry insurance certificates and
 * other per-car documents later without another migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32)->default('mulkiya');   // 'mulkiya' today; insurance/other later

            // --- where the bytes are ---
            $table->string('disk', 32)->default('public');    // 'public' locally, 's3' when configured
            $table->string('file_path');                      // relative key on that disk
            $table->string('original_name')->nullable();       // what the uploader's file was called
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('note', 500)->nullable();

            // --- who put it there ---
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('uploaded_by_name')->nullable();    // kept verbatim so history survives a deleted user

            // dateTime(), not timestamp() — see the MariaDB ON UPDATE CURRENT_TIMESTAMP trap.
            $table->dateTime('uploaded_at');
            // NULL = this is the card in force. Set when a newer scan replaces it.
            $table->dateTime('superseded_at')->nullable();
            $table->timestamps();

            // The one query this table exists to answer: "the current Mulkiya for car X".
            $table->index(['vehicle_id', 'kind', 'superseded_at'], 'vehicle_documents_current_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_documents');
    }
};
