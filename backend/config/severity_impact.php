<?php

/**
 * Severity Review — explainability copy for the Diagnostic Review / QC page.
 *
 * The Severity Review surface (WorkflowOversightController::severityReview) flags tickets whose
 * inspector fault-severity grade looks too LOW for the signals (a critical-risk keyword, a breakdown,
 * a red-graded car). To make each recommendation reviewable rather than a bare "should be Critical",
 * every card carries an EXPLAINABILITY block: what the risk actually is, what happens if it's ignored,
 * and what to do about it.
 *
 * This file is the deterministic source of that copy. It is NOT machine-learned and never changes from
 * operator decisions — it simply reads the fault's findings CATEGORY (engine / brakes / …, keyed by the
 * finding_keywords.category_key that matched) and the RECOMMENDED risk tier, and returns fixed, curated
 * text. Editing a line here is a one-line change, no migration.
 *
 * Shape:
 *   'categories'[category_key] => ['risk_category' => {en,ar}, 'impact' => {en,ar}]
 *   'actions'[risk_tier]       => {en,ar}   — the recommended action, keyed by the recommended severity
 *   '_default'                 => the fallback used when a category / tier is unknown
 *
 * `category_key` mirrors config/maintenance_findings.php ('engine', 'brakes', 'tyres', …). A keyword
 * that isn't in the library (breakdown / red-grade signals with no keyword) falls back to '_default'.
 */

return [

    // ── Per-category risk framing + impact ──────────────────────────────────────────────────────
    'categories' => [
        'engine' => [
            'risk_category' => ['en' => 'Engine failure',        'ar' => 'عطل في المحرك'],
            'impact'        => ['en' => 'Possible breakdown or loss of power on the road', 'ar' => 'احتمال تعطل السيارة أو فقدان القوة أثناء القيادة'],
        ],
        'brakes' => [
            'risk_category' => ['en' => 'Braking safety',        'ar' => 'سلامة الفرامل'],
            'impact'        => ['en' => 'Reduced stopping ability — direct accident risk', 'ar' => 'ضعف القدرة على التوقف — خطر حوادث مباشر'],
        ],
        'tyres' => [
            'risk_category' => ['en' => 'Tyre / wheel failure',  'ar' => 'عطل في الإطارات / العجلات'],
            'impact'        => ['en' => 'Blowout or loss of control at speed',  'ar' => 'انفجار الإطار أو فقدان السيطرة على السرعة'],
        ],
        'suspension' => [
            'risk_category' => ['en' => 'Steering / suspension', 'ar' => 'التوجيه والتعليق'],
            'impact'        => ['en' => 'Unstable handling and uneven tyre wear', 'ar' => 'عدم ثبات القيادة وتآكل غير متساوٍ للإطارات'],
        ],
        'transmission' => [
            'risk_category' => ['en' => 'Drivetrain failure',    'ar' => 'عطل في ناقل الحركة'],
            'impact'        => ['en' => 'Car may become undriveable without warning', 'ar' => 'قد تتعطل السيارة عن الحركة دون إنذار'],
        ],
        'electrical' => [
            'risk_category' => ['en' => 'Electrical fault',      'ar' => 'عطل كهربائي'],
            'impact'        => ['en' => 'Failure to start or intermittent shutdowns', 'ar' => 'تعذّر التشغيل أو توقف متقطع'],
        ],
        'fluids' => [
            'risk_category' => ['en' => 'Fluid / coolant loss',  'ar' => 'تسرب السوائل / سائل التبريد'],
            'impact'        => ['en' => 'Overheating and consequential engine damage', 'ar' => 'ارتفاع الحرارة وتلف المحرك تبعاً لذلك'],
        ],
        'ac' => [
            'risk_category' => ['en' => 'Climate system',        'ar' => 'نظام التكييف'],
            'impact'        => ['en' => 'Comfort issue — low safety risk',   'ar' => 'مشكلة راحة — خطورة أمان منخفضة'],
        ],
        'lights' => [
            'risk_category' => ['en' => 'Visibility / lighting', 'ar' => 'الرؤية والإضاءة'],
            'impact'        => ['en' => 'Poor visibility and a traffic-fine exposure', 'ar' => 'ضعف الرؤية والتعرض لمخالفات مرورية'],
        ],
        'bodywork' => [
            'risk_category' => ['en' => 'Bodywork / exterior',   'ar' => 'الهيكل الخارجي'],
            'impact'        => ['en' => 'Cosmetic — minimal operational risk', 'ar' => 'شكلي — خطورة تشغيلية ضئيلة'],
        ],
        'interior' => [
            'risk_category' => ['en' => 'Interior condition',    'ar' => 'حالة المقصورة الداخلية'],
            'impact'        => ['en' => 'Cosmetic — minimal operational risk', 'ar' => 'شكلي — خطورة تشغيلية ضئيلة'],
        ],
        'routine' => [
            'risk_category' => ['en' => 'Routine servicing',     'ar' => 'صيانة دورية'],
            'impact'        => ['en' => 'Deferred wear — escalates if left unserviced', 'ar' => 'تآكل مؤجل — يتفاقم إذا تُرك دون صيانة'],
        ],
    ],

    // ── Recommended action, by the recommended severity tier ────────────────────────────────────
    'actions' => [
        'critical' => ['en' => 'Immediate inspection required before any rental', 'ar' => 'يلزم فحص فوري قبل أي تأجير'],
        'high'     => ['en' => 'Inspect before the next dispatch',  'ar' => 'يُفحص قبل الإرسال التالي'],
        'moderate' => ['en' => 'Schedule a workshop review',        'ar' => 'جدولة مراجعة في الورشة'],
        'routine'  => ['en' => 'Monitor at the next scheduled service', 'ar' => 'المتابعة في الصيانة الدورية القادمة'],
    ],

    // ── Fallback when the signal carries no library category (breakdown / red-grade) ─────────────
    '_default' => [
        'risk_category' => ['en' => 'Vehicle safety',   'ar' => 'سلامة المركبة'],
        'impact'        => ['en' => 'Potential safety or availability impact if left ungraded', 'ar' => 'أثر محتمل على السلامة أو الجاهزية إذا تُرك دون تصنيف'],
    ],
];
