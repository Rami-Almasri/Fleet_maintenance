<?php

namespace Tests\Unit;

use App\Services\Knowledge\RepairSignatureClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Locks the behaviour the maintenance intelligence loop depends on.
 *
 * The span-consumption and LEAK_OTHER tests are not style checks — each encodes a mistake that
 * already produced a confident, plausible, wrong result during analysis. They exist so the same
 * mistake cannot return through a well-meaning pattern edit.
 */
class RepairSignatureClassifierTest extends TestCase
{
    private RepairSignatureClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new RepairSignatureClassifier();
    }

    /**
     * THE ARTIFACT TEST. "check engine light" contains the vocabulary of two signatures. Before span
     * consumption, CHECK_ENGINE + LIGHTS appeared as the fleet's strongest fault bundle (n=481,
     * lift 6.13) — the classifier correlating with itself. 591 notes contain this phrase.
     */
    public function test_check_engine_light_does_not_also_fire_lights(): void
    {
        $result = $this->classifier->classify('check engine light is on');

        $this->assertContains('CHECK_ENGINE', $result['signatures']);
        $this->assertNotContains('LIGHTS', $result['signatures'], 'CHECK_ENGINE must consume the whole phrase, leaving nothing for LIGHTS.');
    }

    public function test_a_genuine_light_fault_still_fires_lights(): void
    {
        $result = $this->classifier->classify('front right head light broken, needs new lamp');

        $this->assertContains('LIGHTS', $result['signatures']);
        $this->assertNotContains('CHECK_ENGINE', $result['signatures']);
    }

    /** LEAK_OTHER is a fallback. A radiator leak is COOLING, not a generic leak. */
    public function test_leak_other_yields_to_a_specific_signature(): void
    {
        $specific = $this->classifier->classify('leak from the bottom of the radiator');
        $this->assertContains('COOLING', $specific['signatures']);
        $this->assertNotContains('LEAK_OTHER', $specific['signatures']);

        $generic = $this->classifier->classify('there is a leak under the car');
        $this->assertSame(['LEAK_OTHER'], $generic['signatures']);
    }

    /** Real notes drawn from the corpus — the classifier must handle the fleet's actual prose. */
    public function test_classifies_real_corpus_notes(): void
    {
        $cases = [
            'Front right rim scratch'                                  => 'RIM',
            'got new two tires going to futurre tire garage'            => 'TYRE',
            'Radar problem'                                             => 'ACCESSORY',
            'did not start work on car, will change engine belt fan'     => 'ENGINE_MECH',
            'suspension check with alignment'                           => 'SUSPENSION',
            'oil and filter change'                                     => 'OIL_SERVICE',
            'Scratches on rims and vehicle body'                        => 'BODY',
        ];

        foreach ($cases as $note => $expected) {
            $this->assertContains(
                $expected,
                $this->classifier->signaturesFor($note),
                "Expected [$expected] from: $note"
            );
        }
    }

    /** Multi-fault notes are the norm, not the exception — the road-impact cluster travels together. */
    public function test_returns_every_signature_present(): void
    {
        $signatures = $this->classifier->signaturesFor(
            'Change 4 tire, rims Repairing. Wheel alignment In Computer, front Hub Bearing'
        );

        $this->assertContains('TYRE', $signatures);
        $this->assertContains('RIM', $signatures);
        $this->assertContains('STEERING', $signatures);
    }

    /**
     * Progress chatter must be distinguishable from an unclassifiable fault. 2,319 events are pure
     * status noise; counting them as "failed to classify" would understate coverage.
     */
    public function test_workflow_chatter_is_flagged_as_noise_not_as_a_fault(): void
    {
        $result = $this->classifier->classify('car is ready in parking');

        $this->assertSame([], $result['signatures']);
        $this->assertTrue($result['noise']);
    }

    public function test_empty_input_is_neither_labelled_nor_noise(): void
    {
        $result = $this->classifier->classify(null);

        $this->assertSame([], $result['signatures']);
        $this->assertFalse($result['noise']);
    }

    /** Every label must be explainable — a Decision Card has to be able to show its working. */
    public function test_reports_the_terms_that_fired(): void
    {
        $result = $this->classifier->classify('radiator leaking and battery dead');

        $this->assertArrayHasKey('COOLING', $result['matches']);
        $this->assertContains('radiator', $result['matches']['COOLING']);
        $this->assertArrayHasKey('BATTERY', $result['matches']);
    }

    /** Human `service_main` strings map onto the same vocabulary, including their misspellings. */
    public function test_maps_human_labels_including_misspellings(): void
    {
        $this->assertSame(['OIL_SERVICE'], $this->classifier->fromHumanLabel('Oil & Fillter Change'));
        $this->assertContains('SUSPENSION', $this->classifier->fromHumanLabel('Suspension Troubles'));
        $this->assertContains('BODY', $this->classifier->fromHumanLabel('Body Damage'));
    }

    /** Workflow states masquerading as fault labels must resolve to nothing. */
    public function test_workflow_states_are_not_faults(): void
    {
        foreach (['Ready', 'NEW CAR', 'Testing', 'Main reason'] as $notAFault) {
            $this->assertSame([], $this->classifier->fromHumanLabel($notAFault), "[$notAFault] is a workflow state, not a fault.");
        }
    }

    /** Exposure signatures measure customers, not workshops — quality metrics must exclude them. */
    public function test_exposure_signatures_are_identified(): void
    {
        $this->assertTrue($this->classifier->isExposure('BODY'));
        $this->assertTrue($this->classifier->isExposure('RIM'));
        $this->assertFalse($this->classifier->isExposure('COOLING'));
    }

    public function test_vocabulary_is_the_canonical_22(): void
    {
        $this->assertCount(22, $this->classifier->vocabulary());
    }
}
