// Repair Cost Breakdown — the ticket's money read as a journey, not as a number.
//
//     Fault → Required Part → Purchase Source → Invoice → Installation Cost → Final Ticket Cost
//
// The point of this panel is that a repair's cost genuinely comes from two different places, and the
// ticket should say which is which instead of showing one blended figure:
//
//     the SUPPLIER sold us the part   → their invoice (number, date, photo)
//     the GARAGE fitted it            → their invoice (labour, and any part THEY supplied)
//
// So every figure here names the document behind it. A part with no paper is called out rather than
// quietly averaged in, a return shows as a credit beside the buy it reverses (never as a smaller number),
// and money spent on a fault later ruled a mis-diagnosis gets its own heading instead of disappearing.
//
// Read-only and self-fetching: it explains figures that already exist and creates none. The actions that
// change money live where they belong — the Invoices panel, and the Parts board.

import { useCallback, useEffect, useState } from 'react';
import api from '../../api/client';
import Icon from '../ui/Icon';
import { SHOW_FINANCIALS } from '../../config/features';

const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);

const money = (n) =>
  `AED ${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

// A credit is written with a leading minus so the arithmetic on screen is the arithmetic in the database.
const signed = (n) => (Number(n) < 0 ? `− ${money(Math.abs(n))}` : `+ ${money(n)}`);

// The document behind a figure. Rendered on EVERY amount — a line that cannot name one says so in amber
// rather than looking like the rest, because an unauditable number should never blend in.
function SourceChip({ source }) {
  if (!source) return null;

  const tone = source.traceable
    ? 'bg-slate-100 text-slate-600'
    : 'bg-amber-100 text-amber-800 font-medium';

  return (
    <span className={`inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] ${tone}`} title={source.detail || ''}>
      {source.label}
      {source.reference ? ` · ${source.reference}` : ''}
      {source.photo_url && (
        <a href={source.photo_url} target="_blank" rel="noreferrer" className="text-sky-600 hover:underline">
          ↗
        </a>
      )}
    </span>
  );
}

// Where a part's money came from — the distinction the whole panel exists to keep visible.
const ORIGIN_LABEL = {
  supplier: 'Supplier',
  garage: 'Garage (bought)',
  garage_invoice: 'Garage supplied',
  return: 'Returned',
};

function Row({ label, value, sub, tone = 'slate', strong = false }) {
  return (
    <div className="flex items-start justify-between gap-3 py-1.5">
      <div className="min-w-0">
        <p className={`truncate text-sm ${strong ? 'font-semibold text-slate-800' : 'text-slate-700'}`}>{label}</p>
        {sub && <p className="mt-0.5 text-[11px] text-slate-500">{sub}</p>}
      </div>
      <span className={`shrink-0 text-sm tabular-nums ${strong ? 'font-semibold' : ''} text-${tone}-700`}>{value}</span>
    </div>
  );
}

// ── One side of the money: the suppliers' bills, or the garages' ─────────────────────────────────
//
// `allocated` is the only figure here that is this ticket's money. For a supplier invoice covering
// several cars, `total` is what the whole document says — shown as context, and clearly labelled as
// belonging to more than this ticket, so nobody reads the document total as the repair's part cost.
function InvoiceGroup({ title, subtitle, rows, party }) {
  if (!rows.length) return null;

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-3">
      <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">{title}</p>
      <p className="text-[11px] text-slate-400">{subtitle}</p>

      <div className="mt-2 space-y-2">
        {rows.map((inv) => (
          <div key={inv.id} className="rounded-lg border border-slate-200 px-2.5 py-2">
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0">
                <p className="truncate text-sm font-medium text-slate-800">{party(inv) || '—'}</p>
                <p className="text-[11px] text-slate-500">
                  {inv.invoice_no || 'no number'}{inv.date ? ` · ${inv.date}` : ''}
                </p>
              </div>
              <span className="shrink-0 text-sm tabular-nums text-slate-800">{money(inv.allocated)}</span>
            </div>

            {inv.shared && (
              <p className="mt-1 rounded bg-sky-50 px-1.5 py-1 text-[11px] text-sky-700">
                This invoice totals {money(inv.total)} and also covers{' '}
                {inv.shared_with_ticket_ids.length} other ticket
                {inv.shared_with_ticket_ids.length === 1 ? '' : 's'} — only this ticket's share is counted here.
              </p>
            )}

            <div className="mt-1 flex flex-wrap items-center gap-2 text-[11px]">
              {inv.photo_url && (
                <a href={inv.photo_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-sky-600 hover:underline">
                  <Icon.Camera className="h-3 w-3" /> Photo
                </a>
              )}
              {inv.variance != null && Math.abs(inv.variance) > 0.01 && (
                <span className="text-amber-700">variance {money(Math.abs(inv.variance))}</span>
              )}
              {inv.reconciliation_status === 'reconciled' && <span className="text-emerald-700">reconciled</span>}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

// ── One part, with its paper ──────────────────────────────────────────────────────────────────────
function PartRow({ part }) {
  const isCredit = part.kind === 'credit';
  const invoice = part.invoice;

  return (
    <div className={`rounded-lg border px-3 py-2 ${isCredit ? 'border-emerald-200 bg-emerald-50/50' : 'border-slate-200 bg-white'}`}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate text-sm font-medium text-slate-800">
            {part.part_name || part.description}
          </p>
          <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-slate-500">
            <span className="rounded bg-slate-100 px-1.5 py-0.5 font-medium text-slate-600">
              {ORIGIN_LABEL[part.origin] || 'Part'}
            </span>
            {part.supplier && <span>{part.supplier}</span>}
            {part.quantity > 1 && <span>× {part.quantity}</span>}
            {isCredit && part.reason_label && <span>· {part.reason_label}</span>}
          </div>

          {/* The document. A supplier part must be able to prove its price; a garage part is on the
              garage's own bill and deliberately has none of its own. */}
          {invoice && (
            <div className="mt-1.5 flex flex-wrap items-center gap-2 text-[11px]">
              <span className="font-medium text-slate-600">
                {invoice.invoice_no || 'Invoice'}{invoice.date ? ` · ${invoice.date}` : ''}
              </span>
              {invoice.photo_url && (
                <a
                  href={invoice.photo_url}
                  target="_blank"
                  rel="noreferrer"
                  className="inline-flex items-center gap-1 text-sky-600 hover:underline"
                >
                  <Icon.Camera className="h-3 w-3" /> Photo
                </a>
              )}
            </div>
          )}
          {part.invoice_missing && (
            <p className="mt-1.5 text-[11px] font-medium text-amber-600">
              No supplier invoice recorded — this price has no document behind it.
            </p>
          )}
          {part.refunded > 0 && !isCredit && (
            <p className="mt-1 text-[11px] text-emerald-700">Refunded {money(part.refunded)} · net {money(part.net)}</p>
          )}

          <div className="mt-1.5"><SourceChip source={part.source} /></div>
        </div>

        <span className={`shrink-0 text-sm tabular-nums ${isCredit ? 'font-medium text-emerald-700' : 'text-slate-800'}`}>
          {signed(part.total)}
        </span>
      </div>
    </div>
  );
}

// ── One fault, whole ──────────────────────────────────────────────────────────────────────────────
function FaultBlock({ fault }) {
  const hasMoney =
    fault.parts.length > 0 || fault.labour.length > 0 || fault.awaiting_installation.length > 0;

  return (
    <div className={`rounded-xl border p-3 ${fault.is_incorrect ? 'border-rose-200 bg-rose-50/40' : 'border-slate-200 bg-slate-50/60'}`}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold text-slate-800">{fault.symptom}</p>
          {fault.is_incorrect && (
            <p className="mt-0.5 text-[11px] font-medium text-rose-600">
              Ruled an incorrect diagnosis{fault.incorrect_reason ? ` — ${fault.incorrect_reason}` : ''}. No further
              cost can be added to it.
            </p>
          )}
        </div>
        <span className="shrink-0 text-sm font-semibold tabular-nums text-slate-800">{money(fault.totals.net)}</span>
      </div>

      {/* What the inspector said it would need — the step before any money moved. */}
      {fault.required_parts.length > 0 && (
        <div className="mt-2.5">
          <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">Required parts</p>
          <div className="mt-1 flex flex-wrap gap-1.5">
            {fault.required_parts.map((rp) => (
              <span key={rp.id} className="rounded bg-white px-2 py-0.5 text-[11px] text-slate-600 ring-1 ring-slate-200">
                {rp.part_name}
                {rp.quantity > 1 ? ` × ${rp.quantity}` : ''}
              </span>
            ))}
          </div>
        </div>
      )}

      {fault.parts.length > 0 && (
        <div className="mt-2.5 space-y-1.5">
          <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">Parts</p>
          {fault.parts.map((p, i) => <PartRow key={p.line_item_id || i} part={p} />)}
        </div>
      )}

      {fault.labour.length > 0 && (
        <div className="mt-2.5">
          <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">Installation / labour</p>
          {fault.labour.map((l) => (
            <div key={l.line_item_id}>
              <Row
                label={l.description}
                sub={l.garage_invoice ? `${l.garage_invoice.garage || 'Garage'}${l.garage_invoice.invoice_no ? ` · ${l.garage_invoice.invoice_no}` : ''}` : null}
                value={l.is_refund ? signed(l.total) : money(l.total)}
                tone={l.is_refund ? 'emerald' : 'slate'}
              />
              <SourceChip source={l.source} />
            </div>
          ))}
        </div>
      )}

      {/* Bought for this fault but not fitted yet — spend that is deliberately NOT in the ticket total,
          because adding it would make the ticket disagree with the garage's paper. */}
      {fault.awaiting_installation.length > 0 && (
        <div className="mt-2.5 rounded-lg border border-dashed border-amber-300 bg-amber-50/60 px-3 py-2">
          <p className="text-[11px] font-medium uppercase tracking-wide text-amber-700">Bought, not yet fitted</p>
          {fault.awaiting_installation.map((p) => (
            <div key={p.purchase_id} className="mt-1 flex items-center justify-between gap-3 text-[12px]">
              <span className="truncate text-slate-700">
                {p.part_name}
                {p.supplier ? ` · ${p.supplier}` : ''}
                {p.invoice?.invoice_no ? ` · ${p.invoice.invoice_no}` : ''}
              </span>
              <span className="shrink-0 tabular-nums text-amber-800">{money(p.net)}</span>
            </div>
          ))}
          <p className="mt-1 text-[10px] text-amber-700">Not in the ticket total until it is fitted.</p>
        </div>
      )}

      {!hasMoney && <p className="mt-2 text-[12px] text-slate-400">No cost recorded against this fault yet.</p>}
    </div>
  );
}

export default function CostJourney({ ticketId, reloadKey }) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    if (!ticketId) return;
    setLoading(true);
    try {
      const res = await api.get(`/maintenance-tickets/${ticketId}/cost-journey`);
      setData(payload(res));
    } catch {
      setData(null);
    } finally {
      setLoading(false);
    }
  }, [ticketId]);

  useEffect(() => { load(); }, [load, reloadKey]);

  // Money UI is gated by Financial Decoupling like every other computed figure.
  if (!SHOW_FINANCIALS || loading || !data) return null;

  const t = data.totals;
  const nothingYet = t.net_total === 0 && t.committed === 0;
  if (nothingYet && !data.faults.some((f) => f.required_parts.length > 0)) return null;

  const incorrect = t.incorrect_fault_cost;

  return (
    <div className="space-y-3">
      {data.faults.map((f) => <FaultBlock key={f.id} fault={f} />)}

      {/* Charged to the ticket but to no single fault — VAT, discounts and ticket-wide adjustments live
          here, because they apply to a document rather than to one repair. */}
      {data.general.lines.length > 0 && (
        <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
          <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">
            Document-level charges
          </p>
          {data.general.lines.map((l) => (
            <div key={l.line_item_id}>
              <Row label={l.description} value={signed(l.total)} />
              <SourceChip source={l.source} />
            </div>
          ))}
        </div>
      )}

      {/* The two financial events, side by side and never merged: what the suppliers charged for the
          parts, and what the garages charged to fit them. A supplier bill can span several tickets, so it
          shows this ticket's ALLOCATED share, with the document total as context beside it. */}
      {(data.invoices.supplier.length > 0 || data.invoices.garage.length > 0) && (
        <div className="grid gap-3 sm:grid-cols-2">
          <InvoiceGroup
            title="Supplier invoices"
            subtitle="What the parts cost"
            rows={data.invoices.supplier}
            party={(i) => i.supplier}
          />
          <InvoiceGroup
            title="Garage invoices"
            subtitle="What fitting them cost"
            rows={data.invoices.garage}
            party={(i) => i.garage}
          />
        </div>
      )}

      {/* The six bands, then the net. Each is a plain sum of ledger rows, so the arithmetic on screen is
          the arithmetic in the database. */}
      <div className="rounded-xl border border-slate-300 bg-white p-3">
        <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">Total repair cost</p>
        <div className="mt-1 divide-y divide-slate-100">
          <Row
            label="Parts"
            sub={[
              t.supplier_parts > 0 ? `${money(t.supplier_parts)} from suppliers` : null,
              t.garage_parts > 0 ? `${money(t.garage_parts)} supplied by the garage` : null,
            ].filter(Boolean).join(' · ') || null}
            value={money(t.parts)}
          />
          <Row label="Labour" value={money(t.labour)} />
          {t.vat !== 0 && <Row label="VAT" value={money(t.vat)} />}
          {t.discounts !== 0 && <Row label="Discounts" value={signed(t.discounts)} tone="emerald" />}
          {t.refunds !== 0 && (
            <Row
              label="Refunds & returns"
              sub={[
                t.parts_returned !== 0 ? `${money(Math.abs(t.parts_returned))} parts returned` : null,
                t.labour_refunded !== 0 ? `${money(Math.abs(t.labour_refunded))} labour refunded` : null,
              ].filter(Boolean).join(' · ') || null}
              value={signed(t.refunds)}
              tone="emerald"
            />
          )}
          {t.adjustments !== 0 && <Row label="Adjustments" value={signed(t.adjustments)} />}
          <Row label="Final net total" value={money(t.net_total)} strong />
        </div>
        {t.committed > 0 && (
          <p className="mt-2 border-t border-slate-100 pt-2 text-[11px] text-amber-700">
            {money(t.committed)} of parts bought and not yet fitted — outside this total until installation.
          </p>
        )}
      </div>

      {/* The audit. Shown on the ticket rather than buried in a report, because a figure nobody can check
          is the thing this whole breakdown exists to prevent. */}
      {data.audit && (
        <div className={`rounded-xl border p-3 ${data.audit.fully_traceable ? 'border-emerald-200 bg-emerald-50' : 'border-amber-300 bg-amber-50'}`}>
          <div className="flex items-center justify-between gap-3">
            <p className={`text-sm font-semibold ${data.audit.fully_traceable ? 'text-emerald-800' : 'text-amber-800'}`}>
              {data.audit.fully_traceable
                ? 'Every amount traces to a document'
                : `${money(data.audit.untraceable)} has no source document`}
            </p>
            <span className={`text-sm font-semibold tabular-nums ${data.audit.fully_traceable ? 'text-emerald-800' : 'text-amber-800'}`}>
              {data.audit.coverage_pct}%
            </span>
          </div>
          {!data.audit.fully_traceable && (
            <>
              <ul className="mt-1.5 space-y-1">
                {data.audit.untraceable_items.map((u, i) => (
                  <li key={u.line_item_id || `lump-${i}`} className="text-[12px] text-amber-800">
                    {u.description} — {money(u.amount)}
                    <span className="text-amber-600"> · {u.why}</span>
                  </li>
                ))}
              </ul>
              <p className="mt-1.5 text-[11px] text-amber-700">
                Record the invoice it came from, or an adjustment that explains it.
              </p>
            </>
          )}
        </div>
      )}

      {/* Money spent on a diagnosis that turned out to be wrong. Never subtracted — it left the company —
          and never blended in either, because it is the number that measures diagnosis quality. */}
      {incorrect.amount !== 0 && (
        <div className="rounded-xl border border-rose-200 bg-rose-50 p-3">
          <div className="flex items-center justify-between gap-3">
            <p className="text-sm font-semibold text-rose-800">Incorrect-diagnosis cost</p>
            <span className="text-sm font-semibold tabular-nums text-rose-800">{money(incorrect.amount)}</span>
          </div>
          <ul className="mt-1.5 space-y-1">
            {incorrect.faults.map((f) => (
              <li key={f.id} className="text-[12px] text-rose-700">
                {f.symptom} — {money(f.spent)}
                {f.reason ? ` · ${f.reason}` : ''}
              </li>
            ))}
          </ul>
          <p className="mt-1.5 text-[11px] text-rose-600">{incorrect.note}</p>
        </div>
      )}

      {/* Traceability: name the tables behind the figures rather than asking anyone to trust them. */}
      <details className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
        <summary className="cursor-pointer text-[11px] font-medium uppercase tracking-wide text-slate-400">
          Data origin
        </summary>
        <dl className="mt-2 space-y-1">
          {Object.entries(data.sources).map(([k, v]) => (
            <div key={k} className="flex gap-2 text-[11px]">
              <dt className="w-24 shrink-0 font-medium text-slate-500">{k.replace(/_/g, ' ')}</dt>
              <dd className="text-slate-500">{v}</dd>
            </div>
          ))}
        </dl>
      </details>
    </div>
  );
}
