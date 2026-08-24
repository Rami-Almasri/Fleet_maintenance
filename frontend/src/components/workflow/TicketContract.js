// EVERYTHING THAT HAPPENED INSIDE THIS VISIT'S CONTRACT.
//
// A maintenance visit exists twice: as the workshop's ticket (this page) and as the office's
// OfficeManager type-U contract (the sheet record). They are two readings of the SAME visit, and a
// person reading a ticket to understand a car keeps having to ask what the office recorded — the
// contract number to quote, who it was opened for, when the car went out and came back, the mileage
// at each end, and what the visit was billed.
//
// So this panel puts the contract header on the ticket, and under it every event that fell inside the
// contract's own window — the car's activity trail (GET /Vehicle/{id}/activity), clipped to
// out_date → in_date (or today, while the contract is still open). The two records stay separate and
// each is labelled: the header is what the OFFICE wrote down, the events are what the APP recorded.
// Nothing here is derived or reconciled — a disagreement between them stays visible, because that
// disagreement is information.
//
// Renders nothing when the ticket has no contract behind it (a workshop-only visit).

import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import { Skeleton } from '../ui/Skeleton';
import { SHOW_FINANCIALS } from '../../config/features';
import { aed, fmtDate, num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

const TYPE_LABEL = { C: 'Rental', U: 'Maintenance', R: 'Booking' };

// Start-of-day / end-of-day millis for a YYYY-MM-DD, so the window includes both boundary days whole.
const dayStart = (d) => (d ? new Date(`${d}T00:00:00`).getTime() : null);
const dayEnd = (d) => (d ? new Date(`${d}T23:59:59`).getTime() : null);

function Fact({ label, children }) {
  return (
    <div className="min-w-0">
      <dt className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className="truncate text-xs font-semibold text-slate-800">{children ?? '—'}</dd>
    </div>
  );
}

export default function TicketContract({ contract, vehicleId }) {
  const { t } = useI18n();
  const [events, setEvents] = useState(null);
  const [loading, setLoading] = useState(true);
  const [showAll, setShowAll] = useState(false);

  const from = dayStart(contract?.out_date);
  // An open contract's window runs to now — the visit is still happening.
  const to = dayEnd(contract?.in_date) ?? Date.now();

  const load = useCallback(async () => {
    // Clear the spinner on the way out too — an early return that leaves `loading` true pins the
    // section on skeletons forever for a ticket with no vehicle behind it.
    if (!vehicleId || !contract) { setLoading(false); return; }
    try {
      const r = await api.get(`/Vehicle/${vehicleId}/activity`);
      const all = r.data?.data?.events || r.data?.data || [];
      // Clip to the contract's own window. With no out_date we have no window to clip to, and we say
      // so below rather than quietly showing the car's whole life as if it belonged to this contract.
      const inWindow = from
        ? all.filter((e) => {
          const at = new Date(e.occurred_at).getTime();
          return !Number.isNaN(at) && at >= from && at <= to;
        })
        : [];
      setEvents(inWindow);
    } catch {
      setEvents([]);
    } finally {
      setLoading(false);
    }
  }, [vehicleId, contract, from, to]);

  useEffect(() => { setLoading(true); load(); }, [load]);

  if (!contract) return null;

  const open = !contract.in_date;
  const km = (contract.out_milage != null && contract.in_milage != null)
    ? contract.in_milage - contract.out_milage
    : null;
  const rows = events || [];
  const shown = showAll ? rows : rows.slice(0, 8);

  return (
    <div className="space-y-4">
      {/* ── What the OFFICE recorded ─────────────────────────────────────────── */}
      <div className="rounded-xl bg-slate-50/70 p-3 ring-1 ring-inset ring-slate-100">
        <div className="mb-2.5 flex flex-wrap items-center gap-2">
          <Link
            to={`/contracts/${contract.id}`}
            className="text-sm font-bold text-indigo-600 hover:text-indigo-700"
          >
            #{contract.contract_no || contract.id}
          </Link>
          <Badge tone="gray">{t(TYPE_LABEL[contract.type] || contract.type || '—')}</Badge>
          <Badge tone={open ? 'amber' : 'green'}>
            {open ? t('workflow.contract.open') : t('workflow.contract.closed')}
          </Badge>
        </div>
        <dl className="grid grid-cols-2 gap-x-4 gap-y-2.5 sm:grid-cols-4">
          <Fact label={t('workflow.contract.customer')}>
            {contract.customer_id
              ? <Link to={`/customers/${contract.customer_id}`} className="text-indigo-600 hover:text-indigo-700">{contract.customer || `#${contract.customer_id}`}</Link>
              : contract.customer}
          </Fact>
          <Fact label={t('workflow.contract.out')}>{fmtDate(contract.out_date)}</Fact>
          <Fact label={t('workflow.contract.in')}>{contract.in_date ? fmtDate(contract.in_date) : t('workflow.contract.stillOut')}</Fact>
          <Fact label={t('workflow.contract.mileage')}>
            {contract.out_milage != null ? num(contract.out_milage) : '—'}
            {contract.in_milage != null ? ` → ${num(contract.in_milage)}` : ''}
            {km != null ? ` (${t('workflow.contract.km', { n: num(km) })})` : ''}
          </Fact>
          {SHOW_FINANCIALS && (
            <>
              <Fact label={t('workflow.contract.debit')}>{aed(contract.debit)}</Fact>
              <Fact label={t('workflow.contract.credit')}>{aed(contract.credit)}</Fact>
              <Fact label={t('workflow.contract.balance')}>{aed(contract.balance)}</Fact>
            </>
          )}
        </dl>
        <p className="mt-2.5 text-[10px] leading-relaxed text-slate-400">
          {t('workflow.contract.headerOrigin')}
        </p>
      </div>

      {/* ── What the APP recorded inside that window ─────────────────────────── */}
      <div>
        <h4 className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">
          {t('workflow.contract.whatHappened')}
          {rows.length > 0 && <span className="ms-1.5 font-semibold text-slate-400">{num(rows.length)}</span>}
        </h4>

        {loading ? (
          <div className="space-y-2">
            {[0, 1, 2].map((i) => <Skeleton key={i} className="h-8 rounded-lg" />)}
          </div>
        ) : !from ? (
          <p className="text-xs text-slate-400">{t('workflow.contract.noWindow')}</p>
        ) : rows.length === 0 ? (
          <p className="text-xs text-slate-400">{t('workflow.contract.nothingLogged')}</p>
        ) : (
          <>
            <ol className="space-y-1.5">
              {shown.map((e) => (
                <li key={e.id} className="flex items-start gap-2.5 rounded-lg bg-white px-2.5 py-1.5 ring-1 ring-inset ring-slate-100">
                  <span className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-slate-300" />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-xs font-semibold text-slate-800">
                      {e.action}
                      {e.transition && <span className="ms-1.5 font-normal text-slate-400">{e.transition}</span>}
                    </p>
                    {e.description && <p className="truncate text-[11px] text-slate-500">{e.description}</p>}
                  </div>
                  <span className="shrink-0 text-[11px] text-slate-400">
                    {e.actor_name ? `${e.actor_name} · ` : ''}{fmtDate(e.occurred_at)}
                  </span>
                </li>
              ))}
            </ol>
            {rows.length > shown.length && (
              <button
                type="button"
                onClick={() => setShowAll(true)}
                className="mt-2 text-xs font-semibold text-indigo-600 hover:text-indigo-700"
              >
                {t('workflow.contract.showAll', { n: num(rows.length) })}
              </button>
            )}
          </>
        )}

        <p className="mt-2.5 flex items-start gap-1.5 text-[10px] leading-relaxed text-slate-400">
          <Icon.Clock className="mt-0.5 h-3 w-3 shrink-0" />
          {t('workflow.contract.eventsOrigin')}
        </p>
      </div>
    </div>
  );
}
