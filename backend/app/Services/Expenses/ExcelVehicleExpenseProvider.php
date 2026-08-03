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
 *
 * COST EXCLUSIONS. Every aggregate that answers "what did this vehicle COST" — total, totalsByVehicle,
 * totalsByMonth, linesByVehicle — drops the categories listed in `expenses.excluded_categories`
 * (sub-rental recharges: cars hired IN from other companies and billed through the same ledger, which
 * are a rental transaction, not spend on the asset). {@see history()} is the deliberate exception: it
 * returns EVERY line, each flagged `excluded`, so the drawer can show what came out of the number
 * instead of quietly shrinking it.
 */
class ExcelVehicleExpenseProvider implements VehicleExpenseProvider
{
    private const SOURCE = 'excel';

    /** {@inheritDoc} */
    public function exclusions(): array
    {
        $labels = ExpenseCategoryClassifier::labels();
        $out = [];
        foreach ((array) config('expenses.excluded_categories', []) as $key => $reason) {
            $out[] = [
                'key'    => (string) $key,
                'label'  => $labels[$key] ?? (string) $key,
                'reason' => (string) $reason,
            ];
        }

        return $out;
    }

    /**
     * Category keys that are in the ledger but are not spend on the vehicle. Read from config so the
     * policy is one line to change and one place to audit.
     *
     * @return array<int,string>
     */
    private function excludedCategories(): array
    {
        return array_map('strval', array_keys((array) config('expenses.excluded_categories', [])));
    }

    /** Apply the cost exclusions to a query. The ONE place the policy is enforced. */
    private function costOnly($query)
    {
        $excluded = $this->excludedCategories();

        return $excluded ? $query->whereNotIn('category', $excluded) : $query;
    }

    /** {@inheritDoc} */
    public function totalsByVehicle(?array $vehicleIds = null, ?string $from = null, ?string $to = null): array
    {
        $rows = $this->costOnly(DB::table('vehicle_expenses'))
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
        $total = $this->costOnly(DB::table('vehicle_expenses'))
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
        $rows = $this->costOnly(DB::table('vehicle_expenses'))
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

    /**
     * {@inheritDoc}
     *
     * Returns EVERY line, including the ones the cost aggregates exclude — each carrying `excluded`
     * so the drawer can show them under their own heading. A total that dropped AED X has to be able
     * to name the lines it dropped.
     */
    public function history(int $vehicleId, ?string $from = null, ?string $to = null): array
    {
        $labels   = ExpenseCategoryClassifier::labels();
        $excluded = $this->excludedCategories();

        return DB::table('vehicle_expenses')
            ->where('source', self::SOURCE)
            ->where('vehicle_id', $vehicleId)
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))
            ->orderByRaw('entry_date IS NULL, entry_date ASC')   // oldest first; undated last
            ->orderBy('id')
            ->get(['entry_date', 'remarks', 'amount', 'account_type', 'category', 'category_matched'])
            ->map(function ($r) use ($labels, $excluded) {
                $key = $r->category ?: ExpenseCategoryClassifier::UNCATEGORISED;

                return [
                    'date'             => $r->entry_date ? substr((string) $r->entry_date, 0, 10) : null,
                    'remarks'          => $r->remarks,
                    'amount'           => round((float) $r->amount, 2),
                    'account_type'     => $r->account_type,
                    'category'         => $key,
                    'category_label'   => $labels[$key] ?? 'Uncategorised',
                    'category_matched' => $r->category_matched,
                    'excluded'         => in_array($key, $excluded, true),
                ];
            })
            ->all();
    }

    /** {@inheritDoc} */
    public function linesByVehicle(?array $vehicleIds = null, ?string $from = null, ?string $to = null): array
    {
        $out = [];
        $this->costOnly(DB::table('vehicle_expenses'))
            ->where('source', self::SOURCE)
            ->whereNotNull('vehicle_id')
            ->when($vehicleIds !== null, fn ($q) => $q->whereIn('vehicle_id', $vehicleIds))
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))
            ->orderBy('id')
            // Chunked: the full ledger is ~28k rows and hydrating it in one collection is avoidable.
            ->select(['vehicle_id', 'entry_date', 'remarks', 'amount'])
            ->chunk(5000, function ($rows) use (&$out) {
                foreach ($rows as $r) {
                    $out[(int) $r->vehicle_id][] = [
                        'date'    => $r->entry_date ? substr((string) $r->entry_date, 0, 10) : null,
                        'remarks' => $r->remarks,
                        'amount'  => round((float) $r->amount, 2),
                    ];
                }
            });

        return $out;
    }

    /**
     * {@inheritDoc}
     *
     * Judged on ENTRY dates, not import dates. A re-import of the same frozen spreadsheet refreshes
     * `imported_at` for every row and would report a dead ledger as healthy — the only honest question
     * is whether new SPENDING is arriving, so the verdict rests on entry_date and the trend rests on
     * comparing the last 90 days against the 90 before them.
     */
    public function freshness(): array
    {
        $base = fn () => DB::table('vehicle_expenses')->where('source', self::SOURCE);

        $lastImport = $base()->max('imported_at');
        $lastEntry = $base()->whereNotNull('entry_date')
            // Ledgers routinely carry a few future-dated rows (a prepayment, a keying slip). Those must
            // not be read as "data arrived today" — that would mask a source that died months ago.
            ->whereDate('entry_date', '<=', now()->toDateString())
            ->max('entry_date');

        // Carbon 3's diffInDays is SIGNED and reads "from $this to the argument" — the operands have to
        // run oldest-first or a ledger updated yesterday reports as −1 days old.
        $days = $lastEntry
            ? (int) \Illuminate\Support\Carbon::parse($lastEntry)->startOfDay()->diffInDays(now()->startOfDay())
            : null;

        $lines30 = $base()->whereDate('entry_date', '>=', now()->subDays(30)->toDateString())
            ->whereDate('entry_date', '<=', now()->toDateString())->count();
        $lines90 = $base()->whereDate('entry_date', '>=', now()->subDays(90)->toDateString())
            ->whereDate('entry_date', '<=', now()->toDateString())->count();
        $prior90 = $base()->whereDate('entry_date', '>=', now()->subDays(180)->toDateString())
            ->whereDate('entry_date', '<', now()->subDays(90)->toDateString())->count();

        [$status, $message] = $this->verdict($days, $lines30, $lines90, $prior90);

        return [
            'last_import'      => $lastImport ? (string) $lastImport : null,
            'last_entry'       => $lastEntry ? substr((string) $lastEntry, 0, 10) : null,
            'days_since_entry' => $days,
            'lines_30d'        => $lines30,
            'lines_90d'        => $lines90,
            'prior_90d'        => $prior90,
            'status'           => $status,
            'message'          => $message,
        ];
    }

    /**
     * The verdict, in the terms someone deciding whether to trust a cost figure actually needs.
     *
     * `declining` exists because it is the failure that would otherwise be missed: a sheet nobody has
     * formally stopped maintaining, but which is receiving a fraction of what it used to. That looks
     * entirely healthy on a "last updated" date and is exactly when cost estimates start drifting.
     *
     * @return array{0:string, 1:string}
     */
    private function verdict(?int $days, int $lines30, int $lines90, int $prior90): array
    {
        if ($days === null) {
            return ['unknown', 'No dated expense lines at all — freshness cannot be judged.'];
        }
        if ($days > 180) {
            return ['frozen', "No expense recorded for {$days} days. This source has stopped: anything derived from it describes the past, not the fleet today."];
        }
        if ($days > 60) {
            return ['stale', "Last expense was {$days} days ago. Check whether the sheet is still being maintained before relying on cost figures."];
        }
        // A source can be recent and still be dying — half the volume it used to carry is a real signal.
        if ($prior90 > 20 && $lines90 < $prior90 * 0.5) {
            return ['declining', "Only {$lines90} lines in the last 90 days against {$prior90} in the 90 before. The sheet is still being updated, but far less than it was."];
        }
        if ($lines30 === 0) {
            return ['stale', 'Nothing recorded in the last 30 days, though older entries are recent enough. Worth confirming the import is still running.'];
        }
        return ['current', "{$lines30} expense lines in the last 30 days — the source is being maintained."];
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
