// The financial story of a repair, on the ticket, in one place.
//
// The goal is that a manager opens a ticket and needs no other screen to understand the money: what was
// diagnosed, what it needed, where each part came from, which documents exist, what has been paid, what
// came back, and what is still owed before the ticket can close.
//
// It leads with WHAT IS STILL OWED rather than with history, because that is the only part anyone has to
// act on. A ticket that cannot close says so at the top, names the amount, and links to the screen where
// it gets fixed — the block is never a mystery discovered by pressing a button.
//
// Everything below that is the narrative, oldest first. Amounts are shown per event, but only events that
// actually moved the ticket's total carry a signed figure; a purchase before it is fitted, an approval or
// a delivery are facts, not money, and showing them as money would double-count the repair.

import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import Icon from '../ui/Icon';
import { SHOW_FINANCIALS } from '../../config/features';
import { useI18n } from '../../i18n/I18nContext';

const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);

// Arabic must stay on Gregorian dates and Latin digits, so the locale is passed explicitly.
const money = (n, lang) =>
  `AED ${Number(n || 0).toLocaleString(lang === 'ar' ? 'ar-AE-u-nu-latn' : undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const when = (iso, lang) => {
  if (!iso) return '';
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? ''
    : d.toLocaleDateString(lang === 'ar' ? 'ar-AE-u-ca-gregory-nu-latn' : undefined, { day: 'numeric', month: 'short', year: 'numeric' });
};

// Phase → the dot colour on the spine. Written out in full so Tailwind ships the classes.
const PHASE_DOT = {
  diagnosis:   'bg-slate-400',
  procurement: 'bg-sky-500',
  document:    'bg-indigo-500',
  money:       'bg-emerald-500',
  closure:     'bg-slate-800',
};

const PHASE_LABEL = {
  diagnosis: 'Diagnosis',
  procurement: 'Procurement',
  document: 'Document',
  money: 'Money',
  closure: 'Closure',
};

function Event({ event, isLast }) {
  const { t, lang } = useI18n();
  const moved = Number(event.signed_amount || 0) !== 0;

  return (
    <li className="relative flex gap-3 pb-4 last:pb-0">
      {!isLast && <span className="absolute start-[5px] top-3 h-full w-px bg-slate-200" aria-hidden />}
      <span className={`relative z-10 mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${PHASE_DOT[event.phase] || 'bg-slate-300'}`} />

      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-baseline justify-between gap-x-3">
          <span className="text-[13px] font-medium text-slate-800">{event.title}</span>
          <span className="text-[11px] tabular-nums text-slate-400">{when(event.at, lang)}</span>
        </div>

        {event.detail && <p className="mt-0.5 text-[12px] text-slate-600">{event.detail}</p>}

        <div className="mt-1 flex flex-wrap items-center gap-2">
          <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">
            {PHASE_LABEL[event.phase] ? t(PHASE_LABEL[event.phase]) : event.phase}
          </span>
          {event.reference && (
            <span className="text-[10px] font-medium text-slate-500">{event.reference}</span>
          )}
          {SHOW_FINANCIALS && moved && (
            <span className={`text-[11px] font-semibold tabular-nums ${event.signed_amount < 0 ? 'text-emerald-700' : 'text-slate-700'}`}>
              {event.signed_amount < 0 ? '− ' : '+ '}{money(Math.abs(event.signed_amount), lang)}
            </span>
          )}
          {SHOW_FINANCIALS && !moved && event.amount > 0 && (
            <span className="text-[11px] tabular-nums text-slate-400" title={t('Recorded, but not part of the ticket total')}>
              {money(event.amount, lang)}
            </span>
          )}
        </div>
      </div>
    </li>
  );
}

export default function FinancialStory({ ticketId, reloadKey }) {
  const { t, lang } = useI18n();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [showAll, setShowAll] = useState(false);

  const load = useCallback(async () => {
    if (!ticketId) return;
    setLoading(true);
    try {
      setData(payload(await api.get(`/maintenance-tickets/${ticketId}/financial-story`)));
    } catch {
      setData(null);
    } finally {
      setLoading(false);
    }
  }, [ticketId]);

  useEffect(() => { load(); }, [load, reloadKey]);

  if (loading || !data) return null;

  const events = data.timeline?.events || [];
  const blockers = data.blockers || [];
  const warnings = data.warnings || [];

  // Nothing financial has happened and nothing is owed — say nothing rather than show an empty frame.
  if (!events.length && !blockers.length) return null;

  const shown = showAll ? events : events.slice(-8);
  const hidden = events.length - shown.length;

  return (
    <div className="space-y-3">
      {/* What must happen before this ticket can close. The only part anyone has to act on. */}
      {blockers.length > 0 && (
        <div className="rounded-xl border border-amber-300 bg-amber-50 p-3">
          <div className="flex items-center gap-2">
            <Icon.Alert className="h-4 w-4 text-amber-700" />
            <p className="text-sm font-semibold text-amber-900">
              {blockers.length === 1
                ? t('This ticket cannot be closed yet — 1 financial item is still open')
                : t('This ticket cannot be closed yet — {n} financial items are still open', { n: blockers.length })}
            </p>
          </div>

          <ul className="mt-2 space-y-2">
            {blockers.map((b, i) => (
              <li key={`${b.code}-${i}`} className="rounded-lg bg-white/70 px-2.5 py-2">
                <div className="flex items-start justify-between gap-3">
                  <p className="text-[12px] text-amber-900">{b.message}</p>
                  {SHOW_FINANCIALS && b.amount > 0 && (
                    <span className="shrink-0 text-[12px] font-semibold tabular-nums text-amber-900">
                      {money(b.amount, lang)}
                    </span>
                  )}
                </div>
                <p className="mt-0.5 text-[11px] font-medium text-amber-700">
                  {b.action}
                  {b.route && (
                    <Link to={b.route} className="ms-1 text-sky-700 underline hover:text-sky-800">
                      {t('Open')}
                    </Link>
                  )}
                </p>
              </li>
            ))}
          </ul>
        </div>
      )}

      {/* Explained but not finished. Shown, never blocked on. */}
      {warnings.length > 0 && (
        <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
          <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">{t('Still outstanding')}</p>
          {warnings.map((w, i) => (
            <p key={`${w.code}-${i}`} className="mt-0.5 text-[12px] text-slate-600">
              {w.message} <span className="text-slate-400">{w.action}</span>
            </p>
          ))}
        </div>
      )}

      {blockers.length === 0 && (
        <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2">
          <Icon.Check className="h-4 w-4 text-emerald-700" />
          <p className="text-[12px] font-medium text-emerald-800">
            {t('Every amount on this ticket traces to a document — it is ready to close.')}
          </p>
        </div>
      )}

      {/* The narrative. */}
      {events.length > 0 && (
        <div className="rounded-xl border border-slate-200 bg-white p-3">
          <div className="flex items-center justify-between">
            <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">
              {t('Financial timeline')}
            </p>
            {hidden > 0 && (
              <button
                type="button"
                onClick={() => setShowAll(true)}
                className="text-[11px] text-sky-600 hover:underline"
              >
                {t('Show {n} earlier', { n: hidden })}
              </button>
            )}
          </div>

          <ol className="mt-2">
            {shown.map((e, i) => (
              <Event key={`${e.kind}-${e.reference}-${i}`} event={e} isLast={i === shown.length - 1} />
            ))}
          </ol>
        </div>
      )}
    </div>
  );
}
