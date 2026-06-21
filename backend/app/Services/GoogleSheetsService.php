<?php

namespace App\Services;

use Google\Client;
use Google\Service\Sheets;
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
        $client->setScopes([Sheets::SPREADSHEETS_READONLY]);

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
}
