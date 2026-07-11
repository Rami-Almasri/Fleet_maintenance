// Severity Grade Review (/oversight/severity) — tickets whose fault-severity grade looks too LOW for the
// situation: the inspector picked Routine / Moderate when a critical-risk keyword, a breakdown origin, or
// a red-graded car says it should be Critical. Each row shows what was graded → what it should be, and the
// reasons why. Deep-links to the ticket so a supervisor can re-grade at the Decide step.
// Backed by GET /Oversight/severity-review.

import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../../components/ui/Icon';
import { Skeleton } from '../../components/ui/Skeleton';

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }) : '—');

const REASON_ICON = { keyword: 'Flag', breakdown: 'Alert', condition: 'Shield' };

export default function SeverityReview() {
  const { t } = useI18n();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [onlyCritical, setOnlyCritical] = useState(false);
  const [q, setQ] = useState('');

  useEffect(() => {
    let alive = true;
    api.get('/Oversight/severity-review')
      .then((res) => { if (alive) setData(res.data.data); })
      .catch(() => {})
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  const rows = useMemo(() => {
    let r = data?.rows || [];
    if (onlyCritical) r = r.filter((x) => x.expected === 'critical');
    const term = q.trim().toLowerCase();
    if (term) r = r.filter((x) => `${x.plate_no} ${x.car} ${x.graded_by || ''}`.toLowerCase().includes(term));
    return r;
  }, [data, onlyCritical, q]);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1200px] space-y-6 px-4 sm:px-6 lg:px-8">
        <div className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <Link to="/oversight" className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-400 hover:text-slate-600">
              <Icon.ArrowRight className="h-3 w-3 rotate-180" /> {t('oversight.hub.title')}
            </Link>
            <h1 className="font-display text-2xl font-bold tracking-tight text-slate-900">{t('oversight.severity.title')}</h1>
            <p className="mt-1 max-w-2xl text-sm text-slate-500">{t('oversight.severity.subtitle')}</p>
          </div>
          <div className="flex gap-3">
            <div className="rounded-2xl bg-white px-4 py-3 text-center shadow-sm ring-1 ring-slate-200">
              <p className="text-2xl font-bold tabular-nums text-slate-900">{data?.total ?? 0}</p>
              <p className="text-[11px] uppercase tracking-wide text-slate-400">{t('oversight.severity.mismatches')}</p>
            </div>
            <div className={`rounded-2xl px-4 py-3 text-center shadow-sm ring-1 ${data?.critical ? 'bg-red-50 ring-red-200' : 'bg-white ring-slate-200'}`}>
              <p className={`text-2xl font-bold tabular-nums ${data?.critical ? 'text-red-600' : 'text-slate-900'}`}>{data?.critical ?? 0}</p>
              <p className="text-[11px] uppercase tracking-wide text-slate-400">{t('oversight.severity.criticalMissed')}</p>
            </div>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <button
            onClick={() => setOnlyCritical((v) => !v)}
            className={`rounded-full px-3 py-1.5 text-xs font-semibold transition ${onlyCritical ? 'bg-red-600 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'}`}
          >
            {t('oversight.severity.criticalMissed')}
          </button>
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

        {loading ? (
          <Skeleton className="h-64 rounded-2xl" />
        ) : rows.length === 0 ? (
          <div className="flex flex-col items-center justify-center rounded-2xl bg-white py-20 text-center shadow-sm ring-1 ring-slate-200">
            <Icon.Check className="h-10 w-10 text-emerald-500" />
            <p className="mt-3 text-sm font-medium text-slate-700">{t('oversight.common.empty')}</p>
            <p className="text-xs text-slate-400">{t('oversight.common.emptyBody')}</p>
          </div>
        ) : (
          <div className="space-y-3">
            {rows.map((r) => (
              <div key={r.ticket_id} className="flex flex-col gap-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 sm:flex-row sm:items-center">
                {/* Vehicle */}
                <div className="sm:w-44 sm:flex-shrink-0">
                  <Link to={`/maintenance-workflow/${r.ticket_id}`} className="font-mono text-base font-bold text-slate-900 hover:text-indigo-600">{r.plate_no || `#${r.ticket_id}`}</Link>
                  {r.car && <p className="text-xs text-slate-400">{r.car}</p>}
                  {r.graded_by && <p className="mt-1 text-[11px] text-slate-400">{t('oversight.severity.gradedBy')}: {r.graded_by}</p>}
                  <p className="text-[11px] text-slate-400">{fmtDate(r.at)}</p>
                </div>

                {/* Graded → Should be */}
                <div className="flex items-center gap-3 sm:w-64 sm:flex-shrink-0">
                  <Grade emoji={r.graded_emoji} label={r.graded_label} tone={r.graded_tone} caption={t('oversight.severity.graded')} muted />
                  <Icon.ArrowRight className="h-4 w-4 flex-shrink-0 text-slate-300" />
                  <Grade emoji={r.expected_emoji} label={r.expected_label} tone={r.expected_tone} caption={t('oversight.severity.shouldBe')} />
                </div>

                {/* Reasons */}
                <div className="min-w-0 flex-1">
                  <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('oversight.severity.reasons')}</p>
                  <div className="flex flex-wrap gap-1.5">
                    {r.reasons.map((rs, i) => {
                      const RIco = Icon[REASON_ICON[rs.type]] || Icon.Info;
                      return (
                        <span key={i} className="inline-flex items-center gap-1 rounded-lg bg-slate-50 px-2 py-1 text-xs text-slate-600 ring-1 ring-slate-200">
                          <RIco className="h-3 w-3 text-slate-400" /> {rs.label}
                        </span>
                      );
                    })}
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

const GRADE_TONE = {
  red: 'bg-red-50 text-red-700 ring-red-200',
  orange: 'bg-orange-50 text-orange-700 ring-orange-200',
  amber: 'bg-amber-50 text-amber-700 ring-amber-200',
  green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
};

function Grade({ emoji, label, tone, caption, muted }) {
  return (
    <div className="text-center">
      <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold ring-1 ${muted ? 'bg-slate-50 text-slate-500 ring-slate-200' : (GRADE_TONE[tone] || GRADE_TONE.amber)}`}>
        {emoji} {label}
      </span>
      <p className="mt-1 text-[10px] uppercase tracking-wide text-slate-400">{caption}</p>
    </div>
  );
}
