<?php

namespace App\Console\Commands\Concerns;

/**
 * A demo tool must never be able to write financial rows into the live database.
 *
 * This is not hypothetical: a demo seeder polluted the live `laravel` schema once already and had to be
 * purged by hand. Demo commands create part purchases and cost lines directly — bypassing the audited
 * services and their gates — so on the live schema they would manufacture exactly the untraceable money
 * the financial workflow exists to prevent.
 *
 * The guard refuses on the production environment, and refuses on ANY database named like the live one,
 * because the environment flag is the thing most likely to be wrong on a developer's machine — a local
 * `.env` pointed at `laravel` is the realistic accident, and APP_ENV would still read "local".
 */
trait GuardsDemoWrites
{
    /**
     * Returns true when it is safe to write demo data. Prints the reason and returns false otherwise.
     */
    protected function demoWritesAllowed(): bool
    {
        $database = (string) config('database.connections.' . config('database.default') . '.database');

        if (app()->environment('production')) {
            $this->error('Refused: this is a demo tool and the environment is production.');

            return false;
        }

        // The live schema, whatever the environment claims to be.
        if (in_array($database, ['laravel', 'fleet', 'production'], true)) {
            $this->error("Refused: this is a demo tool and the target database is '{$database}', which is the live schema.");
            $this->line('  Point DB_DATABASE at a scratch schema (e.g. laravel_demo) and run it again.');

            return false;
        }

        $this->warn("Writing demo data into '{$database}'.");

        return true;
    }
}
