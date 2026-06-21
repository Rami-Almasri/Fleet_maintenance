<?php

$s    = app(App\Services\GoogleSheetsService::class);
$rows = $s->readByGid(config('google.sheets.contracts.id'), (int) config('google.sheets.contracts.gid'));

$norm = fn ($h) => strtolower(preg_replace('/\s+/', '', str_replace(["\r", "\n"], ' ', (string) $h)));
$map = [];
foreach ($rows[0] as $i => $h) {
    $k = $norm($h);
    if ($k !== '' && ! isset($map[$k])) {
        $map[$k] = $i;
    }
}
$ci = $map['contractno'] ?? null;
$vi = $map['chasisno'] ?? null;

$knownVins = App\Models\Vehicle::pluck('id', 'vin'); // vin => id

$total = 0; $linked = 0; $blank = 0; $notInFleet = 0;
$blankEx = []; $notInFleetEx = [];

foreach (array_slice($rows, 1) as $row) {
    $cno = trim((string) ($row[$ci] ?? ''));
    if ($cno === '' || ! preg_match('/\d/', $cno)) {
        continue;
    }
    $total++;
    $vin = trim((string) ($row[$vi] ?? ''));

    if ($vin === '') {
        $blank++;
        if (count($blankEx) < 6) $blankEx[] = $cno;
    } elseif (isset($knownVins[$vin])) {
        $linked++;
    } else {
        $notInFleet++;
        if (count($notInFleetEx) < 10) $notInFleetEx[] = "contract {$cno}  ->  VIN '{$vin}'";
    }
}

echo "Total contract rows : {$total}\n";
echo "Linked to a vehicle : {$linked}\n";
echo "------ NOT linked ({$blank} + {$notInFleet} = " . ($blank + $notInFleet) . ") ------\n";
echo "  (A) ChasisNo is BLANK in the sheet : {$blank}\n";
echo "  (B) VIN present but NOT in our fleet: {$notInFleet}\n";
echo "\nExamples (A) blank-VIN contracts: " . implode(', ', $blankEx) . "\n";
echo "Examples (B) VIN-not-in-fleet:\n";
foreach ($notInFleetEx as $e) {
    echo "  {$e}\n";
}
