<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle Inspection Workflow store. One row per captured condition photo and/or
 * manual damage finding, linked to the contract + vehicle being inspected.
 *
 * The actual image bytes live in S3-compatible object storage — only the object
 * KEY (s3_key) is kept here, never the file — so the database stays small and the
 * photos are served via short-lived signed URLs. `s3_key` is nullable so a damage
 * finding can be recorded even before (or without) a photo.
 *
 * The rich manual flag fields (damage_type, severity, note) are what feed the
 * planned "Fleet Health" reports, so they're first-class indexed columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_records', function (Blueprint $table) {
            $table->id();

            // what this inspection belongs to (a rental/maintenance contract + the car)
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();

            // who inspected (snapshot the name so the record survives a user deletion)
            $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('inspector_name')->nullable();

            // where in the rental life-cycle, and which body zone (e.g. 'door_front_right')
            $table->string('phase', 20)->default('pre');   // pre = pre-rental, post = post-return
            $table->string('body_part', 40);

            // the image — bytes go to object storage, only the key is stored here
            $table->string('s3_disk', 30)->default('s3');
            $table->string('s3_key')->nullable();
            $table->string('mime_type', 60)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();   // compressed size in bytes
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // rich manual damage flag (Option A). Null type/severity = no damage on this record.
            $table->boolean('damage_flagged')->default(false);
            $table->string('damage_type', 20)->nullable();   // scratch | dent | glass_crack | other
            $table->string('severity', 10)->nullable();      // low | medium | high
            $table->text('note')->nullable();

            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->index(['contract_id', 'phase', 'body_part']);
            $table->index('vehicle_id');
            $table->index('damage_flagged');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_records');
    }
};
