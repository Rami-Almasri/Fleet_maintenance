<?php
/**
 * GOLD SET SCORER — dual track.
 *
 *   php score-goldset.php --human=concept-bridge-TECHNICIAN-LABELLED.csv \
 *                         --ai=concept-bridge-AI-BASELINE.csv
 *   php score-goldset.php <file>          # single file, treated as human
 *
 * TWO TRACKS, NEVER MIXED:
 *   TRACK A  OFFICIAL BENCHMARK — human labels only. This is the number of record.
 *   TRACK B  AI AGREEMENT — how closely the machine baseline matched the human on the same rows.
 *            Tells us how far the AI baseline can be trusted on rows no human labelled.
 *   TRACK C  AI-only rows — indicative, never quoted as benchmark.
 *
 * Standalone: no Laravel, no DB, no network.
 */

$args = array_slice($argv, 1);
$humanPath = $aiPath = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--human=')) { $humanPath = substr($a, 8); }
    elseif (str_starts_with($a, '--ai=')) { $aiPath = substr($a, 5); }
    elseif (! str_starts_with($a, '--')) { $humanPath ??= $a; }
}
if (! $humanPath && ! $aiPath) { fwrite(STDERR, "usage: score-goldset.php --human=FILE [--ai=FILE]\n"); exit(1); }

$VERDICTS = ['correct_specific','correct_broad','incorrect','none_predicted'];
$TYPES    = ['fault','action','part','procedure','operational','unclear'];
$QUALITY  = ['strong','medium','weak',''];

function loadLabels(?string $path, array &$report): array {
    if (! $path) { return []; }
    if (! is_readable($path)) { fwrite(STDERR, "Cannot read: $path\n"); exit(1); }
    global $VERDICTS, $TYPES, $QUALITY;
    $fh = fopen($path, 'r');
    $bom = fread($fh, 3); if ($bom !== "\xEF\xBB\xBF") { rewind($fh); }
    $head = fgetcsv($fh); $col = array_flip($head);
    foreach (['row_id','stratum','LABEL_pred1_verdict','LABEL_segment_type'] as $c) {
        if (! isset($col[$c])) { fwrite(STDERR, "Missing column '$c' in $path\n"); exit(1); }
    }
    $rows = []; $report = ['unlabelled'=>0,'invalid'=>[],'excluded'=>0];
    while ($r = fgetcsv($fh)) {
        $g = fn (string $c) => isset($col[$c]) ? trim((string)($r[$col[$c]] ?? '')) : '';
        $v = strtolower($g('LABEL_pred1_verdict'));
        $t = strtolower($g('LABEL_segment_type'));
        $q = strtolower($g('LABEL_evidence_quality'));
        if ($v === '' && $t === '') { $report['unlabelled']++; continue; }
        if ($v !== '' && ! in_array($v, $VERDICTS, true)) { $report['invalid'][] = "verdict '$v' (row {$g('row_id')})"; continue; }
        if ($t !== '' && ! in_array($t, $TYPES, true))    { $report['invalid'][] = "type '$t' (row {$g('row_id')})"; continue; }
        if (! in_array($q, $QUALITY, true))               { $report['invalid'][] = "quality '$q' (row {$g('row_id')})"; continue; }
        $notes = $g('LABEL_notes');
        if (stripos($notes,'needs the full ticket')!==false || stripos($notes,'needs ticket')!==false) { $report['excluded']++; continue; }
        $rows[(int)$g('row_id')] = [
            'stratum'=>$g('stratum'), 'sig'=>$g('v1_signature'), 'text'=>$g('segment_text'),
            'concept'=>$g('pred1_concept'), 'score'=>(int)$g('pred1_score'), 'stage'=>$g('pred1_primary_stage'),
            'term'=>$g('pred1_matched_term'), 'type'=>$t, 'verdict'=>$v, 'quality'=>$q,
            'valid'=>$g('LABEL_valid_concepts'), 'missing'=>$g('LABEL_missing_concepts'), 'notes'=>$notes,
        ];
    }
    fclose($fh);
    return $rows;
}

$hRep = $aRep = [];
$human = loadLabels($humanPath, $hRep);
$ai    = loadLabels($aiPath, $aRep);

$pc  = fn ($n,$d) => $d>0 ? round(100*$n/$d,1) : null;
$fmt = fn (?float $v) => $v===null ? '   n/a' : sprintf('%5.1f%%',$v);
$bar = fn (?float $v) => $v===null ? '' : str_repeat('#', (int)round($v/4));
$L   = str_repeat('=',82);

$prec = function (array $set): array {
    $ok=$br=$bad=0;
    foreach ($set as $r) {
        if ($r['verdict']==='correct_specific') $ok++;
        elseif ($r['verdict']==='correct_broad') $br++;
        elseif ($r['verdict']==='incorrect') $bad++;
    }
    $den=$ok+$br+$bad;
    return ['n'=>$den,'specific'=>$ok,'broad'=>$br,'wrong'=>$bad,
            'precision'=>$den?round(100*($ok+$br)/$den,1):null,
            'strict'=>$den?round(100*$ok/$den,1):null];
};

echo "\n$L\nCONCEPT BRIDGE — GOLD SET RESULTS\n$L\n";
if ($humanPath) printf("  human file : %s  (%d rows scored, %d blank)\n", basename($humanPath), count($human), $hRep['unlabelled'] ?? 0);
if ($aiPath) printf("  ai file    : %s  (%d rows)\n", basename($aiPath), count($ai));
if (! empty($hRep['invalid'])) { echo "\n⚠ INVALID HUMAN LABELS (skipped):\n"; foreach (array_slice($hRep['invalid'],0,10) as $m) echo "   $m\n"; }

if (! $human) {
    echo "\n$L\n⚠ NO HUMAN LABELS — there is no official benchmark yet.\n$L\n";
    if ($ai) {
        $p = $prec($ai);
        echo "\nAI BASELINE ONLY (indicative, machine-generated — do NOT quote as accuracy):\n";
        printf("   n=%-4d precision %s   strict %s   (specific=%d broad=%d incorrect=%d)\n",
            $p['n'], $fmt($p['precision']), $fmt($p['strict']), $p['specific'], $p['broad'], $p['wrong']);
        $st = [];
        foreach ($ai as $r) { $st[$r['stratum']][] = $r; }
        ksort($st);
        echo "\n   BY STRATUM\n";
        foreach ($st as $s => $set) { $q = $prec($set); printf("     %-24s n=%-4d %s %s\n", $s, $q['n'], $fmt($q['precision']), $bar($q['precision'])); }
        echo "\nRun again with --human=… once the technician set is labelled.\n";
    }
    echo "\n"; exit(0);
}

// ══════════════════════════════════════════════════════════════════════════════
echo "\n$L\nTRACK A — OFFICIAL BENCHMARK   (human ground truth ONLY)\n$L\n";
$where = fn (callable $f) => array_values(array_filter($human, $f));

echo "\nA1. PRECISION   (precision = specific + broad · strict = specific only)\n";
$all = $prec($human);
printf("   OVERALL            n=%-4d precision %s   strict %s\n", $all['n'], $fmt($all['precision']), $fmt($all['strict']));
printf("                      specific=%d  broad=%d  incorrect=%d\n", $all['specific'],$all['broad'],$all['wrong']);

echo "\n   BY STRATUM\n";
$strata = [];
foreach ($human as $r) { $strata[$r['stratum']][] = $r; }
ksort($strata);
foreach ($strata as $s => $set) {
    $p = $prec($set);
    printf("     %-24s n=%-4d %s %s\n", $s, $p['n'], $fmt($p['precision']), $bar($p['precision']));
}

echo "\n   BY MATCHING METHOD\n";
$stages=[]; foreach ($human as $r) { if ($r['stage']!=='') $stages[$r['stage']][]=$r; }
uasort($stages, fn($a,$b)=>count($b)<=>count($a));
foreach ($stages as $st=>$set) {
    $p=$prec($set);
    printf("     %-24s n=%-4d %s %s%s\n", $st, $p['n'], $fmt($p['precision']), $bar($p['precision']), $p['n']<20?'  ⚠ thin':'');
}

echo "\nA2. ACTION-AS-FAULT\n";
$nonFault = $where(fn($r)=>in_array($r['type'],['action','part','procedure'],true) && $r['concept']!=='');
$accepted = array_values(array_filter($nonFault, fn($r)=>$r['verdict']!=='incorrect'));
printf("   action/part/procedure segments with a fault concept predicted: %d\n", count($nonFault));
printf("   ...fault concept accepted anyway:                              %d (%s)\n", count($accepted), $fmt($pc(count($accepted),count($nonFault))));
foreach (['action','part','procedure'] as $t) {
    $set=$where(fn($r)=>$r['type']===$t && $r['concept']!=='');
    $bad=array_values(array_filter($set,fn($r)=>$r['verdict']==='incorrect'));
    printf("     %-11s n=%-4d outright wrong %s\n", $t, count($set), $fmt($pc(count($bad),count($set))));
}

echo "\nA3. GRANULARITY\n";
$correct=$where(fn($r)=>in_array($r['verdict'],['correct_specific','correct_broad'],true));
$broad  =$where(fn($r)=>$r['verdict']==='correct_broad');
printf("   correct: %d · too broad: %d (%s)   → >30%% ⇒ ontology needs component-level concepts\n",
    count($correct), count($broad), $fmt($pc(count($broad),count($correct))));

echo "\nA4. ONTOLOGY GAPS\n";
$gaps=[];
foreach ($human as $r) foreach (preg_split('/[;,]/',$r['missing']) as $m) {
    $m=trim(mb_strtolower($m)); if($m===''||$m==='none'||$m==='n/a') continue; $gaps[$m]=($gaps[$m]??0)+1;
}
arsort($gaps);
printf("   distinct: %d\n", count($gaps));
$i=0; foreach ($gaps as $g=>$n) { if($i++>=30) break; printf("     %4d  %s\n",$n,$g); }

echo "\nA5. CONFIDENCE CALIBRATION\n";
printf("     %-10s %6s %10s %9s\n",'QUALITY','N','AVG SCORE','RANGE');
foreach (['strong','medium','weak'] as $q) {
    $set=$where(fn($r)=>$r['quality']===$q);
    if(!$set){ printf("     %-10s %6d        n/a\n",$q,0); continue; }
    $sc=array_column($set,'score');
    printf("     %-10s %6d %10.1f %4d-%d\n",$q,count($set),array_sum($sc)/count($sc),min($sc),max($sc));
}

echo "\nA6. FALSE-POSITIVE PATTERNS\n";
$fp=[];
foreach ($where(fn($r)=>$r['verdict']==='incorrect') as $r) {
    $k='"'.($r['term']?:mb_substr($r['text'],0,22)).'"  ->  '.$r['concept'];
    $fp[$k]=($fp[$k]??0)+1;
}
arsort($fp); $i=0;
foreach ($fp as $k=>$n) { if($i++>=20) break; printf("     %4d  %s\n",$n,$k); }

// ══════════════════════════════════════════════════════════════════════════════
if ($ai) {
    $both = array_intersect_key($ai, $human);
    echo "\n$L\nTRACK B — AI AGREEMENT WITH HUMAN   (rows labelled by BOTH: " . count($both) . ")\n$L\n";
    echo "  Measures how far the machine baseline can be trusted — NOT the matcher's accuracy.\n";

    if (! $both) { echo "\n  No overlapping rows.\n"; }
    else {
        foreach ([['verdict','B1. VERDICT'],['type','B2. SEGMENT TYPE'],['quality','B3. EVIDENCE QUALITY']] as [$f,$title]) {
            $agree=0; $n=0; $conf=[];
            foreach ($both as $id=>$a) {
                $h=$human[$id];
                if ($h[$f]==='' && $a[$f]==='') continue;
                $n++;
                if ($h[$f]===$a[$f]) $agree++;
                else $conf["human={$h[$f]} · ai={$a[$f]}"] = ($conf["human={$h[$f]} · ai={$a[$f]}"]??0)+1;
            }
            printf("\n  %s   agreement %s  (%d/%d)\n", $title, $fmt($pc($agree,$n)), $agree, $n);
            arsort($conf); $i=0;
            foreach ($conf as $k=>$c) { if($i++>=6) break; printf("       %3d  %s\n",$c,$k); }
        }

        // Directional bias: is the AI more generous than the human?
        $rank=['incorrect'=>0,'correct_broad'=>1,'correct_specific'=>2];
        $up=$down=0;
        foreach ($both as $id=>$a) {
            $h=$human[$id];
            if(!isset($rank[$h['verdict']],$rank[$a['verdict']])) continue;
            if($rank[$a['verdict']]>$rank[$h['verdict']]) $up++;
            elseif($rank[$a['verdict']]<$rank[$h['verdict']]) $down++;
        }
        printf("\n  B4. BIAS   AI more generous than human: %d · stricter: %d", $up, $down);
        echo $up > $down*1.5 ? "   ⚠ AI baseline is optimistic — discount Track C\n" : "\n";

        $pH=$prec(array_intersect_key($human,$both)); $pA=$prec(array_intersect_key($ai,$both));
        printf("\n  B5. SAME ROWS, BOTH LENSES   human %s   ai %s   gap %s\n",
            $fmt($pH['precision']), $fmt($pA['precision']),
            $fmt($pA['precision']!==null&&$pH['precision']!==null ? round($pA['precision']-$pH['precision'],1) : null));
    }

    $only = array_diff_key($ai, $human);
    if ($only) {
        $p=$prec($only);
        echo "\n$L\nTRACK C — AI-ONLY ROWS (" . count($only) . ")   ⚠ INDICATIVE, NOT BENCHMARK\n$L\n";
        printf("   precision %s   strict %s   (machine-generated labels; weight by Track B agreement)\n",
            $fmt($p['precision']), $fmt($p['strict']));
    }
}

// ══════════════════════════════════════════════════════════════════════════════
echo "\n$L\nPRE-REGISTERED DECISION RULES   (Track A only)\n$L\n";
$core = $prec($where(fn($r)=>$r['stratum']==='control_80_plus'||str_starts_with($r['stratum'],'system_')));
$band = $prec($where(fn($r)=>$r['stratum']==='band_60_79'));
$broadPct  = $pc(count($broad),count($correct));
$actionPct = $pc(count($accepted),count($nonFault));

$say=function(string $label,?float $val,array $rules) use ($fmt){
    printf("   %-34s %s   ",$label,$fmt($val));
    if($val===null){ echo "→ insufficient data in the human set\n"; return; }
    foreach($rules as [$t,$m]){ if($t($val)){ echo "→ $m\n"; return; } }
    echo "\n";
};
$say('Core precision (control+systems)',$core['precision'],[
    [fn($v)=>$v>=85,'PROCEED to full backfill'],
    [fn($v)=>$v>=70,'BLOCKED — expand ontology + phrase-first rule, re-score'],
    [fn($v)=>true,  'BLOCKED — fix segmentation/matching first'],
]);
$say('Band 60-79 precision',$band['precision'],[
    [fn($v)=>$v>=75,'LOWER the threshold toward 60 (~20 pts coverage)'],
    [fn($v)=>$v>=60,'inconclusive — keep 70, needs more rows'],
    [fn($v)=>true,  'KEEP threshold at 70 — that band is noise'],
]);
$say('correct_broad share',$broadPct,[
    [fn($v)=>$v>30,'ontology needs COMPONENT-LEVEL concepts before ingestion'],
    [fn($v)=>true, 'granularity acceptable for knowledge fusion'],
]);
$say('Action-as-fault rate',$actionPct,[
    [fn($v)=>$v>50,'BUILD the fault/action/part/procedure split before backfill'],
    [fn($v)=>true, 'action confusion is not systemic'],
]);
echo "\n$L\n\n";
