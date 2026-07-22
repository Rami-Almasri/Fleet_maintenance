<?php

namespace App\Services\Expenses;

use App\Contracts\VehicleExpenseProvider;
use App\Models\VehicleExpense;
use Illuminate\Support\Facades\DB;

/**
 * Excel-backed expense provider — reads the imported `vehicle_expenses` rows (source = 'excel') and
 * NOTHING else. This is the temporary source of truth for vehicle expense; swapping to Odoo later means
 * a sibling OdooVehicleExpenseProvider bound in place of this one (same {@see VehicleExpenseProvider}
 * contract), with zero change to any consumer.
 *
 * Expense per vehicle = Σ amount (amount = debit − credit) of its lines. All reads are scoped to
 * source = 'excel' so a future provider's rows never bleed in.
 */
class ExcelVehicleExpenseProvider implements VehicleExpenseProvider
{
    private const SOURCE = 'excel';

    /** {@inheritDoc} */
    public function totalsByVehicle(?array $vehicleIds = null, ?string $from = null, ?string $to = null): array
    {
        $rows = DB::table('vehicle_expenses')
            ->where('source', self::SOURCE)
            ->whereNotNull('vehicle_id')
            ->when($vehicleIds !== null, fn ($q) => $q->whereIn('vehicle_id', $vehicleIds))
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))
            ->groupBy('vehicle_id')
            ->select('vehicle_id', DB::raw('SUM(amount) as total'))
            ->pluck('total', 'vehicle_id');

        $out = [];
        foreach ($rows as $vid => $total) {
            $out[(int) $vid] = round((float) $total, 2);
        }

        return $out;
    }

    /** {@inheritDoc} */
    public function total(int $vehicleId, ?string $from = null, ?string $to = null): ?float
    {
        $total = DB::table('vehicle_expenses')
            ->where('source', self::SOURCE)
            ->where('vehicle_id', $vehicleId)
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))
            ->sum('amount');

        // A vehicle with no lines at all reads as "unknown" (null), not a fabricated 0.
        if (! $this->hasAnyLine($vehicleId)) {
            return null;
        }

        return round((float) $total, 2);
    }

    /** {@inheritDoc} */
    public function totalsByMonth(?string $from = null, ?string $to = null, ?array $vehicleIds = null): array
    {
        $rows = DB::table('vehicle_expenses')
            ->where('source', self::SOURCE)
            ->whereNotNull('vehicle_id')
            ->whereNotNull('entry_date')
            ->when($vehicleIds !== null, fn ($q) => $q->whereIn('vehicle_id', $vehicleIds))
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))
            ->groupByRaw("DATE_FORMAT(entry_date, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(entry_date, '%Y-%m') as ym, SUM(amount) as total, COUNT(*) as line_count")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->ym] = [
                'total' => round((float) $r->total, 2),
                'lines' => (int) $r->line_count,
            ];
        }

        return $out;
    }

    /** {@inheritDoc} */
    public function history(int $vehicleId, ?string $from = null, ?string $to = null): array
    {
        return DB::table('vehicle_expenses')
            ->where('source', self::SOURCE)
            ->where('vehicle_id', $vehicleId)
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))
            ->orderByRaw('entry_date IS NULL, entry_date ASC')   // oldest first; undated last
            ->orderBy('id')
            ->get(['entry_date', 'remarks', 'amount', 'account_type'])
            ->map(fn ($r) => [
                'date'         => $r->entry_date ? substr((string) $r->entry_date, 0, 10) : null,
                'remarks'      => $r->remarks,
                'amount'       => round((float) $r->amount, 2),
                'account_type' => $r->account_type,
            ])
            ->all();
    }

    /** {@inheritDoc} */
    public function source(): array
    {
        $count = VehicleExpense::where('source', self::SOURCE)->count();
        $asOf  = VehicleExpense::where('source', self::SOURCE)->max('imported_at');

        return [
            'label'     => (string) config('expenses.source_label', 'Expenses sheet'),
            'available' => $count > 0,
            'as_of'     => $asOf ? (string) $asOf : null,
            'lines'     => $count,
        ];
    }

    private function hasAnyLine(int $vehicleId): bool
    {
        return DB::table('vehicle_expenses')
            ->where('source', self::SOURCE)
            ->where('vehicle_id', $vehicleId)
            ->exists();
    }
}
