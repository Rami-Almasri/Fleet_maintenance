// The accounting lifecycle of a repair, one chain per part:
//
//   Purchase Request → Purchase Order → Supplier Invoice → Goods Received
//     → Part Installed → Return / Credit Note → Supplier Payment → Ticket Closed
//
// This answers what a cost figure cannot: what has been ORDERED, RECEIVED, FITTED, RETURNED and PAID.
// Those are five different questions, and a single total collapses all of them into one number.
//
// Two display rules carry most of the meaning:
//
//   • A SKIPPED stage is drawn as legitimately not applicable, not as a gap. Most buys raise no purchase
//     order and most parts are never returned; drawing those as omissions would cry wolf on every normal
//     repair, and a panel that cries wolf stops being read.
//   • A BLOCKING stage is called out in amber with the reason in words. That is reserved for a real hole —
//     money spent with no document behind it — so when it does appear it means something.
//
// Read-only and self-fetching: it reads the rows the workflow already writes and invents no state.

import { useCallback, useEffect, useState } from 'react';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../ui/Icon';
import { SHOW_FINANCIALS } from '../../config/features';

const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);

const money = (n) =>
  `AED ${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

// Gregorian calendar + Latin digits under Arabic — a Hijri stamp would misread against the ledger.
const shortDate = (iso, lang) => {
  if (!iso) return null;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return null;
  return d.toLocaleDateString(lang === 'ar' ? 'ar-AE-u-ca-gregory-nu-latn' : undefined, { day: 'numeric', month: 'short' });
};

// How each state reads at a glance. `skipped` is deliberately muted rather than red — it is a normal
// outcome, not a failure.
const STATE_STYLE = {
  done:    { dot: 'bg-emerald-500', text: 'text-slate-700', ring: 'ring-emerald-200' },
  current: { dot: 'bg-sky-500 ring-4 ring-sky-100', text: 'text-sky-800 font-semibold', ring: 'ring-sky-200' },
  pending: { dot: 'bg-slate-200', text: 'text-slate-400', ring: 'ring-slate-100' },
  skipped: { dot: 'bg-slate-200', text: 'text-slate-300 line-through', ring: 'ring-slate-100' },
};

function Stage({ label, stage, isLast }) {
  const { lang } = useI18n();
  const style = STATE_STYLE[stage.state] || STATE_STYLE.pending;
  const when = shortDate(stage.at, lang);

  return (
    <li className="relative flex gap-3 pb-3 last:pb-0">
      {/* The spine. Drawn behind the dots so the chain reads as one line. */}
      {!isLast && <span className="absolute start-[5px] top-3 h-full w-px bg-slate-200" aria-hidden />}

      <span className={`relative z-10 mt-1 h-2.5 w-2.5 shrink-0 rounded-full ${style.dot}`} />

      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-baseline justify-between gap-x-2">
          <span className={`text-[13px] ${style.text}`}>{label}</span>
          {when && <span className="text-[11px] tabular-nums text-slate-400">{when}</span>}
        </div>

        {(stage.detail || stage.document) && (
          <p className="text-[11px] text-slate-500">
            {stage.document && <span className="font-medium text-slate-600">{stage.document}</span>}
            {stage.document && stage.detail ? ' · ' : ''}
            {stage.detail}
            {stage.by ? ` · ${stage.by}` : ''}
          </p>
        )}

        {/* A real hole in the audit trail, stated in words rather than left to inference. */}
        {stage.blocking && (
          <p className="mt-1 rounded bg-amber-50 px-1.5 py-1 text-[11px] font-medium text-amber-800">
            {stage.blocking}
          </p>
        )}
      </div>
    </li>
  );
}

function Chain({ chain, stages }) {
  const { t } = useI18n();
  return (
    <div className={`rounded-xl border p-3 ${chain.blocking ? 'border-amber-300 bg-amber-50/40' : 'border-slate-200 bg-white'}`}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold text-slate-800">{chain.part_name}</p>
          <p className="text-[11px] text-slate-500">
            {chain.supplier || t('no supplier recorded')}
            {chain.source ? ` · ${chain.source === 'supplier' ? t('from a supplier') : t('from the garage')}` : ''}
          </p>
        </div>
        {SHOW_FINANCIALS && chain.gross > 0 && (
          <div className="shrink-0 text-end">
            <p className="text-sm tabular-nums text-slate-800">{money(chain.net)}</p>
            {chain.net !== chain.gross && (
              <p className="text-[10px] tabular-nums text-slate-400 line-through">{money(chain.gross)}</p>
            )}
          </div>
        )}
      </div>

      <ol className="mt-3">
        {stages.map((s, i) => (
          <Stage
            key={s.key}
            label={s.label}
            stage={chain.stages[s.key] || { state: 'pending' }}
            isLast={i === stages.length - 1}
          />
        ))}
      </ol>
    </div>
  );
}

// Written out in full rather than interpolated: Tailwind only ships classes it can see as literal strings,
// so a `bg-${tone}-50` would be purged from the build and the tile would render unstyled.
const TILE_TONE = {
  slate:  'border-slate-200 bg-slate-50 text-slate-500 text-slate-800',
  violet: 'border-violet-200 bg-violet-50',
  sky:    'border-sky-200 bg-sky-50',
};
const TILE_LABEL_TONE = { slate: 'text-slate-500', violet: 'text-violet-500', sky: 'text-sky-600' };
const TILE_VALUE_TONE = { slate: 'text-slate-800', violet: 'text-violet-800', sky: 'text-sky-800' };

function SummaryTile({ label, value, tone = 'slate' }) {
  return (
    <div className={`rounded-lg border px-2.5 py-2 ${TILE_TONE[tone] || TILE_TONE.slate}`}>
      <p className={`text-[10px] font-medium uppercase tracking-wide ${TILE_LABEL_TONE[tone] || TILE_LABEL_TONE.slate}`}>
        {label}
      </p>
      <p className={`text-sm font-semibold tabular-nums ${TILE_VALUE_TONE[tone] || TILE_VALUE_TONE.slate}`}>
        {value}
      </p>
    </div>
  );
}

export default function ProcurementLifecycle({ ticketId, reloadKey }) {
  const { t } = useI18n();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    if (!ticketId) return;
    setLoading(true);
    try {
      setData(payload(await api.get(`/maintenance-tickets/${ticketId}/lifecycle`)));
    } catch {
      setData(null);
    } finally {
      setLoading(false);
    }
  }, [ticketId]);

  useEffect(() => { load(); }, [load, reloadKey]);

  // Nothing was ever ordered for this ticket — there is no chain to show.
  if (loading || !data || !data.chains.length) return null;

  const s = data.summary;

  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <SummaryTile label={t('Ordered')} value={`${s.ordered} / ${s.parts_total}`} />
        <SummaryTile label={t('Received')} value={s.received} />
        <SummaryTile label={t('Installed')} value={s.installed} />
        {s.returned > 0 && <SummaryTile label={t('Returned')} value={s.returned} tone="violet" />}
        {s.returned === 0 && SHOW_FINANCIALS && (
          <SummaryTile label={t('Owed to suppliers')} value={money(s.outstanding_to_suppliers)} tone="sky" />
        )}
      </div>

      {/* The one thing worth interrupting for: spend with no document behind it. */}
      {s.awaiting_invoice > 0 && (
        <div className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2">
          <p className="text-[12px] font-medium text-amber-800">
            {s.awaiting_invoice === 1
              ? t('1 part bought with no supplier invoice')
              : t('{n} parts bought with no supplier invoice', { n: s.awaiting_invoice })}
            {SHOW_FINANCIALS ? ` — ${t('{amount} unaccounted', { amount: money(s.awaiting_invoice_value) })}` : ''}.
          </p>
          <p className="text-[11px] text-amber-700">
            {t('Record the invoice so this spend can be audited: {list}.', { list: s.blocked.join(', ') })}
          </p>
        </div>
      )}

      <div className="space-y-3">
        {data.chains.map((c, i) => (
          <Chain key={c.purchase_id || c.request_id || i} chain={c} stages={data.stages} />
        ))}
      </div>

      <p className="flex items-center gap-1.5 text-[11px] text-slate-400">
        <Icon.Info className="h-3 w-3" />
        {t('A crossed-out stage did not apply — most buys raise no purchase order, and most parts are never returned.')}
      </p>
    </div>
  );
}
