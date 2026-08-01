<?php

namespace App\Services\Garage;

/**
 * What a repair is likely to cost, and — the part that matters — how much that figure is worth.
 *
 * FleetView barely records repair cost directly (314 priced repairs in 26,839), so cost is rebuilt from
 * the vehicle expense ledger by ATTRIBUTION: whatever was booked against the car in a tight window around
 * the day it went to the garage. That is an inference, not an invoice, and this class is built so the
 * inference can never be mistaken for one.
 *
 * The estimate walks a ladder of decreasing specificity and STOPS at the first rung with enough history:
 *
 *   garage_fault_model  this garage, this fault, this model   ← the real answer when it exists
 *   garage_fault        this garage, this fault
 *   garage              this garage, all its work             ← not a price for THIS fault
 *   fleet               everyone                              ← not about this garage at all
 *
 * Every rung it did NOT use is still published in `support`, so the operator can see the estimate was
 * demoted and why. A figure that silently falls back is worse than no figure: it looks specific, reads as
 * specific, and is not — which is exactly the failure the fleet-median cost had before this existed.
 *
 * PURE: no DB, no config(), no facades. {@see RepairCostEstimator} assembles the buckets and injects the
 * thresholds; this class only decides what may be claimed from them.
 *
 * See [[garage-recommendation-engine]] and [[evidence-layer-governance]].
 */
class RepairCostIndex
{
    /**
     * @param  array<string, array{median:float, p90:float, n:int}>  $buckets  keyed by grain (see key())
     * @param  array{garage_fault_model:int, garage_fault:int, garage:int}  $minSample
     */
    public function __construct(
        private array $buckets,
        private array $minSample = ['garage_fault_model' => 5, 'garage_fault' => 5, 'garage' => 5],
    ) {
    }

    /**
     * How much ledger the whole index rests on — line counts and bucket count. Published so the
     * explanation of the cost figure can quote the REAL corpus rather than a number someone typed.
     *
     * @return array{lines_kept:int, lines_dropped:int, buckets:int}
     */
    public function meta(): array
    {
        $m = $this->buckets['__meta'] ?? [];
        return [
            'lines_kept'    => (int) ($m['lines_kept'] ?? 0),
            'lines_dropped' => (int) ($m['lines_dropped'] ?? 0),
            'buckets'       => (int) ($m['buckets'] ?? 0),
        ];
    }

    /** The bucket key for one grain. Kept in one place so the builder and the reader cannot disagree. */
    public static function key(string $grain, ?int $vendorId = null, ?string $fault = null, ?string $model = null): string
    {
        return implode('|', array_filter([$grain, $vendorId, $fault, $model !== null ? mb_strtolower(trim($model)) : null], fn ($p) => $p !== null && $p !== ''));
    }

    /**
     * The best defensible cost estimate for this garage, these faults and this model.
     *
     * Where the ticket carries several faults, each is priced at the finest grain available and the
     * results are SUMMED — a car in for an engine knock and a scraped door costs both, and quoting the
     * median of the two would understate the job. When no fault can be priced individually the whole
     * job falls back to the garage's overall median, which is a different claim and is labelled as one.
     *
     * @param  array<int, string>  $faults
     * @return array{value:?float, basis:string, sample:int, support:array<string,int>, per_fault:array<int,array<string,mixed>>, reason:?string}
     */
    public function estimate(int $vendorId, array $faults = [], ?string $model = null): array
    {
        $support = [
            'garage_fault_model' => 0,
            'garage_fault'       => 0,
            'garage'             => (int) ($this->buckets[self::key('garage', $vendorId)]['n'] ?? 0),
            'fleet'              => (int) ($this->buckets[self::key('fleet')]['n'] ?? 0),
        ];

        // Price each fault at the best grain it can support.
        $perFault = [];
        foreach (array_unique($faults) as $fault) {
            $row = $this->forFault($vendorId, $fault, $model);
            if ($row['value'] !== null) {
                $support[$row['basis']] = max($support[$row['basis']] ?? 0, $row['sample']);
            }
            $perFault[] = ['fault' => $fault] + $row;
        }

        $priced = array_values(array_filter($perFault, fn ($f) => $f['value'] !== null));

        // Every fault priced → the sum is a genuine per-fault estimate. A PARTIAL sum would be worse
        // than useless: it looks like the job total while silently omitting the faults it could not
        // price, so it is refused in favour of the honest garage-level figure.
        if ($priced && count($priced) === count($perFault)) {
            $weakest = $this->weakest(array_column($priced, 'basis'));
            return [
                'value'     => round(array_sum(array_column($priced, 'value')), 0),
                'basis'     => $weakest,
                'sample'    => min(array_column($priced, 'sample')),
                'support'   => $support,
                'per_fault' => $perFault,
                'reason'    => null,
            ];
        }

        // Fall back to what this garage bills across ALL its work — a real figure about a broader
        // question, and it has to say which.
        $garage = $this->buckets[self::key('garage', $vendorId)] ?? null;
        if ($garage && $garage['n'] >= $this->minSample['garage']) {
            return [
                'value' => $garage['median'], 'basis' => 'garage', 'sample' => $garage['n'],
                'support' => $support, 'per_fault' => $perFault,
                'reason' => $faults ? 'Not enough priced history for these specific faults — showing what this garage bills across all its work.' : null,
            ];
        }

        $fleet = $this->buckets[self::key('fleet')] ?? null;
        if (! $fleet || $fleet['n'] <= 0) {
            return [
                'value' => null, 'basis' => 'unavailable', 'sample' => 0, 'support' => $support,
                'per_fault' => $perFault,
                'reason' => 'No priced repairs anywhere in the expense ledger yet.',
            ];
        }

        return [
            'value' => $fleet['median'], 'basis' => 'fleet', 'sample' => $fleet['n'],
            'support' => $support, 'per_fault' => $perFault,
            'reason' => 'Only ' . $support['garage'] . ' priced repairs at this garage — showing the fleet median instead, which is not specific to them.',
        ];
    }

    /**
     * One fault at the finest grain its history can support. Returns a null value rather than reaching
     * for the garage-wide median: at this level "we cannot price this fault" is the honest answer, and
     * the caller decides what to do about it.
     *
     * @return array{value:?float, basis:string, sample:int, label:?string}
     */
    public function forFault(int $vendorId, string $fault, ?string $model = null): array
    {
        if ($model !== null && $model !== '') {
            $b = $this->buckets[self::key('garage_fault_model', $vendorId, $fault, $model)] ?? null;
            if ($b && $b['n'] >= $this->minSample['garage_fault_model']) {
                return ['value' => $b['median'], 'basis' => 'garage_fault_model', 'sample' => $b['n'], 'label' => null];
            }
        }

        $b = $this->buckets[self::key('garage_fault', $vendorId, $fault)] ?? null;
        if ($b && $b['n'] >= $this->minSample['garage_fault']) {
            return ['value' => $b['median'], 'basis' => 'garage_fault', 'sample' => $b['n'], 'label' => null];
        }

        return ['value' => null, 'basis' => 'unavailable', 'sample' => (int) ($b['n'] ?? 0), 'label' => null];
    }

    /**
     * Every fault this garage CAN price, for the per-fault cost strip. Only what clears the sample floor
     * appears — Phase 2's whole point is that a category price shows up when it is earned and stays away
     * when it is not.
     *
     * @param  array<int, string>  $faults
     * @return array<int, array{fault:string, value:float, basis:string, sample:int}>
     */
    public function breakdown(int $vendorId, array $faults, ?string $model = null): array
    {
        $out = [];
        foreach (array_unique($faults) as $f) {
            $row = $this->forFault($vendorId, $f, $model);
            if ($row['value'] !== null) {
                $out[] = ['fault' => $f, 'value' => $row['value'], 'basis' => $row['basis'], 'sample' => $row['sample']];
            }
        }
        return $out;
    }

    /** The whole is only as specific as its least specific part. */
    private function weakest(array $bases): string
    {
        $order = ['garage_fault_model', 'garage_fault', 'garage', 'fleet', 'unavailable'];
        $worst = 'garage_fault_model';
        foreach ($bases as $b) {
            if (array_search($b, $order, true) > array_search($worst, $order, true)) {
                $worst = $b;
            }
        }
        return $worst;
    }
}
