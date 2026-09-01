<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\GoogleSheetsService;
use App\Services\SpareKeyInventoryImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import the "Spare keys" register so every car's profile shows the keys it actually holds.
 *
 *   php artisan spare-keys:import-inventory --dry-run   # resolve and report, write nothing
 *   php artisan spare-keys:import-inventory             # apply
 *
 * Idempotent and top-up only: it brings each car UP to the register's count and never removes.
 * @see \App\Services\SpareKeyInventoryImporter for the plate-matching rules and why they are strict.
 */
class SpareKeysImportInventory extends Command
{
    protected $signature = 'spare-keys:import-inventory
        {--sheet= : Spreadsheet ID (defaults to config google.sheets.spare_keys.id)}
        {--gid= : Tab gid (defaults to config google.sheets.spare_keys.gid)}
        {--file= : Read rows from a local JSON dump instead of Google (offline / testing)}
        {--actor= : User id to attribute the backfill to (defaults to the first super-admin)}
        {--dry-run : Preview only, write nothing}';

    protected $description = 'Backfill vehicle spare keys from the "Spare keys" Google Sheet register (top-up only, never removes)';

    public function handle(SpareKeyInventoryImporter $importer, GoogleSheetsService $sheets): int
    {
        $dry = (bool) $this->option('dry-run');

        try {
            $rows = $this->rows($sheets);
        } catch (Throwable $e) {
            $this->error('Could not read the register: ' . $e->getMessage());

            return self::FAILURE;
        }

        $actor = $this->actor();
        if (! $actor) {
            $this->error('No actor — pass --actor=<user id>. Every asset write is attributed to a person.');

            return self::FAILURE;
        }

        $this->info($dry ? 'DRY RUN — nothing will be written.' : "Backfilling spare keys as {$actor->name}…");

        $result = $importer->import($rows, $actor, $dry);

        // Problems first: a row nobody looks at is a row nobody fixes.
        foreach ($result['rows'] as $r) {
            if (in_array($r['outcome'], ['unmatched', 'zero_on_hand', 'surplus'], true)) {
                $this->warn(sprintf('  %-14s %-28s %s', strtoupper($r['outcome']), $r['model'], $r['detail']));
            }
        }

        $counts = array_count_values(array_column($result['rows'], 'outcome'));
        $this->newLine();
        foreach ($counts as $outcome => $n) {
            $this->line(sprintf('  %-16s %d', $outcome, $n));
        }

        $this->newLine();
        $this->info(sprintf('%d cars carry keys in the register; %d key(s) written%s.',
            $result['applied'], $result['created'], $dry ? ' (dry run — none)' : ''));

        return self::SUCCESS;
    }

    /** @return array<int, array<int, string>> */
    private function rows(GoogleSheetsService $sheets): array
    {
        if ($file = $this->option('file')) {
            $json = json_decode((string) file_get_contents($file), true);

            // Accept either a raw row array or the multi-tab dump shape.
            return $json['Spare keys'] ?? $json['rows'] ?? $json;
        }

        $id  = (string) ($this->option('sheet') ?: config('google.sheets.spare_keys.id'));
        $gid = (int) ($this->option('gid') ?? config('google.sheets.spare_keys.gid'));

        if ($id === '') {
            throw new \RuntimeException('No spreadsheet id — set GOOGLE_SHEETS_SPARE_KEYS_ID or pass --sheet.');
        }

        return $sheets->readByGid($id, $gid);
    }

    /**
     * WHO the backfill is attributed to. Asset rows carry an actor because every component event
     * names the person behind it; a backfill is still somebody's decision to run.
     */
    private function actor(): ?User
    {
        if ($id = $this->option('actor')) {
            return User::find($id);
        }

        return User::role('super-admin')->orderBy('id')->first() ?? User::orderBy('id')->first();
    }
}
