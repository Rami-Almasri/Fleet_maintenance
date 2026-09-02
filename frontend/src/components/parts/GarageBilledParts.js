// Parts billed on a garage's invoice, beside the labour — the half of "what did this part cost"
// that the Parts page could not see.
//
// A part reaches the fleet down two roads, and they are billed differently:
//
//     bought from a SUPPLIER  →  PartInvoice        parts only, keyed on this page
//     fitted by a GARAGE      →  MaintenanceInvoice the part AND the work on one document
//
// `maintenance_invoices` models the second directly — `parts_total` and `labor_total` sit on the
// same row — and PartInvoiceService REFUSES to let a garage-sourced part also be keyed as a supplier
// bill, because the ticket would then be charged for it twice. That refusal is correct.
//
// What it left behind was a reading problem: the page named after parts showed one road only. Ask it
// "what have we been billed for this part" and it answered with a fraction, giving no sign that a
// fraction was all it was. This closes that without merging the two entities.
//
// READ-ONLY on purpose. A garage bill is written on its ticket, through MaintenanceInvoiceService.
// Offering an Edit button here would be a second write path into the exact number the double-count
// guard protects, so every row is a pointer: here is the bill, here is its ticket, change it there.

import { useCallback, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { useI18n } from '../../i18n/I18nContext';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import { EmptyState } from '../ui/Misc';
import { aed2, fmtAgo } from '../../lib/format';

const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);

export default function GarageBilledParts() {
  const { t } = useI18n();
  const [open, setOpen] = useState(null);

  const fetcher = useCallback(async () => payload(await api.get('/part-invoices/garage-billed', { params: { per_page: 100 } })), []);
  const { data, loading } = useFetch(fetcher, []);

  const rows = data?.invoices || [];

  if (loading && !data) return null;

  return (
    <div className="overflow-hidden rounded-2xl border border-slate-200/60 bg-white shadow-soft">
      <div className="border-b border-slate-100 px-5 py-4">
        <h3 className="text-base font-semibold text-slate-900">{t('Parts billed by a garage')}</h3>
        <p className="mt-0.5 text-sm text-slate-500">
          {t('When a garage fits a part it usually bills the part and the work on one document. Those parts are on the ticket’s invoice, not here — this is the list, so nothing about a part is invisible on the parts page.')}
        </p>
      </div>

      {rows.length === 0 ? (
        <EmptyState
          title={t('No garage has billed a part yet')}
          message={t('When one does, the bill appears here with the part and the labour separated.')}
        />
      ) : (
        <ul className="divide-y divide-slate-100">
          {rows.map((r) => {
            const showing = open === r.id;
            return (
              <li key={r.id} className="px-5 py-3.5">
                <div className="flex flex-wrap items-start gap-3">
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-semibold text-slate-900">{r.invoice_no || t('No number')}</span>
                      <span className="text-sm text-slate-500">{r.garage || t('Garage not named')}</span>
                      {r.is_internal && <Badge tone="cyan">{t('In-house')}</Badge>}
                      {r.plate && (
                        <Link to={`/vehicles/${r.vehicle_id}`} className="text-sm font-medium text-indigo-600 hover:underline">
                          {r.plate}
                        </Link>
                      )}
                    </div>

                    {/* The split is the reason this row exists: how much of a mixed bill was the
                        part, and how much was the work. */}
                    <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                      <span>
                        {t('Parts')} <span className="font-semibold tabular-nums text-slate-800">{aed2(r.parts_total)}</span>
                      </span>
                      <span>
                        {t('Labour')} <span className="tabular-nums">{aed2(r.labor_total)}</span>
                      </span>
                      <span>
                        {t('Bill total')} <span className="tabular-nums">{aed2(r.amount)}</span>
                      </span>
                      {r.recorded_at && <span>{fmtAgo(r.recorded_at)}</span>}
                    </div>
                  </div>

                  <div className="flex shrink-0 items-center gap-2">
                    <button
                      type="button"
                      onClick={() => setOpen(showing ? null : r.id)}
                      className="text-sm font-medium text-slate-600 hover:text-slate-900"
                    >
                      {showing ? t('Hide the parts') : t('Show the parts ({n})', { n: r.parts?.length || 0 })}
                    </button>
                    {r.maintenance_id && (
                      <Link
                        to={`/maintenance/${r.maintenance_id}`}
                        className="inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:underline"
                      >
                        {t('Open the ticket')}
                        <Icon.ArrowRight className="h-4 w-4" />
                      </Link>
                    )}
                  </div>
                </div>

                {showing && (
                  <ul className="mt-2.5 space-y-1 rounded-xl bg-slate-50 px-3 py-2 text-sm">
                    {(r.parts || []).map((p) => (
                      <li key={p.id} className="flex items-center justify-between gap-3">
                        <span className="min-w-0 truncate text-slate-700">
                          {p.quantity} × {p.description || t('Unnamed part')}
                          {p.part_number ? <span className="text-slate-400"> · {p.part_number}</span> : null}
                        </span>
                        <span className="shrink-0 tabular-nums text-slate-700">{aed2(p.line_total)}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
