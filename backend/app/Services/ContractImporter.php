<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Observers\ContractObserver;
use Carbon\Carbon;
use Throwable;

class ContractImporter
{
    /** Cache of VIN => vehicle id. */
    protected array $vehicleCache = [];

    /** Cache of CustomerNo => customer id. */
    protected array $customerCache = [];

    public function __construct(protected GoogleSheetsService $sheets)
    {
    }

    /**
     * Import contracts from the rich RA "Contracts" tab.
     * Customer details come separately (CustomerImporter); here we only link by CustomerNo.
     *
     * @return array<string, mixed>
     */
    public function import(): array
    {
        $cfg  = config('google.sheets.contracts');
        $rows = $this->sheets->readByGid($cfg['id'], (int) $cfg['gid']);

        if (count($rows) < 2) {
            return ['error' => 'No data found.'];
        }

        $map = $this->headerMap($rows[0]); // header on row 1, data from row 2

        if (! isset($map['contractno'])) {
            return ['error' => "Missing 'ContractNo' column."];
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $protected = 0;
        $problems = [];

        // Mute the per-row balance sync; we reconcile every customer once at the end.
        ContractObserver::$muted = true;

        foreach (array_slice($rows, 1) as $i => $row) {
            $rowNo = $i + 2;

            $contractNo = trim($this->cell($row, $map['contractno'] ?? null));
            if ($contractNo === '' || ! preg_match('/\d/', $contractNo)) {
                $skipped++;
                continue;
            }

            $contractType = $this->strOrNull($this->cell($row, $map['contracttype'] ?? null));

            $existing = Contract::withTrashed()
                ->where('contract_no', $contractNo)
                ->where('contract_type', $contractType)
                ->first();
            if ($existing && $existing->origin === 'web') {
                $protected++;
                continue;
            }

            try {
                $data = [
                    'contract_type'        => $contractType,
                    'vehicle_id'           => $this->resolveVehicle($this->cell($row, $map['chasisno'] ?? null)),
                    'customer_id'          => $this->resolveCustomer($this->cell($row, $map['customerno'] ?? null)),
                    'state'                => $this->dateOrNull($this->cell($row, $map['indate'] ?? null)) ? 'closed' : 'open',

                    'day_price'            => $this->moneyOrNull($this->cell($row, $map['dayprice'] ?? null)),
                    'week_price'           => $this->moneyOrNull($this->cell($row, $map['weekprice'] ?? null)),
                    'month_price'          => $this->moneyOrNull($this->cell($row, $map['monthprice'] ?? null)),
                    'hour_price'           => $this->moneyOrNull($this->cell($row, $map['hourprice'] ?? null)),
                    'year_price'           => $this->moneyOrNull($this->cell($row, $map['yearprice'] ?? null)),

                    'out_date'             => $this->dateOrNull($this->cell($row, $map['outdate'] ?? null)),
                    'out_time'             => $this->timeOrNull($this->cell($row, $map['outtime'] ?? null)),
                    'out_milage'           => $this->intOrNull($this->cell($row, $map['outmilage'] ?? null)),
                    'out_fuel'             => $this->strOrNull($this->cell($row, $map['outfuel'] ?? null)),
                    'opened_by'            => $this->strOrNull($this->cell($row, $map['openedby'] ?? null)),

                    'in_date'              => $this->dateOrNull($this->cell($row, $map['indate'] ?? null)),
                    'in_time'              => $this->timeOrNull($this->cell($row, $map['intime'] ?? null)),
                    'in_milage'            => $this->intOrNull($this->cell($row, $map['inmilage'] ?? null)),
                    'in_fuel'              => $this->strOrNull($this->cell($row, $map['infuel'] ?? null)),
                    'closed_by'            => $this->strOrNull($this->cell($row, $map['closedby'] ?? null)),

                    'days'                 => $this->intOrNull($this->cell($row, $map['days'] ?? null)),
                    'km'                   => $this->intOrNull($this->cell($row, $map['km'] ?? null)),

                    'rents_debit'          => $this->moneyOrNull($this->cell($row, $map['rentsdebit'] ?? null)),
                    'breachs_debit'        => $this->moneyOrNull($this->cell($row, $map['breachsdebit'] ?? null)),
                    'salik_debit'          => $this->moneyOrNull($this->cell($row, $map['salikdebit'] ?? null)),
                    'damages_debit'        => $this->moneyOrNull($this->cell($row, $map['damagesdebit'] ?? null)),
                    'extra_charges_debit'  => $this->moneyOrNull($this->cell($row, $map['extrachargesdebit'] ?? null)),
                    'co_driver_debit'      => $this->moneyOrNull($this->cell($row, $map['codriverdebit'] ?? null)),
                    'km_debit'             => $this->moneyOrNull($this->cell($row, $map['kmdebit'] ?? null)),
                    'fuel_debit'           => $this->moneyOrNull($this->cell($row, $map['fueldebit'] ?? null)),
                    'gps_debit'            => $this->moneyOrNull($this->cell($row, $map['gpsdebit'] ?? null)),
                    'cdw_debit'            => $this->moneyOrNull($this->cell($row, $map['cdwdebit'] ?? null)),
                    'extra_driver_debit'   => $this->moneyOrNull($this->cell($row, $map['extradriverdebit'] ?? null)),
                    'vat_debit'            => $this->moneyOrNull($this->cell($row, $map['vatdebit'] ?? null)),
                    'deposit_debit'        => $this->moneyOrNull($this->cell($row, $map['depositdebit'] ?? null)),

                    'rents_credit'         => $this->moneyOrNull($this->cell($row, $map['rentscredit'] ?? null)),
                    'breachs_credit'       => $this->moneyOrNull($this->cell($row, $map['breachscredit'] ?? null)),
                    'salik_credit'         => $this->moneyOrNull($this->cell($row, $map['salikcredit'] ?? null)),
                    'damages_credit'       => $this->moneyOrNull($this->cell($row, $map['damagescredit'] ?? null)),
                    'extra_charges_credit' => $this->moneyOrNull($this->cell($row, $map['extrachargescredit'] ?? null)),
                    'co_driver_credit'     => $this->moneyOrNull($this->cell($row, $map['codrivercredit'] ?? null)),
                    'km_credit'            => $this->moneyOrNull($this->cell($row, $map['kmcredit'] ?? null)),
                    'fuel_credit'          => $this->moneyOrNull($this->cell($row, $map['fuelcredit'] ?? null)),
                    'gps_credit'           => $this->moneyOrNull($this->cell($row, $map['gpscredit'] ?? null)),
                    'cdw_credit'           => $this->moneyOrNull($this->cell($row, $map['cdwcredit'] ?? null)),
                    'extra_driver_credit'  => $this->moneyOrNull($this->cell($row, $map['extradrivercredit'] ?? null)),
                    'vat_credit'           => $this->moneyOrNull($this->cell($row, $map['vatcredit'] ?? null)),
                    'deposit_credit'       => $this->moneyOrNull($this->cell($row, $map['depositcredit'] ?? null)),

                    'contract_debit'       => $this->moneyOrNull($this->cell($row, $map['contractdebit'] ?? null)),
                    'contract_credit'      => $this->moneyOrNull($this->cell($row, $map['contractcredit'] ?? null)),
                    'contract_balance'     => $this->moneyOrNull($this->cell($row, $map['contractbalance'] ?? null)),
                    'contract_refunds'     => $this->moneyOrNull($this->cell($row, $map['contractrefunds'] ?? null)),
                    'contract_discount'    => $this->moneyOrNull($this->cell($row, $map['contractdiscount'] ?? null)),
                    'contract_bad_debts'   => $this->moneyOrNull($this->cell($row, $map['contractbaddebts'] ?? null)),
                    'contract_deposit'     => $this->moneyOrNull($this->cell($row, $map['contractdeposit'] ?? null)),
                    'contract_commissions' => $this->moneyOrNull($this->cell($row, $map['contractcommissions'] ?? null)),
                    'contract_income'      => $this->moneyOrNull($this->cell($row, $map['contractincome'] ?? null)),

                    'miles_allowed_pd'     => $this->moneyOrNull($this->cell($row, $map['milesallowedpd'] ?? null)),
                    'miles_allowed_pm'     => $this->moneyOrNull($this->cell($row, $map['milesallowedpm'] ?? null)),
                    'extra_mile_charge'    => $this->moneyOrNull($this->cell($row, $map['extramilecharge'] ?? null)),
                    'cdw_rate'             => $this->moneyOrNull($this->cell($row, $map['cdwrate'] ?? null)),
                    'pai_rate'             => $this->moneyOrNull($this->cell($row, $map['pairate'] ?? null)),
                    'authorization_amount' => $this->moneyOrNull($this->cell($row, $map['authorizationamount'] ?? null)),
                    'insurance_type'       => $this->strOrNull($this->cell($row, $map['insurencetype'] ?? null)),
                    'trip_direction'       => $this->strOrNull($this->cell($row, $map['tripdirection'] ?? null)),
                    'under_claim'          => $this->boolOrNull($this->cell($row, $map['underclaim'] ?? null)),
                    'guarantor_no'         => $this->strOrNull($this->cell($row, $map['guarantorno'] ?? null)),

                    'contract_status_no'   => $this->strOrNull($this->cell($row, $map['contractstatusno'] ?? null)),
                    'reference'            => $this->strOrNull($this->cell($row, $map['refrunce'] ?? null)),
                    'contract_serial'      => $this->strOrNull($this->cell($row, $map['contractserial'] ?? null)),
                    'driver2'              => $this->strOrNull($this->cell($row, $map['driver2'] ?? null)),
                    'driver3'              => $this->strOrNull($this->cell($row, $map['driver3'] ?? null)),
                    'driver_out'           => $this->strOrNull($this->cell($row, $map['driverout'] ?? null)),
                    'driver_in'            => $this->strOrNull($this->cell($row, $map['driverin'] ?? null)),
                    'co_driver_cost'       => $this->moneyOrNull($this->cell($row, $map['codrivercost'] ?? null)),
                    'extra_driver_charge'  => $this->moneyOrNull($this->cell($row, $map['extradrivercharge'] ?? null)),
                    'gps_charge'           => $this->moneyOrNull($this->cell($row, $map['gpscharge'] ?? null)),
                    'fuel_charge'          => $this->moneyOrNull($this->cell($row, $map['fuelcharge'] ?? null)),
                    'ra_vat_percentage'    => $this->moneyOrNull($this->cell($row, $map['racontractvatpercentage'] ?? null)),
                    'salesman_commission_no1'    => $this->strOrNull($this->cell($row, $map['salesmancommissionno1'] ?? null)),
                    'salesman_commission_value1' => $this->moneyOrNull($this->cell($row, $map['salesmancommissionvalue1'] ?? null)),
                    'salesman_commission_no2'    => $this->strOrNull($this->cell($row, $map['salesmancommissionno2'] ?? null)),
                    'salesman_commission_value2' => $this->moneyOrNull($this->cell($row, $map['salesmancommissionvalue2'] ?? null)),
                    'tax_inclusive'        => $this->boolOrNull($this->cell($row, $map['taxinclusive'] ?? null)),
                    'cdw_on_contract'      => $this->boolOrNull($this->cell($row, $map['cdwoncontract'] ?? null)),
                    'credit_card_no'       => $this->strOrNull($this->cell($row, $map['creditcardno'] ?? null)),
                    'credit_card_expiry'   => $this->strOrNull($this->cell($row, $map['creditcardexpiary'] ?? null)),
                    'authorization_date'   => $this->dateOrNull($this->cell($row, $map['authorizationdate'] ?? null)),
                    'out_date_hijri'       => $this->strOrNull($this->cell($row, $map['outdatehijri'] ?? null)),
                    'in_date_hijri'        => $this->strOrNull($this->cell($row, $map['indatehijri'] ?? null)),

                    'remarks'              => $this->strOrNull($this->cell($row, $map['remarks'] ?? null)),
                    'sales_man1'           => $this->strOrNull($this->cell($row, $map['salesman1'] ?? null)),
                    'sales_man2'           => $this->strOrNull($this->cell($row, $map['salesman2'] ?? null)),
                    'source'               => $this->strOrNull($this->cell($row, $map['source'] ?? null)),

                    'external_id'          => $contractNo,
                    'synced_at'            => now(),
                    'origin'               => 'sheet',
                ];

                $contract = Contract::withTrashed()->updateOrCreate(
                    ['contract_no' => $contractNo, 'contract_type' => $contractType],
                    $data
                );
                $contract->wasRecentlyCreated ? $created++ : $updated++;
            } catch (Throwable $e) {
                $problems[] = "Row {$rowNo} (Contract {$contractNo}): " . $e->getMessage();
            }
        }

        ContractObserver::$muted = false;

        // Reconcile every customer's cached debit/credit/balance from the imported contracts.
        app(AccountingService::class)->recalcAllCustomers();

        return compact('created', 'updated', 'skipped', 'protected', 'problems');
    }

    /** Find a vehicle by VIN (ChasisNo). */
    protected function resolveVehicle($rawVin): ?int
    {
        $vin = trim((string) $rawVin);
        if ($vin === '') {
            return null;
        }
        if (array_key_exists($vin, $this->vehicleCache)) {
            return $this->vehicleCache[$vin];
        }
        return $this->vehicleCache[$vin] = Vehicle::where('vin', $vin)->value('id');
    }

    /** Link to a customer by CustomerNo; create a stub if it isn't in the customers table yet. */
    protected function resolveCustomer($rawNo): ?int
    {
        $no = trim((string) $rawNo);
        if ($no === '') {
            return null;
        }
        if (array_key_exists($no, $this->customerCache)) {
            return $this->customerCache[$no];
        }

        $id = Customer::where('customer_no', $no)->value('id');
        if (! $id) {
            $id = Customer::create([
                'customer_no' => $no,
                'external_id' => $no,
                'origin'      => 'sheet',
                'synced_at'   => now(),
            ])->id;
        }

        return $this->customerCache[$no] = $id;
    }

    /** @return array<string,int> */
    protected function headerMap(array $header): array
    {
        $map = [];
        foreach ($header as $i => $h) {
            $key = strtolower(preg_replace('/\s+/', '', str_replace(["\r", "\n"], ' ', (string) $h)));
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

    protected function intOrNull($s): ?int
    {
        $digits = preg_replace('/[^0-9\-]/', '', (string) $s);
        return ($digits === '' || $digits === '-') ? null : (int) $digits;
    }

    protected function moneyOrNull($s): ?float
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
            if ($d->year < 1901) { // Excel zero-date (1899-12-30) used for blank values
                return null;
            }
            return $d->format('Y-m-d');
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Extract a time. The sheet stores times as Excel serial dates like "1899-12-30 14:43:00". */
    protected function timeOrNull($s): ?string
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        try {
            return Carbon::parse($s)->format('H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }
}
