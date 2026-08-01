<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove `maintenances.delegated_driver_id` — a column that exists only by drift.
 *
 * `php artisan schema:health --drift` compares the live database against a database built by running the
 * migrations from empty, and found this column (plus its foreign key and that key's index) present live
 * and absent from a clean build. It therefore never came from a migration: a fresh deploy would not have
 * it, and no environment created after today would either.
 *
 * It is genuinely dead, not merely unused-for-now — verified before writing this:
 *   • 0 non-NULL values across all 26,839 maintenance rows
 *   • 0 references anywhere in the backend source
 *   • driver delegation is actually carried by the columns that DO come from migrations —
 *     `delegated_by`, `delegated_at`, `delegation_task`, `delegation_status`
 *
 * Dropping is the right direction rather than adding it to a migration: capturing it would preserve a
 * column nothing reads, forever, in every future environment. Removing it makes the live schema and a
 * clean build converge, which is what stops the drift check sitting permanently yellow — and a warning
 * that is always on is a warning everybody learns to ignore.
 *
 * SAFETY: refuses to run if any row has a value, so this can never silently discard data on an
 * environment that used the column differently. Idempotent — a no-op where the column is already absent,
 * so it is safe on a fresh install. Reversible: down() restores the column and its foreign key.
 *
 * See [[migrations-cannot-run-from-empty]].
 */
return new class extends Migration
{
    private const TABLE = 'maintenances';
    private const COLUMN = 'delegated_driver_id';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;   // fresh install — the drift never existed here
        }

        // Never destroy data as a side effect of a deploy. If another environment genuinely populated
        // this column, that is a conversation, not an automatic drop.
        $populated = DB::table(self::TABLE)->whereNotNull(self::COLUMN)->count();
        if ($populated > 0) {
            throw new RuntimeException(sprintf(
                'Refusing to drop %s.%s: %d row(s) hold a value. Migrate that data deliberately first.',
                self::TABLE, self::COLUMN, $populated,
            ));
        }

        // Drop the foreign key before the column. Its name is looked up rather than assumed, because a
        // hand-made column may carry a hand-made constraint name.
        foreach ($this->foreignKeys() as $constraint) {
            DB::statement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', self::TABLE, $constraint));
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn(self::COLUMN);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->foreignId(self::COLUMN)->nullable()->after('delegated_by')
                ->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Foreign-key constraint names on the column, from information_schema so it works on both MariaDB
     * (local) and MySQL 8 (production).
     *
     * @return array<int, string>
     */
    private function foreignKeys(): array
    {
        return array_map(
            fn ($r) => $r->constraint_name,
            DB::select(
                'SELECT DISTINCT constraint_name FROM information_schema.key_column_usage
                 WHERE table_schema = ? AND table_name = ? AND column_name = ?
                   AND referenced_table_name IS NOT NULL',
                [DB::getDatabaseName(), self::TABLE, self::COLUMN],
            ),
        );
    }
};
