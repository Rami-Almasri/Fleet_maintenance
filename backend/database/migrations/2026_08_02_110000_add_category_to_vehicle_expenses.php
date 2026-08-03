<?php

use App\Services\Expenses\ExpenseCategoryClassifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * vehicle_expenses.category — the operational bucket each line belongs to (insurance / tyres / oil /
 * sub-rental …), derived from `remarks` by {@see ExpenseCategoryClassifier}.
 *
 * Why a stored column rather than classifying on read: `account_type` is "Expence" on effectively
 * every row, so the bucket has to come from free text — and the cost aggregates that need it
 * (totalsByVehicle, totalsByMonth) are single GROUP BY queries over the whole ledger. Classifying in
 * PHP would mean hydrating ~28k rows on every profitability and cost-per-km read. Stored + indexed,
 * "exclude sub-rental recharges from cost" stays one WHERE clause.
 *
 * The column is DERIVED, not source data: `remarks` remains untouched and re-runnable via
 * `php artisan expenses:classify`, which is also what the importer applies on write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_expenses', function (Blueprint $table) {
            $table->string('category', 32)->default(ExpenseCategoryClassifier::UNCATEGORISED)->after('remarks');
            $table->string('category_matched', 64)->nullable()->after('category'); // the term that decided it
            $table->index(['source', 'category']);
        });

        // Backfill immediately. A deployed column left at its 'other' default would silently exclude
        // nothing, which reads as "no sub-rental in this ledger" rather than as a missing backfill.
        if (class_exists(ExpenseCategoryClassifier::class)) {
            $classifier = new ExpenseCategoryClassifier();
            DB::table('vehicle_expenses')->select('id', 'remarks')->orderBy('id')
                ->chunk(2000, function ($rows) use ($classifier) {
                    foreach ($rows as $r) {
                        $c = $classifier->classify($r->remarks);
                        DB::table('vehicle_expenses')->where('id', $r->id)
                            ->update(['category' => $c['key'], 'category_matched' => $c['matched']]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('vehicle_expenses', function (Blueprint $table) {
            $table->dropIndex(['source', 'category']);
            $table->dropColumn(['category', 'category_matched']);
        });
    }
};
