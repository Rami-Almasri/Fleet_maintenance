<?php

namespace App\Console\Commands;

use Database\Seeders\VehicleComponentDemoSeeder;
use Illuminate\Console\Command;

/**
 * components:demo-fit — generate DEMO component history for named vehicles, and only those.
 *
 * `db:seed --class=VehicleComponentDemoSeeder` rebuilds the whole 80-car sample, which re-rolls every
 * demo car including ones a colleague has open in a browser. This runs the same seeder scoped to the
 * vehicles you name: it wipes those cars' demo rows and rebuilds them, and touches nothing else.
 *
 * THIS WRITES FAKE DATA. Every row is tagged {@see VehicleComponentDemoSeeder::MARKER} and comes back
 * out with `--remove`, so it is reversible — but it is still invented history sitting in the same
 * tables as real workflow output. Do not run it against a vehicle whose component record is real.
 *
 *   php artisan components:demo-fit 1741                       # fit one car
 *   php artisan components:demo-fit 1741 --min-generations=3    # guarantee repeat chains
 *   php artisan components:demo-fit 1741 --remove               # take it all back out
 */
class ComponentsDemoFitCommand extends Command
{
    protected $signature = 'components:demo-fit
        {vehicles* : Vehicle ids to fit}
        {--min-generations=0 : Floor for each slot\'s replacement chain (never raises a slot\'s own maximum)}
        {--remove : Delete these vehicles\' demo components instead of generating them}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Generate (or remove) demo installed-component history for specific vehicles';

    public function handle(): int
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->argument('vehicles'))));

        if (! $ids) {
            $this->error('No valid vehicle ids given.');

            return self::FAILURE;
        }

        $list = implode(', ', $ids);

        if ($this->option('remove')) {
            if (! $this->option('force') && ! $this->confirm("Delete demo components for vehicle(s) {$list}?", true)) {
                return self::SUCCESS;
            }

            VehicleComponentDemoSeeder::teardown($ids);
            $this->info("Removed demo components for vehicle(s) {$list}.");

            return self::SUCCESS;
        }

        $this->warn('This writes FAKE component history into ' . config('database.connections.' . config('database.default') . '.database') . '.');
        $this->line('  Every row is tagged ' . VehicleComponentDemoSeeder::MARKER . ' and can be removed with --remove.');

        if (! $this->option('force') && ! $this->confirm("Generate demo components for vehicle(s) {$list}?", true)) {
            return self::SUCCESS;
        }

        $seeder = new VehicleComponentDemoSeeder();
        $seeder->setCommand($this);
        $seeder->onlyVehicleIds = $ids;
        $seeder->minGenerations = (int) $this->option('min-generations');
        $seeder->run();

        $this->newLine();
        $this->line('  Check it derives from the events:  php artisan components:verify --vehicle=' . $ids[0]);
        $this->line('  Remove it again:                   php artisan components:demo-fit ' . $list . ' --remove');

        return self::SUCCESS;
    }
}
