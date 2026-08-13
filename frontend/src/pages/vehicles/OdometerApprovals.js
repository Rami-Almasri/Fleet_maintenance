import { useCallback, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { useToast } from '../../components/ui/Toast';
import { usePermissions } from '../../hooks/usePermissions';
import Badge from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import Modal from '../../components/ui/Modal';
import { Textarea } from '../../components/ui/Field';
import { PageHeader, EmptyState } from '../../components/ui/Misc';
import Skeleton from '../../components/ui/Skeleton';
import DataTable, { SectionCard } from '../../components/ui/Table';
import Tabs from '../../components/ui/Tabs';
import MetricCard, { MetricGrid } from '../../components/ui/MetricCard';
import OdometerApprovalsAnalytics from '../../components/analytics/OdometerApprovalsAnalytics';
import { num, fmtAgo, fmtDate } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

// Odometer Modification Approval — the review board for significant manual odometer edits
// (> OdometerChangeRequest::SIGNIFICANT_DELTA_KM km, either direction). Each pending row shows the
// before/after readings, the car's workflow stage at request time, and the mandatory reason note;
// an admin approves (writes the reading onto the car) or rejects (leaves it untouched).
export default function OdometerApprovals() {
  const toast = useToast();
  const { t } = useI18n();
  const { can } = usePermissions();
  const allowed = can('vehicles.approve_odometer');

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/OdometerChangeRequests');
    return data.data;
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher);

  const stagesFetcher = useCallback(async () => {
    const { data } = await api.get('/OdometerChangeRequests/stage-readings');
    return data.data;
  }, []);
  const { data: stagesData, loading: stagesLoading, error: stagesError } = useFetch(stagesFetcher);

  const [tab, setTab] = useState('approvals'); // 'approvals' | 'stages'
  const [showHistory, setShowHistory] = useState(false);
  const [reviewing, setReviewing] = useState(null); // { request, action: 'approve'|'reject' }
  const [reviewNote, setReviewNote] = useState('');
  const [saving, setSaving] = useState(false);

  // Defensive client-side gate — the route itself already redirects unauthorised users away
  // (see App.js RequirePermission), this just avoids a flash of content if it ever mounts anyway.
  if (!allowed) {
    return (
      <div className="py-16">
        <div className="mx-auto max-w-2xl px-4 text-center">
          <EmptyState title={t('Restricted')} message={t("You don't have permission to review odometer changes.")} />
        </div>
      </div>
    );
  }

  const pending = data?.pending || [];
  const recent = data?.recent || [];
  const threshold = data?.threshold ?? 10;
  const stages = stagesData?.tickets || [];

  const openReview = (request, action) => {
    setReviewing({ request, action });
    setReviewNote('');
  };

  const submitReview = async () => {
    if (!reviewing) return;
    setSaving(true);
    try {
      await api.post(`/OdometerChangeRequests/${reviewing.request.id}/${reviewing.action}`, { note: reviewNote || undefined });
      toast.success(reviewing.action === 'approve' ? t('Odometer change approved') : t('Odometer change rejected'));
      setReviewing(null);
      reload();
    } catch (e) {
      toast.error(e.response?.data?.message || t('Could not save the review'));
    } finally {
      setSaving(false);
    }
  };

  const vehicleCell = (r) => (
    <Link to={`/vehicles/${r.vehicle_id}`} className="block">
      <span className="font-medium text-indigo-600 hover:text-indigo-700">{r.plate_no || `#${r.vehicle_id}`}</span>
      <div className="text-xs text-slate-400">{[r.make, r.model].filter(Boolean).join(' ') || r.vin || '—'}</div>
    </Link>
  );

  const pendingColumns = [
    { key: 'vehicle', header: t('Vehicle / Plate'), render: vehicleCell },
    {
      key: 'requester', header: t('Requester'),
      render: (r) => (
        <div>
          <div className="text-slate-700">{r.requested_by || '—'}</div>
          {/* A stage capture reads differently from a manual edit: the reading is ALREADY on the car and
              the ticket, and this row is the after-the-fact audit of it. Say which ticket + which stage,
              so the reviewer can go and look at the job rather than guess. */}
          {r.already_applied ? (
            <div className="text-xs text-amber-700">
              {t('Recorded on ticket #{id} · {stage}', { id: r.maintenance_id, stage: r.stage_label })}
            </div>
          ) : (
            <div className="text-xs text-slate-400">{r.workflow_stage_label}</div>
          )}
        </div>
      ),
    },
    {
      key: 'before', header: t('Before'), align: 'right', cellClass: 'tabular-nums',
      render: (r) => r.previous_odometer != null ? `${num(r.previous_odometer)} km` : '—',
    },
    {
      key: 'after', header: t('After'), align: 'right', cellClass: 'tabular-nums font-semibold text-slate-900',
      render: (r) => `${num(r.requested_odometer)} km`,
    },
    {
      key: 'change',
      header: t('Change'),
      align: 'right',
      tooltip: t("Held for review because it's more than {km} km from the previous reading, either direction.", { km: threshold }),
      render: (r) => (
        <Badge tone={r.delta < 0 ? 'red' : 'amber'}>
          {r.delta > 0 ? '+' : ''}{num(r.delta)} km
        </Badge>
      ),
    },
    {
      key: 'note', header: t('Reason / Note'), cellClass: 'max-w-xs whitespace-pre-wrap text-slate-600',
      render: (r) => r.note || <span className="text-slate-300">—</span>,
    },
    {
      key: 'timestamp', header: t('Timestamp'),
      render: (r) => <span title={fmtDate(r.created_at)}>{fmtAgo(r.created_at)}</span>,
    },
    {
      key: 'actions', header: t('Actions'), align: 'right', headerClass: 'sr-only',
      render: (r) => (
        <div className="flex justify-end gap-2">
          <Button size="sm" variant="danger" onClick={() => openReview(r, 'reject')}>{t('Reject')}</Button>
          <Button size="sm" variant="success" onClick={() => openReview(r, 'approve')}>{t('Approve')}</Button>
        </div>
      ),
    },
  ];

  const historyColumns = [
    { key: 'vehicle', header: t('Vehicle / Plate'), render: vehicleCell },
    { key: 'requester', header: t('Requester'), render: (r) => r.requested_by || '—' },
    {
      key: 'change', header: t('Change'), align: 'right',
      render: (r) => (
        <>
          <span className="text-slate-500 tabular-nums">{r.previous_odometer != null ? num(r.previous_odometer) : '—'} → {num(r.requested_odometer)} km</span>{' '}
          <Badge tone={r.delta < 0 ? 'red' : 'amber'}>{r.delta > 0 ? '+' : ''}{num(r.delta)} km</Badge>
        </>
      ),
    },
    { key: 'note', header: t('Reason / Note'), cellClass: 'max-w-xs whitespace-pre-wrap text-slate-600', render: (r) => r.note || '—' },
    {
      key: 'status', header: t('Decision'),
      render: (r) => <Badge tone={r.status === 'approved' ? 'emerald' : 'red'}>{r.status === 'approved' ? t('Approved') : t('Rejected')}</Badge>,
    },
    {
      key: 'reviewed', header: t('Reviewed'),
      render: (r) => (
        <div>
          <div className="text-slate-700">{r.reviewed_by || '—'}</div>
          <div className="text-xs text-slate-400" title={fmtDate(r.reviewed_at)}>{fmtAgo(r.reviewed_at)}</div>
        </div>
      ),
    },
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title={t('Odometer Change Approvals')}
          subtitle={t('Manual odometer edits over {km} km are held here for review before they apply to the car.', { km: threshold })}
        >
          <Link to="/vehicles" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">
            {t('Vehicles')} <span className="inline-block rtl:-scale-x-100">→</span>
          </Link>
        </PageHeader>

        {/* Analytics — the queue plus its recent history, before the tabbed detail. */}
        {!loading && <OdometerApprovalsAnalytics pending={pending} recent={recent} />}

        <Tabs
          active={tab}
          onChange={setTab}
          ariaLabel={t('Odometer views')}
          tabs={[
            { key: 'approvals', label: t('Approvals'), badge: pending.length || null },
            { key: 'stages', label: t('Stage Odometers'), badge: stages.length || null },
          ]}
        />

        {tab === 'approvals' && (
          <div className="space-y-6">
            {error && (
              <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
            )}

            <MetricGrid cols={2}>
              <MetricCard label={t('Awaiting approval')} value={pending.length} tone="amber" hint={t('Requests needing a decision')} />
              <MetricCard label={t('Significance threshold')} value={`±${num(threshold)} km`} tone="slate" hint={t('Edits beyond this are held for review')} />
            </MetricGrid>

            <SectionCard title={t('Pending requests')} subtitle={t('Oldest first — review and decide.')}>
              <DataTable
                columns={pendingColumns}
                rows={pending}
                rowKey={(r) => r.id}
                loading={loading}
                highlightRow={(r) => Math.abs(r.delta) > threshold * 5}
                empty={t('No pending odometer changes.')}
              />
            </SectionCard>

            <SectionCard
              title={t('Recently reviewed')}
              subtitle={t('Audit trail of past approve/reject decisions.')}
              actions={
                <Button size="sm" variant="secondary" onClick={() => setShowHistory((v) => !v)}>
                  {showHistory ? t('Hide') : t('Show')} ({recent.length})
                </Button>
              }
            >
              {showHistory && (
                <DataTable
                  columns={historyColumns}
                  rows={recent}
                  rowKey={(r) => r.id}
                  loading={loading}
                  empty={t('No reviewed odometer changes yet.')}
                />
              )}
            </SectionCard>
          </div>
        )}

        {tab === 'stages' && (
          <div className="space-y-6">
            {stagesError && (
              <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{stagesError}</div>
            )}

            <SectionCard
              title={t('Odometer by stage')}
              subtitle={t("Each car's odometer as it was captured at every workflow stage — inspection test drive, breakdown report, pickup intake, dispatch to garage, at garage, garage return and re-inspection. A red step ran backwards versus the stage before it.")}
            >
              {stagesLoading ? (
                <div className="p-6"><Skeleton className="h-24 w-full" /></div>
              ) : stages.length === 0 ? (
                <EmptyState
                  title={t('No stage readings yet')}
                  message={t("Once tickets capture odometer readings through the maintenance workflow, each car's per-stage trail appears here.")}
                />
              ) : (
                <div className="divide-y divide-slate-100">
                  {stages.map((tk) => (
                    <StageTrail key={tk.id} ticket={tk} />
                  ))}
                </div>
              )}
            </SectionCard>
          </div>
        )}
      </div>

      <Modal
        open={!!reviewing}
        onClose={() => !saving && setReviewing(null)}
        title={reviewing?.action === 'approve' ? t('Approve odometer change') : t('Reject odometer change')}
        subtitle={reviewing ? `${reviewing.request.plate_no || '#' + reviewing.request.vehicle_id}: ${reviewing.request.previous_odometer ?? '—'} → ${reviewing.request.requested_odometer} km` : ''}
        footer={
          <>
            <Button variant="secondary" onClick={() => setReviewing(null)} disabled={saving}>{t('Cancel')}</Button>
            <Button
              variant={reviewing?.action === 'reject' ? 'danger' : 'success'}
              onClick={submitReview}
              loading={saving}
            >
              {reviewing?.action === 'approve'
                ? (reviewing?.request.awaiting_apply ? t('Approve & Apply') : t('Confirm reading'))
                : t('Reject')}
            </Button>
          </>
        }
      >
        {reviewing && (
          <div className="space-y-3">
            <p className="text-sm text-slate-600">
              {t('Requester’s reason: “{note}”', { note: reviewing.request.note })}
            </p>
            {/* Spell out what the button actually does — there are three cases, not two. A manual edit is
                still waiting to be applied. A stage capture that ran FORWARD is already live on the car,
                so rejecting it is what winds the odometer back. A stage capture that ran BACKWARD was
                accepted but never reached the car (the heal only moves forward), so there approving is
                the act that corrects the mileage down — which is the opposite of the sentence above it.
                `awaiting_apply` is the flag that tells the last two apart. */}
            {reviewing.request.already_applied && !reviewing.request.awaiting_apply && (
              <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                {reviewing.action === 'approve'
                  ? t('This reading was already recorded when the driver captured it. Confirming just closes the review — nothing on the car changes.')
                  : t('This reading was already recorded when the driver captured it. Rejecting winds the car back to {km} km.', { km: reviewing.request.previous_odometer })}
              </p>
            )}
            {reviewing.request.already_applied && reviewing.request.awaiting_apply && (
              <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                {reviewing.action === 'approve'
                  ? t('The dial read LOWER than our records, so the car was left on {km} km. Approving sets it to the reading below.', { km: reviewing.request.previous_odometer })
                  : t('The car is still on {km} km — this reading was never applied. Rejecting simply closes the review and nothing changes.', { km: reviewing.request.previous_odometer })}
              </p>
            )}
            <Textarea
              label={reviewing.action === 'approve' ? t('Approval note (optional)') : t('Reason for rejection (optional)')}
              value={reviewNote}
              onChange={(e) => setReviewNote(e.target.value)}
              placeholder={reviewing.action === 'approve' ? t('Any context for the record…') : t('Why is this being rejected? (the requester can be notified)')}
            />
          </div>
        )}
      </Modal>
    </div>
  );
}

// One car's odometer walk through the maintenance workflow: a header row (plate + where it is now)
// followed by a horizontal chain of the stages that actually captured a reading, each showing the km
// and its change versus the previous stage. A backward step (delta < 0) is impossible, so it's flagged red.
function StageTrail({ ticket }) {
  const { t } = useI18n();
  const stages = ticket.stages || [];

  const deltaTone = (d) => {
    if (d == null) return 'text-slate-400';
    if (d < 0) return 'text-red-600 font-semibold';
    if (d === 0) return 'text-slate-400';
    return 'text-emerald-600';
  };

  return (
    <div className="py-4">
      <div className="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <div>
          <Link to={`/vehicles/${ticket.vehicle_id}`} className="font-semibold text-indigo-600 hover:text-indigo-700">
            {ticket.plate_no || `#${ticket.vehicle_id}`}
          </Link>
          <span className="ms-2 text-sm text-slate-400">{[ticket.make, ticket.model].filter(Boolean).join(' ') || ticket.vin || '—'}</span>
          {ticket.issue && <span className="ms-2 text-xs text-slate-400">· {ticket.issue}</span>}
        </div>
        {ticket.current_odometer != null && (
          <span className="text-xs text-slate-400">
            {t('Current:')} <span className="tabular-nums font-medium text-slate-600">{num(ticket.current_odometer)} km</span>
          </span>
        )}
      </div>

      <div className="flex flex-wrap items-stretch gap-2">
        {stages.map((s, i) => (
          <div key={s.key} className="flex items-stretch gap-2">
            {s.captured ? (
              <div className="min-w-[7.5rem] rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                <div className="text-[11px] font-medium uppercase tracking-wide text-slate-400">{s.label}</div>
                <div className="tabular-nums text-base font-semibold text-slate-900">{num(s.reading)}<span className="ms-1 text-xs font-normal text-slate-400">{t('km')}</span></div>
                {s.delta != null && (
                  <div className={`text-xs tabular-nums ${deltaTone(s.delta)}`}>
                    {s.delta > 0 ? '+' : ''}{num(s.delta)} km
                  </div>
                )}
              </div>
            ) : (
              // Stage never reached / no reading captured — shown dimmed so the full lifecycle is always visible.
              <div className="min-w-[7.5rem] rounded-lg border border-dashed border-slate-200 bg-white px-3 py-2">
                <div className="text-[11px] font-medium uppercase tracking-wide text-slate-300">{s.label}</div>
                <div className="text-base font-semibold text-slate-300">—</div>
                <div className="text-xs text-slate-300">{t('not captured')}</div>
              </div>
            )}
            {i < stages.length - 1 && <div className="flex items-center text-slate-300 rtl:-scale-x-100">→</div>}
          </div>
        ))}
      </div>
    </div>
  );
}
