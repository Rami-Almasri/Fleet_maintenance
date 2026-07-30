<?php

namespace Database\Seeders;

use App\Models\FindingKeyword;
use App\Models\KeywordTerm;
use App\Services\KeywordOntologyService;
use App\Support\TextNormalizer;
use Illuminate\Database\Seeder;

/**
 * The vocabulary customers actually use, in English and Gulf Arabic.
 *
 * WHY THIS IS SEEDED RATHER THAN LEARNED. Six complaints exist in the system. There is nothing to
 * learn from, and there will not be for months — but complaints arrive in customer language from day
 * one, and a system that cannot read them is not usable while it waits for data. So this is written
 * knowledge now, and the continuous-learning loop ([[OntologyFeedbackService]]) will correct and
 * extend it from real complaints as they accumulate.
 *
 * WHY IT GOES IN keyword_terms AND NOT A NEW MATCHER. Customer phrasing is vocabulary, and this
 * platform already has one vocabulary store and one matching pipeline. Feeding the existing pipeline
 * means colloquial input immediately gets every stage — exact, alias, phrase, token, fuzzy, semantic
 * — plus the Arabic normalisation, the confidence model and the explanation trail, all for free. A
 * separate "complaint matcher" would have had to reimplement each of those and would drift from the
 * main one within a release.
 *
 * ONE PHRASE MAY MAP TO SEVERAL FAULTS, ON PURPOSE. "The car feels heavy" is listed against steering,
 * brakes and tyres. Forcing a single answer would be a fabricated diagnosis; returning three ranked
 * candidates for a technician to narrow down is what the complaint actually supports.
 */
class CustomerVocabularySeeder extends Seeder
{
    /**
     * concept keyword => [customer phrases]. Arabic entries are Gulf colloquial, which is what gets
     * typed here — not Modern Standard Arabic, which nobody uses to report a broken car.
     */
    private const VOCABULARY = [
        'Overheating' => [
            'engine running hot', 'temperature gauge going up', 'temp light came on',
            'steam from the bonnet', 'steam from the hood', 'car is boiling',
            'الموتر يسخن', 'السيارة تسخن', 'حرارة المكينة عالية', 'طلع دخان من المكينة',
        ],
        'Engine noise' => [
            'strange sound from the engine', 'knocking sound', 'ticking noise',
            'engine sounds rough', 'loud engine', 'rattling from the front',
            'صوت من المكينة', 'المكينة تطق', 'صوت غريب قدام',
        ],
        'Brake noise (squeal / grind)' => [
            'strange metallic sound when braking', 'squealing when i brake', 'grinding when stopping',
            'screeching noise when slowing down', 'brakes make noise',
            'صوت عند الفرامل', 'صرير عند البريك', 'صوت حديد عند الفرملة',
        ],
        'Vibration when braking' => [
            'steering wheel shakes when braking', 'pedal pulses when stopping',
            'car shudders when i slow down', 'shaking when braking at high speed',
            'الدركسون يرجف عند الفرملة', 'اهتزاز عند البريك',
        ],
        'Soft / spongy pedal' => [
            'brake pedal goes to the floor', 'pedal feels soft', 'brakes feel weak',
            'have to press hard to stop', 'البريك ما يمسك', 'الدعسة تنزل تحت',
        ],
        'Loss of power' => [
            "it doesn't pull", 'no power going uphill', 'car feels slow',
            'engine feels weak', 'not accelerating properly',
            'الموتر ما يسحب', 'ما فيه قوة', 'ضعيف في الطلعة',
        ],
        'Rough idle / misfire' => [
            'it hesitates', 'car shakes when stopped', 'engine stutters',
            'jerking when driving', 'vibrates only when cold', 'shaking at traffic lights',
            'الموتر يرجف وهو واقف', 'يقطع', 'يرجف على البارد',
        ],
        'Stalling' => [
            'engine cuts out', 'car switches off while driving', 'it dies at the lights',
            'الموتر يطفي', 'يفصل وهو ماشي',
        ],
        'Hard starting' => [
            'takes long to start', 'have to crank it many times', 'hard to start in the morning',
            'ما يشتغل بسرعة', 'يتعب في التشغيل',
        ],
        'Battery / won\'t start' => [
            "car won't start", 'nothing happens when i turn the key', 'battery died',
            'clicking sound when starting', 'needed a jump start',
            'البطارية فاضية', 'ما يشتغل نهائي', 'يحتاج شحن',
        ],
        'A/C not cooling' => [
            'ac not cold', 'air conditioning blowing warm', 'aircon not working',
            'no cold air', 'ac weak',
            'المكيف ما يبرد', 'التكييف حار', 'المكيف ضعيف',
        ],
        'Bad smell from vents' => [
            'it smells like burning', 'bad smell from the ac', 'smells musty inside',
            'burning smell in the cabin', 'ريحة حريق', 'ريحة كريهة من المكيف',
        ],
        'Steering vibration' => [
            'steering shakes', 'wheel vibrates at highway speed', 'car shakes at high speed',
            'vibration in the steering wheel',
            'الدركسون يرجف', 'اهتزاز في السرعة العالية',
        ],
        'Hard / heavy steering' => [
            'the car feels heavy', 'steering is stiff', 'hard to turn the wheel',
            'steering feels tight', 'الدركسون ثقيل', 'صعب في اللف',
        ],
        'Pulling / drifting' => [
            'car pulls to one side', 'drifts to the right', 'have to hold the wheel straight',
            'الموتر يميل', 'يسحب على جنب',
        ],
        'Knocking over bumps' => [
            'noise over bumps', 'clunking on rough road', 'banging from the suspension',
            'صوت عند المطبات', 'طقطقة في الشاصي',
        ],
        'Hard / jerky shifting' => [
            'gears change roughly', 'jerks when changing gear', 'transmission is harsh',
            'القير يخبط', 'يقفز عند تبديل السرعة',
        ],
        'Gear slipping' => [
            'gears slip', 'revs go up but car does not move', 'loses drive',
            'القير يفلت', 'الدوران يزيد والموتر ما يمشي',
        ],
        'Oil leak' => [
            'oil under the car', 'oil spots on the floor', 'leaking oil',
            'زيت تحت السيارة', 'تسريب زيت',
        ],
        'Coolant leak' => [
            'water under the car', 'green liquid leaking', 'coolant going down',
            'ماء تحت الموتر', 'تسريب ماء الرديتر',
        ],
        'Puncture / slow leak' => [
            'tyre keeps going flat', 'tire losing air', 'flat tyre',
            'البنشر', 'الكفر ينقص هوا',
        ],
        'Check-engine light' => [
            'warning light on the dashboard', 'engine light came on', 'yellow light on dash',
            'لمبة المكينة', 'إشارة صفراء في الطبلون',
        ],
    ];

    public function run(): void
    {
        $concepts = FindingKeyword::query()
            ->get(['id', 'keyword'])
            ->keyBy(fn (FindingKeyword $k) => TextNormalizer::key($k->keyword));

        $written = 0;
        $missing = [];

        foreach (self::VOCABULARY as $keyword => $phrases) {
            $concept = $concepts->get(TextNormalizer::key($keyword));

            if (! $concept) {
                $missing[] = $keyword;

                continue;
            }

            foreach ($phrases as $phrase) {
                $normalized = TextNormalizer::key($phrase);

                if ($normalized === '') {
                    continue;
                }

                // firstOrNew on the unique key, so re-seeding is safe and never duplicates.
                $term = KeywordTerm::firstOrNew([
                    'finding_keyword_id' => $concept->id,
                    'normalized'         => $normalized,
                ]);

                // A phrase an admin has taken ownership of is left exactly as they left it.
                if ($term->exists && $term->source === KeywordTerm::SOURCE_HUMAN) {
                    continue;
                }

                $term->fill([
                    'term'               => $phrase,
                    'lang'               => TextNormalizer::isArabic($phrase) ? 'ar' : 'en',
                    'kind'               => KeywordTerm::KIND_CUSTOMER_PHRASE,
                    'source'             => KeywordTerm::SOURCE_SEED,
                    'source_quality'     => 'workshop',
                    'workshop_frequency' => 'high',
                    'confidence'         => 80,
                    'is_active'          => true,
                ])->save();

                $written++;
            }
        }

        $this->command?->info("Customer vocabulary: {$written} phrase(s) across ".count(self::VOCABULARY).' concept(s).');

        if ($missing !== []) {
            $this->command?->warn('No concept found for: '.implode(', ', $missing));
        }

        KeywordOntologyService::flushCache();
    }
}
