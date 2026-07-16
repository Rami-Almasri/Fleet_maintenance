import { useEffect, useState } from 'react';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../ui/Icon';

// Post-Repair Inspection — the drawer's "Repair Quality Check" panel. Reads the durable QC verdicts
// recorded at sign-off (GET /maintenance-tickets/{id}/repair-inspections) and renders them: a green
// "Fixed" row per verified fault, a red "REPAIR FAILED" card per still-broken fault (fault · previous
// repair garage + date · reason · note), and a "New problem found" row for anything the check surfaced.
// It is READ-ONLY: the verdicts themselves are written by the existing PASS (close) / FAIL (reopen)
// actions — this panel is the structured history of those decisions. See RepairInspectionService.

// The stages at which a car has been (or is being) checked after a repair — the panel is only relevant
// then. Before that there is nothing to inspect; a legacy ticket with recorded verdicts still shows.
const QC_STATES = new Set([
  'ready_for_reinspection', 'reinspection_failed', 'ready_for_pickup', 'in_our_park',
  'awaiting_invoice', 'closed',
]);

const fmtDay = (d) => {
  if (!d) return '—';
  try {
    return new Date(d).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
  } catch {
    return d;
  }
};

function Section({ title, icon, children }) {
  return (
    <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-soft">
      <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-2.5">
        <h3 className="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-slate-500">
          {icon}
          {title}
        </h3>
      </div>
      <div className="px-4 py-3.5">{children}</div>
    </section>
  );
}

export default function RepairQualityCheck({ ticketId, workflowStatus, reloadKey = 0 }) {
  const { t } = useI18n();
  const [rows, setRows] = useState(null); // null = loading, [] = none
  const [err, setErr] = useState(false);

  useEffect(() => {
    if (!ticketId) return undefined;
    let alive = true;
    setRows(null);
    setErr(false);
    api.get(`/maintenance-tickets/${ticketId}/repair-inspections`)
      .then((r) => { if (alive) setRows(r.data?.data || []); })
      .catch(() => { if (alive) setErr(true); })
      .finally(() => { /* rows stays null→[] via then */ });
    return () => { alive = false; };
  }, [ticketId, reloadKey]);

  // Don't take up drawer space on a ticket that hasn't reached a QC stage AND has no history.
  const hasHistory = Array.isArray(rows) && rows.length > 0;
  const relevant = QC_STATES.has(workflowStatus) || hasHistory;
  if (err || !relevant) return null;

  const waiting = workflowStatus === 'ready_for_reinspection';
  const icon = <Icon.Shield className="h-3.5 w-3.5 text-slate-400" />;

  return (
    <Section title={t('workflow.detail.repairQuality.title')} icon={icon}>
      {/* Status line — what the QC gate is doing right now. */}
      <div className="mb-3 flex items-center gap-2">
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${
          waiting ? 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200'
                  : 'bg-slate-100 text-slate-500 ring-1 ring-inset ring-slate-200'}`}>
          <span className="h-1.5 w-1.5 rounded-full" style={{ background: waiting ? '#f59e0b' : '#94a3b8' }} />
          {waiting ? t('workflow.detail.repairQuality.waiting') : t('workflow.detail.repairQuality.status')}
        </span>
      </div>

      {rows === null ? (
        <p className="text-xs text-slate-400">{t('workflow.detail.repairQuality.loading')}</p>
      ) : rows.length === 0 ? (
        <p className="text-xs text-slate-400">{t('workflow.detail.repairQuality.none')}</p>
      ) : (
        <div className="space-y-2.5">
          {rows.map((i) => {
            if (i.is_failure) {
              // Case B — the "REPAIR FAILED" card.
              return (
                <div key={i.id} className="rounded-xl border border-red-200 bg-red-50/60 px-3.5 py-3">
                  <div className="flex items-center justify-between gap-2">
                    <span className="inline-flex items-center gap-1.5 text-xs font-bold text-red-700">
                      <span aria-hidden>{i.is_recurrence ? '🔁' : '⚠️'}</span>
                      {i.is_recurrence ? t('workflow.detail.repairQuality.repeated') : t('workflow.detail.repairQuality.failed')}
                    </span>
                    <span className="text-[11px] text-slate-400">{fmtDay(i.inspection_date)}</span>
                  </div>
                  <p className="mt-1.5 text-sm font-semibold text-slate-800">{i.fault_symptom || '—'}</p>
                  <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1.5">
                    <Fact label={t('workflow.detail.repairQuality.prevRepair')} value={i.previous_garage} />
                    <Fact label={t('workflow.detail.repairQuality.repairDate')} value={i.previous_repaired_at ? fmtDay(i.previous_repaired_at) : null} />
                    <Fact label={t('workflow.detail.repairQuality.daysSince')} value={i.days_since_repair != null ? `${i.days_since_repair}` : null} />
                    <Fact label={t('workflow.detail.repairQuality.reason')} value={i.failure_reason_label} tone="red" />
                    <Fact label={t('workflow.detail.repairQuality.inspector')} value={i.inspector_name} />
                  </dl>
                  {i.notes && <p className="mt-2 rounded-lg bg-white px-3 py-1.5 text-xs text-slate-600 ring-1 ring-inset ring-red-100">{i.notes}</p>}
                </div>
              );
            }
            if (i.is_new_issue) {
              // Case C — a new problem the check surfaced.
              return (
                <div key={i.id} className="rounded-xl border border-indigo-200 bg-indigo-50/50 px-3.5 py-2.5">
                  <div className="flex items-center justify-between gap-2">
                    <span className="inline-flex items-center gap-1.5 text-xs font-bold text-indigo-700">
                      <span aria-hidden>🆕</span> {t('workflow.detail.repairQuality.newIssue')}
                    </span>
                    <span className="text-[11px] text-slate-400">{fmtDay(i.inspection_date)}</span>
                  </div>
                  <p className="mt-1 text-sm font-semibold text-slate-800">{i.new_fault_symptom || '—'}</p>
                  {i.notes && <p className="mt-1.5 text-xs text-slate-500">{i.notes}</p>}
                </div>
              );
            }
            // Case A — fixed successfully.
            return (
              <div key={i.id} className="flex items-center justify-between gap-2 rounded-xl border border-emerald-200 bg-emerald-50/50 px-3.5 py-2.5">
                <div className="min-w-0">
                  <span className="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-700">
                    <Icon.Check className="h-3.5 w-3.5" /> {t('workflow.detail.repairQuality.fixed')}
                  </span>
                  <p className="mt-0.5 truncate text-sm text-slate-800">{i.fault_symptom || '—'}</p>
                </div>
                <span className="shrink-0 text-[11px] text-slate-400">{fmtDay(i.inspection_date)}</span>
              </div>
            );
          })}
        </div>
      )}
    </Section>
  );
}

function Fact({ label, value, tone }) {
  if (value == null || value === '') return null;
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-[11px] font-medium uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className={`text-sm ${tone === 'red' ? 'font-semibold text-red-700' : 'text-slate-800'}`}>{value}</dd>
    </div>
  );
}
