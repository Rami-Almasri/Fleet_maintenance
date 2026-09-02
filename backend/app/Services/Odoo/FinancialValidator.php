<?php

namespace App\Services\Odoo;

use App\Models\ExpenseTypeMapping;
use App\Models\FinancialEvent;
use App\Models\FinancialEventLine;
use App\Support\ExpenseType;
use App\Support\FinancialBlockReason as Reason;
use App\Support\OdooDocumentType;

/**
 * Everything that must be true before an event may be sent to Odoo (§27).
 *
 * The validator is a PURE FUNCTION of the event and the mappings: it reads, it never writes, and it
 * never calls Odoo. That is what lets it run on every page load to render the financial panel, on every
 * save of an invoice, and again immediately before a push, always giving the same answer for the same
 * data. {@see FinancialEventService} is what turns its answer into a stored status.
 *
 * ── WHAT "BLOCKED" MEANS, AND WHAT IT DOES NOT ────────────────────────────────────────────────────
 *
 * A reason returned here means: sending this would put something WRONG into the accounting system.
 * It never means the operational work is unfinished. §8 requires those two to stay separate, and the
 * separation is structural rather than a matter of discipline — nothing in this class is reachable from
 * a workflow transition, so a car can be repaired, closed and returned to service with a BLOCKED event
 * sitting beside it. The repair is done. The bookkeeping is not. Both statements are true and the system
 * says both.
 *
 * ── EVERY REASON IS COLLECTED, NOT JUST THE FIRST ─────────────────────────────────────────────────
 *
 * Returning on the first failure would make fixing a blocked event a guessing game with one answer
 * revealed per attempt: map the supplier, re-validate, discover the vehicle is unmapped, and so on. All
 * reasons are gathered so the panel can list the complete work.
 */
class FinancialValidator
{
    public function __construct(
        private OdooMappingService $mappings,
        private OdooClient $client,
    ) {
    }

    /**
     * @return list<array{code:string, params:array<string,mixed>, text:string}>  empty = ready
     */
    public function validate(FinancialEvent $event): array
    {
        $reasons = [];

        // The integration itself. Reported first and on its own terms, so "nobody has connected Odoo"
        // never masquerades as a mapping problem somebody is expected to fix on the mappings screen.
        if (! $this->client->isConfigured()) {
            $reasons[] = Reason::odooNotConfigured();
        }

        $mapping = $this->mappings->expenseTypeMapping((string) $event->expense_type);

        $reasons = array_merge(
            $reasons,
            $this->checkExpenseType($event, $mapping),
            $this->checkVehicle($event),
            $this->checkMoney($event),
            $this->checkLines($event),
            $this->checkSupplierAndPaperwork($event, $mapping),
        );

        return array_values($reasons);
    }

    /** Convenience for callers that only need the yes/no. */
    public function isReady(FinancialEvent $event): bool
    {
        return $this->validate($event) === [];
    }

    // ── The individual checks ─────────────────────────────────────────────────────────────────────

    private function checkExpenseType(FinancialEvent $event, ?ExpenseTypeMapping $mapping): array
    {
        $type = (string) $event->expense_type;

        if (! ExpenseType::isValid($type)) {
            return [Reason::expenseAccountUnresolved($type)];
        }

        if (! $mapping) {
            // No row at all: the category has never been configured. Reported as an unresolved account
            // AND an unresolved document type, because both are genuinely missing and a partial answer
            // would send somebody back for a second round.
            return [Reason::expenseAccountUnresolved($type), Reason::documentTypeUnresolved($type)];
        }

        $reasons = [];

        if (! $mapping->active) {
            $reasons[] = Reason::expenseTypeInactive($type);
        }
        if (! $mapping->hasAccount()) {
            $reasons[] = Reason::expenseAccountUnresolved($type);
        }
        if (! $mapping->hasDocumentType()) {
            $reasons[] = Reason::documentTypeUnresolved($type);
        }

        return $reasons;
    }

    /**
     * The vehicle and its analytic account (§16).
     *
     * TAXI is exempt from needing a vehicle at all — a driver's fare is a fleet-admin cost that often
     * belongs to no single car, and demanding one would block a legitimate expense permanently. Every
     * other type is vehicle-bound (see ExpenseType::VEHICLE_BOUND).
     *
     * Note what is NOT checked here: the vehicle's operational status. §17 is explicit that "Lent" is
     * not a financial rule — a lent car still incurs repair cost that belongs in Odoo, and refusing to
     * post it because of where the car currently is would be an accounting error dressed up as a policy.
     * Eligibility narrows the mapping BACKLOG (config('odoo.vehicle_eligibility')), never the posting.
     */
    private function checkVehicle(FinancialEvent $event): array
    {
        if (! ExpenseType::requiresVehicle((string) $event->expense_type)) {
            return [];
        }

        $vehicle = $event->vehicle;

        if (! $vehicle) {
            return [Reason::vehicleMissing()];
        }

        if ($this->mappings->analyticAccountIdFor($vehicle) === null) {
            return [Reason::vehicleAnalyticAccountMissing($vehicle->plate_no, $vehicle->vin)];
        }

        return [];
    }

    private function checkMoney(FinancialEvent $event): array
    {
        $reasons  = [];
        $amount   = round((float) $event->amount, 2);
        $currency = trim((string) $event->currency);

        // A cost of zero or less is not a bill. An event whose source genuinely produced no cost should
        // be NOT_REQUIRED rather than blocked — the builder decides that; reaching here with zero means
        // something is wrong with the figures, which is worth saying out loud.
        if ($amount <= 0) {
            $reasons[] = Reason::amountInvalid($amount);
        }

        $configured = strtoupper(trim((string) config('odoo.currency', 'AED')));
        if ($currency === '' || strtoupper($currency) !== $configured) {
            $reasons[] = Reason::currencyUnsupported($currency ?: null);
        }

        return $reasons;
    }

    /**
     * The lines, and the product mapping every PART line needs (§18).
     *
     * Labour and service lines are exempt: Odoo records a charge that is not a catalogue item against
     * the expense account directly, and demanding a product for every hour of garage time would force
     * somebody to fabricate product records — the precise outcome §26 forbids.
     */
    private function checkLines(FinancialEvent $event): array
    {
        $lines = $event->relationLoaded('lines') ? $event->lines : $event->lines()->with('catalogPart')->get();

        if ($lines->isEmpty()) {
            return [Reason::noLines()];
        }

        $reasons = [];

        foreach ($lines as $line) {
            if (! $line->requiresProductMapping()) {
                continue;
            }

            $part = $line->catalogPart;

            // A part line with no catalogue entry cannot be mapped at all — the same block, named by the
            // description that WAS billed so the person fixing it can find the line on the invoice.
            if (! $part || $this->mappings->productIdFor($part) === null) {
                $reasons[] = Reason::productNotMapped((string) $line->description, $line->id);
            }
        }

        // The posted total must equal what the lines say. A mismatch means the event and its source have
        // drifted, and posting either figure would put a number in the ledger that nothing supports.
        $lineTotal = round((float) $lines->sum(fn (FinancialEventLine $l) => (float) $l->line_total), 2);
        $amount    = round((float) $event->amount, 2);

        if (abs($lineTotal - $amount) > 0.01) {
            $reasons[] = Reason::amountMismatch($amount, $lineTotal);
        }

        return $reasons;
    }

    /**
     * The supplier and the paperwork — DYNAMIC per document type (§28).
     *
     * There is no fixed field list here on purpose. What a document needs comes from
     * {@see OdooDocumentType::REQUIREMENTS}, so a vendor bill demands a partner, a number and a date
     * while an expense demands a receipt and a date and no partner at all. Changing what a category
     * needs is changing that map — or, for a whole expense type, changing its document type on the
     * mappings screen — and never editing this method.
     */
    private function checkSupplierAndPaperwork(FinancialEvent $event, ?ExpenseTypeMapping $mapping): array
    {
        $documentType = $mapping?->odoo_document_type ?: $event->odoo_document_type;

        if (! OdooDocumentType::isValid($documentType)) {
            // Already reported by checkExpenseType — nothing further can be judged without knowing what
            // kind of document this is, and repeating the same block would just be noise.
            return [];
        }

        // The document type's defaults, with the expense type's own attachment override applied. Read
        // from the MAPPING rather than from the document type directly, so Finance's decision about
        // this category is what actually governs — see ExpenseTypeMapping::requirements().
        $needs   = $mapping ? $mapping->requirements() : OdooDocumentType::requirements($documentType);
        $reasons = [];

        if ($needs['supplier']) {
            $vendor = $event->vendor;

            if (! $vendor) {
                $reasons[] = Reason::supplierMissing();
            } elseif ($this->mappings->partnerIdFor($vendor) === null) {
                $reasons[] = Reason::supplierNotMapped($vendor->name);
            }
        }

        // These read through the event's resolvers, which prefer the OPERATIONAL record — so a garage
        // invoice that already carries its number satisfies this without anybody re-typing it (§31).
        if ($needs['invoice_number'] && $event->resolvedInvoiceNumber() === null) {
            $reasons[] = Reason::invoiceNumberMissing();
        }

        if ($needs['invoice_date'] && $event->resolvedInvoiceDate() === null) {
            $reasons[] = Reason::invoiceDateMissing();
        }

        if ($needs['attachment'] && $event->resolvedAttachment() === null) {
            $reasons[] = Reason::attachmentMissing();
        }

        // An hr.expense cannot exist without an employee to claim it. See the reason's docblock for why
        // this is configuration rather than a mapping.
        if ($documentType === OdooDocumentType::EXPENSE && ! config('odoo.expense_employee_id')) {
            $reasons[] = Reason::expenseEmployeeMissing();
        }

        return $reasons;
    }
}
