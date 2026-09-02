<?php

namespace App\Services\Odoo;

use App\Exceptions\OdooException;
use App\Models\FinancialEvent;
use App\Models\FinancialEventLine;
use App\Support\OdooDocumentType;
use Illuminate\Support\Facades\Storage;

/**
 * Creates the actual document in Odoo — and, above all, refuses to create a second one.
 *
 * ── THE IDEMPOTENCY GUARANTEE (§23) ────────────────────────────────────────────────────────────────
 *
 * Every document we create carries the event's {@see FinancialEvent::odooRef()} in Odoo's own `ref`
 * field. {@see push()} ALWAYS searches for that ref before it creates anything — on the first attempt as
 * well as on every retry. The sequence §23 describes therefore resolves like this:
 *
 *     attempt 1   search → nothing   → create → Odoo makes Vendor Bill #18452 → response LOST
 *     attempt 2   search → #18452    → LINK it. No create. No #18453.
 *
 * The search is not an optimisation and must never be made conditional on the previous outcome, because
 * the state we are recovering from is precisely the one where we do not know what the previous outcome
 * was. A first attempt that searches costs one cheap RPC; a first attempt that skips the search is a
 * duplicate bill the day a timeout happens on a create.
 *
 * The return value says which of the two happened, and callers record it, so that "a duplicate was
 * avoided" is provable after the fact rather than merely assumed.
 *
 * ── IT ASSUMES VALIDATION HAS PASSED ───────────────────────────────────────────────────────────────
 *
 * The pusher does not re-run business validation; {@see FinancialEventSyncService} does that immediately
 * before calling in, inside the same guarded transition. What the pusher DOES re-resolve is the mapping
 * ids, because those are what it is about to write into a ledger — and it throws rather than posting a
 * document with a hole in it if any has disappeared between validation and here.
 */
class OdooDocumentPusher
{
    public function __construct(
        private OdooClient $client,
        private OdooMappingService $mappings,
    ) {
    }

    /**
     * Create or adopt the Odoo document for this event.
     *
     * @return array{outcome:string, odoo_model:string, odoo_id:int, reference:?string, payload:array}
     *         outcome is 'created' or 'linked'
     */
    public function push(FinancialEvent $event): array
    {
        $documentType = (string) $event->odoo_document_type;
        $model        = OdooDocumentType::odooModel($documentType);

        if (! $model) {
            throw OdooException::validation(
                "No Odoo document type is resolved for event #{$event->id}.",
                ['financial_event_id' => $event->id]
            );
        }

        // ── The idempotency search, ALWAYS first ───────────────────────────────────────────────────
        $existing = $this->findExisting($model, $event->odooRef());

        if ($existing !== null) {
            return [
                'outcome'    => 'linked',
                'odoo_model' => $model,
                'odoo_id'    => $existing['id'],
                'reference'  => $existing['reference'],
                'payload'    => [],
            ];
        }

        $payload = $documentType === OdooDocumentType::EXPENSE
            ? $this->expensePayload($event)
            : $this->vendorBillPayload($event);

        $odooId = $this->client->create($model, $payload);

        // The document exists from this point on. Anything that fails BELOW here must not be allowed to
        // look like a failed push — the caller records the id first and treats attachment problems as
        // non-fatal, because a bill that exists with no scan attached is a far better outcome than a
        // retry that hunts for a document we never recorded.
        $this->attachDocument($event, $model, $odooId);

        return [
            'outcome'    => 'created',
            'odoo_model' => $model,
            'odoo_id'    => $odooId,
            'reference'  => $this->readReference($model, $odooId),
            'payload'    => $this->client->redact($payload),
        ];
    }

    /**
     * Ask Odoo whether a document already carries our ref.
     *
     * @return array{id:int, reference:?string}|null
     */
    private function findExisting(string $model, string $ref): ?array
    {
        // hr.expense has no `ref`; its equivalent free reference field is `reference`. Searching the
        // wrong field would silently return nothing, which would look exactly like "no duplicate
        // exists" — the one wrong answer this whole mechanism cannot afford to give.
        $field = $this->refField($model);

        $ids = $this->client->search($model, [[$field, '=', $ref]], 2);

        if ($ids === []) {
            return null;
        }

        if (count($ids) > 1) {
            // Two documents already carry our ref. That is a duplicate we did not create and cannot
            // safely choose between, so we refuse rather than adopting one at random.
            throw OdooException::validation(
                "More than one {$model} in Odoo carries the reference {$ref}.",
                ['odoo_model' => $model, 'ref' => $ref, 'ids' => $ids]
            );
        }

        return ['id' => (int) $ids[0], 'reference' => $this->readReference($model, (int) $ids[0])];
    }

    /** The free-text reference field each document model exposes. */
    private function refField(string $model): string
    {
        return $model === 'hr.expense' ? 'reference' : 'ref';
    }

    /** Odoo's own human name for the document, once it has one. */
    private function readReference(string $model, int $id): ?string
    {
        $rows = $this->client->read($model, [$id], ['name']);

        $name = $rows[0]['name'] ?? null;

        // A freshly-created draft bill has name '/' until Odoo assigns a sequence on posting.
        return is_string($name) && $name !== '' && $name !== '/' ? $name : null;
    }

    // ── Payloads ──────────────────────────────────────────────────────────────────────────────────

    /**
     * An account.move of type in_invoice — a supplier bill.
     *
     * Created as a DRAFT and deliberately not posted. Posting is an accounting decision that belongs to
     * whoever owns the books, and an integration that posts on their behalf takes that decision away —
     * along with the chance to correct a bill before it hits the ledger. FleetView's job ends at "the
     * bill is in Odoo, complete and correctly coded".
     *
     * Tax is NOT sent. The lines carry net amounts and Odoo applies the tax rules configured on the
     * account/partner; sending our own VAT line as well would tax the bill twice (see
     * MaintenanceInvoice::financialLines()).
     */
    private function vendorBillPayload(FinancialEvent $event): array
    {
        $partnerId = $this->mappings->partnerIdFor($event->vendor);

        if ($partnerId === null) {
            throw OdooException::validation(
                'The supplier lost its Odoo partner mapping before the bill could be created.',
                ['financial_event_id' => $event->id]
            );
        }

        $payload = [
            'move_type'    => 'in_invoice',
            'partner_id'   => $partnerId,
            // OUR external identity. This is the field findExisting() searches on every attempt.
            'ref'          => $event->odooRef(),
            'invoice_date' => $event->resolvedInvoiceDate(),
            'narration'    => $event->description,
            'invoice_line_ids' => array_map(
                fn (FinancialEventLine $line) => [0, 0, $this->billLine($event, $line)],
                $event->lines->all()
            ),
        ];

        // The supplier's own document number, where the source carried one. Odoo shows it as the bill
        // reference on the vendor's side, which is what makes our bill findable from their paperwork.
        if ($number = $event->resolvedInvoiceNumber()) {
            $payload['payment_reference'] = $number;
        }

        if ($journal = $this->journalIdFor($event)) {
            $payload['journal_id'] = $journal;
        }

        if ($company = config('odoo.company_id')) {
            $payload['company_id'] = (int) $company;
        }

        return $payload;
    }

    /** One invoice line: what was bought, how much, which account, and which car it belongs to. */
    private function billLine(FinancialEvent $event, FinancialEventLine $line): array
    {
        $values = [
            'name'       => $line->description,
            'quantity'   => (float) $line->quantity,
            'price_unit' => (float) $line->unit_price,
            'account_id' => $this->accountIdFor($event),
        ];

        // A part posts as its mapped product; labour and services post against the expense account
        // alone, which is how Odoo itself records a charge with no catalogue item behind it.
        if ($line->requiresProductMapping()) {
            $productId = $this->mappings->productIdFor($line->catalogPart);

            if ($productId === null) {
                throw OdooException::validation(
                    "Part \"{$line->description}\" lost its Odoo product mapping before the bill could be created.",
                    ['financial_event_id' => $event->id, 'line_id' => $line->id]
                );
            }

            $values['product_id'] = $productId;
        }

        // The per-asset analytic account — this is what makes the cost roll up against the CAR in Odoo
        // (§16). Odoo 17 takes a distribution map (account id → percentage); 100 means the whole line
        // belongs to this vehicle, which is always true here because an event has exactly one vehicle.
        if ($analyticId = $event->odoo_analytic_account_id) {
            $values['analytic_distribution'] = [(string) $analyticId => 100];
        }

        return $values;
    }

    /**
     * An hr.expense — a claim rather than a bill.
     *
     * The employee comes from configuration, not from a mapping; see FinancialBlockReason::
     * expenseEmployeeMissing() for why. The validator has already refused the event if none is set,
     * so reaching here without one is a genuine fault and throws.
     */
    private function expensePayload(FinancialEvent $event): array
    {
        $employeeId = config('odoo.expense_employee_id');

        if (! $employeeId) {
            throw OdooException::validation(
                'No Odoo employee is configured for expense claims.',
                ['financial_event_id' => $event->id]
            );
        }

        $payload = [
            'name'          => $event->description ?: $event->expenseTypeLabel(),
            'employee_id'   => (int) $employeeId,
            'total_amount'  => round((float) $event->amount, 2),
            'quantity'      => 1,
            'account_id'    => $this->accountIdFor($event),
            'date'          => $event->resolvedInvoiceDate(),
            // hr.expense's free reference field — the one findExisting() searches for this model.
            'reference'     => $event->odooRef(),
        ];

        if ($analyticId = $event->odoo_analytic_account_id) {
            $payload['analytic_distribution'] = [(string) $analyticId => 100];
        }

        if ($company = config('odoo.company_id')) {
            $payload['company_id'] = (int) $company;
        }

        return $payload;
    }

    /**
     * The expense account this event posts to.
     *
     * Read from the SNAPSHOT on the event, which the sync service froze from the mapping a moment ago.
     * Reading the live mapping here instead would open a window where a mapping edited mid-push sends a
     * document coded differently from the one we validated.
     */
    private function accountIdFor(FinancialEvent $event): int
    {
        if (! $event->odoo_account_id) {
            throw OdooException::validation(
                'The expense account was not resolved before the document was built.',
                ['financial_event_id' => $event->id]
            );
        }

        return (int) $event->odoo_account_id;
    }

    private function journalIdFor(FinancialEvent $event): ?int
    {
        return $event->odoo_journal_id ? (int) $event->odoo_journal_id : null;
    }

    // ── Attachments (§29) ─────────────────────────────────────────────────────────────────────────

    /**
     * Copy the receipt/invoice scan onto the Odoo document.
     *
     * NON-FATAL BY DESIGN. The document already exists by the time this runs, and a missing scan is a
     * far smaller problem than a push that reports failure and sends somebody looking for a bill they
     * think was never created. A failure here is logged and swallowed; the FleetView attachment remains
     * the traceable original either way, which is what §29 asks for.
     */
    private function attachDocument(FinancialEvent $event, string $model, int $odooId): void
    {
        $attachment = $event->resolvedAttachment();

        if (! $attachment || ! ($attachment['key'] ?? null)) {
            return;
        }

        try {
            $disk     = Storage::disk($attachment['disk'] ?: 'public');
            $contents = $disk->get($attachment['key']);

            if ($contents === null || $contents === '') {
                return;
            }

            $this->client->create('ir.attachment', [
                'name'      => basename((string) $attachment['key']),
                'res_model' => $model,
                'res_id'    => $odooId,
                'type'      => 'binary',
                'datas'     => base64_encode($contents),
            ]);
        } catch (\Throwable $e) {
            $this->client->log('attachment transfer failed', [
                'financial_event_id' => $event->id,
                'odoo_model'         => $model,
                'odoo_document_id'   => $odooId,
                'error'              => $e->getMessage(),
            ]);
        }
    }
}
