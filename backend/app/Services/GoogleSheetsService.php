<?php

namespace App\Services;

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\AddSheetRequest;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\ClearValuesRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Google\Service\Sheets\SheetProperties;
use Google\Service\Sheets\ValueRange;
use RuntimeException;

class GoogleSheetsService
{
    protected Sheets $service;

    public function __construct()
    {
        $path = $this->resolveCredentialsPath();

        $client = new Client();
        $client->setApplicationName('Fleet Sheets Sync');
        $client->setAuthConfig($path);
        // Full read/write: this service both PULLS source sheets (importers) and PUSHES the
        // vehicle-timeline export. SPREADSHEETS is a superset of the old READONLY scope, so every
        // existing reader keeps working unchanged.
        $client->setScopes([Sheets::SPREADSHEETS]);

        $this->service = new Sheets($client);
    }

    /**
     * Resolve the credentials file path (supports relative or absolute).
     */
    protected function resolveCredentialsPath(): string
    {
        $configured = (string) config('google.credentials');

        $isAbsolute = str_starts_with($configured, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $configured) === 1;

        $path = $isAbsolute ? $configured : base_path($configured);

        if (! is_file($path)) {
            throw new RuntimeException(
                "Google credentials file not found at: {$path}. " .
                "Place the service-account JSON there or fix GOOGLE_SHEETS_CREDENTIALS in .env."
            );
        }

        return $path;
    }

    /**
     * List all tabs in a spreadsheet: [['title' => ..., 'gid' => ...], ...].
     *
     * @return array<int, array{title: string, gid: int}>
     */
    public function listTabs(string $spreadsheetId): array
    {
        $spreadsheet = $this->service->spreadsheets->get($spreadsheetId);

        $tabs = [];
        foreach ($spreadsheet->getSheets() as $sheet) {
            $props = $sheet->getProperties();
            $tabs[] = [
                'title' => $props->getTitle(),
                'gid'   => (int) $props->getSheetId(),
            ];
        }

        return $tabs;
    }

    /**
     * Find a tab's title from its gid.
     */
    public function titleForGid(string $spreadsheetId, int $gid): ?string
    {
        foreach ($this->listTabs($spreadsheetId) as $tab) {
            if ($tab['gid'] === $gid) {
                return $tab['title'];
            }
        }

        return null;
    }

    /**
     * Read a raw range in A1 notation. Returns rows (each row = list of cell values).
     *
     * @return array<int, array<int, mixed>>
     */
    public function readRange(string $spreadsheetId, string $range): array
    {
        $response = $this->service->spreadsheets_values->get($spreadsheetId, $range);

        return $response->getValues() ?? [];
    }

    /**
     * Read a tab by its gid. $maxRows = 0 reads the whole tab.
     *
     * @return array<int, array<int, mixed>>
     */
    public function readByGid(string $spreadsheetId, int $gid, int $maxRows = 0): array
    {
        $title = $this->titleForGid($spreadsheetId, $gid);

        if ($title === null) {
            throw new RuntimeException("No tab with gid {$gid} found in spreadsheet {$spreadsheetId}.");
        }

        // Single-quote the title so tabs with spaces (e.g. 'Oil Change') work.
        $range = $maxRows > 0
            ? "'{$title}'!A1:ZZ{$maxRows}"
            : "'{$title}'";

        return $this->readRange($spreadsheetId, $range);
    }

    // ── Write side (used by the vehicle-timeline export) ─────────────────────────────

    /** Create a tab if it doesn't already exist (no-op when it does). */
    public function ensureTab(string $spreadsheetId, string $title): void
    {
        foreach ($this->listTabs($spreadsheetId) as $tab) {
            if ($tab['title'] === $title) {
                return;
            }
        }

        $request = new SheetsRequest([
            'addSheet' => new AddSheetRequest([
                'properties' => new SheetProperties(['title' => $title]),
            ]),
        ]);

        $this->service->spreadsheets->batchUpdate(
            $spreadsheetId,
            new BatchUpdateSpreadsheetRequest(['requests' => [$request]])
        );
    }

    /**
     * Append rows to the bottom of a tab (INSERT_ROWS — never overwrites existing data).
     *
     * @param array<int, array<int, mixed>> $rows
     * @return int number of rows appended
     */
    public function appendRows(string $spreadsheetId, string $tabTitle, array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $this->service->spreadsheets_values->append(
            $spreadsheetId,
            "'{$tabTitle}'!A1",
            new ValueRange(['values' => $rows]),
            ['valueInputOption' => 'RAW', 'insertDataOption' => 'INSERT_ROWS']
        );

        return count($rows);
    }

    /**
     * Overwrite a range starting at A1 notation (used to (re)write the header row).
     *
     * @param array<int, array<int, mixed>> $rows
     */
    public function writeRows(string $spreadsheetId, string $range, array $rows): void
    {
        $this->service->spreadsheets_values->update(
            $spreadsheetId,
            $range,
            new ValueRange(['values' => $rows]),
            ['valueInputOption' => 'RAW']
        );
    }

    /** Wipe every value in a tab (keeps the tab + formatting). Used by a --fresh rebuild. */
    public function clearTab(string $spreadsheetId, string $tabTitle): void
    {
        $this->service->spreadsheets_values->clear(
            $spreadsheetId,
            "'{$tabTitle}'",
            new ClearValuesRequest()
        );
    }
}
