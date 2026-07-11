<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Strip the implicit "ON UPDATE CURRENT_TIMESTAMP" MySQL auto-attached to maintenance_task_assignments
 * .assigned_at (its first non-null TIMESTAMP column). Left in place, every UPDATE to a stint row — e.g.
 * stamping released_at when a fault is transferred — silently rewrote assigned_at to the DB clock,
 * corrupting the stint's start time and any per-garage duration computed from it.
 *
 * Redefining the column with an explicit DEFAULT CURRENT_TIMESTAMP (and no ON UPDATE clause) removes the
 * auto-update; assigned_at is always set explicitly in code, so the default is never actually used.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE maintenance_task_assignments MODIFY assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        // Restore the prior (buggy) auto-updating definition.
        DB::statement('ALTER TABLE maintenance_task_assignments MODIFY assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }
};
