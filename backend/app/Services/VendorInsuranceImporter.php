<?php

namespace App\Services;

use App\Models\Vendor;
use Throwable;

/**
 * Pulls the distinct insurance companies from the "F Insurance" tab into the
 * vendors table (type = insurance). Standalone — does not need the per-car sync.
 */
class VendorInsuranceImporter
{
    public function __construct(protected GoogleSheetsService $sheets)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function import(): array
    {
        $cfg  = config('google.sheets.insurance');
        $rows = $this->sheets->readByGid($cfg['id'], (int) $cfg['gid']);

        if (count($rows) < 2) {
            return ['error' => 'No data found.'];
        }

        $map = $this->headerMap($rows[0]);
        $idx = $map['insuranceco'] ?? null;

        if ($idx === null) {
            return ['error' => "Missing 'Insurance Co.' column."];
        }

        $created  = 0;
        $existing = 0;
        $seen     = [];

        foreach (array_slice($rows, 1) as $row) {
            $name = trim((string) ($row[$idx] ?? ''));
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            try {
                $vendor = Vendor::firstOrCreate(
                    ['name' => $name, 'type' => 'insurance'],
                    ['active' => true, 'origin' => 'sheet']
                );
                $vendor->wasRecentlyCreated ? $created++ : $existing++;
            } catch (Throwable $e) {
                // skip malformed names
            }
        }

        return [
            'distinct_companies' => count($seen),
            'created'            => $created,
            'already_existed'    => $existing,
        ];
    }

    /** @return array<string,int> */
    protected function headerMap(array $header): array
    {
        $map = [];
        foreach ($header as $i => $h) {
            $key = strtolower(preg_replace('/[^a-z0-9]/i', '', str_replace(["\r", "\n"], ' ', (string) $h)));
            if ($key !== '' && ! isset($map[$key])) {
                $map[$key] = $i;
            }
        }
        return $map;
    }
}
