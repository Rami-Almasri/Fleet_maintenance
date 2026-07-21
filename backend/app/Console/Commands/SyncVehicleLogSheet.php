<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\VehicleLogEvent;
use App\Services\GoogleSheetsService;
use App\Services\VehicleLogSheetExporter;
use Illuminate\Console\Command;

/**
 * Push the whole fleet's maintenance-workflow audit trail (vehicle_log_events) to the
 * "Vehicle Timeline" Google Sheet, one row per event.
 *
 * INCREMENTAL by default: a high-water mark (the last event id pushed) is kept in app_settings, so
 * each run appends ONLY the events created since the previous run — cheap to re-run and safe to
 * schedule. Rows are appended in id order (oldest → newest at the bottom), the natural order for a
 * running log.
 *
 *   php artisan events:sync-sheet            # append new events since last run
 *   php artisan events:sync-sheet --fresh    # wipe the tab, rewrite header, resync ALL events
 *   php artisan events:sync-sheet --dry-run  # report what would be pushed, write nothing
 */
class SyncVehicleLogSheet extends Command
{
    protected $signature = 'events:sync-sheet
                            {--fresh : Clear the tab and re-export every event from scratch}
                            {--dry-run : Report what would be pushed without writing to the sheet}';

    protected $description = 'Append new vehicle timeline events to the Vehicle Timeline Google Sheet';

    /** app_settings key holding the id of the last event pushed to the sheet. */
    private const MARKER = 'vehicle_log_sheet_last_id';

    /** Push in bounded chunks so one run never builds a giant in-memory payload. */
    private const CHUNK = 500;

    public function handle(GoogleSheetsService $sheets, VehicleLogSheetExporter $exporter): int
    {
        $spreadsheetId = (string) config('google.sheets.events_export.id');
        $tab           = (string) config('google.sheets.events_export.tab');

        if ($spreadsheetId === '') {
            $this->error('No export sheet configured (GOOGLE_SHEETS_EVENTS_ID).');
            return self::FAILURE;
        }

        $fresh  = (bool) $this->option('fresh');
        $dryRun = (bool) $this->option('dry-run');

        $lastId = $fresh ? 0 : (int) AppSetting::get(self::MARKER, 0);

        $pending = VehicleLogEvent::where('id', '>', $lastId)->count();
        if ($pending === 0) {
            $this->info("Nothing to sync — sheet already current at event #{$lastId}.");
            return self::SUCCESS;
        }

        $this->info(($fresh ? 'Fresh rebuild' : 'Incremental sync') . ": {$pending} event(s) to push (after #{$lastId}).");

        if ($dryRun) {
            $this->line('[dry-run] no changes written.');
            return self::SUCCESS;
        }

        // Make sure the tab exists and carries a header. On --fresh we wipe + rewrite it.
        $sheets->ensureTab($spreadsheetId, $tab);
        if ($fresh) {
            $sheets->clearTab($spreadsheetId, $tab);
        }
        $existing = $sheets->readRange($spreadsheetId, "'{$tab}'!A1:A1");
        if ($fresh || empty($existing)) {
            $sheets->writeRows($spreadsheetId, "'{$tab}'!A1", [VehicleLogSheetExporter::HEADER]);
        }

        $pushed = 0;
        $maxId  = $lastId;

        VehicleLogEvent::with(['vehicle', 'actor'])
            ->where('id', '>', $lastId)
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($events) use ($sheets, $exporter, $spreadsheetId, $tab, &$pushed, &$maxId) {
                $sheets->appendRows($spreadsheetId, $tab, $exporter->rows($events));
                $pushed += $events->count();
                $maxId = max($maxId, (int) $events->max('id'));
                AppSetting::put(self::MARKER, $maxId);   // advance after each chunk lands → resumable
                $this->line("  …{$pushed} pushed (through #{$maxId})");
            });

        $this->info("Done. {$pushed} event(s) appended to '{$tab}'. High-water mark: #{$maxId}.");

        return self::SUCCESS;
    }
}
