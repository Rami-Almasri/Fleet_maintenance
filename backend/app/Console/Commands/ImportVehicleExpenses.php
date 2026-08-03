<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\Expenses\ExpenseCategoryClassifier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

/**
 * Import the vehicle-expense sheet into `vehicle_expenses` — the SOLE source of vehicle expense.
 *
 * The sheet is a GL expense export: one row per expense line with columns
 *   CarSerial | Account type | Remarks | VoucherDate | Debit | Credit
 * Per-vehicle expense = Σ(Debit − Credit). We store every line (so the drawer can show the exact
 * remarks/date/amount history) keyed to a FleetView vehicle via CarSerial.
 *
 * Idempotent: a run replaces ALL rows of its --source (default 'excel'). Parses .xlsx natively (no
 * PhpSpreadsheet dependency) via ZipArchive + XMLReader.
 *
 *   php artisan expenses:import "C:/path/Expenses.xlsx"            # import
 *   php artisan expenses:import "C:/path/Expenses.xlsx" --dry-run  # validate only, write nothing
 */
class ImportVehicleExpenses extends Command
{
    protected $signature = 'expenses:import {path : Path to the .xlsx expense sheet}
        {--source=excel : Source tag stored on every row (replaced wholesale on re-import)}
        {--dry-run : Parse and summarise only; write nothing}';

    protected $description = 'Import the vehicle-expense sheet (the sole source of vehicle expense) into vehicle_expenses';

    // Zero-based column positions in the sheet (A..F).
    private const COL_CAR_SERIAL = 0;
    private const COL_ACCOUNT    = 1;
    private const COL_REMARKS    = 2;
    private const COL_DATE       = 3;
    private const COL_DEBIT      = 4;
    private const COL_CREDIT     = 5;

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $source = (string) $this->option('source');
        $dryRun = (bool) $this->option('dry-run');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        // A file can exist (stat OK) yet be unreadable by the current process — the common cause of a
        // spurious "not a valid zip". `docker compose cp` preserves the host's umask-0027 perms, so the
        // file lands as 0640 owned by the copying user while artisan runs as www-data → open() denied.
        if (! is_readable($path)) {
            $perms  = @fileperms($path);
            $octal  = $perms !== false ? substr(sprintf('%o', $perms), -4) : '????';
            $this->error("File exists but is not readable by the current user: {$path} (mode {$octal}).");
            $this->line('  Fix on the server: chmod 644 the file (or run the import as its owner). Inside Docker:');
            $this->line("  docker compose exec -u root backend chmod 644 {$path}");

            return self::FAILURE;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Reading {$path} …");

        // vehicles.car_serial → id, so each line is joined to a FleetView vehicle.
        $vehicleByCarSerial = Vehicle::query()
            ->whereNotNull('car_serial')
            ->pluck('id', 'car_serial');
        $vehicleMap = [];
        foreach ($vehicleByCarSerial as $serial => $id) {
            $vehicleMap[(int) $serial] = (int) $id;
        }

        $now = now();
        $parsed = 0; $skipped = 0; $matched = 0; $unmatched = 0; $closingSkipped = 0;
        $sumAmount = 0.0; $carSerials = []; $unmatchedSerials = [];
        $batch = []; $sample = [];
        $wroteFresh = false;
        $classifier = new ExpenseCategoryClassifier();

        foreach ($this->readRows($path) as $i => $cells) {
            if ($i === 0) {
                continue; // header row
            }
            $carSerial = (int) $this->numeric($cells[self::COL_CAR_SERIAL] ?? null);
            if ($carSerial <= 0) {
                $skipped++;
                continue;
            }
            $remarks = $this->text($cells[self::COL_REMARKS] ?? null);
            // Year-end "Closing Expense Account for the year YYYY" entries are accounting reversals that
            // zero the expense accounts — NOT real vehicle spend. Including them nets real expenses back
            // to ~0. Drop them entirely (they'd also pollute the expense history/timeline).
            if ($this->isClosingEntry($remarks)) {
                $closingSkipped++;
                continue;
            }
            $debit  = $this->numeric($cells[self::COL_DEBIT] ?? null);
            $credit = $this->numeric($cells[self::COL_CREDIT] ?? null);
            $amount = round($debit - $credit, 2);
            $vehicleId = $vehicleMap[$carSerial] ?? null;

            $parsed++;
            $carSerials[$carSerial] = true;
            $sumAmount += $amount;
            if ($vehicleId !== null) {
                $matched++;
            } else {
                $unmatched++;
                $unmatchedSerials[$carSerial] = true;
            }

            // The operational bucket, derived from the remark at write time (account_type is "Expence"
            // on every row and tells us nothing). Re-derivable at any point with `expenses:classify`.
            $category = $classifier->classify($remarks);

            $row = [
                'car_serial'   => $carSerial,
                'vehicle_id'   => $vehicleId,
                'entry_date'   => $this->excelDate($cells[self::COL_DATE] ?? null),
                'account_type' => $this->text($cells[self::COL_ACCOUNT] ?? null),
                'remarks'      => $remarks,
                'category'         => $category['key'],
                'category_matched' => $category['matched'],
                'debit'        => round($debit, 2),
                'credit'       => round($credit, 2),
                'amount'       => $amount,
                'source'       => $source,
                'imported_at'  => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
            if (count($sample) < 8) {
                $sample[] = $row;
            }

            if (! $dryRun) {
                // Replace the source's old rows exactly once, on the first row we're about to write, so a
                // failed parse before any insert never wipes existing data.
                if (! $wroteFresh) {
                    DB::table('vehicle_expenses')->where('source', $source)->delete();
                    $wroteFresh = true;
                }
                $batch[] = $row;
                if (count($batch) >= 1000) {
                    DB::table('vehicle_expenses')->insert($batch);
                    $batch = [];
                }
            }
        }

        if (! $dryRun && $batch) {
            DB::table('vehicle_expenses')->insert($batch);
        }

        // ---- Report ----------------------------------------------------------------------------
        $this->newLine();
        $this->line('<info>Lines parsed:</info> ' . number_format($parsed)
            . ' · <info>skipped (no car serial):</info> ' . number_format($skipped)
            . ' · <info>closing entries dropped:</info> ' . number_format($closingSkipped));
        $this->line('<info>Distinct vehicles (CarSerial):</info> ' . number_format(count($carSerials)));
        $this->line('<info>Lines matched to a FleetView vehicle:</info> ' . number_format($matched)
            . ' · <comment>unmatched:</comment> ' . number_format($unmatched)
            . ' (' . number_format(count($unmatchedSerials)) . ' unknown car serials)');
        $this->line('<info>Total expense (Σ debit − credit):</info> AED ' . number_format($sumAmount, 2));

        if ($sample) {
            $this->newLine();
            $this->line('Sample lines:');
            $this->table(
                ['car_serial', 'vehicle_id', 'entry_date', 'account_type', 'remarks', 'amount'],
                array_map(fn ($r) => [
                    $r['car_serial'], $r['vehicle_id'] ?? '—', $r['entry_date'] ?? '—',
                    $r['account_type'] ?? '—',
                    mb_strimwidth((string) ($r['remarks'] ?? ''), 0, 40, '…'),
                    number_format((float) $r['amount'], 2),
                ], $sample),
            );
        }

        $this->newLine();
        if ($dryRun) {
            $this->comment('Dry run — nothing was written. Re-run without --dry-run to import.');
        } else {
            $this->info('Imported ' . number_format($matched + $unmatched) . " rows into vehicle_expenses (source={$source}).");
        }

        return self::SUCCESS;
    }

    /**
     * Stream the first worksheet as zero-indexed rows of zero-indexed columns.
     * Yields [rowIndex => [colIndex => rawValue]]. Native .xlsx parse (ZipArchive + XMLReader).
     *
     * @return \Generator<int,array<int,mixed>>
     */
    private function readRows(string $path): \Generator
    {
        $zip = new ZipArchive();
        $code = $zip->open($path);
        if ($code !== true) {
            // $code is a ZipArchive::ER_* constant. Surface the real reason instead of always
            // blaming the file — e.g. ER_OPEN/ER_READ mean a permission problem, not corruption.
            throw new RuntimeException(sprintf(
                'ZipArchive could not open %s — %s (code %d). %s',
                $path,
                $this->zipErrorMeaning((int) $code),
                (int) $code,
                is_readable($path) ? '' : 'The file is not readable by the current user — chmod 644 it.'
            ));
        }

        $shared = $this->readSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheetXml === false) {
            throw new RuntimeException('xl/worksheets/sheet1.xml not found in workbook.');
        }

        $reader = new XMLReader();
        $reader->XML($sheetXml);
        $rowIndex = -1;
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }
            $rowIndex++;
            $rowNode = new SimpleXMLElement($reader->readOuterXML());
            $cells = [];
            foreach ($rowNode->children() as $c) {
                $ref = (string) $c['r'];                 // e.g. "C2"
                $col = $this->colIndex($ref);
                $type = (string) $c['t'];
                if ($type === 's') {
                    // shared-string index
                    $idx = (int) ((string) $c->v);
                    $cells[$col] = $shared[$idx] ?? null;
                } elseif ($type === 'inlineStr') {
                    $cells[$col] = (string) $c->is->t;
                } else {
                    $cells[$col] = isset($c->v) ? (string) $c->v : null;
                }
            }
            yield $rowIndex => $cells;
        }
        $reader->close();
    }

    /**
     * Human-readable meaning for a ZipArchive::open() error code, so a failure names the real cause
     * (permission vs. missing vs. genuinely-not-a-zip) instead of always reading as corruption.
     */
    private function zipErrorMeaning(int $code): string
    {
        return match ($code) {
            ZipArchive::ER_NOENT => 'file does not exist',
            ZipArchive::ER_OPEN  => 'cannot open file (permission denied?)',
            ZipArchive::ER_READ  => 'read error (permission denied?)',
            ZipArchive::ER_SEEK  => 'seek error',
            ZipArchive::ER_NOZIP => 'not a valid zip archive (file may be corrupt or not a real .xlsx)',
            ZipArchive::ER_INCONS => 'zip archive inconsistent / corrupt',
            ZipArchive::ER_MEMORY => 'out of memory',
            default => 'unknown error',
        };
    }

    /**
     * Read xl/sharedStrings.xml into an index → text array (streamed; handles multi-run <si>).
     *
     * @return array<int,string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $shared = [];
        $reader = new XMLReader();
        $reader->XML($xml);
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') {
                continue;
            }
            $si = new SimpleXMLElement($reader->readOuterXML());
            $text = '';
            foreach ($si->xpath('.//*[local-name()="t"]') as $t) {
                $text .= (string) $t;
            }
            $shared[] = $text;
        }
        $reader->close();

        return $shared;
    }

    /** True for year-end expense-account closing reversals (accounting mechanics, not real spend). */
    private function isClosingEntry(?string $remarks): bool
    {
        return $remarks !== null && preg_match('/^\s*closing\s+expense\s+account/i', $remarks) === 1;
    }

    /** Column letters of an A1 reference → zero-based index ("A"→0, "F"→5, "AA"→26). */
    private function colIndex(string $ref): int
    {
        $letters = preg_replace('/[^A-Za-z]/', '', $ref);
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    private function numeric($v): float
    {
        if ($v === null || $v === '') {
            return 0.0;
        }

        return (float) str_replace(',', '', (string) $v);
    }

    private function text($v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    /** Excel serial date → Y-m-d (base 1899-12-30 handles the 1900 leap-year quirk). Null when blank. */
    private function excelDate($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        // Already an ISO date string? keep its date part.
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
            return substr($v, 0, 10);
        }
        $serial = (int) round((float) $v);
        if ($serial <= 0) {
            return null;
        }

        return Carbon::create(1899, 12, 30)->addDays($serial)->toDateString();
    }
}
