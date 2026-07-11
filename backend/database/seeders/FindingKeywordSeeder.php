<?php

namespace Database\Seeders;

use App\Models\FindingKeyword;
use Illuminate\Database\Seeder;

/**
 * Seeds the findings keyword library from config/maintenance_findings.php, assigning each keyword a
 * sensible default RISK grade (by category) and its Arabic translation. Idempotent: it keys on
 * (category_key, keyword) so re-running never duplicates. It NEVER overwrites a `risk` an admin has
 * tuned, and it only FILLS an Arabic label when one is missing — so a hand-edited translation stays.
 * This lets the migration + this seeder backfill Arabic onto the rows the first seeder already made.
 */
class FindingKeywordSeeder extends Seeder
{
    /**
     * Default risk per category. Safety-critical systems (brakes, steering, engine, drivetrain,
     * leaks) default to Critical; comfort / cosmetic systems default to Routine; the rest Moderate.
     */
    private const CATEGORY_DEFAULT_RISK = [
        'engine'       => FindingKeyword::RISK_CRITICAL,
        'brakes'       => FindingKeyword::RISK_CRITICAL,
        'suspension'   => FindingKeyword::RISK_CRITICAL,
        'transmission' => FindingKeyword::RISK_CRITICAL,
        'fluids'       => FindingKeyword::RISK_CRITICAL,
        'tyres'        => FindingKeyword::RISK_MODERATE,
        'electrical'   => FindingKeyword::RISK_MODERATE,
        'lights'       => FindingKeyword::RISK_MODERATE,
        'ac'           => FindingKeyword::RISK_ROUTINE,
        'bodywork'     => FindingKeyword::RISK_ROUTINE,
        'interior'     => FindingKeyword::RISK_ROUTINE,
    ];

    /** Arabic translation per English keyword (the automotive terms the workshop actually uses). */
    private const KEYWORD_AR = [
        // Engine
        'Engine noise'                   => 'ضجيج المحرك',
        'Rough idle / misfire'           => 'تقطيع / عدم انتظام الرالنتي',
        'Loss of power'                  => 'فقدان القوة',
        'Excessive exhaust smoke'        => 'دخان عادم زائد',
        'Overheating'                    => 'ارتفاع حرارة المحرك',
        'Stalling'                       => 'توقف المحرك المفاجئ',
        'Hard starting'                  => 'صعوبة في التشغيل',
        'Check-engine light'             => 'ضوء فحص المحرك',
        // Brakes
        'Brake noise (squeal / grind)'   => 'صوت الفرامل (صرير / احتكاك)',
        'Soft / spongy pedal'            => 'دواسة فرامل لينة / إسفنجية',
        'Vibration when braking'         => 'اهتزاز عند الفرملة',
        'Pulling to one side'            => 'انحراف لجهة واحدة عند الفرملة',
        'Worn pads / discs'              => 'تآكل الفحمات / الأقراص',
        'Handbrake fault'                => 'عطل فرامل اليد',
        'ABS warning light'              => 'ضوء تحذير ABS',
        // Tyres & Wheels
        'Tire Rotation'                   => 'تدوير الإطارات',
        'Tire Change'                     => 'تغيير الإطارات',
        'Worn / bald tyre'               => 'إطار متآكل / أصلع',
        'Puncture / slow leak'           => 'ثقب / تسريب بطيء',
        'Uneven tyre wear'               => 'تآكل غير منتظم للإطار',
        'Wheel alignment'                => 'ضبط زوايا العجلات (ترصيص)',
        'Wheel balancing'                => 'موازنة العجلات (بلنص)',
        'TPMS / tyre-pressure warning'   => 'تحذير ضغط الإطارات',
        // Suspension & Steering
        'Knocking over bumps'            => 'طقطقة عند المطبات',
        'Steering vibration'             => 'اهتزاز المقود',
        'Hard / heavy steering'          => 'ثقل في المقود',
        'Pulling / drifting'             => 'انحراف السيارة',
        'Worn shock / strut'             => 'تآكل المساعدين',
        'Wheel-bearing noise'            => 'صوت رمان بلي العجلة',
        // Transmission & Drivetrain
        'Gear slipping'                  => 'انزلاق الجير',
        'Hard / jerky shifting'          => 'تنقل الجير بقساوة / باهتزاز',
        'Clutch issue'                   => 'مشكلة في الكلتش (القابض)',
        'Whining / grinding noise'       => 'صوت أزيز / احتكاك',
        'Delayed engagement'             => 'تأخر استجابة الجير',
        // Electrical
        'Battery / won\'t start'         => 'البطارية / لا تعمل',
        'Alternator / charging fault'    => 'عطل الدينامو / الشحن',
        'Warning light on dash'          => 'ضوء تحذير على الطبلون',
        'Power window / lock fault'      => 'عطل زجاج / قفل كهربائي',
        'Central locking / key fob'      => 'القفل المركزي / الريموت',
        'Wiring / fuse issue'            => 'مشكلة أسلاك / فيوز',
        // Climate / A/C
        'A/C not cooling'                => 'المكيف لا يبرّد',
        'Heater not working'             => 'المدفأة لا تعمل',
        'Weak airflow'                   => 'ضعف تدفق الهواء',
        'Bad smell from vents'           => 'رائحة كريهة من المكيف',
        'Noisy blower'                   => 'صوت مروحة المكيف',
        // Bodywork & Exterior
        'Dent'                           => 'صدمة / انبعاج',
        'Scratch'                        => 'خدش',
        'Paint damage'                   => 'تلف بالطلاء',
        'Rust / corrosion'               => 'صدأ / تآكل',
        'Broken / loose mirror'          => 'مرآة مكسورة / مفكوكة',
        'Windscreen crack / chip'        => 'شرخ / كسر بالزجاج الأمامي',
        'Door / panel misalignment'      => 'عدم محاذاة الباب / اللوحة',
        // Interior
        'Seat / upholstery damage'       => 'تلف المقاعد / الفرش',
        'Dashboard fault'                => 'عطل بالطبلون',
        'Infotainment / screen issue'    => 'مشكلة الشاشة / نظام الترفيه',
        'Broken trim'                    => 'تلف الزينة الداخلية',
        'Bad odour'                      => 'رائحة كريهة',
        // Fluids & Leaks
        'Oil leak'                       => 'تسريب زيت',
        'Coolant leak'                   => 'تسريب ماء التبريد',
        'Brake-fluid leak'               => 'تسريب زيت الفرامل',
        'Power-steering leak'            => 'تسريب زيت الدركسون',
        'Fuel smell / leak'              => 'رائحة / تسريب وقود',
        'Low fluid level'                => 'انخفاض مستوى السوائل',
        // Lights & Visibility
        'Headlight out'                  => 'ضوء أمامي معطل',
        'Tail / brake light out'         => 'ضوء خلفي / فرامل معطل',
        'Indicator fault'                => 'عطل الإشارة (الغماز)',
        'Wiper / washer fault'           => 'عطل المساحات / الرشاش',
        'Foggy / dim lights'             => 'أضواء باهتة / معتمة',
    ];

    public function run(): void
    {
        $categories = config('maintenance_findings.categories', []);

        foreach ($categories as $category) {
            $key = $category['key'] ?? null;
            if (! $key) {
                continue;
            }

            $label       = $category['label'] ?? $key;
            $labelAr     = $category['label_ar'] ?? null;
            $defaultRisk = self::CATEGORY_DEFAULT_RISK[$key] ?? FindingKeyword::RISK_MODERATE;

            foreach (array_values($category['keywords'] ?? []) as $i => $keyword) {
                // firstOrNew so we can backfill Arabic onto rows the first (English-only) seed created,
                // without clobbering an admin-tuned risk / description / is_active.
                $row = FindingKeyword::firstOrNew(['category_key' => $key, 'keyword' => $keyword]);

                if (! $row->exists) {
                    $row->risk       = $defaultRisk;
                    $row->is_active  = true;
                    $row->sort_order = $i;
                }

                // Labels are safe to keep in sync with the config (single source for the English text).
                $row->category_label    = $label;
                $row->category_label_ar = $labelAr;
                // Only FILL the Arabic keyword when it's missing — never overwrite a hand-edited one.
                if (blank($row->keyword_ar)) {
                    $row->keyword_ar = self::KEYWORD_AR[$keyword] ?? null;
                }

                $row->save();
            }
        }
    }
}
