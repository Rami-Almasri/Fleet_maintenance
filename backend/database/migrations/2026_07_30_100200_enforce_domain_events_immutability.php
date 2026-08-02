<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutability at the database, not only in the model.
 *
 * WHY THIS IS NEEDED. The Eloquent guards on [[DomainEvent]] cover the path application code
 * normally takes, but they are bypassed entirely by a mass update — `DomainEvent::query()->update()`
 * fires no model events, and neither does a raw statement, a migration, or someone at a MySQL
 * prompt. Since the whole value of this table is that a reader never has to wonder whether it was
 * edited, "immutable unless you use the other API" is not immutable.
 *
 * These triggers close that. Correcting a record means inserting one that supersedes it — which
 * remains possible from every path, because inserts are untouched.
 *
 * SQLITE IS SKIPPED. Its trigger syntax differs and some suites run on an in-memory database where
 * this guarantee is not what is under test. The model guards still apply there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->supportsTriggers()) {
            return;
        }

        // Dropped first so a re-run is clean. This migration creates two triggers in two statements
        // with no transaction around them (MySQL does not roll back DDL), so a failure on the second
        // leaves the first behind and the migration unrecorded — the retry would then die on "trigger
        // already exists" instead of on the real cause. That is not hypothetical: it is how the
        // deploy that first hit error 1419 left the database.
        DB::unprepared('DROP TRIGGER IF EXISTS domain_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS domain_events_no_delete');

        DB::unprepared('
            CREATE TRIGGER domain_events_no_update
            BEFORE UPDATE ON domain_events
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\'
                SET MESSAGE_TEXT = \'domain_events is append-only: record a superseding event instead of updating.\';
            END
        ');

        DB::unprepared('
            CREATE TRIGGER domain_events_no_delete
            BEFORE DELETE ON domain_events
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\'
                SET MESSAGE_TEXT = \'domain_events is append-only: events cannot be deleted.\';
            END
        ');
    }

    public function down(): void
    {
        if (! $this->supportsTriggers()) {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS domain_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS domain_events_no_delete');
    }

    private function supportsTriggers(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
