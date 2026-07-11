// Mileage Discrepancies (/oversight/mileage) — every workflow stage where the odometer entered didn't
// line up with what was expected: a reading that ran backwards (a real data error), a big forward jump,
// a garage test-drive, or an over-threshold gap the operator had to explain. Each row shows the stage
// transition, the expected (previous) → entered figures with the change, the note, and who entered it.
// Backed by GET /Oversight/mileage-discrepancies.

import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../../components/ui/Icon';
import { Skeleton } from '../../components/ui/Skeleton';

const fmtKm = (n) => (n == null ? '—' : `${Number(n).toLocaleString()} km`);
const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }) : '—');

const KIND = {
  blocked:     { labelKey: 'oversight.mileage.kindBlocked',     cls: 'bg-red-600 text-white',          icon: 'Alert' },
  discrepancy: { labelKey: 'oversight.mileage.kindDiscrepancy', cls: 'bg-red-100 text-red-700',        icon: 'TrendDown' },
  jump:        { labelKey: 'oversight.mileage.kindJump',        cls: 'bg-amber-100 text-amber-700',    icon: 'TrendUp' },
  test_drive:  { labelKey: 'oversight.mileage.kindTestDrive',   cls: 'bg-sky-100 text-sky-700',        icon: 'Route' },
  deviation:   { labelKey: 'oversight.mileage.kindDeviation',   cls: 'bg-violet-100 text-violet-700',  icon: 'Info' },
  note:        { labelKey: 'oversight.mileage.kindNote',        cls: 'bg-slate-100 text-slate-600',    icon: 'Info' },
};

const FILTERS = ['all', 'blocked', 'discrepancy', 'jump', 'test_drive', 'deviation', 'note'];

// Magnitude buckets — filter rows by the absolute size of the odometer change (delta).
// null delta rows carry no measurable change and only show under 'all'.
const MAG = {
  all:   { labelKey: 'oversight.common.all',        test: () => true },
  tiny:  { labelKey: 'oversight.mileage.magTiny',   test: (d) => d != null && Math.abs(d) <= 5 },
  large: { labelKey: 'oversight.mileage.magLarge',  test: (d) => d != null && Math.abs(d) > 5 },
  none:  { labelKey: 'oversight.mileage.magNone',   test: (d) => d == null },
};
const MAG_FILTERS = ['all', 'tiny', 'large', 'none'];

export default function MileageDiscrepancies() {
  const { t } = useI18n();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState('all');
  const [mag, setMag] = useState('all');
  const [q, setQ] = useState('');

  useEffect(() => {
    let alive = true;
    api.get('/Oversight/mileage-discrepancies')
      .then((res) => { if (alive) setData(res.data.data); })
      .catch(() => {})
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  const rows = useMemo(() => {
    let r = data?.rows || [];
    if (filter !== 'all') r = r.filter((x) => x.kind === filter);
    if (mag !== 'all') r = r.filter((x) => MAG[mag].test(x.delta));
    const term = q.trim().toLowerCase();
    if (term) r = r.filter((x) => `${x.plate_no} ${x.car} ${x.stage_label} ${x.entered_by || ''}`.toLowerCase().includes(term));
    return r;
  }, [data, filter, mag, q]);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1200px] space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Header */}
        <div className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <Link to="/oversight" className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-400 hover:text-slate-600">
              <Icon.ArrowRight className="h-3 w-3 rotate-180" /> {t('oversight.hub.title')}
            </Link>
            <h1 className="font-display text-2xl font-bold tracking-tight text-slate-900">{t('oversight.mileage.title')}</h1>
            <p className="mt-1 max-w-2xl text-sm text-slate-500">{t('oversight.mileage.subtitle')}</p>
          </div>
          <div className="flex gap-3">
            <Stat value={data?.total} label={t('oversight.mileage.flags')} />
            <Stat value={data?.blocked} label={t('oversight.mileage.blocked')} tone={data?.blocked ? 'red' : 'slate'} />
            <Stat value={data?.discrepancies} label={t('oversight.mileage.discrepancies')} tone={data?.discrepancies ? 'red' : 'slate'} />
          </div>
        </div>

        {/* Controls */}
        <div className="flex flex-wrap items-center gap-2">
          {FILTERS.map((f) => (
            <button
              key={f}
              onClick={() => setFilter(f)}
              className={`rounded-full px-3 py-1.5 text-xs font-semibold transition ${filter === f ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'}`}
            >
              {f === 'all' ? t('oversight.common.all') : t(KIND[f].labelKey)}
            </button>
          ))}
          <div className="relative ms-auto">
            <Icon.Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder={t('common.search')}
              className="w-56 rounded-lg border border-slate-200 bg-white py-2 ps-9 pe-3 text-sm outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100"
            />
          </div>
        </div>

        {/* Change-size filter — bucket rows by how big the odometer jump was (5 km / 10 km / larger) */}
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-xs font-semibold uppercase tracking-wide text-slate-400">{t('oversight.mileage.magnitude')}</span>
          {MAG_FILTERS.map((m) => (
            <button
              key={m}
              onClick={() => setMag(m)}
              className={`rounded-full px-3 py-1.5 text-xs font-semibold transition ${mag === m ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'}`}
            >
              {t(MAG[m].labelKey)}
            </button>
          ))}
        </div>

        {loading ? (
          <Skeleton className="h-64 rounded-2xl" />
        ) : rows.length === 0 ? (
          <Empty t={t} />
        ) : (
          <div className="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
            <table className="w-full min-w-[880px] text-sm">
              <thead className="border-b border-slate-100 bg-slate-50/70 text-left text-xs uppercase tracking-wide text-slate-400">
                <tr>
                  <th className="px-4 py-3 font-semibold">{t('oversight.common.vehicle')}</th>
                  <th className="px-4 py-3 font-semibold">{t('oversight.mileage.transition')}</th>
                  <th className="px-4 py-3 text-right font-semibold">{t('oversight.mileage.expected')}</th>
                  <th className="px-4 py-3 text-right font-semibold">{t('oversight.mileage.entered')}</th>
                  <th className="px-4 py-3 text-right font-semibold">{t('oversight.mileage.change')}</th>
                  <th className="px-4 py-3 font-semibold">{t('oversight.mileage.kind')}</th>
                  <th className="px-4 py-3 font-semibold">{t('oversight.common.enteredBy')}</th>
                  <th className="px-4 py-3 font-semibold">{t('oversight.common.when')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((r, i) => {
                  const k = KIND[r.kind] || KIND.note;
                  const KIco = Icon[k.icon] || Icon.Info;
                  return (
                    <tr key={`${r.ticket_id}-${r.stage_key}-${i}`} className={r.kind === 'blocked' ? 'bg-red-50' : (r.kind === 'discrepancy' ? 'bg-red-50/40' : '')}>
                      <td className="px-4 py-3">
                        <Link to={`/maintenance-workflow/${r.ticket_id}`} className="font-mono font-semibold text-slate-900 hover:text-indigo-600">{r.plate_no || `#${r.ticket_id}`}</Link>
                        {r.car && <p className="text-xs text-slate-400">{r.car}</p>}
                      </td>
                      <td className="px-4 py-3">
                        <span className="font-medium text-slate-700">{r.stage_label}</span>
                        {r.outcome === 'blocked' && <span className="ms-1 rounded bg-red-600 px-1.5 py-0.5 text-[10px] font-semibold text-white">{t('oversight.mileage.rejected')}</span>}
                        {r.tolerance_waived && <span className="ms-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500">{t('oversight.mileage.waived')}</span>}
                      </td>
                      <td className="px-4 py-3 text-right tabular-nums text-slate-500">{fmtKm(r.previous)}</td>
                      <td className={`px-4 py-3 text-right font-semibold tabular-nums ${r.outcome === 'blocked' ? 'text-red-600 line-through decoration-red-400' : 'text-slate-900'}`}>{fmtKm(r.reading)}</td>
                      <td className={`px-4 py-3 text-right font-semibold tabular-nums ${r.delta < 0 ? 'text-red-600' : 'text-slate-600'}`}>
                        {r.delta == null ? '—' : `${r.delta > 0 ? '+' : ''}${Number(r.delta).toLocaleString()}`}
                      </td>
                      <td className="px-4 py-3">
                        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${k.cls}`}>
                          <KIco className="h-3 w-3" /> {t(k.labelKey)}
                        </span>
                        {r.note && <p className="mt-1 max-w-[220px] truncate text-xs italic text-slate-500" title={r.note}>“{r.note}”</p>}
                      </td>
                      <td className="px-4 py-3 text-slate-600">{r.entered_by || <span className="text-slate-300">{t('oversight.common.system')}</span>}</td>
                      <td className="px-4 py-3 text-slate-500">{fmtDate(r.at)}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}

function Stat({ value, label, tone = 'slate' }) {
  const red = tone === 'red';
  return (
    <div className={`rounded-2xl px-4 py-3 text-center shadow-sm ring-1 ${red ? 'bg-red-50 ring-red-200' : 'bg-white ring-slate-200'}`}>
      <p className={`text-2xl font-bold tabular-nums ${red ? 'text-red-600' : 'text-slate-900'}`}>{value ?? 0}</p>
      <p className="text-[11px] uppercase tracking-wide text-slate-400">{label}</p>
    </div>
  );
}

function Empty({ t }) {
  return (
    <div className="flex flex-col items-center justify-center rounded-2xl bg-white py-20 text-center shadow-sm ring-1 ring-slate-200">
      <Icon.Check className="h-10 w-10 text-emerald-500" />
      <p className="mt-3 text-sm font-medium text-slate-700">{t('oversight.common.empty')}</p>
      <p className="text-xs text-slate-400">{t('oversight.common.emptyBody')}</p>
    </div>
  );
}
