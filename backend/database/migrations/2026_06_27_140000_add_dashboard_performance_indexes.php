<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard performance indexes.
 *
 * The homepage KPIs (DashboardService) repeatedly hit two hot access patterns that
 * had no supporting index and were running as full table scans on `contracts`
 * (~38k rows), each costing seconds:
 *
 *   1. currentlyOpen() scope: WHERE state='open' AND in_date IS NULL
 *      — used ~5x per summary() (active contracts, cars in maintenance,
 *        manual-garage diff, fleet donut, bookings).
 *   2. The Real-Profit / overdue aggregates: WHERE contract_type=? AND out_date >= ?
 *      GROUP BY vehicle_id.
 *
 * Open contracts are a small slice of the table, so (state, in_date) turns a
 * 38k-row scan into a tiny range. (contract_type, out_date) covers the type+window
 * filter shared by negative-yield, overdue rentals and overdue maintenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (! $this->hasIndex('contracts', 'contracts_state_in_date_index')) {
                $table->index(['state', 'in_date'], 'contracts_state_in_date_index');
            }
            if (! $this->hasIndex('contracts', 'contracts_contract_type_out_date_index')) {
                $table->index(['contract_type', 'out_date'], 'contracts_contract_type_out_date_index');
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            // totalOutstandingBalance(): WHERE balance > 0 over ~17k rows.
            if (! $this->hasIndex('customers', 'customers_balance_index')) {
                $table->index('balance', 'customers_balance_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if ($this->hasIndex('contracts', 'contracts_state_in_date_index')) {
                $table->dropIndex('contracts_state_in_date_index');
            }
            if ($this->hasIndex('contracts', 'contracts_contract_type_out_date_index')) {
                $table->dropIndex('contracts_contract_type_out_date_index');
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            if ($this->hasIndex('customers', 'customers_balance_index')) {
                $table->dropIndex('customers_balance_index');
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->pluck('Key_name')
            ->contains($index);
    }
};
