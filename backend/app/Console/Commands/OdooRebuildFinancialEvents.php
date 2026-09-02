<?php

namespace App\Console\Commands;

use App\Models\FuelFill;
use App\Models\LogisticsTask;
use App\Models\Maintenance;
use App\Models\MaintenanceInvoice;
use App\Models\VehicleRegistration;
use App\Models\VehicleWashJob;
use App\Services\Odoo\FinancialEventBuilder;
use Illuminate\Console\Command;

/**
 * Rebuild financial events from the operational records that produce them.
 *
 * This is safe to run at any time and is the answer to three different situations:
 *
 *   BACKFILL      the integration was installed after the operational data existed. Every garage bill
 *                 and every recovery leg already in the system needs its obligation raised once.
 *   REPAIR        an event failed to build because of a transient fault (the invoice service's hook is
 *                 deliberately best-effort so a bridge problem can never lose a bill).
 *   RE-VALIDATION mappings changed in bulk, and the blocked queue should reflect the new reality.
 *
 * It is safe because {@see FinancialEventBuilder::syncFor()} is an UPSERT keyed on the source, and
 * because it refuses to touch anything already sent to Odoo. Running it a hundred times produces the
 * same events with the same ids, and can never post anything twice — this command never sends.
 */
class OdooRebuildFinancialEvents extends Command
{
    protected $signature = 'odoo:rebuild-events
        {--source=all : all|invoices|recoveries|fuel|washes|registrations|taxi}
        {--limit=0 : stop after N records (0 = no limit)}';

    protected $description = 'Rebuild financial events from every operational producer (never sends anything)';

    /**
     * Every producer, and the query that finds the records that actually carry a cost.
     *
     * Each entry filters to rows with money on them. That is not an optimisation: scanning 27,000
     * maintenance tickets to build nothing is a long way to do no work, and a builder call on a record
     * with no obligation is a wasted transaction. The builder itself would return null for all of them.
     *
     * @return array<string, callable():\Illuminate\Database\Eloquent\Builder>
     */
    private function producers(): array
    {
        return [
            // A garage bill — REPAIR or ROUTINE depending on the ticket it sits under.
            'invoices' => fn () => MaintenanceInvoice::with(['lineItems', 'maintenance.vehicle', 'vendor'])
                ->where('amount', '>', 0),

            // The tow leg of a ticket.
            'recoveries' => fn () => Maintenance::with(['vehicle', 'recoveryVendor'])
                ->whereNotNull('recovery_cost')->where('recovery_cost', '>', 0),

            'fuel' => fn () => FuelFill::with(['vehicle', 'vendor'])
                ->where('cost', '>', 0),

            // Only EXTERNAL washes were bought from anyone; an internal one raises nothing by design.
            'washes' => fn () => VehicleWashJob::with(['vehicle', 'vendor'])
                ->where('performed_by_kind', VehicleWashJob::BY_EXTERNAL)
                ->whereNotNull('cost')->where('cost', '>', 0),

            'registrations' => fn () => VehicleRegistration::with(['vehicle', 'renewalVendor'])
                ->whereNotNull('renewal_cost')->where('renewal_cost', '>', 0),

            'taxi' => fn () => LogisticsTask::with(['vehicle'])
                ->whereNotNull('taxi_fare')->where('taxi_fare', '>', 0),
        ];
    }

    public function handle(FinancialEventBuilder $builder): int
    {
        $source    = (string) $this->option('source');
        $limit     = (int) $this->option('limit');
        $producers = $this->producers();

        if ($source !== 'all' && ! isset($producers[$source])) {
            $this->error("Unknown source {$source}. Use all|" . implode('|', array_keys($producers)) . '.');

            return self::FAILURE;
        }

        $wanted = $source === 'all' ? array_keys($producers) : [$source];
        $built  = 0;

        foreach ($wanted as $name) {
            $this->line(ucfirst($name) . ' …');
            $before = $built;

            $producers[$name]()
                ->when($limit > 0, fn ($q) => $q->limit($limit))
                ->chunkById(200, function ($records) use ($builder, &$built) {
                    foreach ($records as $record) {
                        if ($builder->syncFor($record)) {
                            $built++;
                        }
                    }
                });

            $this->info('  ' . ($built - $before) . ' event(s)');
        }

        $this->newLine();
        $this->info("{$built} financial event(s) created or refreshed. Nothing was sent to Odoo.");

        return self::SUCCESS;
    }
}
