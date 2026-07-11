<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Video Evidence for maintenance tickets — the garage's repair videos, uploaded to the ticket by a
 * supervisor (Waleed/Abdullah) as the PERMANENT record the QA review is based on. A ticket can hold
 * several (one per repair pass). Deliberately its OWN table (not maintenances columns or the
 * image-oriented inspection_records) so a ticket can carry many videos without bloating the row.
 *
 * Storage mirrors the odometer-photo pattern: bytes live on S3 when configured, else the local
 * `public` disk; only the disk + key + metadata are stored here. See [[maintenance-workflow-engine]].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_media', function (Blueprint $table) {
            $table->id();
            // Loose link to the ticket (mirrors line_items / tasks — no hard FK, matches the codebase style).
            $table->unsignedBigInteger('maintenance_id')->index();
            $table->string('kind', 20)->default('video'); // 'video' today; room for 'photo'/'doc' later
            $table->string('disk', 30)->default('s3');     // s3 | public
            $table->string('s3_key', 1024);                // object key on the disk
            $table->string('content_type', 100)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('note', 500)->nullable();       // what the video shows (optional supervisor note)
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('uploaded_by_name', 191)->nullable(); // snapshot, so history survives a user rename
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_media');
    }
};
