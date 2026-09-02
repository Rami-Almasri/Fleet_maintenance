<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EVERY TIME WE TALKED TO ODOO ABOUT AN EVENT, AND WHAT CAME BACK.
 *
 * §38 asks for structured observability on the integration; §33 asks that every important financial
 * action be traceable. This is both, and it is a table rather than log lines because the questions asked
 * of it are questions about rows: how many times has this event been retried, what did Odoo say the
 * first time, was the document that eventually appeared CREATED by us or FOUND by the idempotency
 * search.
 *
 * That last one is why `outcome` distinguishes `created` from `linked`. A retry after a lost response
 * lands on `linked` — the bill already existed and we adopted it. If the two were recorded as the same
 * outcome, the single most important guarantee in this integration would be invisible in its own audit
 * trail: nobody could prove after the fact that a duplicate had been AVOIDED rather than never
 * attempted. A `linked` row is that proof.
 *
 * NOTHING SECRET IS EVER WRITTEN HERE. The request payload is stored for diagnosis, but it is the
 * document body only — credentials never appear in it, because OdooClient keeps authentication out of
 * the call payload entirely and {@see \App\Services\Odoo\OdooClient::redact()} is applied on the way in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_event_attempts', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('financial_event_id');
            $table->unsignedInteger('attempt');

            // created | linked | failed — see the docblock on why linked is its own outcome.
            $table->string('outcome', 24);

            // The Odoo model + id when the attempt ended with a document (created or linked).
            $table->string('odoo_model', 64)->nullable();
            $table->unsignedBigInteger('odoo_document_id')->nullable();

            // Stable machine code for grouping failures on the dashboard: odoo_validation,
            // odoo_authentication, odoo_timeout, odoo_network, odoo_not_configured, unexpected.
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();

            // The document body we sent — diagnosis only, never credentials.
            $table->json('request_payload')->nullable();

            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index('financial_event_id', 'fea_event_idx');
            $table->index(['outcome', 'error_code'], 'fea_outcome_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_event_attempts');
    }
};
