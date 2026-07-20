<?php

namespace App\Console\Commands;

use App\Models\PlateCode;
use Illuminate\Console\Command;

/**
 * Import the plate-code dictionary (OM PlateColorNo / EmNo → plate letter) from the
 * version-controlled CSV at database/data/plate_codes.csv (exported from PlateCode.xlsx).
 * Idempotent upsert keyed on em_no. Read-only against everything else.
 */
class PlateImportCodes extends Command
{
    protected $signature = 'plate:import-codes {--path= : CSV path (defaults to database/data/plate_codes.csv)}';

    protected $description = 'Import the plate-code → letter dictionary (idempotent)';

    public function handle(): int
    {
        $path = $this->option('path') ?: database_path('data/plate_codes.csv');
        if (! is_file($path)) {
            $this->error("CSV not found: {$path}");

            return self::FAILURE;
        }

        $fh = fopen($path, 'r');
        $header = fgetcsv($fh); // em_no,letter_en,letter_ar
        $n = 0;
        while (($r = fgetcsv($fh)) !== false) {
            if (! isset($r[0]) || ! is_numeric($r[0])) {
                continue;
            }
            PlateCode::updateOrCreate(
                ['em_no' => (int) $r[0]],
                ['letter_en' => $r[1] ?? null, 'letter_ar' => $r[2] ?? null],
            );
            $n++;
        }
        fclose($fh);

        $this->info("Imported/updated {$n} plate codes. Total in table: " . PlateCode::count());

        return self::SUCCESS;
    }
}
