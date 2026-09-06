<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHY A CAR IS BEING SENT IN — the reason list, as DATA rather than as a PHP constant.
 *
 * It lived in Maintenance::REQUEST_REASONS_INSPECTION / _DISPATCH, which meant two things nobody wanted:
 * the office could not add a reason without a deploy, and "how many cars went in because the garage
 * asked for them back?" could only be answered by matching a string against a hard-coded array. A row
 * per reason makes the list editable by the people who use it and makes the column joinable — filtering
 * a board or a report by reason is now a join, not a lookup table pasted into three files.
 *
 * THE CODE IS STILL THE STORED FACT. `maintenances.request_reason_code` keeps holding the code and only
 * the code (see [[reason-code-contract]]); this table is what the code MEANS, and rewording a label
 * rewrites no history.
 *
 * A REASON IS NEVER DELETED. Retiring one sets `retired_at`: it drops out of the picker immediately and
 * stays readable forever, so a ticket filed under it a year ago still says what it was filed under
 * instead of showing a bare code. That is the whole reason this is a timestamp and not a DELETE.
 *
 * The two doors ask different questions and so keep different lists — the inspection door's reasons all
 * end in a test drive, the dispatch door's are already decisions — which is why `door` is part of the
 * identity and the same code may legitimately exist on both.
 *
 * SEEDED HERE, not in a seeder: the form is unusable with an empty list, so the rows ship with the
 * schema. `other` ("Something else") is seeded ALREADY RETIRED — it was withdrawn as a choice, but
 * tickets were filed under it and those must still read back.
 */
return new class extends Migration
{
    /** The list as it stood when it was a constant. Order is the order the picker shows them in. */
    private const SEED = [
        'inspection' => [
            ['warning_light',       'A warning light is on',                        'مؤشر تحذير مضاء'],
            ['feels_wrong',         "It didn't feel right — I can't say what",       'الإحساس بالسيارة غير طبيعي — لا أستطيع التحديد'],
            ['back_from_rental',    'Just back from a long rental',                  'عادت للتو من إيجار طويل'],
            ['long_idle',           'Sat parked for a long time',                    'متوقفة لفترة طويلة'],
            ['before_handover',     'Going out to a customer — check it first',      'ستُسلّم لعميل — يجب فحصها أولاً'],
            ['recheck_last_repair', 'Check the last repair held',                    'التأكد من ثبات آخر إصلاح'],
        ],
        'dispatch' => [
            ['known_fault',       'A fault we already know — no test needed',        'عطل معروف مسبقاً — لا حاجة للفحص'],
            ['scheduled_service', 'Booked service work',                             'صيانة مجدولة'],
            ['parts_arrived',     'The parts are in — going in to have them fitted', 'وصلت القطع — للتركيب'],
            ['garage_callback',   'The garage asked for the car back',               'الورشة طلبت إعادة السيارة'],
            ['visible_damage',    'Visibly broken — nothing to test-drive',          'عطل ظاهر — لا حاجة لتجربة القيادة'],
        ],
    ];

    public function up(): void
    {
        Schema::create('request_reasons', function (Blueprint $table) {
            $table->id();

            // WHICH DOOR this reason belongs to — 'inspection' (ask for a test) or 'dispatch' (straight
            // to the garage). Part of the identity: the doors are separate lists, not one list filtered.
            $table->string('door', 16);

            // The stable machine key. This — never the label — is what lands in
            // maintenances.request_reason_code and what anything downstream counts.
            $table->string('code', 64);

            $table->string('label');
            $table->string('label_ar')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            // RETIRED, NOT DELETED. Set = gone from the picker, still resolvable for every ticket that
            // was ever filed under it.
            $table->timestamp('retired_at')->nullable();
            $table->unsignedBigInteger('retired_by')->nullable();

            // Who added it, so "where did this reason come from?" has an answer. Null = shipped seeded.
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->unique(['door', 'code'], 'request_reasons_door_code_uq');
            $table->index(['door', 'retired_at', 'sort_order'], 'request_reasons_live_idx');
        });

        $now  = now();
        $rows = [];

        foreach (self::SEED as $door => $reasons) {
            foreach ($reasons as $i => [$code, $label, $labelAr]) {
                $rows[] = [
                    'door'       => $door,
                    'code'       => $code,
                    'label'      => $label,
                    'label_ar'   => $labelAr,
                    'sort_order' => ($i + 1) * 10,
                    'retired_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // WITHDRAWN, AND STILL READABLE. "Something else" was a reason that recorded nothing — it
            // needed a sentence typed beside it to mean anything, which is what the WRITE A NOTE tab is
            // already for. It is seeded retired rather than omitted so the tickets already carrying the
            // code `other` keep reading as "Something else" instead of as a bare code.
            $rows[] = [
                'door'       => $door,
                'code'       => 'other',
                'label'      => 'Something else',
                'label_ar'   => 'سبب آخر',
                'sort_order' => 999,
                'retired_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('request_reasons')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('request_reasons');
    }
};
