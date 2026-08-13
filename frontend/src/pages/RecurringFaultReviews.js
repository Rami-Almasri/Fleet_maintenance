import { useCallback, useMemo, useState } from 'react';
import RecurringFaultsAnalytics from '../components/analytics/RecurringFaultsAnalytics';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useToast } from '../components/ui/Toast';
import { usePermissions } from '../hooks/usePermissions';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import { Card, PageHeader, TableSkeleton, EmptyState } from '../components/ui/Misc';
import { Select } from '../components/ui/Field';
import { fmtAgo, fmtDate, num } from '../lib/format';
import { useI18n } from '../i18n/I18nContext';

const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);

const STATUS_TONE = { open: 'amber', decided: 'gray' };
const STATUS_FILTERS = ['open', 'decided'];
// Module-level word helper: the translator is threaded in from the component that renders it.
const statusLabel = (t, s) => (s === 'open' ? t('Open') : s === 'decided' ? t('Decided') : s);

// Decisions are no longer recorded from this page — cases are ruled on by clearing or rejecting the
// repair gate. Rulings stored before that change still render, so the tones stay. Wording lives in the
// catalog under `recurringFaults.decision.*`.
const DECISION_TONE = {
  same_repair_failed: 'red', new_unrelated_failure: 'blue',
  workshop_responsibility: 'amber', customer_misuse: 'violet',
  investigation_required: 'cyan',
};
const PREV_RESULT_TONE = { verified_fixed: 'green', fixed: 'slate' };

const plate = (v) => v?.plate || (v?.id ? `#${v.id}` : '—');
const carLine = (v) => [v?.make, v?.model].filter(Boolean).join(' ') || '—';
const km = (n) => (n != null ? `${num(n)} km` : '—');
// Arabic has six plural categories, so the English branches in code and each form is its own phrase.
const days = (t, n) => (n == null ? '—' : n === 1 ? t('1 day') : t('{n} days', { n: num(n) }));

// ─── Detail modal ────────────────────────────────────────────────────────────
function DetailModal({ open, review, onClose }) {
  const { t, tf } = useI18n();
  // Shared with the board below; `tf` keeps an unrecognised code readable.
  const decisionLabel = (code) => tf(`recurringFaults.decision.${code}`, code || '—');
  if (!review) return null;
  const parts = review.parts || [];

  const Row = ({ label, value }) => (
    <div className="flex justify-between gap-4 py-0.5"><dt className="text-slate-500">{label}</dt><dd className="text-end font-medium text-slate-900">{value}</dd></div>
  );

  // One sentence per shape: Arabic clause order differs, so the optional garage / distance clauses
  // cannot be glued on as fragments — each combination is its own complete phrase.
  const symptom = review.symptom;
  const duration = days(t, review.days_since_repair);
  const garage = review.previous_garage;
  const distance = review.distance_since_repair != null ? km(review.distance_since_repair) : null;
  const recurrenceLine = garage && distance
    ? t('This vehicle came back with “{symptom}” {duration} after it was repaired at {garage}, having driven {distance} since.', { symptom, duration, garage, distance })
    : garage
      ? t('This vehicle came back with “{symptom}” {duration} after it was repaired at {garage}.', { symptom, duration, garage })
      : distance
        ? t('This vehicle came back with “{symptom}” {duration} after it was repaired, having driven {distance} since.', { symptom, duration, distance })
        : t('This vehicle came back with “{symptom}” {duration} after it was repaired.', { symptom, duration });

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('Recurring Fault Detail')}
      subtitle={`${review.symptom} · ${plate(review.vehicle)} ${carLine(review.vehicle) !== '—' ? `(${carLine(review.vehicle)})` : ''}`}
      size="lg"
      footer={<Button variant="secondary" onClick={onClose}>{t('Close')}</Button>}
    >
      <div className="space-y-4">
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={STATUS_TONE[review.status] || 'gray'}>{statusLabel(t, review.status)}</Badge>
          {review.previous_result && (
            <Badge tone={PREV_RESULT_TONE[review.previous_result] || 'slate'}>
              {t('recurringFaults.previous', { result: tf(`recurringFaults.prevResult.${review.previous_result}`, review.previous_result) })}
            </Badge>
          )}
          <Badge tone="slate">{t('Happened {n}×', { n: num(review.occurrence_count) })}</Badge>
          {review.decision && <Badge tone={DECISION_TONE[review.decision] || 'gray'}>{decisionLabel(review.decision)}</Badge>}
        </div>

        {/* Recurrence banner */}
        <div className="rounded-xl bg-red-50 p-4 text-sm ring-1 ring-inset ring-red-600/15">
          <p className="font-semibold text-red-800">{t('Recurring fault detected')}</p>
          <p className="mt-0.5 text-red-700">{recurrenceLine}</p>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          {/* Previous repair */}
          <div className="rounded-xl bg-slate-50 p-4 ring-1 ring-inset ring-slate-200">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{t('Previous repair')}</p>
            <dl className="mt-2 text-sm">
              <Row label={t('Ticket')} value={review.previous_maintenance_id ? `#${review.previous_maintenance_id}` : '—'} />
              <Row label={t('Garage')} value={review.previous_garage || '—'} />
              <Row label={t('Repaired')} value={fmtDate(review.previous_repaired_at) || '—'} />
              <Row label={t('recurringFaults.result')} value={tf(`recurringFaults.prevResult.${review.previous_result}`, '—')} />
              <Row label={t('Odometer')} value={km(review.previous_odometer)} />
            </dl>
            {parts.length > 0 && (
              <div className="mt-2">
                <p className="text-xs font-medium text-slate-500">{t('Parts replaced')}</p>
                <div className="mt-1 flex flex-wrap gap-1.5">
                  {parts.map((p, i) => (
                    <span key={i} className="rounded-full bg-white px-2 py-0.5 text-xs text-slate-700 ring-1 ring-inset ring-slate-200">
                      {p.description || p.part_number || t('Part')}{p.part_number && p.description ? ` · ${p.part_number}` : ''}
                    </span>
                  ))}
                </div>
              </div>
            )}
          </div>

          {/* This occurrence */}
          <div className="rounded-xl bg-amber-50 p-4 ring-1 ring-inset ring-amber-600/15">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{t('This occurrence (confirmed)')}</p>
            <dl className="mt-2 text-sm">
              <Row label={t('Ticket')} value={review.maintenance_id ? `#${review.maintenance_id}` : '—'} />
              <Row label={t('Current odometer')} value={km(review.current_odometer)} />
              <Row label={t('Distance since repair')} value={km(review.distance_since_repair)} />
              <Row label={t('Days since repair')} value={days(t, review.days_since_repair)} />
              <Row label={t('Times fixed before')} value={num(Math.max(0, (review.occurrence_count || 1) - 1))} />
            </dl>
          </div>
        </div>

        <div className="rounded-xl bg-slate-50 p-4 text-sm ring-1 ring-inset ring-slate-200">
          <div className="flex justify-between gap-4"><span className="text-slate-500">{t('Opened')}</span><span className="text-slate-700">{review.opened_by || '—'} · {fmtAgo(review.opened_at) || '—'}</span></div>
          {review.decision && <div className="flex justify-between gap-4"><span className="text-slate-500">{t('recurringFaults.decisionLabel')}</span><span className="text-slate-700">{decisionLabel(review.decision)}{review.decided_by ? ` · ${review.decided_by}` : ''}</span></div>}
          {review.decision_note && <p className="mt-1 text-slate-600">“{review.decision_note}”</p>}
        </div>
      </div>
    </Modal>
  );
}

// ─── Page ────────────────────────────────────────────────────────────────────
export default function RecurringFaultReviews() {
  const { t, tf } = useI18n();
  const decisionLabel = (code) => tf(`recurringFaults.decision.${code}`, code || '—');
  const toast = useToast();
  const { can } = usePermissions();
  const allowed = can('maintenance.recurring.view');
  const canDecide = can('maintenance.recurring.manage');
  const [busyGate, setBusyGate] = useState(null);

  const [status, setStatus] = useState('open');

  const [detail, setDetail] = useState(null);
  const anyModal = !!detail;

  const fetcher = useCallback(async () => {
    const r = await api.get('/recurring-fault-reviews', {
      params: { status: status || undefined },
    });
    return payload(r) || {};
  }, [status]);
  const { data, loading, error, reload } = useFetch(fetcher, [status], {
    refreshInterval: 30000,
    paused: () => anyModal,
  });

  // The charts run on FLEET-WIDE stats, not the filtered table: the table answers "what must I rule on
  // now", the dashboard answers "how is rework trending". Fetched separately so changing a filter never
  // reshapes the trend line under the reader.
  //
  // The two ranking windows are the exception, and each scopes ONE panel: "faults that keep coming back"
  // and "cars that keep coming back". They ride on the same request (the roll-ups all come from one pass
  // over the table) but the API applies each to its own ranking, so the KPIs and the trend line stay put
  // while either changes — and narrowing the cars panel never reshapes the faults panel.
  const [faultWindow, setFaultWindow] = useState({ days: 0, from: null, to: null });
  const [carWindow, setCarWindow] = useState({ days: 0, from: null, to: null });

  const statsFetcher = useCallback(async () => payload(await api.get('/recurring-fault-reviews/stats', {
    params: {
      faults_days: faultWindow.days || undefined,
      faults_from: faultWindow.from || undefined,
      faults_to: faultWindow.to || undefined,
      cars_days: carWindow.days || undefined,
      cars_from: carWindow.from || undefined,
      cars_to: carWindow.to || undefined,
    },
  })) || null, [faultWindow, carWindow]);
  const { data: stats, loading: statsLoading, reload: reloadStats } = useFetch(statsFetcher, [faultWindow, carWindow], {
    refreshInterval: 60000,
    paused: () => anyModal,
  });

  const reviews = useMemo(() => data?.reviews || [], [data]);
  const openCount = useMemo(() => reviews.filter((r) => r.status === 'open').length, [reviews]);

  // Clear (approve) or reject a blocked recurring-fault repair straight from the inbox.
  const gateAction = async (r, decision) => {
    setBusyGate(`${r.id}:${decision}`);
    try {
      await api.post(`/maintenance-tasks/${r.maintenance_task_id}/repair-approval`, { decision });
      toast.success(decision === 'approve' ? t('Repair approved') : t('Repair rejected'));
      reload({ silent: true });
      reloadStats({ silent: true });
    } catch (err) {
      toast.error(err.response?.data?.message || err.response?.data?.msg || t('Could not update the repair gate'));
    } finally {
      setBusyGate(null);
    }
  };

  if (!allowed) {
    return (
      <div className="py-8">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <PageHeader title={t('Recurring Fault Reviews')} />
          <Card className="mt-6">
            <EmptyState title={t("You don't have access")} message={t('This inbox is limited to staff with the recurring-fault review permission.')} />
          </Card>
        </div>
      </div>
    );
  }

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title={t('Recurring Fault Reviews')}
          subtitle={t('Cars that returned with the SAME confirmed fault after a completed repair. Review the evidence and decide why it came back.')}
        />

        {/* Analytics — fleet-wide, deliberately independent of the table filters below. */}
        <RecurringFaultsAnalytics
          stats={stats}
          loading={statsLoading}
          faultWindow={faultWindow}
          onFaultWindowChange={setFaultWindow}
          carWindow={carWindow}
          onCarWindowChange={setCarWindow}
        />

        {/* Filters — scope the case list only. */}
        <div className="flex flex-col gap-3 sm:flex-row">
          <Select className="sm:w-52" value={status} onChange={(e) => setStatus(e.target.value)}>
            {STATUS_FILTERS.map((s) => <option key={s} value={s}>{statusLabel(t, s)}</option>)}
            <option value="">{t('All statuses')}</option>
          </Select>
          <div className="flex items-center text-sm text-slate-500 sm:ms-auto">
            {loading ? '…' : t('{open} open · {shown} shown', { open: num(openCount), shown: num(reviews.length) })}
          </div>
        </div>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <Card>
          <div className="overflow-x-auto">
            <table className="min-w-full border-separate border-spacing-0 text-sm">
              <thead className="bg-slate-50/90">
                <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('Vehicle')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('Fault')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('Previous repair')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('Since repair')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('Times')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('Status')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-end">{t('Actions')}</th>
                </tr>
              </thead>

              {loading ? (
                <TableSkeleton cols={7} />
              ) : (
                <tbody>
                  {reviews.map((r) => (

                    <tr key={r.id} className="cursor-pointer bg-white transition-colors even:bg-slate-50/40 hover:bg-indigo-50/40" onClick={() => setDetail(r)}>
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <div className="font-medium text-slate-900">{plate(r.vehicle)}</div>
                        <div className="text-xs text-slate-400">{carLine(r.vehicle)}</div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5 max-w-xs">
                        <div className="truncate font-medium text-slate-800">{r.symptom}</div>
                        <div className="text-xs text-slate-400">{r.previous_result === 'verified_fixed' ? t('Previously verified fixed') : t('Previously fixed')}</div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">
                        <div>{r.previous_garage || '—'}</div>
                        <div className="text-xs text-slate-400">{r.previous_maintenance_id ? `#${r.previous_maintenance_id}` : ''} · {fmtDate(r.previous_repaired_at) || '—'}</div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">
                        <div>{days(t, r.days_since_repair)}</div>
                        <div className="text-xs text-slate-400">{km(r.distance_since_repair)}</div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <Badge tone={r.occurrence_count > 2 ? 'red' : 'amber'}>{num(r.occurrence_count)}×</Badge>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <div className="flex flex-col items-start gap-1">
                          {r.decision
                            ? <Badge tone={DECISION_TONE[r.decision] || 'gray'}>{decisionLabel(r.decision)}</Badge>
                            : <Badge tone={STATUS_TONE[r.status] || 'gray'}>{statusLabel(t, r.status)}</Badge>}
                          {r.repair_gate === 'pending' && <Badge tone="red">{t('Repair blocked')}</Badge>}
                          {r.repair_gate === 'approved' && <Badge tone="green">{t('Repair approved')}</Badge>}
                          {r.repair_gate === 'rejected' && <Badge tone="gray">{t('Repair rejected')}</Badge>}
                        </div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5" onClick={(e) => e.stopPropagation()}>
                        <div className="flex flex-wrap justify-end gap-2">
                          {r.repair_gate === 'pending' && canDecide && (
                            <>
                              <Button variant="success" size="sm" loading={busyGate === `${r.id}:approve`} onClick={() => gateAction(r, 'approve')}>{t('Approve repair')}</Button>
                              <Button variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" loading={busyGate === `${r.id}:reject`} onClick={() => gateAction(r, 'reject')}>{t('Reject')}</Button>
                            </>
                          )}
                          {!(r.repair_gate === 'pending' && canDecide) && (
                            <span className="text-slate-300">—</span>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              )}
            </table>

            {!loading && reviews.length === 0 && (
              <EmptyState title={t('No recurring faults')} message={t('No confirmed fault has recurred after a completed repair for these filters.')} />
            )}
          </div>
        </Card>
      </div>

      <DetailModal open={!!detail} review={detail} onClose={() => setDetail(null)} />
    </div>
  );
}
