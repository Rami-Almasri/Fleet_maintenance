<?php

namespace Tests\Unit;

use App\Services\Expenses\ExpenseCategoryClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DB-free tests for the expense bucket rules. Every remark below is a REAL line from the ledger — the
 * point of these cases is not that keyword matching works, but that the RULE ORDER survives edits: the
 * composite remarks (a sub-rental invoice that also lists salik, fuel and traffic; a "fuel filter" job
 * that is not a fuel purchase) are the ones that break when a term is moved.
 */
class ExpenseCategoryClassifierTest extends TestCase
{
    private ExpenseCategoryClassifier $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ExpenseCategoryClassifier();
    }

    #[DataProvider('realRemarks')]
    public function test_it_files_a_remark_under_the_expected_bucket(string $remark, string $expected): void
    {
        $this->assertSame($expected, $this->svc->classify($remark)['key'], $remark);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function realRemarks(): array
    {
        return [
            'insurance premium'    => ['ADAMJEE INSURANCE CO INVOICE 10140309 / CAMARO 46287 / INSURANCE ANNUAL PREMIUM.', 'insurance'],
            'monthly insurance'    => ['insurance expence for sep 25 / car 81147', 'insurance'],
            'plate replacement'    => ['REPLACEMENT OF LOST OR DAMAGED PLATE FOR CHARGER', 'registration'],
            'new registration'     => ['NEW VEHICLE REGISTRATION FOR CHARGER', 'registration'],
            'rta test'             => ['PAYMENT FOR RTA CAR TEST', 'registration'],
            'life span extension'  => ['EXTEND LIFE SPAN P74880', 'registration'],
            'salik tag'            => ['SALIK TAG FOR CHARGER', 'salik'],
            'gps device'           => ['PAID FOR CHARGER SILVER GPS DEVICE', 'gps'],
            'tyre repair'          => ['FOR REPAIRING TYRES NEW CHARGER SILVER', 'tyres'],
            'exhaust repair'       => ['EXHAUST REPAIR FOR NEW CHARGER SILVER', 'engine_transmission'],
            'fender repair'        => ['repair fenders for dodg charger silver red', 'body_paint'],
            'oil and filter'       => ['pd to amer car no 53725 oil and filter change km 119026', 'oil_fluids'],
            'accounting reversal'  => ['REVISED PY. NO 44459 FOR WRONG ENTRY', 'adjustment'],

            // Order-critical. A sub-rental invoice itemises salik, traffic and fuel inside it; reading
            // those as separate cost types would triple-count one rent transaction across three buckets.
            'sub-rental invoice'   => ['INV56400/CAR63393/RENT 1 DAYS/RENT 2000/SALIK 30/TRAFFIC 3040/FUEL 50/VAT104', 'sub_rental'],
            // "fuel filter" is a filter job, so oil/fluids/filters has to outrank fuel.
            'fuel filter cleaning' => ['inv 250 /car 32409 /cleane fuel filter, air filter and AC filter +labour', 'oil_fluids'],
            // GPS outranks sub-rental, or a tracker subscription reads as a car rental.
            'gps device rent'      => ['NAJOOM ALTHURAYA INVOICE 0007-2025 / LEVANTE 36726 / Renewal for GPS device rent.', 'gps'],
            // Specific repair families outrank the generic service/repair catch-all.
            'brake work'           => ['replacing abs and programing abs and brake oil /INVo1046 /Car 25873', 'brakes_suspension'],
        ];
    }

    public function test_a_remark_that_matches_nothing_is_uncategorised_not_guessed(): void
    {
        $r = $this->svc->classify('Nasir Metro - Bring the vehicle');

        $this->assertSame(ExpenseCategoryClassifier::UNCATEGORISED, $r['key']);
        $this->assertNull($r['matched'], 'An uncategorised line must not claim a matching term.');
    }

    public function test_an_empty_remark_is_uncategorised(): void
    {
        foreach ([null, '', '   '] as $empty) {
            $this->assertSame(ExpenseCategoryClassifier::UNCATEGORISED, $this->svc->classify($empty)['key']);
        }
    }

    public function test_every_classification_names_the_term_that_decided_it(): void
    {
        $r = $this->svc->classify('SALIK TAG FOR CHARGER');

        $this->assertSame('salik', $r['key']);
        $this->assertSame('salik', $r['matched'], 'The audit term is what makes the bucket defensible.');
    }

    public function test_terms_match_on_word_boundaries_not_substrings(): void
    {
        // "ARMADA" must not match the suspension term "arm"; "renewal" must not match "rent".
        $this->assertNotSame('brakes_suspension', $this->svc->classify('PAID ARMADA 35484 WASHING')['key']);
        $this->assertNotSame('sub_rental', $this->svc->classify('VEHICLE RENEWAL MAZDA 98223')['key']);
    }

    public function test_separators_do_not_weld_words_together(): void
    {
        // The ledger writes "RENT+SALIK+VAT" and "oil/filter" with no spaces at all.
        $this->assertSame('salik', $this->svc->classify('SALIK+VAT (ARMADA 35484)')['key']);
        $this->assertSame('oil_fluids', $this->svc->classify('engine-oil/filter change')['key']);
    }

    public function test_categories_are_ordered_with_uncategorised_last(): void
    {
        $keys = array_column(ExpenseCategoryClassifier::categories(), 'key');

        $this->assertSame(ExpenseCategoryClassifier::UNCATEGORISED, end($keys));
        $this->assertSame($keys, array_unique($keys), 'Bucket keys must be unique — they are filter identities.');
        $this->assertArrayHasKey('insurance', ExpenseCategoryClassifier::labels());
    }
}
