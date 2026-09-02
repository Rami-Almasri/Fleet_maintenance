<?php

namespace App\Support;

/**
 * Why a financial event cannot go to Odoo — as a code the interface can render in any language.
 *
 * Same contract as {@see \App\Services\Garage\Reason}: every reason is a triple of
 *
 *   code    a stable identifier the frontend resolves against its own label table
 *   params  the facts that fill the sentence — names, ids, numbers, never prose
 *   text    the English rendering, frozen for the audit trail
 *
 * `text` is not for display. A blocked event stores the reasons that blocked it so that "why did this
 * sit unsent for three weeks?" stays answerable later; if the UI's label table were reworded, a stored
 * English sentence would silently rewrite history. The screen renders from code + params.
 *
 * "Blocked" here always means ONE thing: sending this to Odoo would put something wrong in the
 * accounting system. It never means the operational work is incomplete — a car is repaired and back on
 * the road whether or not anyone has mapped the brake pads to an Odoo product. §8's separation of
 * operational completion from financial readiness is enforced by that distinction, and nothing in this
 * class is ever consulted by a workflow transition.
 */
final class FinancialBlockReason
{
    /**
     * @param  array<string, mixed>  $params
     * @return array{code:string, params:array<string,mixed>, text:string}
     */
    private static function of(string $code, array $params, string $text): array
    {
        return ['code' => $code, 'params' => $params, 'text' => $text];
    }

    /** Pull the frozen English out of a reason list — for logs, audit rows and assertions. */
    public static function texts(array $reasons): array
    {
        return array_values(array_map(
            static fn ($r) => is_array($r) ? ($r['text'] ?? '') : (string) $r,
            $reasons
        ));
    }

    /** The codes only — what the sync dashboard groups its "4 blocked → 2 missing product mapping" by. */
    public static function codes(array $reasons): array
    {
        return array_values(array_filter(array_map(
            static fn ($r) => is_array($r) ? ($r['code'] ?? null) : null,
            $reasons
        )));
    }

    // ── The integration itself is not usable ──────────────────────────────────────────────────────

    /**
     * No credentials configured. Deliberately its own reason rather than a generic failure: a user
     * looking at a blocked event must be able to tell "nobody has connected Odoo yet" apart from
     * "somebody forgot to map this supplier", because those need different people to fix them.
     */
    public static function odooNotConfigured(): array
    {
        return self::of('odoo_not_configured', [],
            'Odoo is not configured — no connection details have been set for this environment.');
    }

    // ── Mapping gaps ──────────────────────────────────────────────────────────────────────────────

    public static function vehicleAnalyticAccountMissing(?string $plate, ?string $vin): array
    {
        $who = $plate ?: ($vin ?: 'this vehicle');

        return self::of('vehicle_analytic_account_missing', ['plate' => $plate, 'vin' => $vin],
            "Vehicle {$who} is missing an Odoo Analytic Account mapping.");
    }

    public static function vehicleMissing(): array
    {
        return self::of('vehicle_missing', [],
            'This cost is not attached to a vehicle, and its expense type requires one.');
    }

    public static function productNotMapped(string $description, ?int $lineId = null): array
    {
        return self::of('product_not_mapped', ['description' => $description, 'line_id' => $lineId],
            "Product \"{$description}\" is not mapped to an Odoo Product.");
    }

    public static function supplierNotMapped(?string $name): array
    {
        return self::of('supplier_not_mapped', ['name' => $name],
            'Supplier ' . ($name ? "\"{$name}\"" : '') . ' is not mapped to an Odoo Partner.');
    }

    public static function supplierMissing(): array
    {
        return self::of('supplier_missing', [],
            'A vendor bill needs a supplier, and none has been selected.');
    }

    public static function expenseAccountUnresolved(string $expenseType): array
    {
        return self::of('expense_account_unresolved',
            ['expense_type' => $expenseType, 'label' => ExpenseType::label($expenseType)],
            'No Odoo expense account is configured for ' . ExpenseType::label($expenseType) . '.');
    }

    public static function documentTypeUnresolved(string $expenseType): array
    {
        return self::of('document_type_unresolved',
            ['expense_type' => $expenseType, 'label' => ExpenseType::label($expenseType)],
            'No Odoo document type is configured for ' . ExpenseType::label($expenseType) . '.');
    }

    public static function expenseTypeInactive(string $expenseType): array
    {
        return self::of('expense_type_inactive',
            ['expense_type' => $expenseType, 'label' => ExpenseType::label($expenseType)],
            ExpenseType::label($expenseType) . ' is switched off for Odoo synchronisation.');
    }

    // ── Money ─────────────────────────────────────────────────────────────────────────────────────

    public static function amountInvalid(float $amount): array
    {
        return self::of('amount_invalid', ['amount' => $amount],
            'The amount (' . number_format($amount, 2) . ') is not a cost that can be posted.');
    }

    public static function amountMismatch(float $eventTotal, float $lineTotal): array
    {
        return self::of('amount_mismatch', ['event_total' => $eventTotal, 'line_total' => $lineTotal],
            'The event total (' . number_format($eventTotal, 2) . ') does not match the sum of its lines ('
            . number_format($lineTotal, 2) . ').');
    }

    public static function noLines(): array
    {
        return self::of('no_lines', [], 'This event has no cost lines to post.');
    }

    public static function currencyUnsupported(?string $currency): array
    {
        return self::of('currency_unsupported', ['currency' => $currency],
            'Currency ' . ($currency ?: '(none)') . ' is not configured for Odoo.');
    }

    // ── Supplier paperwork (dynamic per document type — see OdooDocumentType::REQUIREMENTS) ────────

    public static function invoiceNumberMissing(): array
    {
        return self::of('invoice_number_missing', [],
            'Vendor invoice information is incomplete — the invoice number is missing.');
    }

    public static function invoiceDateMissing(): array
    {
        return self::of('invoice_date_missing', [],
            'Vendor invoice information is incomplete — the invoice date is missing.');
    }

    public static function attachmentMissing(): array
    {
        return self::of('attachment_missing', [],
            'A receipt or invoice document must be attached before this can be claimed.');
    }

    /**
     * An Odoo expense claim is always somebody's claim — hr.expense will not accept one without an
     * employee. FleetView has no employee↔Odoo mapping (its users are not Odoo HR records), so the
     * claimant is configuration: ODOO_EXPENSE_EMPLOYEE_ID names the fleet-admin employee that
     * company-paid expenses are booked under. Blocking here rather than inventing an id is the same
     * rule the mappings follow — a fabricated employee id posts a real claim to the wrong person.
     */
    public static function expenseEmployeeMissing(): array
    {
        return self::of('expense_employee_missing', [],
            'No Odoo employee is configured for expense claims (ODOO_EXPENSE_EMPLOYEE_ID).');
    }
}
