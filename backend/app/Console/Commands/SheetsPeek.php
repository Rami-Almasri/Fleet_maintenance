<?php

namespace App\Console\Commands;

use App\Services\GoogleSheetsService;
use Illuminate\Console\Command;
use Throwable;

class SheetsPeek extends Command
{
    /**
     * Examples:
     *   php artisan sheets:peek                          (cars sheet from .env, first rows)
     *   php artisan sheets:peek --tabs                   (list all tabs of the cars sheet)
     *   php artisan sheets:peek {sheetId} --tabs         (list tabs of any sheet)
     *   php artisan sheets:peek {sheetId} {gid}          (peek a specific tab)
     *   php artisan sheets:peek {sheetId} {gid} --rows=10
     */
    protected $signature = 'sheets:peek
        {sheet? : Spreadsheet ID (defaults to GOOGLE_SHEETS_CARS_ID)}
        {gid? : Tab gid (defaults to GOOGLE_SHEETS_CARS_GID)}
        {--rows=6 : How many rows to read (including the header)}
        {--tabs : List all tabs of the spreadsheet instead of reading a tab}';

    protected $description = 'Peek at a Google Sheet: list its tabs, or print a tab\'s header row + first rows';

    public function handle(GoogleSheetsService $sheets): int
    {
        $spreadsheetId = $this->argument('sheet') ?: config('google.sheets.cars.id');

        if (! $spreadsheetId) {
            $this->error('No spreadsheet id provided and GOOGLE_SHEETS_CARS_ID is not set in .env.');
            return self::FAILURE;
        }

        try {
            // --tabs : just list the tabs and their gids
            if ($this->option('tabs')) {
                $tabs = $sheets->listTabs($spreadsheetId);
                $this->info("Tabs in spreadsheet {$spreadsheetId}:");
                $this->table(
                    ['Title', 'gid'],
                    array_map(fn ($t) => [$t['title'], $t['gid']], $tabs)
                );
                return self::SUCCESS;
            }

            $gid  = $this->argument('gid') !== null
                ? (int) $this->argument('gid')
                : (int) config('google.sheets.cars.gid');

            $rows = max(1, (int) $this->option('rows'));

            $data = $sheets->readByGid($spreadsheetId, $gid, $rows);

            if (empty($data)) {
                $this->warn('No data returned. The tab may be empty, or the sheet is not shared with the service account.');
                return self::SUCCESS;
            }

            // Header row
            $headers = $data[0];
            $this->info('Headers (' . count($headers) . ' columns):');
            foreach ($headers as $i => $header) {
                $this->line(sprintf('  [%2d] %s', $i, $header));
            }

            // Data rows
            $dataRows = array_slice($data, 1);
            if (! empty($dataRows)) {
                $this->newLine();
                $this->info('First ' . count($dataRows) . ' data row(s):');
                foreach ($dataRows as $r => $row) {
                    $this->line('  Row ' . ($r + 2) . ': ' . implode(' | ', array_map(fn ($c) => (string) $c, $row)));
                }
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
