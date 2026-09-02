<?php

namespace App\Support;

/**
 * HOW a cost is recorded in Odoo — the document, not the account.
 *
 * The distinction this class exists to protect: an expense ACCOUNT is an accounting classification
 * ("Fleets Maintenance | Repair Maintenance Expenses"), while a DOCUMENT is the paper through which the
 * cost enters the books. They vary independently. A repair charged to the repair account is normally a
 * vendor bill because a garage invoiced us; a taxi fare charged to the transport account is normally an
 * expense because an employee paid and claimed it back. Neither pairing is a law — Finance can change
 * either side on {@see \App\Models\ExpenseTypeMapping} without a deploy.
 *
 * Each document type names the Odoo model it creates and what that model demands of us, which is what
 * makes the invoice-requirement layer dynamic rather than one hard-coded field list:
 *
 *   VENDOR_BILL  account.move (move_type=in_invoice) — a third party billed us, so there IS a supplier
 *                and there IS a supplier document. Partner + invoice number + invoice date required.
 *   EXPENSE      hr.expense — somebody in the company spent money and is claiming it. No vendor partner
 *                is required; a receipt reference is what evidences it.
 */
final class OdooDocumentType
{
    public const VENDOR_BILL = 'VENDOR_BILL';
    public const EXPENSE     = 'EXPENSE';

    public const ALL = [self::VENDOR_BILL, self::EXPENSE];

    public const LABELS = [
        self::VENDOR_BILL => 'Vendor Bill',
        self::EXPENSE     => 'Expense',
    ];

    /** The Odoo model each document type is created in. */
    public const ODOO_MODELS = [
        self::VENDOR_BILL => 'account.move',
        self::EXPENSE     => 'hr.expense',
    ];

    /**
     * What each document type REQUIRES before it can be sent. This is the whole of the dynamic invoice
     * requirement — §28's "do not force the same fields onto every expense" is this map, and nothing else.
     *
     * supplier        a mapped Odoo partner is required (someone billed us)
     * invoice_number  the supplier's own document number
     * invoice_date    the date on that document
     * attachment      the scan/photo must be present, not merely allowed
     */
    public const REQUIREMENTS = [
        self::VENDOR_BILL => [
            'supplier'       => true,
            'invoice_number' => true,
            'invoice_date'   => true,
            // The supporting document is REQUIRED before a bill may be sent. A number and a date can be
            // typed from memory; the scan is the only thing that proves the bill exists and says what we
            // claim it says, and it is what an auditor asks for. Blocking here does not delay the
            // repair — operational completion and financial readiness are separate (§8) — it delays
            // only the posting, which is exactly the thing that should wait for evidence.
            //
            // Overridable per expense type via expense_type_mappings.requires_attachment, because a
            // AED 20 fare and a AED 40,000 engine rebuild do not deserve the same insistence.
            'attachment'     => true,
        ],
        self::EXPENSE => [
            'supplier'       => false,
            'invoice_number' => false,
            'invoice_date'   => true,
            // An expense claim with no receipt is exactly the thing an auditor asks about, and unlike a
            // vendor bill there is no supplier-side copy to fall back on. So here the scan IS the evidence.
            'attachment'     => true,
        ],
    ];

    public static function label(?string $type): string
    {
        return self::LABELS[$type] ?? 'Unknown';
    }

    public static function isValid(?string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    public static function odooModel(?string $type): ?string
    {
        return self::ODOO_MODELS[$type] ?? null;
    }

    /** @return array{supplier:bool,invoice_number:bool,invoice_date:bool,attachment:bool} */
    public static function requirements(?string $type): array
    {
        return self::REQUIREMENTS[$type] ?? self::REQUIREMENTS[self::VENDOR_BILL];
    }

    public static function requires(?string $type, string $field): bool
    {
        return (bool) (self::requirements($type)[$field] ?? false);
    }
}
