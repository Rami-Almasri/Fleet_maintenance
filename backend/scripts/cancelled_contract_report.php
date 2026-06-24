<?php
/**
 * One-off report: verify the "cancelled contract" hypothesis and the type-U info gap.
 *
 *   Hypothesis 1: a contract whose out_date == in_date AND out_time == in_time is cancelled.
 *                 -> does EVERY such contract have contract_status_no = 5 ?
 *   Hypothesis 2: type-U (maintenance) contracts have no linked maintenance info,
 *                 and they share the same out==in date+time shape.
 *
 * Outputs 4 CSV files next to the project root. Read-only against the DB.
 *
 * Run:  php backend/scripts/cancelled_contract_report.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Contract;
use Illuminate\Support\Facades\DB;

$outDir = __DIR__ . '/../../reports';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

/** helper: open a csv, write header, return handle */
function csv(string $path, array $header)
{
    $fh = fopen($path, 'w');
    // BOM so Excel reads UTF-8 correctly
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, $header);
    return $fh;
}

/** the two equality flags, computed in SQL so NULLs behave predictably */
$sameDate = "(out_date IS NOT NULL AND in_date IS NOT NULL AND DATE(out_date) = DATE(in_date))";
$sameTime = "(out_time IS NOT NULL AND in_time IS NOT NULL AND out_time = in_time)";
$sameBoth = "($sameDate AND $sameTime)";

echo "Building reports...\n";

/* ---------------------------------------------------------------------------
 * 1. SUMMARY — the headline verification numbers
 * ------------------------------------------------------------------------- */
$total          = Contract::count();
$matchRule      = Contract::whereRaw($sameBoth)->count();
$matchRuleS5    = Contract::whereRaw($sameBoth)->where('contract_status_no', 5)->count();
$matchRuleNotS5 = Contract::whereRaw($sameBoth)->where(function ($q) {
    $q->where('contract_status_no', '!=', 5)->orWhereNull('contract_status_no');
})->count();
$totalS5        = Contract::where('contract_status_no', 5)->count();
$s5NotMatch     = Contract::where('contract_status_no', 5)->whereRaw("NOT $sameBoth")->count();

$ruleHoldsForAllS5 = ($s5NotMatch === 0);          // every status-5 has same date+time?
$allMatchAreS5     = ($matchRuleNotS5 === 0);       // every same date+time is status-5?

$fh = csv("$outDir/1_cancelled_summary.csv", ['check', 'value', 'meaning']);
fputcsv($fh, ['total_contracts', $total, 'all contracts in DB']);
fputcsv($fh, ['rule_match (out=in date AND time)', $matchRule, 'contracts matching your cancelled rule']);
fputcsv($fh, ['  of which status_no = 5', $matchRuleS5, 'rule matches that ARE flagged cancelled by OM']);
fputcsv($fh, ['  of which status_no != 5', $matchRuleNotS5, 'rule matches that are NOT cancelled (false positives)']);
fputcsv($fh, ['total status_no = 5', $totalS5, 'OM authoritative cancelled count']);
fputcsv($fh, ['  status_no=5 NOT matching rule', $s5NotMatch, 'cancelled contracts your rule would MISS']);
fputcsv($fh, ['VERDICT: every status_no=5 has same date+time?', $ruleHoldsForAllS5 ? 'YES' : 'NO', 'is status_no=5 a subset of the rule']);
fputcsv($fh, ['VERDICT: every same-date+time is status_no=5?', $allMatchAreS5 ? 'YES' : 'NO', 'is the rule exact, or over-broad']);
fclose($fh);

/* ---------------------------------------------------------------------------
 * 2. ALL same-date+time contracts, each tagged with whether it is status 5
 * ------------------------------------------------------------------------- */
$fh = csv("$outDir/2_same_datetime_contracts.csv", [
    'contract_no', 'contract_serial', 'contract_type', 'contract_status_no',
    'state', 'out_date', 'out_time', 'in_date', 'in_time', 'days',
    'out_milage', 'in_milage', 'milage_changed', 'vehicle_id',
    'is_status_5', 'matches_cancelled_flag',
]);
Contract::whereRaw($sameBoth)
    ->orderBy('out_date')
    ->chunk(500, function ($rows) use ($fh) {
        foreach ($rows as $c) {
            $milageChanged = ($c->out_milage !== null && $c->in_milage !== null
                && (int) $c->out_milage !== (int) $c->in_milage);
            $isS5 = ((string) $c->contract_status_no === '5');
            fputcsv($fh, [
                $c->contract_no, $c->contract_serial, $c->contract_type, $c->contract_status_no,
                $c->state, $c->out_date, $c->out_time, $c->in_date, $c->in_time, $c->days,
                $c->out_milage, $c->in_milage, $milageChanged ? 'yes' : 'no', $c->vehicle_id,
                $isS5 ? 'yes' : 'no',
                $isS5 ? 'matches' : 'FALSE POSITIVE (status ' . ($c->contract_status_no ?? 'NULL') . ')',
            ]);
        }
    });
fclose($fh);

/* ---------------------------------------------------------------------------
 * 3. TYPE-U contracts — info presence + same-date+time shape
 * ------------------------------------------------------------------------- */
$fh = csv("$outDir/3_type_u_contracts.csv", [
    'contract_no', 'contract_serial', 'contract_status_no', 'state',
    'out_date', 'out_time', 'in_date', 'in_time', 'days', 'vehicle_id',
    'same_date_and_time', 'is_status_5', 'has_linked_maintenance_info',
]);

$uTotal = 0; $uSameBoth = 0; $uWithInfo = 0; $uS5 = 0;
Contract::where('contract_type', 'U')
    ->withCount('maintenance')           // contract_id-linked maintenance rows
    ->orderBy('out_date')
    ->chunk(500, function ($rows) use ($fh, &$uTotal, &$uSameBoth, &$uWithInfo, &$uS5) {
        foreach ($rows as $c) {
            $uTotal++;
            $same = ($c->out_date && $c->in_date && $c->out_time && $c->in_time
                && (string) $c->out_date === (string) $c->in_date
                && (string) $c->out_time === (string) $c->in_time);
            $hasInfo = ($c->maintenance_count > 0);
            $isS5 = ((string) $c->contract_status_no === '5');
            if ($same) $uSameBoth++;
            if ($hasInfo) $uWithInfo++;
            if ($isS5) $uS5++;
            fputcsv($fh, [
                $c->contract_no, $c->contract_serial, $c->contract_status_no, $c->state,
                $c->out_date, $c->out_time, $c->in_date, $c->in_time, $c->days, $c->vehicle_id,
                $same ? 'yes' : 'no', $isS5 ? 'yes' : 'no', $hasInfo ? 'yes' : 'no (EMPTY)',
            ]);
        }
    });
fclose($fh);

/* ---------------------------------------------------------------------------
 * 4. TYPE-U summary
 * ------------------------------------------------------------------------- */
$maintTotal      = DB::table('maintenances')->count();
$maintWithCid    = DB::table('maintenances')->whereNotNull('contract_id')->count();

$fh = csv("$outDir/4_type_u_summary.csv", ['check', 'value', 'meaning']);
fputcsv($fh, ['total_type_U_contracts', $uTotal, 'maintenance contracts (ContractType = U)']);
fputcsv($fh, ['  with linked maintenance info', $uWithInfo, 'U contracts that have a contract_id-linked maintenance row']);
fputcsv($fh, ['  with NO maintenance info', $uTotal - $uWithInfo, 'U contracts that are empty shells']);
fputcsv($fh, ['  with same out=in date AND time', $uSameBoth, 'U contracts sharing the cancelled shape']);
fputcsv($fh, ['  flagged cancelled (status_no=5)', $uS5, 'U contracts OM marks cancelled']);
fputcsv($fh, ['maintenances_table_total_rows', $maintTotal, 'all maintenance log rows']);
fputcsv($fh, ['  linked to a contract (contract_id set)', $maintWithCid, 'how many are joined to ANY contract']);
fclose($fh);

echo "Done. CSV files written to: " . realpath($outDir) . "\n";
foreach (['1_cancelled_summary.csv', '2_same_datetime_contracts.csv', '3_type_u_contracts.csv', '4_type_u_summary.csv'] as $f) {
    echo "  - $f\n";
}
