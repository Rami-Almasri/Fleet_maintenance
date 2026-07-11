<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App Settings — a tiny key→value store for RUNTIME-adjustable tunables that a user must be able
 * to change from the UI without a redeploy (which the static config/*.php files require).
 *
 * First consumer: the Booking Readiness triggers (look-ahead horizon, alert lead time, pre-rental
 * inspection validity window, and the excluded-holiday list). Values are stored as JSON so a single
 * row can hold a scalar (7) or a list (["2026-08-01", ...]) uniformly; the AppSetting model casts
 * `value` to an array and exposes get()/put() helpers with code-side defaults, so an absent key is
 * never an error — the default lives with the feature, not in a seeded row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();   // dotted namespace, e.g. "booking_readiness.alert_lead_days"
            $table->json('value')->nullable();  // scalar or list, JSON-encoded
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
