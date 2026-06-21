<?php

namespace App\Console\Commands;

use App\Models\MaintenanceReason;
use App\Services\GoogleSheetsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import the "Main reason" tab (maintenance reason -> status vocabulary) into
 * maintenance_reasons. Columns: [0] ID, [1] status, [2] Main reason (EN),
 * [3] Arabic name, [4] explanation. Header on row 1, data from row 2.
 */
class ImportMaintenanceReasons extends Command
{
    protected $signature = 'import:maintenance-reasons {--dry-run}';

    protected $description = 'Import the maintenance reason->status vocabulary from the "Main reason" sheet tab';

    /** Sheet status text -> our normalized priority level. */
    private const LEVEL_MAP = [
        'major / critical'      => 'critical',
        'cosmetic / minor'      => 'minor',
        'maintenance / routine' => 'routine',
        'special case'          => 'special',
    ];

    public function handle(GoogleSheetsService $sheets): int
    {
        $sheetId = (string) config('google.sheets.maintenance.id');
        $gid     = (int) config('google.sheets.maintenance.reasons_gid');

        if ($sheetId === '') {
            $this->error('GOOGLE_SHEETS_MAINTENANCE_ID is not set in .env.');
            return self::FAILURE;
        }

        try {
            $rows = $sheets->readByGid($sheetId, $gid, 0); // whole tab
        } catch (Throwable $e) {
            $this->error('Could not read the sheet: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (count($rows) < 2) {
            $this->warn('No data rows found.');
            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $created = 0; $updated = 0; $skipped = 0;
        $byLevel = ['critical' => 0, 'minor' => 0, 'routine' => 0, 'special' => 0];

        // skip header row (row 1)
        foreach (array_slice($rows, 1) as $row) {
            $reasonEn = trim((string) ($row[2] ?? ''));
            if ($reasonEn === '') {
                $skipped++;
                continue;
            }

            $statusRaw = trim((string) ($row[1] ?? ''));
            $key = strtolower(preg_replace('/\s+/', ' ', $statusRaw));
            $level = self::LEVEL_MAP[$key] ?? 'routine';
            $byLevel[$level]++;

            $data = [
                'sheet_ref'   => is_numeric($row[0] ?? null) ? (int) $row[0] : null,
                'reason_ar'   => trim((string) ($row[3] ?? '')) ?: null,
                'status_raw'  => $statusRaw ?: null,
                'level'       => $level,
                'explanation' => trim((string) ($row[4] ?? '')) ?: null,
            ];

            if ($dry) {
                $this->line(sprintf('  %-32s -> %-8s (%s)', $reasonEn, $level, $statusRaw));
                continue;
            }

            $model = MaintenanceReason::updateOrCreate(['reason_en' => $reasonEn], $data);
            $model->wasRecentlyCreated ? $created++ : $updated++;
        }

        $this->info(sprintf(
            '%s reasons: +%d new, %d updated, %d skipped  |  critical %d · minor %d · routine %d · special %d',
            $dry ? '[dry-run]' : 'Imported',
            $created, $updated, $skipped,
            $byLevel['critical'], $byLevel['minor'], $byLevel['routine'], $byLevel['special']
        ));

        return self::SUCCESS;
    }
}
