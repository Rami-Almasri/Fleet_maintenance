<?php

namespace App\Contracts;

/**
 * An operational record that can generate a financial obligation.
 *
 * This interface is how §31 is ENFORCED rather than merely intended. A financial event never asks a user
 * to re-key information the operation already holds; instead the operational model is asked for it. A
 * garage invoice already knows its invoice number, its date, its supplier and where its receipt photo
 * lives — so {@see \App\Models\MaintenanceInvoice} answers these, and the corresponding columns on
 * financial_events stay null.
 *
 * A source that genuinely has no answer returns null, and only THEN does the financial layer collect it
 * (and store it as an override). "Only ask for information that genuinely does not exist yet" is the
 * difference between a null answer and a missing method.
 *
 * Implementing this interface is also what makes a model ELIGIBLE to raise an event at all — see
 * {@see \App\Services\Odoo\FinancialEventBuilder}. There is no path that builds an event from a model
 * that has not declared what kind of cost it is and what it is worth.
 */
interface FinancialEventSource
{
    /**
     * Which canonical expense type this operation's cost is — see {@see \App\Support\ExpenseType}.
     * Null means this particular record generates no financial obligation at all (the event, if one
     * exists, becomes NOT_REQUIRED rather than being deleted, so the absence stays explainable).
     */
    public function financialExpenseType(): ?string;

    /** What settling this operation costs, in {@see financialCurrency()}. */
    public function financialAmount(): float;

    public function financialCurrency(): string;

    /** The car this cost was spent on, or null for a cost that belongs to no single vehicle. */
    public function financialVehicleId(): ?int;

    /** The maintenance ticket this cost sits under, when there is one. */
    public function financialMaintenanceId(): ?int;

    /** The supplier who billed us, when there is one. */
    public function financialVendorId(): ?int;

    /** The supplier's own document number, or null when this source does not carry one. */
    public function financialInvoiceNumber(): ?string;

    /** The date on the supplier's document (Y-m-d), or null when this source does not carry one. */
    public function financialInvoiceDate(): ?string;

    /**
     * Where the receipt/invoice scan lives in the app's EXISTING storage (§29 — no second file system).
     *
     * @return array{disk:?string, key:?string}|null  null when this source has no attachment of its own
     */
    public function financialAttachment(): ?array;

    /** A short human description of what was bought — becomes the posted document's label. */
    public function financialDescription(): string;

    /**
     * The postable lines, already shaped for {@see \App\Models\FinancialEventLine}.
     *
     * Returning [] is legitimate: it means this source's cost is a single service charge, and the
     * builder writes one service line from {@see financialAmount()} and {@see financialDescription()}.
     *
     * @return list<array{origin_type:?string, origin_id:?int, component_catalog_id:?int, kind:string,
     *                    description:string, quantity:float, uom:?string, unit_price:float}>
     */
    public function financialLines(): array;
}
