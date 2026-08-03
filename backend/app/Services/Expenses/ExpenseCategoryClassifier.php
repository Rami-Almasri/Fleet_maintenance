<?php

namespace App\Services\Expenses;

/**
 * Expense category classifier — turns a free-text expense remark into ONE operational bucket.
 *
 * Why this exists: `vehicle_expenses.account_type` is a single value ("Expence") on 28,320 of 28,327
 * rows, so the ledger's own type column carries no information. The only thing that distinguishes an
 * insurance premium from a tyre change is the remark someone typed. This class reads that remark and
 * nothing else.
 *
 * The rules are DETERMINISTIC and AUDITABLE, not inference: an ordered list of literal terms, matched
 * on word boundaries, first match wins. Every classification returns the exact term that matched
 * ({@see classify()} → `matched`), so any bucket a user disputes can be traced to the one word that
 * put the line there. A remark that matches nothing is `other` — never a guess, never a score.
 *
 * ORDER IS THE DESIGN. Real remarks are composites ("INV56400/CAR63393/RENT 1 DAYS/SALIK 30/TRAFFIC
 * 3040/FUEL 50/VAT104" is a sub-rental recharge, not four separate costs), so the more specific and
 * more "whole-line-defining" rules run first. Notable orderings, each of which is load-bearing:
 *   - sub-rental recharge above salik/fines/fuel — those words are line items INSIDE a rent invoice.
 *   - GPS above sub-rental — "Renewal for GPS device rent" is a telematics cost, not a car rental.
 *   - oil/fluids/filters above fuel — "clean fuel filter" is a filter job, not a fuel purchase.
 *   - every specific repair family above the generic service/repair catch-all.
 */
final class ExpenseCategoryClassifier
{
    public const UNCATEGORISED = 'other';

    /**
     * Ordered rules — first match wins. `key` is the stable code (safe to store, filter and translate);
     * `label` is the English default for display.
     *
     * @var list<array{key:string,label:string,terms:list<string>}>
     */
    private const RULES = [
        // Bookkeeping corrections, not spend. First, because "revised PV … wrong entry" describes the
        // whole line no matter what the original transaction was about.
        ['key' => 'adjustment', 'label' => 'Accounting adjustment', 'terms' => [
            'wrong entry', 'revised', 'revise', 'reversal', 'reverse entry', 'correction', 'rectify',
            'adjustment', 'double entry', 'duplicate entry',
        ]],
        ['key' => 'insurance', 'label' => 'Insurance', 'terms' => [
            'insurance', 'insurence', 'insuranc', 'insurnce', 'annual premium', 'policy premium', 'tameen',
        ]],
        ['key' => 'gps', 'label' => 'GPS & tracking', 'terms' => [
            'gps', 'securepath', 'secure path', 'tracker', 'tracking', 'najoom', 'alnajoom', 'telematics',
        ]],
        // "CAR TEST", "EXTEND LIFE SPAN" and "CERTIFICATE" are all how this ledger writes the RTA
        // annual test that keeps a car registered — they belong here, not under inspection/repair.
        ['key' => 'registration', 'label' => 'Registration & licensing', 'terms' => [
            'registration', 'register', 'mulkiya', 'mulkia', 'istimara', 'passing', 'plate',
            'number plate', 'rta', 'vehicle licence', 'vehicle license', 'export certificate', 'possession',
            'renewal', 'renew', 'certificate', 'certi', 'car test', 'cars test', 'vehicle test', 'retest',
            'life span', 'extend life',
        ]],
        ['key' => 'sub_rental', 'label' => 'Sub-rental & lease recharge', 'terms' => [
            'rent', 'rental', 'sublease', 'sub lease', 'lease', 'hire',
        ]],
        ['key' => 'salik', 'label' => 'Salik & tolls', 'terms' => [
            'salik', 'toll', 'darb', 'parking',
        ]],
        ['key' => 'fines', 'label' => 'Traffic fines', 'terms' => [
            'fine', 'traffic', 'mukhalafa', 'black point', 'impound', 'penalty',
        ]],
        ['key' => 'tyres', 'label' => 'Tyres & wheels', 'terms' => [
            'tyre', 'tire', 'rim', 'wheel', 'alignment', 'balancing', 'balance', 'puncture', 'nitrogen',
        ]],
        // Above oil/fluids: "brake oil" and "brake fluid" are a brake job, not a routine oil change.
        ['key' => 'brakes_suspension', 'label' => 'Brakes, suspension & steering', 'terms' => [
            'brake', 'disc', 'disk', 'pad', 'caliper', 'shock', 'absorber', 'suspension',
            'arm', 'bush', 'ball joint', 'steering', 'axle', 'axil', 'link rod', 'tie rod', 'abs',
        ]],
        ['key' => 'oil_fluids', 'label' => 'Oil, fluids & filters', 'terms' => [
            'oil', 'filter', 'lubricant', 'grease', 'coolant top',
        ]],
        ['key' => 'fuel', 'label' => 'Fuel', 'terms' => [
            'fuel', 'refuel', 'refule', 'refueling', 'refuling', 'petrol', 'pertol', 'diesel', 'adnoc', 'enoc', 'eppco',
        ]],
        ['key' => 'electrical', 'label' => 'Battery, electrical & electronics', 'terms' => [
            'battery', 'batteries', 'batery', 'baterry', 'dynamo', 'alternator', 'starter', 'wiring', 'wire',
            'fuse', 'bulb', 'buld', 'lamp', 'light', 'headlight', 'horn', 'sensor', 'ecu', 'computer',
            'programming', 'programing', 'diagnos', 'scanning', 'airbag', 'radar', 'immobilizer',
            'key', 'remote',
        ]],
        ['key' => 'ac_cooling', 'label' => 'A/C & cooling', 'terms' => [
            'a/c', 'ac gas', 'aircon', 'air condition', 'air conditioning', 'radiator', 'thermostat',
            'water pump', 'coolant', 'compressor', 'condenser', 'cooling fan',
        ]],
        ['key' => 'engine_transmission', 'label' => 'Engine & transmission', 'terms' => [
            'engine', 'gearbox', 'gear box', 'transmission', 'clutch', 'spark plug', 'ignition coil',
            'timing', 'piston', 'cylinder', 'gasket', 'injector', 'turbo', 'exhaust', 'muffler',
            'catalytic', 'valve', 'belt', 'overhaul', 'differential', 'propeller',
        ]],
        ['key' => 'body_paint', 'label' => 'Body, paint & glass', 'terms' => [
            'denting', 'dent', 'painting', 'paint', 'bumper', 'fender', 'fendar', 'bonnet', 'door',
            'mirror', 'glass', 'windshield', 'windscreen', 'wiper', 'polish', 'buffing', 'sticker', 'tinting',
            'tint', 'body', 'bodyshop', 'scratch', 'grill', 'spoiler', 'upholstery', 'seat',
        ]],
        ['key' => 'cleaning', 'label' => 'Cleaning & detailing', 'terms' => [
            'wash', 'washing', 'cleaning', 'clean', 'shampoo', 'detailing', 'vacuum',
        ]],
        ['key' => 'recovery', 'label' => 'Recovery & towing', 'terms' => [
            'recovery', 'towing', 'tow', 'winch', 'jump start', 'roadside',
        ]],
        ['key' => 'parts', 'label' => 'Spare parts', 'terms' => [
            'spare', 'spare part', 'used part', 'part', 'accessories',
        ]],
        ['key' => 'service_repair', 'label' => 'General service & repair', 'terms' => [
            'service', 'servicing', 'repair', 'repairing', 'maintenance', 'maintenanc', 'maintenace',
            'maintenac', 'workshop', 'garage', 'labour', 'labor', 'check up', 'checkup', 'inspection',
            'fix', 'fixing', 'replace', 'replacement', 'installation', 'fitting',
        ]],
    ];

    /**
     * Classify one remark.
     *
     * @return array{key:string,label:string,matched:string|null} `matched` is the literal term that
     *         decided the bucket — null for `other`.
     */
    public function classify(?string $remarks): array
    {
        $text = $this->normalise($remarks);

        if ($text !== '') {
            foreach (self::RULES as $rule) {
                foreach ($rule['terms'] as $term) {
                    // Boundaries are "not a letter or digit" rather than \b, so "/" and "+" separate
                    // words ("RENT+SALIK") while "ARMADA" still cannot match the term "arm". A trailing
                    // "s" is optional so terms are stored singular once — "fender" catches "fenders".
                    if (preg_match('/(?<![a-z0-9])' . preg_quote($term, '/') . 's?(?![a-z0-9])/', $text)) {
                        return ['key' => $rule['key'], 'label' => $rule['label'], 'matched' => $term];
                    }
                }
            }
        }

        return ['key' => self::UNCATEGORISED, 'label' => 'Uncategorised', 'matched' => null];
    }

    /**
     * Every bucket in rule order, `other` last — the canonical order for a filter UI.
     *
     * @return list<array{key:string,label:string}>
     */
    public static function categories(): array
    {
        $out = array_map(fn ($r) => ['key' => $r['key'], 'label' => $r['label']], self::RULES);
        $out[] = ['key' => self::UNCATEGORISED, 'label' => 'Uncategorised'];

        return $out;
    }

    /** @return array<string,string> key → label, including `other`. */
    public static function labels(): array
    {
        return array_column(self::categories(), 'label', 'key');
    }

    /**
     * Lowercase, then flatten every separator to a space so "-", "+", "." and "," stop welding words
     * together ("RENT+SALIK", "bumper-repair"). "/" is the one separator kept, because it is part of
     * the term "a/c"; it still reads as a boundary everywhere else, since the matcher's boundary is
     * "not a letter or digit" rather than \b.
     */
    private function normalise(?string $remarks): string
    {
        $t = mb_strtolower(trim((string) $remarks));
        if ($t === '') {
            return '';
        }

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9\/]+/', ' ', $t)));
    }
}
