// Severity Review (/oversight/severity) — the Quality-Control layer over maintenance grading.
//
// This is NOT a passive list of mistakes: it's a review workflow. Each ticket the system believes is
// UNDER-graded (a critical-risk keyword / a breakdown / a red-graded car scored only Routine or Moderate)
// becomes a Decision Card carrying (1) explainability — what was detected, the risk category, the impact
// and the recommended action, (2) a deterministic confidence read from the rule library (NOT learned),
// and (3) a human decision: Upgrade the grade, or Keep it. Every decision is written to the vehicle
// audit log. Deep-links open the ticket / the vehicle's inspection history.
//
// Backed by GET /Oversight/severity-review and POST /Oversight/severity-review/{ticket}/decide.

import { useEffect, useMemo, useState } from 'react';
import AuditAnalytics from '../../components/analytics/AuditAnalytics';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import { usePermissions } from '../../hooks/usePermissions';
import { useToast } from '../../components/ui/Toast';
import Icon from '../../components/ui/Icon';
import { Skeleton } from '../../components/ui/Skeleton';

// Arabic keeps Gregorian dates and Latin digits so the audit trail stays comparable.
const fmtDate = (iso, lang) => (iso ? new Date(iso).toLocaleDateString(lang === 'ar' ? 'ar-AE-u-ca-gregory-nu-latn' : undefined, { day: '2-digit', month: 'short', year: 'numeric' }) : '—');

const REASON_ICON = { keyword: 'Flag', breakdown: 'Alert', condition: 'Shield' };

const GRADE_TONE = {
  red: 'bg-red-50 text-red-700 ring-red-200',
  orange: 'bg-orange-50 text-orange-700 ring-orange-200',
  amber: 'bg-amber-50 text-amber-700 ring-amber-200',
  green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
};

export default function SeverityReview() {
  const { t, lang } = useI18n();
  const { can } = usePermissions();
  const toast = useToast();
  const canDecide = can('maintenance.manage');

  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState('all');
  const [q, setQ] = useState('');
  const [busy, setBusy] = useState(null); // ticket_id currently saving

  const load = () => {
    setLoading(true);
    return api.get('/Oversight/severity-review')
      .then((res) => setData(res.data.data))
      .catch(() => {})
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    let alive = true;
    api.get('/Oversight/severity-review')
      .then((res) => { if (alive) setData(res.data.data); })
      .catch(() => {})
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  const filtered = useMemo(() => {
    let r = data?.rows || [];
    if (filter === 'reviewed') {
      r = r.filter((x) => x.decision !== 'pending');
    } else {
      r = r.filter((x) => x.decision === 'pending');
      if (filter !== 'all') r = r.filter((x) => x.transition === filter);
    }
    const term = q.trim().toLowerCase();
    if (term) r = r.filter((x) => `${x.plate_no} ${x.car} ${x.graded_by || ''}`.toLowerCase().includes(term));
    return r;
  }, [data, filter, q]);

  const decide = async (row, action, note) => {
    if (!canDecide) { toast.error(t('oversight.severity.noPermission')); return; }
    setBusy(row.ticket_id);
    try {
      const body = { action };
      if (action === 'upgrade') body.severity = row.expected;
      if (note) body.note = note;
      await api.post(`/Oversight/severity-review/${row.ticket_id}/decide`, body);
      toast.success(action === 'upgrade' ? t('oversight.severity.upgradeSuccess') : t('oversight.severity.keepSuccess'));
      await load();
    } catch (e) {
      toast.error(e?.response?.data?.message || t('oversight.severity.actionFailed'));
    } finally {
      setBusy(null);
    }
  };

  const kpis = [
    { key: 'issuesDetected', value: data?.total ?? 0, tone: 'slate' },
    { key: 'pending', value: data?.pending ?? 0, tone: 'indigo' },
    { key: 'upgraded', value: data?.upgraded ?? 0, tone: 'emerald' },
    { key: 'kept', value: data?.kept ?? 0, tone: 'slate' },
  ];

  const FILTERS = [
    ['all', t('oversight.severity.filterAll'), data?.pending ?? 0],
    ['routine_critical', t('oversight.severity.filterRoutineCritical'), data?.transitions?.routine_critical ?? 0],
    ['moderate_critical', t('oversight.severity.filterModerateCritical'), data?.transitions?.moderate_critical ?? 0],
    ['moderate_high', t('oversight.severity.filterModerateHigh'), data?.transitions?.moderate_high ?? 0],
    ['reviewed', t('oversight.severity.filterReviewed'), data?.kept ?? 0],
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1200px] space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Header */}
        <div>
          <Link to="/apps/reports" className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-400 hover:text-slate-600">
            <Icon.ArrowRight className="h-3 w-3 rotate-180 rtl:-scale-x-100" /> {t('Reports')}
          </Link>
          <h1 className="font-display text-2xl font-bold tracking-tight text-slate-900">{t('oversight.severity.title')}</h1>
          <p className="mt-1 max-w-3xl text-sm text-slate-500">{t('oversight.severity.subtitle')}</p>
        </div>

        {/* KPI dashboard */}
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {kpis.map((k) => (
            <div key={k.key} className="rounded-2xl bg-white px-4 py-4 shadow-sm ring-1 ring-slate-200">
              <p className={`text-3xl font-bold tabular-nums ${k.tone === 'indigo' ? 'text-indigo-600' : k.tone === 'emerald' ? 'text-emerald-600' : 'text-slate-900'}`}>{k.value}</p>
              <p className="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-slate-400">{t(`oversight.severity.${k.key}`)}</p>
            </div>
          ))}
        </div>

        {/* Filters */}
        <div className="flex flex-wrap items-center gap-2">
          {FILTERS.map(([key, label, count]) => (
            <button
              key={key}
              onClick={() => setFilter(key)}
              className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition ${filter === key ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'}`}
            >
              {label}
              {count > 0 && <span className={`rounded-full px-1.5 text-[10px] tabular-nums ${filter === key ? 'bg-white/20' : 'bg-slate-100 text-slate-500'}`}>{count}</span>}
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

        {/* Cards */}
        {loading ? (
          <div className="space-y-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-56 rounded-2xl" />)}</div>
        ) : filtered.length === 0 ? (
          <div className="flex flex-col items-center justify-center rounded-2xl bg-white py-20 text-center shadow-sm ring-1 ring-slate-200">
            <Icon.Check className="h-10 w-10 text-emerald-500" />
            <p className="mt-3 text-sm font-medium text-slate-700">{t('oversight.severity.allGraded')}</p>
            <p className="text-xs text-slate-400">{t('oversight.severity.allGradedBody')}</p>
          </div>
        ) : (
          <>
          <AuditAnalytics
            rows={filtered}
            title={t('Cars sent back for a second look most often')}
            subtitle={t('Repeat appearances in the diagnostic-review queue — a car here often means the first grade keeps missing something')}
            metricLabel={t('Reviews')}
            color="purple"
          />
          <div className="space-y-4">
            {filtered.map((r) => (
              <DecisionCard key={r.ticket_id} row={r} t={t} lang={lang} canDecide={canDecide} busy={busy === r.ticket_id} onDecide={decide} />
            ))}
          </div>
          </>
        )}
      </div>
    </div>
  );
}

function DecisionCard({ row, t, lang, canDecide, busy, onDecide }) {
  const [pane, setPane] = useState(null); // 'upgrade' | 'keep' | null
  const [note, setNote] = useState('');
  const reviewed = row.decision !== 'pending';

  const submit = () => {
    onDecide(row, pane, note.trim() || undefined);
    setPane(null);
    setNote('');
  };

  return (
    <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
      <div className="flex flex-col gap-5 p-5 lg:flex-row">
        {/* Vehicle + grade transition */}
        <div className="lg:w-72 lg:flex-shrink-0">
          <Link to={`/maintenance-workflow/${row.ticket_id}`} className="font-mono text-lg font-bold text-slate-900 hover:text-indigo-600">
            {row.plate_no || `#${row.ticket_id}`}
          </Link>
          {row.car && <p className="text-xs text-slate-400">{row.car}</p>}
          <p className="mt-0.5 text-[11px] text-slate-400">
            {row.graded_by ? `${t('oversight.severity.gradedBy')}: ${row.graded_by} · ` : ''}{fmtDate(row.at, lang)}
          </p>

          <div className="mt-3 flex items-center gap-3">
            <Grade emoji={row.graded_emoji} label={row.graded_label} tone={row.graded_tone} caption={t('oversight.severity.currentDiagnosis')} muted />
            <Icon.ArrowRight className="h-4 w-4 flex-shrink-0 text-slate-300" />
            <Grade emoji={row.expected_emoji} label={row.expected_label} tone={row.expected_tone} caption={t('oversight.severity.recommended')} />
          </div>

          {/* Confidence */}
          <div className="mt-4">
            <div className="flex items-center justify-between text-[11px] font-medium text-slate-500">
              <span className="inline-flex items-center gap-1"><Icon.Gauge className="h-3.5 w-3.5 text-slate-400" /> {t('oversight.severity.confidence')}</span>
              <span className="tabular-nums font-bold text-slate-700">{row.confidence}%</span>
            </div>
            <div className="mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
              <div className="h-full rounded-full bg-indigo-500" style={{ width: `${row.confidence}%` }} />
            </div>
            {row.confidence_basis && <p className="mt-1 text-[11px] leading-snug text-slate-400">{row.confidence_basis}</p>}
          </div>
        </div>

        {/* Explainability */}
        <div className="min-w-0 flex-1 border-slate-100 lg:border-s lg:ps-5">
          <div className="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
            <Fact label={t('oversight.severity.detected')} value={row.explain?.detected} icon="Spark" strong />
            <Fact label={t('oversight.severity.riskCategory')} value={row.explain?.risk_category} icon="Alert" />
            <Fact label={t('oversight.severity.impact')} value={row.explain?.impact} icon="TrendDown" />
            <Fact label={t('oversight.severity.recommendedAction')} value={row.explain?.action} icon="Route" />
          </div>

          {/* Rule triggered */}
          {row.reasons?.length > 0 && (
            <div className="mt-4">
              <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('oversight.severity.ruleTriggered')}</p>
              <div className="flex flex-wrap gap-1.5">
                {row.reasons.map((rs, i) => {
                  const RIco = Icon[REASON_ICON[rs.type]] || Icon.Info;
                  return (
                    <span key={i} className="inline-flex items-center gap-1 rounded-lg bg-slate-50 px-2 py-1 text-xs text-slate-600 ring-1 ring-slate-200">
                      <RIco className="h-3 w-3 text-slate-400" /> {rs.label}
                    </span>
                  );
                })}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Decision footer */}
      <div className="border-t border-slate-100 bg-slate-50/60 px-5 py-3">
        {/* System → Human */}
        <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-xs">
          <span className="text-slate-500">
            {t('oversight.severity.systemRecommendation')}: <span className="font-semibold text-slate-700">{row.expected_emoji} {row.expected_label}</span>
          </span>
          <span className="text-slate-500">
            {t('oversight.severity.humanDecision')}:{' '}
            {reviewed ? (
              <span className={`font-semibold ${row.decision === 'upgraded' ? 'text-emerald-600' : 'text-slate-600'}`}>
                {row.decision === 'upgraded' ? t('oversight.severity.decisionUpgraded') : t('oversight.severity.decisionKept')}
                {row.decided_by ? ` ${t('oversight.severity.decidedBy')} ${row.decided_by}` : ''}
              </span>
            ) : (
              <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-700"><Icon.Clock className="h-3 w-3" /> {t('oversight.severity.decisionPending')}</span>
            )}
          </span>
          {reviewed && row.decision_note && <span className="text-slate-400">“{row.decision_note}”</span>}

          {/* Actions */}
          <div className="ms-auto flex flex-wrap items-center gap-2">
            <Link to={`/maintenance-workflow/${row.ticket_id}`} className="inline-flex items-center gap-1 rounded-lg bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-600 ring-1 ring-slate-200 transition hover:bg-slate-50">
              <Icon.Wrench className="h-3.5 w-3.5" /> {t('oversight.severity.openTicket')}
            </Link>
            {row.vehicle_id && (
              <Link to={`/car-status/${row.vehicle_id}`} className="inline-flex items-center gap-1 rounded-lg bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-600 ring-1 ring-slate-200 transition hover:bg-slate-50">
                <Icon.Search className="h-3.5 w-3.5" /> {t('oversight.severity.viewInspection')}
              </Link>
            )}
            {!reviewed && canDecide && !pane && (
              <>
                <button onClick={() => setPane('keep')} disabled={busy} className="inline-flex items-center gap-1 rounded-lg bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-600 ring-1 ring-slate-200 transition hover:bg-slate-50 disabled:opacity-50">
                  <Icon.XCircle className="h-3.5 w-3.5" /> {t('oversight.severity.keepCurrent')}
                </button>
                <button onClick={() => setPane('upgrade')} disabled={busy} className="inline-flex items-center gap-1 rounded-lg bg-red-600 px-2.5 py-1.5 text-xs font-semibold text-white transition hover:bg-red-700 disabled:opacity-50">
                  <Icon.TrendUp className="h-3.5 w-3.5" /> {t('oversight.severity.upgradeSeverity')}
                </button>
              </>
            )}
          </div>
        </div>

        {/* Confirm pane (optional note) */}
        {pane && (
          <div className="mt-3 rounded-xl bg-white p-3 ring-1 ring-slate-200">
            <p className="mb-2 text-xs font-medium text-slate-600">
              {pane === 'upgrade'
                ? `${t('oversight.severity.upgradeSeverity')} → ${row.expected_emoji} ${row.expected_label}`
                : t('oversight.severity.keepReasonPrompt')}
            </p>
            <textarea
              value={note}
              onChange={(e) => setNote(e.target.value)}
              rows={2}
              placeholder={pane === 'keep' ? t('oversight.severity.keepReasonPrompt') : ''}
              className="w-full resize-none rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100"
            />
            <div className="mt-2 flex items-center justify-end gap-2">
              <button onClick={() => { setPane(null); setNote(''); }} disabled={busy} className="rounded-lg px-3 py-1.5 text-xs font-semibold text-slate-500 transition hover:text-slate-700">
                {t('common.cancel')}
              </button>
              <button
                onClick={submit}
                disabled={busy}
                className={`inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-xs font-semibold text-white transition disabled:opacity-50 ${pane === 'upgrade' ? 'bg-red-600 hover:bg-red-700' : 'bg-slate-900 hover:bg-slate-800'}`}
              >
                {busy ? <Icon.Refresh className="h-3.5 w-3.5 animate-spin" /> : <Icon.Check className="h-3.5 w-3.5" />}
                {pane === 'upgrade' ? t('oversight.severity.upgradeSeverity') : t('oversight.severity.keepCurrent')}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

function Fact({ label, value, icon, strong }) {
  if (!value) return null;
  const Ico = Icon[icon] || Icon.Info;
  return (
    <div className="flex items-start gap-2">
      <Ico className="mt-0.5 h-4 w-4 flex-shrink-0 text-slate-300" />
      <div className="min-w-0">
        <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</p>
        <p className={`text-sm leading-snug ${strong ? 'font-semibold text-slate-900' : 'text-slate-600'}`}>{value}</p>
      </div>
    </div>
  );
}

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
