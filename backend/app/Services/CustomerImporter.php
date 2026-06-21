<?php

namespace App\Services;

use App\Models\Customer;
use Carbon\Carbon;
use Throwable;

class CustomerImporter
{
    public function __construct(protected GoogleSheetsService $sheets)
    {
    }

    /**
     * Import rich customer details from the "Customers" tab (matched by CustomerNo).
     *
     * @return array<string, mixed>
     */
    public function import(): array
    {
        $cfg  = config('google.sheets.customers');
        $rows = $this->sheets->readByGid($cfg['id'], (int) $cfg['gid']);

        if (count($rows) < 2) {
            return ['error' => 'No data found.'];
        }

        $map = $this->headerMap($rows[0]);

        if (! isset($map['customerno'])) {
            return ['error' => "Missing 'CustomerNo' column."];
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $protected = 0;
        $problems = [];

        foreach (array_slice($rows, 1) as $i => $row) {
            $rowNo = $i + 2;

            $customerNo = trim($this->cell($row, $map['customerno'] ?? null));
            if ($customerNo === '' || ! preg_match('/\d/', $customerNo)) {
                $skipped++;
                continue;
            }

            $existing = Customer::withTrashed()->where('customer_no', $customerNo)->first();
            if ($existing && $existing->origin === 'web') {
                $protected++;
                continue;
            }

            try {
                $data = [
                    'name_en'          => $this->strOrNull($this->cell($row, $map['customernamee'] ?? null)),
                    'name_ar'          => $this->strOrNull($this->cell($row, $map['customernamea'] ?? null)),
                    'nationality'      => $this->strOrNull($this->cell($row, $map['nationality'] ?? null)),
                    'date_of_birth'    => $this->dateOrNull($this->cell($row, $map['dateofbirth'] ?? null)),
                    'sex'              => $this->strOrNull($this->cell($row, $map['sex'] ?? null)),
                    'mobile1'          => $this->strOrNull($this->cell($row, $map['mobile'] ?? null)),
                    'mobile2'          => $this->strOrNull($this->cell($row, $map['mobile3'] ?? null)),
                    'whatsapp'         => $this->strOrNull($this->cell($row, $map['whatsapp'] ?? null)),
                    'email'            => $this->strOrNull($this->cell($row, $map['email'] ?? null)),
                    'city'             => $this->strOrNull($this->cell($row, $map['city'] ?? null)),
                    'address'          => $this->strOrNull($this->cell($row, $map['adres'] ?? null)),
                    'po_box'           => $this->strOrNull($this->cell($row, $map['pobox'] ?? null)),

                    'passport_no'      => $this->strOrNull($this->cell($row, $map['passportno'] ?? null)),
                    'passport_expiry'  => $this->dateOrNull($this->cell($row, $map['passportexpiarydate'] ?? null)),
                    'license_no'       => $this->strOrNull($this->cell($row, $map['dlno'] ?? null)),
                    'license_expiry'   => $this->dateOrNull($this->cell($row, $map['dlexpiarydate'] ?? null)),
                    'id_no'            => $this->strOrNull($this->cell($row, $map['idno'] ?? null)),
                    'id_expiry'        => $this->dateOrNull($this->cell($row, $map['idexpiarydate'] ?? null)),
                    'residency_no'     => $this->strOrNull($this->cell($row, $map['residencyno'] ?? null)),
                    'residency_expiry' => $this->dateOrNull($this->cell($row, $map['residencyexpiarydate'] ?? null)),
                    'traffic_file_no'  => $this->strOrNull($this->cell($row, $map['trafficfileno'] ?? null)),
                    'vat_number'       => $this->strOrNull($this->cell($row, $map['customervatnumber'] ?? null)),

                    'makani'           => $this->strOrNull($this->cell($row, $map['makani'] ?? null)),
                    'lat'              => $this->floatOrNull($this->cell($row, $map['customerlat'] ?? null)),
                    'lon'              => $this->floatOrNull($this->cell($row, $map['customerlon'] ?? null)),

                    // debit / credit / balance are DERIVED from contracts (kept in sync by ContractObserver),
                    // so we do NOT import them from the sheet here.
                    'deposit'          => $this->moneyOrNull($this->cell($row, $map['customerdeposit'] ?? null)),

                    'external_id'      => $customerNo,
                    'synced_at'        => now(),
                    'origin'           => 'sheet',
                ];

                $customer = Customer::withTrashed()->updateOrCreate(['customer_no' => $customerNo], $data);
                $customer->wasRecentlyCreated ? $created++ : $updated++;
            } catch (Throwable $e) {
                $problems[] = "Row {$rowNo} (Customer {$customerNo}): " . $e->getMessage();
            }
        }

        return compact('created', 'updated', 'skipped', 'protected', 'problems');
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

    protected function cell(array $row, $idx): string
    {
        return $idx === null ? '' : (string) ($row[$idx] ?? '');
    }

    protected function strOrNull($s): ?string
    {
        $s = trim((string) $s);
        return $s === '' ? null : $s;
    }

    protected function moneyOrNull($s): ?float
    {
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $s);
        return ($clean === '' || $clean === '-' || $clean === '.') ? null : (float) $clean;
    }

    protected function floatOrNull($s): ?float
    {
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $s);
        return ($clean === '' || $clean === '-' || $clean === '.') ? null : (float) $clean;
    }

    protected function boolOrNull($s): ?bool
    {
        $s = strtolower(trim((string) $s));
        if ($s === '') {
            return null;
        }
        if (in_array($s, ['true', '1', 'yes'], true)) {
            return true;
        }
        if (in_array($s, ['false', '0', 'no'], true)) {
            return false;
        }
        return null;
    }

    protected function dateOrNull($s): ?string
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        try {
            $d = Carbon::parse($s);
            // Guard against the Excel zero-date (1899-12-30) used for blank values.
            if ($d->year < 1901) {
                return null;
            }
            return $d->format('Y-m-d');
        } catch (Throwable $e) {
            return null;
        }
    }
}
