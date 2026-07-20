<?php

namespace Database\Seeders;

use App\Models\PlateCode;
use Illuminate\Database\Seeder;

/**
 * Seeds the plate-code dictionary (OM PlateColorNo / EmNo → plate letter) from the
 * version-controlled CSV at database/data/plate_codes.csv. Idempotent (upsert on em_no), so it
 * is safe to run on the server as part of `php artisan db:seed --class=PlateCodeSeeder` (or via
 * DatabaseSeeder). Mirrors the `plate:import-codes` command; either populates the same table.
 */
class PlateCodeSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/plate_codes.csv');
        if (! is_file($path)) {
            $this->command?->warn("PlateCodeSeeder: CSV not found at {$path} — skipping.");

            return;
        }

        $fh = fopen($path, 'r');
        fgetcsv($fh); // header: em_no,letter_en,letter_ar
        $rows = [];
        while (($r = fgetcsv($fh)) !== false) {
            if (! isset($r[0]) || ! is_numeric($r[0])) {
                continue;
            }
            $rows[] = [
                'em_no'     => (int) $r[0],
                'letter_en' => $r[1] ?? null,
                'letter_ar' => $r[2] ?? null,
            ];
        }
        fclose($fh);

        // upsert in one statement (idempotent on the unique em_no)
        foreach (array_chunk($rows, 200) as $chunk) {
            PlateCode::upsert($chunk, ['em_no'], ['letter_en', 'letter_ar']);
        }

        $this->command?->info('PlateCodeSeeder: ' . count($rows) . ' plate codes seeded (total ' . PlateCode::count() . ').');
    }
}
