<?php

namespace App\Services;

use App\Models\Vendor;
use Illuminate\Support\Str;

/**
 * Imports garages & used-parts ("scrap") shops from the garages sheet into vendors.
 *
 * Sheet layout (gid 1494017154):
 *   [0] Garage   [1] Name(empty)   [2] phone(empty)
 *   [3] SCRAP NAME   [4] SCRAP DESCIPTION (specialization)   [5] SCRAP PHONE NUMBER
 * Cols 0 and 3 are two independent lists; col 3 occasionally holds a Google Maps URL
 * that belongs to the garage in col 0.
 */
class GarageImporter
{
    public function __construct(private GoogleSheetsService $sheets) {}

    public function import(string $spreadsheetId, int $gid): array
    {
        $rows = $this->sheets->readByGid($spreadsheetId, $gid, 0);
        array_shift($rows); // drop header

        $garages = 0;
        $parts = 0;

        foreach ($rows as $row) {
            $garage = $this->clean($row[0] ?? '');
            $col3   = $this->clean($row[3] ?? '');     // scrap name OR a maps url
            $spec   = $this->clean($row[4] ?? '');     // specialization (German/American/…)
            $phone3 = $this->clean($row[5] ?? '');

            $col3IsUrl = $col3 !== '' && Str::startsWith($col3, ['http://', 'https://']);

            // 1) Garage / shop from col 0
            if ($garage !== '') {
                Vendor::updateOrCreate(
                    ['external_id' => 'garage:' . $this->key($garage)],
                    [
                        'name'      => $garage,
                        'type'      => $this->classify($garage),
                        'phone'     => $this->clean($row[2] ?? '') ?: null,
                        'notes'     => $col3IsUrl ? $col3 : null, // attach the garage's map link
                        'origin'    => 'sheet',
                        'active'    => true,
                        'synced_at' => now(),
                    ]
                );
                $garages++;
            }

            // 2) Scrap / used-parts shop from col 3 (skip when it's a maps url)
            if ($col3 !== '' && ! $col3IsUrl) {
                Vendor::updateOrCreate(
                    ['external_id' => 'scrap:' . $this->key($col3)],
                    [
                        'name'      => $col3,
                        'type'      => 'parts_supplier',
                        'phone'     => $phone3 ?: null,
                        'notes'     => $spec ?: null,
                        'origin'    => 'sheet',
                        'active'    => true,
                        'synced_at' => now(),
                    ]
                );
                $parts++;
            }
        }

        return ['garages' => $garages, 'parts' => $parts];
    }

    /** Trim, collapse internal whitespace/newlines. */
    private function clean($v): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $v));
    }

    /** Stable dedup key for an (often Arabic) name. */
    private function key(string $name): string
    {
        return mb_strtolower($this->clean($name));
    }

    /** Guess a vendor type from the shop name. */
    private function classify(string $name): string
    {
        $n = mb_strtolower($name);
        return match (true) {
            Str::contains($n, ['used part', 'used spare', 'spare part', 'scrap', 'parts']) => 'parts_supplier',
            Str::contains($n, ['glass', 'windshield'])                                     => 'service_center',
            Str::contains($n, ['key'])                                                     => 'service_center',
            Str::contains($n, ['showroom', 'trading', 'accessories'])                      => 'other',
            default                                                                        => 'garage',
        };
    }
}
