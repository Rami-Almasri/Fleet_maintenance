// FinancialPanel — what the accounting system knows about this ticket, shown INSIDE the ticket.
//
// §30 is the whole brief: a user must never have to leave the repair to find out what happened to its
// money. So this is a panel on the existing maintenance screen, not a Finance module — it shows the
// obligation, the checklist of what is still missing, and only the actions this user may actually take.
//
// THE UI DECIDES NOTHING. Which buttons exist, whether the event may be sent, and what is blocking it
// all come from the server (FinancialEventResource). The client renders that answer. Re-deriving any of
// it here would drift from the routes and produce buttons that 403.
//
// BLOCKING REASONS ARE CODES. The backend sends {code, params, text}; REASON_LABELS below turns a code
// into a sentence in the user's language. `text` is the frozen English kept for the audit trail — it is
// the fallback, never the primary rendering, which is what lets the Arabic UI say the same thing.
import { useCallback, useEffect, useState } from 'react';
import { useI18n } from '../../i18n/I18nContext';
import { usePermissions } from '../../hooks/usePermissions';
import {
  approveFinancialEvent,
  cancelFinancialEvent,
  listFinancialEvents,
  retryFinancialEvent,
  syncFinancialEvent,
  validateFinancialEvent,
} from '../../api/financial';

// One tone per status, matching FinancialSyncStatus::TONES on the server so a status looks the same
// wherever it appears.
const TONES = {
  NOT_REQUIRED: 'bg-slate-50 text-slate-600 ring-slate-200',
  DRAFT: 'bg-slate-50 text-slate-700 ring-slate-200',
  BLOCKED: 'bg-amber-50 text-amber-800 ring-amber-200',
  READY: 'bg-blue-50 text-blue-800 ring-blue-200',
  SENDING: 'bg-cyan-50 text-cyan-800 ring-cyan-200',
  SYNCED: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
  FAILED: 'bg-red-50 text-red-800 ring-red-200',
  CANCELLED: 'bg-slate-50 text-slate-500 ring-slate-200',
};

// code → i18n key + English fallback. Params from the server fill the {placeholders}.
const REASON_LABELS = {
  odoo_not_configured: ['financial.reason.odooNotConfigured', 'Odoo is not connected for this environment.'],
  vehicle_analytic_account_missing: ['financial.reason.vehicleAnalytic', 'Vehicle {plate} has no Odoo analytic account.'],
  vehicle_missing: ['financial.reason.vehicleMissing', 'This cost is not attached to a vehicle.'],
  product_not_mapped: ['financial.reason.productNotMapped', 'Product “{description}” is not mapped to an Odoo product.'],
  supplier_not_mapped: ['financial.reason.supplierNotMapped', 'Supplier “{name}” is not mapped to an Odoo partner.'],
  supplier_missing: ['financial.reason.supplierMissing', 'A vendor bill needs a supplier, and none is selected.'],
  expense_account_unresolved: ['financial.reason.accountUnresolved', 'No Odoo expense account is set for {label}.'],
  document_type_unresolved: ['financial.reason.documentTypeUnresolved', 'No Odoo document type is set for {label}.'],
  expense_type_inactive: ['financial.reason.typeInactive', '{label} is switched off for Odoo sync.'],
  expense_employee_missing: ['financial.reason.employeeMissing', 'No Odoo employee is configured for expense claims.'],
  amount_invalid: ['financial.reason.amountInvalid', 'The amount ({amount}) cannot be posted.'],
  amount_mismatch: ['financial.reason.amountMismatch', 'The total ({event_total}) does not match the lines ({line_total}).'],
  no_lines: ['financial.reason.noLines', 'There are no cost lines to post.'],
  currency_unsupported: ['financial.reason.currency', 'Currency {currency} is not configured for Odoo.'],
  invoice_number_missing: ['financial.reason.invoiceNumber', 'The supplier invoice number is missing.'],
  invoice_date_missing: ['financial.reason.invoiceDate', 'The supplier invoice date is missing.'],
  attachment_missing: ['financial.reason.attachment', 'A receipt or invoice document must be attached.'],
};

function Badge({ status, label }) {
  return (
    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${TONES[status] || TONES.DRAFT}`}>
      {label}
    </span>
  );
}

// The six mapping states, and how each should READ. `changed` is green because it posts, but it is
// called out by name — a re-pointed mapping means documents already in Odoo may be coded against a
// different target than the row now names, and that is worth a second look even though nothing is broken.
const MAPPING_STATE = {
  mapped: ['text-emerald-700', 'financial.map.mapped', 'mapped'],
  changed: ['text-emerald-700', 'financial.map.changed', 'mapped (re-pointed)'],
  suggested: ['text-amber-700', 'financial.map.suggested', 'suggested — not confirmed'],
  stale: ['text-red-700', 'financial.map.stale', 'stale — gone from Odoo'],
  none: ['text-slate-500', 'financial.map.none', 'no counterpart'],
  unmapped: ['text-amber-700', 'financial.map.unmapped', 'not mapped'],
};

/** A FleetView record beside the Odoo record it resolves to, and how far that mapping can be trusted. */
function Resolved({ label, ours, target, mapping, tf }) {
  const [tone, key, fallback] = MAPPING_STATE[mapping?.state] || MAPPING_STATE.unmapped;

  return (
    <div>
      <dt className="text-[11px] uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className="text-slate-700">
        {ours || '—'}
        <span className="mx-1 text-slate-300">→</span>
        {mapping?.usable ? (
          <span className="text-emerald-700">
            {mapping.odoo_name || `#${mapping.odoo_id}`}
            {mapping.odoo_ref && <span className="ms-1 text-[11px] text-slate-400">({mapping.odoo_ref})</span>}
          </span>
        ) : (
          <span className={tone}>{tf(key, fallback)}</span>
        )}
        <span className="ms-1 text-[11px] text-slate-400">
          {target}
          {mapping?.state === 'changed' && ` · ${tf('financial.map.changedNote', 're-pointed')}`}
        </span>
      </dd>
    </div>
  );
}

/** One requirement line — a tick when satisfied, a dash when not. */
function Check({ ok, children }) {
  return (
    <li className="flex items-start gap-2 text-sm">
      <span className={ok ? 'text-emerald-600' : 'text-amber-600'} aria-hidden="true">{ok ? '✓' : '•'}</span>
      <span className={ok ? 'text-slate-600' : 'text-slate-800'}>{children}</span>
    </li>
  );
}

/**
 * Scoped by the operational thing being looked at — a maintenance ticket, or a vehicle. Both are
 * "inside the workflow"; there is deliberately no way to ask for the whole finance queue from here,
 * because that is the dashboard's job and this panel exists so nobody has to go there.
 */
export default function FinancialPanel({ maintenanceId, vehicleId, onChanged }) {
  const { tf } = useI18n();
  const { can } = usePermissions();

  const [events, setEvents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(null);
  const [error, setError] = useState(null);

  const canView = can('financial.view');

  const load = useCallback(async () => {
    // Don't ask at all when the user may not see the answer. The early return below hides the panel
    // either way, but firing a request that is certain to 403 is noise in the log and one more thing
    // for a reviewer to wonder about.
    if (!canView) {
      setLoading(false);

      return;
    }

    try {
      setError(null);
      const scope = maintenanceId ? { maintenance_id: maintenanceId } : { vehicle_id: vehicleId };
      const data = await listFinancialEvents({ ...scope, per_page: 25 });
      setEvents(data.items ?? []);
    } catch (e) {
      // A user without financial.view gets a 403 — that is not an error worth shouting about, it just
      // means this panel is not for them. Anything else is worth surfacing.
      if (e.response?.status !== 403) {
        setError(e.response?.data?.message || 'Could not load the financial status.');
      }
      setEvents([]);
    } finally {
      setLoading(false);
    }
  }, [maintenanceId, vehicleId, canView]);

  useEffect(() => { load(); }, [load]);

  // The panel is invisible to anyone who cannot see financial data at all — it is not a locked box
  // with a padlock on it, it simply is not part of their screen.
  if (!canView) return null;

  const run = async (id, fn) => {
    setBusy(id);
    setError(null);
    try {
      const updated = await fn();
      setEvents((prev) => prev.map((e) => (e.id === updated.id ? updated : e)));
      onChanged?.(updated);
    } catch (e) {
      setError(e.response?.data?.message || 'That did not work.');
    } finally {
      setBusy(null);
    }
  };

  const act = (event, key) => {
    switch (key) {
      case 'validate': return run(event.id, () => validateFinancialEvent(event.id));
      case 'sync': return run(event.id, () => syncFinancialEvent(event.id, { now: true }));
      case 'retry':
      case 'reconcile': return run(event.id, () => retryFinancialEvent(event.id));
      case 'approve': return run(event.id, () => approveFinancialEvent(event.id));
      case 'cancel': {
        // eslint-disable-next-line no-alert
        const reason = window.prompt(tf('financial.cancelReason', 'Why is this obligation being withdrawn?'));
        if (!reason) return undefined;
        return run(event.id, () => cancelFinancialEvent(event.id, reason));
      }
      default: return undefined;
    }
  };

  const reasonText = (r) => {
    const entry = REASON_LABELS[r.code];
    // An unknown code still says something useful: the frozen English the server sent. A new reason
    // added on the backend degrades to readable, never to blank.
    return entry ? tf(entry[0], entry[1], r.params || {}) : r.text;
  };

  if (loading) {
    return <p className="text-sm text-slate-400">{tf('financial.loading', 'Checking the financial status…')}</p>;
  }

  if (events.length === 0) {
    return (
      <p className="text-sm text-slate-500">
        {tf('financial.none', 'No financial obligation has been raised for this ticket yet. One appears as soon as a garage bill or a recovery cost is recorded.')}
      </p>
    );
  }

  return (
    <div className="space-y-4">
      {error && (
        <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-inset ring-red-200">{error}</p>
      )}

      {events.map((event) => {
        const req = event.requirements || {};
        const codes = (event.block_reasons || []).map((r) => r.code);
        const blocked = event.status === 'BLOCKED';

        return (
          <section key={event.id} className="rounded-lg border border-slate-200 bg-white p-4">
            <header className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h4 className="text-sm font-semibold text-slate-800">
                  {event.expense_type_label}
                  <span className="ml-2 tabular-nums text-slate-500">
                    {Number(event.amount).toLocaleString(undefined, { minimumFractionDigits: 2 })} {event.currency}
                  </span>
                </h4>
                <p className="mt-0.5 text-[11px] text-slate-400">
                  {tf('financial.willBecome', 'Recorded in Odoo as')} {event.document_type_label}
                </p>
              </div>
              <Badge status={event.status} label={event.status_label} />
            </header>

            {/* WHAT THIS RESOLVES TO IN ODOO — the mapped counterpart of every party to the cost,
                shown beside the FleetView name so the two are never confused for each other. This is
                the "do not match by display name" rule made visible: the name is what a human reads,
                the id underneath it is what actually posts. */}
            <dl className="mt-3 grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
              {event.vehicle && (
                <Resolved
                  label={tf('financial.field.vehicle', 'Vehicle')}
                  ours={event.vehicle.plate_no || event.vehicle.vin}
                  target={tf('financial.field.analytic', 'Analytic account')}
                  mapping={event.vehicle.analytic_account}
                  tf={tf}
                />
              )}
              {event.vendor && (
                <Resolved
                  label={tf('financial.field.supplier', 'Supplier')}
                  ours={event.vendor.name}
                  target={tf('financial.field.partner', 'Odoo partner')}
                  mapping={event.vendor.partner}
                  tf={tf}
                />
              )}
              <div>
                <dt className="text-[11px] uppercase tracking-wide text-slate-400">
                  {tf('financial.field.account', 'Expense account')}
                </dt>
                <dd className={event.expense_account?.resolved ? 'text-slate-700' : 'text-amber-700'}>
                  {event.expense_account?.name || tf('financial.unresolved', 'not resolved')}
                  {event.expense_account?.odoo_account_id && (
                    <span className="ms-1 text-[11px] text-slate-400">#{event.expense_account.odoo_account_id}</span>
                  )}
                </dd>
              </div>
              <div>
                <dt className="text-[11px] uppercase tracking-wide text-slate-400">
                  {tf('financial.field.document', 'Document')}
                </dt>
                <dd className="text-slate-700">
                  {event.invoice_number || tf('financial.noNumber', 'no number')}
                  {' · '}
                  <span className={event.has_attachment ? 'text-emerald-700' : 'text-amber-700'}>
                    {event.has_attachment
                      ? tf('financial.attached', 'attached')
                      : tf('financial.notAttached', 'not attached')}
                  </span>
                </dd>
              </div>
            </dl>

            {/* Parts, with the Odoo product each one resolves to — the guarantee that the same
                FleetView part always posts as the same Odoo product. */}
            {(event.lines || []).some((l) => l.needs_product_mapping) && (
              <ul className="mt-2 space-y-0.5">
                {event.lines.filter((l) => l.needs_product_mapping).map((l) => (
                  <li key={l.id} className="text-sm">
                    <span className="text-slate-700">{l.description}</span>
                    <span className="mx-1 text-slate-300">→</span>
                    {l.product?.usable ? (
                      <span className="text-emerald-700">
                        {l.product.odoo_name || `#${l.product.odoo_id}`}
                        {l.product.odoo_ref && <span className="ms-1 text-[11px] text-slate-400">({l.product.odoo_ref})</span>}
                      </span>
                    ) : (
                      <span className="text-amber-700">{tf('financial.productUnmapped', 'no Odoo product')}</span>
                    )}
                  </li>
                ))}
              </ul>
            )}

            {/* The checklist §30 asks for: what is satisfied, and what is not. */}
            <ul className="mt-3 space-y-1">
              <Check ok={!codes.includes('vehicle_analytic_account_missing') && !codes.includes('vehicle_missing')}>
                {tf('financial.check.vehicle', 'Vehicle mapped to an analytic account')}
              </Check>
              {req.supplier && (
                <Check ok={!codes.includes('supplier_not_mapped') && !codes.includes('supplier_missing')}>
                  {tf('financial.check.supplier', 'Supplier mapped to an Odoo partner')}
                </Check>
              )}
              <Check ok={!codes.includes('product_not_mapped')}>
                {tf('financial.check.products', 'Parts mapped to Odoo products')}
              </Check>
              <Check ok={!codes.includes('expense_account_unresolved') && !codes.includes('expense_type_inactive')}>
                {tf('financial.check.account', 'Expense account resolved')}
              </Check>
              {req.invoice_number && (
                <Check ok={!codes.includes('invoice_number_missing')}>
                  {tf('financial.check.invoiceNo', 'Invoice number')}
                  {event.invoice_number ? <span className="ml-1 text-slate-400">({event.invoice_number})</span> : null}
                </Check>
              )}
              {req.invoice_date && (
                <Check ok={!codes.includes('invoice_date_missing')}>{tf('financial.check.invoiceDate', 'Invoice date')}</Check>
              )}
              {req.attachment && (
                <Check ok={!codes.includes('attachment_missing')}>{tf('financial.check.attachment', 'Receipt attached')}</Check>
              )}
            </ul>

            {/* Why it is stuck, in the user's language — rendered from code + params. */}
            {blocked && (event.block_reasons || []).length > 0 && (
              <div className="mt-3 rounded-md bg-amber-50 px-3 py-2 ring-1 ring-inset ring-amber-200">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-amber-800">
                  {tf('financial.blocked', 'Blocked')}
                </p>
                <ul className="mt-1 list-disc space-y-0.5 ps-4 text-sm text-amber-900">
                  {event.block_reasons.map((r, i) => <li key={`${r.code}-${i}`}>{reasonText(r)}</li>)}
                </ul>
              </div>
            )}

            {event.failure && (
              <div className="mt-3 rounded-md bg-red-50 px-3 py-2 ring-1 ring-inset ring-red-200">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-red-800">
                  {tf('financial.failed', 'Odoo could not accept this')}
                </p>
                <p className="mt-0.5 text-sm text-red-900">{event.failure.message}</p>
                <p className="mt-0.5 text-[11px] text-red-600">
                  {tf('financial.attempts', 'Attempts')}: {event.attempts}
                </p>
              </div>
            )}

            {event.status === 'SYNCED' && (
              <p className="mt-3 text-sm text-emerald-800">
                {tf('financial.syncedAs', 'In Odoo as')}{' '}
                <span className="font-semibold">
                  {event.document_type_label} {event.odoo?.document_reference || `#${event.odoo?.document_id}`}
                </span>
                {/* Only when a URL template is configured — we never invent a link. */}
                {event.odoo?.url && (
                  <>
                    {' · '}
                    <a className="underline" href={event.odoo.url} target="_blank" rel="noreferrer">
                      {tf('financial.openInOdoo', 'Open in Odoo')}
                    </a>
                  </>
                )}
              </p>
            )}

            {/* Only the actions the server said this user may take, in the order it gave them. */}
            {(event.actions || []).length > 0 && (
              <div className="mt-3 flex flex-wrap gap-2">
                {event.actions.map((a) => (
                  <button
                    key={a.key}
                    type="button"
                    disabled={busy === event.id}
                    onClick={() => act(event, a.key)}
                    className={[
                      'rounded-md px-3 py-1.5 text-sm font-medium transition disabled:opacity-50',
                      a.primary
                        ? 'bg-slate-900 text-white hover:bg-slate-700'
                        : a.danger
                          ? 'text-red-700 ring-1 ring-inset ring-red-200 hover:bg-red-50'
                          : 'text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-50',
                    ].join(' ')}
                  >
                    {busy === event.id ? tf('financial.working', 'Working…') : a.label}
                  </button>
                ))}
              </div>
            )}
          </section>
        );
      })}
    </div>
  );
}
