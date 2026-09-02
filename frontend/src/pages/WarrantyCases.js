import { useCallback, useMemo, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useToast } from '../components/ui/Toast';
import { usePermissions } from '../hooks/usePermissions';
import { useI18n } from '../i18n/I18nContext';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Icon from '../components/ui/Icon';
import Modal from '../components/ui/Modal';
import { Card, PageHeader, TableSkeleton, EmptyState } from '../components/ui/Misc';
import { Input, Select, Textarea } from '../components/ui/Field';
import { num } from '../lib/format';
import { STAGE_TONE } from '../lib/warranty';

/**
 * The warranty desk — the tiles that say how much the fleet is protected, and the queue of decisions
 * somebody owes.
 *
 * ── THE TILE ORDER IS AN ARGUMENT ──────────────────────────────────────────────────────────────
 *
 * Cover first (how much of the fleet is protected), then WORK (what is waiting on a person), then
 * MONEY (what the whole thing has been worth). That is the order somebody actually reads it in: the
 * first two are leading indicators of the third, and the third is the only one anybody outside this
 * page cares about.
 *
 * ── TWO TILES THAT MOST DASHBOARDS WOULD OMIT, AND WHY THEY ARE HERE ───────────────────────────
 *
 * "Reviewed as ours to pay" — because a review that ends in no claim is the gate WORKING. Without
 * this tile the board reads as if every review that did not produce a claim was wasted effort, and
 * the desk starts feeling that answering honestly is failure.
 *
 * "Bought over a warranty" — the only number here that measures this system's own failure, and
 * exactly the one a feature like this quietly leaves out. If it climbs, either the reviews are too
 * slow or the gate is being used as a speed bump, and both are things the desk needs to see rather
 * than infer from a claim they lost six months later.
 *
 * MONEY IS TWO FIGURES, NEVER ONE. Recovered came back; avoided was never spent because they did the
 * work. A dealer replacing a gearbox for free recovers nothing and avoids a great deal.
 */

const DECIDE_REASONS = [
  'explicitly_covered', 'explicitly_excluded', 'component_cover_live',
  'repair_cover_live', 'not_in_itemised_cover', 'cover_expired',
];

// The rungs a case can be moved TO by hand. `covered` / `not_covered` are absent on purpose: those
// are the coverage DECISION and are taken through the decide action, which records who decided.
const ADVANCE_STAGES = [
  'authorization_requested', 'authorized', 'sent_to_provider',
  'repair_in_progress', 'repair_completed', 'claim_submitted',
];

export default function WarrantyCases() {
  const toast = useToast();
  const { can } = usePermissions();
  const { t } = useI18n();
  const { caseId } = useParams();
  const [searchParams] = useSearchParams();

  const canReview = can('warranty.review');
  const canWork   = can('warranty.claim');
  const canClose  = can('warranty.close');

  const [openOnly, setOpenOnly] = useState(searchParams.get('open') !== '0');
  const vehicleId = searchParams.get('vehicle_id') || '';

  const dashboardFetcher = useCallback(async () => {
    const { data } = await api.get('/warranty/dashboard');
    return data.data || {};
  }, []);
  const { data: dash } = useFetch(dashboardFetcher, []);

  const casesFetcher = useCallback(async () => {
    const params = new URLSearchParams({ per_page: '100', open: openOnly ? '1' : '0' });
    if (vehicleId) params.set('vehicle_id', vehicleId);
    const { data } = await api.get(`/warranty/cases?${params.toString()}`);
    return data.data || {};
  }, [openOnly, vehicleId]);
  const { data, loading, reload } = useFetch(casesFetcher, [openOnly, vehicleId]);

  const cases = useMemo(() => data?.cases || [], [data]);
  const total = data?.meta?.total ?? cases.length;

  // A single case deep-linked from a notification. The board stays underneath so the reviewer keeps
  // their place in the queue rather than being thrown out of it by a bell.
  const focused = caseId ? cases.find((c) => String(c.id) === String(caseId)) : null;

  const [decideFor, setDecideFor] = useState(null);
  const [advanceFor, setAdvanceFor] = useState(null);
  const [recoveryFor, setRecoveryFor] = useState(null);
  const [closeFor, setCloseFor] = useState(null);
  const [busy, setBusy] = useState(false);

  const [form, setForm] = useState({});
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const fail = (err, fallback) => toast.error(err?.response?.data?.message || fallback);

  const act = async (url, body, successKey, failKey, clear) => {
    setBusy(true);
    try {
      await api.post(url, body);
      toast.success(t(successKey));
      clear();
      setForm({});
      reload();
    } catch (err) {
      fail(err, t(failKey));
    } finally {
      setBusy(false);
    }
  };

  const cover = dash?.cover || {};
  const caseCounts = dash?.cases || {};
  const benefit = dash?.benefit || {};

  const tiles = [
    { key: 'activeVehicles', value: cover.vehicles_active, tone: 'text-emerald-600' },
    { key: 'expiringSoon', value: cover.expiring_soon, tone: 'text-amber-600', hint: t('warrantyOps.tileHint.expiringSoon') },
    { key: 'expired', value: cover.expired, tone: 'text-slate-500' },
    { key: 'reviews', value: caseCounts.coverage_reviews_pending, tone: 'text-amber-600' },
    { key: 'openCases', value: caseCounts.open, tone: 'text-indigo-600' },
    { key: 'awaitingProvider', value: caseCounts.awaiting_provider, tone: 'text-blue-600' },
    { key: 'reviewsCleared', value: caseCounts.reviews_cleared, tone: 'text-slate-500', hint: t('warrantyOps.tileHint.reviewsCleared') },
    { key: 'overrides', value: benefit.overrides, tone: 'text-red-600', hint: t('warrantyOps.tileHint.overrides') },
  ];

  return (
    <div className="space-y-4">
      <PageHeader
        title={t('warrantyOps.dashboard.title')}
        subtitle={t('warrantyOps.dashboard.subtitle')}
      />

      {/* ── Cover · work · money ─────────────────────────────────────────────────────────────── */}
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        {tiles.map((tile) => (
          <Card key={tile.key}>
            <div className="p-3">
              <div className="text-xs uppercase tracking-wide text-slate-400">{t(`warrantyOps.tile.${tile.key}`)}</div>
              <div className={`mt-1 text-2xl font-semibold ${tile.tone}`}>{num(tile.value ?? 0)}</div>
              {tile.hint && <p className="mt-1 text-[11px] leading-snug text-slate-400">{tile.hint}</p>}
            </div>
          </Card>
        ))}
      </div>

      {/* Two figures, added only here — see the header note on why they are two columns. */}
      <Card>
        <div className="grid grid-cols-3 divide-x divide-slate-100">
          {[['recovered', benefit.recovered], ['avoided', benefit.avoided], ['benefit', benefit.total]].map(([k, v]) => (
            <div key={k} className="p-3">
              <div className="text-xs uppercase tracking-wide text-slate-400">{t(`warrantyOps.tile.${k}`)}</div>
              <div className="mt-1 text-lg font-semibold text-slate-800">
                {benefit.currency || 'AED'} {num(Number(v || 0))}
              </div>
            </div>
          ))}
        </div>
      </Card>

      {/* Surfaced, never smoothed over: cover that cannot be judged is not cover. */}
      {cover.distance_unknown > 0 && (
        <p className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
          {t('warrantyOps.tileHint.distanceUnknown', { n: cover.distance_unknown })}
        </p>
      )}

      {/* ── The queue ─────────────────────────────────────────────────────────────────────────── */}
      <Card>
        <div className="flex items-center justify-between gap-3 border-b border-slate-100 p-3">
          <h3 className="text-sm font-semibold text-slate-700">
            {t('warrantyOps.cases.title')} · {t('warrantyOps.cases.subtitle', { shown: cases.length, total })}
          </h3>
          <label className="flex items-center gap-2 text-xs text-slate-500">
            <input type="checkbox" checked={openOnly} onChange={(e) => setOpenOnly(e.target.checked)} />
            {t('warrantyOps.cases.openOnly')}
          </label>
        </div>

        {loading ? (
          <TableSkeleton cols={6} />
        ) : cases.length === 0 ? (
          <EmptyState
            title={t('warrantyOps.cases.empty')}
            message={t('warrantyOps.cases.emptyHint')}
            icon={<Icon.Shield className="h-6 w-6" />}
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="px-3 py-2 text-start">{t('warrantyOps.cases.colCase')}</th>
                  <th className="px-3 py-2 text-start">{t('warrantyOps.cases.colVehicle')}</th>
                  <th className="px-3 py-2 text-start">{t('warrantyOps.cases.colSubject')}</th>
                  <th className="px-3 py-2 text-start">{t('warrantyOps.cases.colStage')}</th>
                  <th className="px-3 py-2 text-start">{t('warrantyOps.cases.colProvider')}</th>
                  <th className="px-3 py-2 text-end">{t('warrantyOps.cases.colActions')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {cases.map((c) => (
                  <tr key={c.id} className={focused?.id === c.id ? 'bg-indigo-50/50' : undefined}>
                    <td className="px-3 py-2">
                      <Link to={`/warranty/cases/${c.id}`} className="font-medium text-indigo-600 hover:underline">#{c.id}</Link>
                      <div className="text-[11px] text-slate-400">{t(`warrantyOps.origin.${c.origin || 'manual'}`)}</div>
                    </td>
                    <td className="px-3 py-2 text-slate-700">{c.vehicle?.plate_no || '—'}</td>
                    <td className="px-3 py-2 text-slate-700">{c.subject || '—'}</td>
                    <td className="px-3 py-2">
                      <Badge tone={STAGE_TONE[c.stage] || 'gray'} dot>{t(`warrantyOps.stage.${c.stage}`)}</Badge>
                      {/* An overdue provider is the loudest thing on this row: it is somebody
                          else's silence, and it is the only condition here that gets worse by
                          being ignored. */}
                      {c.provider_overdue && (
                        <div className="mt-1"><Badge tone="red">{t('warrantyOps.cases.overdue')}</Badge></div>
                      )}
                    </td>
                    <td className="px-3 py-2 text-slate-600">
                      {c.warranty?.provider_name || '—'}
                      {c.warranty?.contact_phone && <div className="text-[11px] text-slate-400">{c.warranty.contact_phone}</div>}
                    </td>
                    <td className="px-3 py-2 text-end">
                      <div className="inline-flex gap-2">
                        {canReview && c.stage === 'coverage_review' && (
                          <Button size="sm" variant="primary" onClick={() => { setForm({ verdict: 'covered', reason_code: 'explicitly_covered' }); setDecideFor(c); }}>
                            {t('warrantyOps.cases.decide')}
                          </Button>
                        )}
                        {canWork && c.stage !== 'coverage_review' && c.is_open && (
                          <Button size="sm" variant="secondary" onClick={() => { setForm({ stage: 'authorization_requested' }); setAdvanceFor(c); }}>
                            {t('warrantyOps.cases.advance')}
                          </Button>
                        )}
                        {canClose && c.is_open && (
                          <>
                            <Button size="sm" variant="ghost" onClick={() => { setForm({}); setRecoveryFor(c); }}>
                              {t('warrantyOps.cases.recovery')}
                            </Button>
                            <Button size="sm" variant="ghost" onClick={() => { setForm({}); setCloseFor(c); }}>
                              {t('warrantyOps.cases.close')}
                            </Button>
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* [[traceability-visibility-requirement]] — every page says where its numbers came from. */}
        <p className="border-t border-slate-100 p-3 text-[11px] leading-snug text-slate-400">
          {t('warrantyOps.dashboard.dataOrigin')}
        </p>
      </Card>

      {/* ── The coverage decision ─────────────────────────────────────────────────────────────── */}
      <Modal open={!!decideFor} onClose={() => setDecideFor(null)} title={t('warrantyOps.cases.decideTitle')}>
        <p className="text-xs text-slate-500">{t('warrantyOps.cases.decideHint')}</p>
        <Select label={t('warrantyOps.cases.fieldVerdict')} value={form.verdict || 'covered'} onChange={set('verdict')}>
          <option value="covered">{t('warrantyOps.verdict.covered')}</option>
          <option value="not_covered">{t('warrantyOps.verdict.not_covered')}</option>
        </Select>
        {/* A CODE, not free text: this is what the engine reads next time so the same question is
            never put to a human twice. The sentence still has somewhere to go — the note below. */}
        <Select label={t('warrantyOps.cases.fieldReason')} value={form.reason_code || ''} onChange={set('reason_code')}>
          {DECIDE_REASONS.map((r) => <option key={r} value={r}>{t(`warrantyOps.reason.${r}`, { part: '…', warranty: '…', subject: '…' })}</option>)}
        </Select>
        <Textarea label={t('warrantyOps.cases.fieldNote')} rows={3} value={form.note || ''} onChange={set('note')} />
        <p className="mt-1 text-[11px] text-slate-400">{t('warrantyOps.cases.noteHint')}</p>
        <div className="mt-3 flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDecideFor(null)}>{t('warrantyOps.gate.cancel')}</Button>
          <Button
            disabled={busy || !form.reason_code}
            onClick={() => act(`/warranty/cases/${decideFor.id}/decide`, {
              verdict: form.verdict || 'covered', reason_code: form.reason_code, note: form.note || null,
            }, 'warrantyOps.cases.decided', 'warrantyOps.cases.decideError', () => setDecideFor(null))}
          >
            {t('warrantyOps.cases.decide')}
          </Button>
        </div>
      </Modal>

      {/* ── The provider ladder ───────────────────────────────────────────────────────────────── */}
      <Modal open={!!advanceFor} onClose={() => setAdvanceFor(null)} title={t('warrantyOps.cases.advanceTitle')}>
        <p className="text-xs text-slate-500">{t('warrantyOps.cases.advanceHint')}</p>
        <Select label={t('warrantyOps.cases.fieldStage')} value={form.stage || ''} onChange={set('stage')}>
          {ADVANCE_STAGES.map((s) => <option key={s} value={s}>{t(`warrantyOps.stage.${s}`)}</option>)}
        </Select>
        {form.stage === 'authorized' && (
          <>
            <Input label={t('warrantyOps.cases.fieldAuthRef')} value={form.authorization_ref || ''} onChange={set('authorization_ref')} />
            <p className="mt-1 text-[11px] text-slate-400">{t('warrantyOps.cases.authRefHint')}</p>
          </>
        )}
        {form.stage === 'claim_submitted' && (
          <Input label={t('warrantyOps.cases.fieldClaimRef')} value={form.claim_reference || ''} onChange={set('claim_reference')} />
        )}
        <Input type="date" label={t('warrantyOps.cases.fieldDueOn')} value={form.provider_response_due_on || ''} onChange={set('provider_response_due_on')} />
        <p className="mt-1 text-[11px] text-slate-400">{t('warrantyOps.cases.dueHint')}</p>
        <div className="mt-3 flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setAdvanceFor(null)}>{t('warrantyOps.gate.cancel')}</Button>
          <Button
            disabled={busy || !form.stage}
            onClick={() => act(`/warranty/cases/${advanceFor.id}/advance`, form,
              'warrantyOps.cases.advanced', 'warrantyOps.cases.advanceError', () => setAdvanceFor(null))}
          >
            {t('warrantyOps.cases.advance')}
          </Button>
        </div>
      </Modal>

      {/* ── What it was worth ─────────────────────────────────────────────────────────────────── */}
      <Modal open={!!recoveryFor} onClose={() => setRecoveryFor(null)} title={t('warrantyOps.cases.recoveryTitle')}>
        <p className="text-xs text-slate-500">{t('warrantyOps.cases.recoveryHint')}</p>
        <Input type="number" min="0" step="0.01" label={t('warrantyOps.cases.fieldRecovered')} value={form.recovered_amount || ''} onChange={set('recovered_amount')} />
        <Input type="number" min="0" step="0.01" label={t('warrantyOps.cases.fieldAvoided')} value={form.avoided_amount || ''} onChange={set('avoided_amount')} />
        <Select label={t('warrantyOps.cases.fieldRemedy')} value={form.remedy || ''} onChange={set('remedy')}>
          <option value="">—</option>
          {['replacement', 'repair', 'credit', 'refund', 'none'].map((r) => (
            <option key={r} value={r}>{t(`warranties.remedy.${r}`)}</option>
          ))}
        </Select>
        <div className="mt-3 flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setRecoveryFor(null)}>{t('warrantyOps.gate.cancel')}</Button>
          <Button
            disabled={busy}
            onClick={() => act(`/warranty/cases/${recoveryFor.id}/recovery`, form,
              'warrantyOps.cases.recoveryDone', 'warrantyOps.cases.recoveryError', () => setRecoveryFor(null))}
          >
            {t('warrantyOps.cases.recovery')}
          </Button>
        </div>
      </Modal>

      <Modal open={!!closeFor} onClose={() => setCloseFor(null)} title={t('warrantyOps.cases.closeTitle')}>
        <Textarea label={t('warrantyOps.cases.fieldCloseReason')} rows={3} value={form.reason || ''} onChange={set('reason')} />
        <div className="mt-3 flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setCloseFor(null)}>{t('warrantyOps.gate.cancel')}</Button>
          <Button
            variant="secondary"
            disabled={busy}
            onClick={() => act(`/warranty/cases/${closeFor.id}/close`, { reason: form.reason || null },
              'warrantyOps.cases.closed', 'warrantyOps.cases.closeError', () => setCloseFor(null))}
          >
            {t('warrantyOps.cases.close')}
          </Button>
        </div>
      </Modal>
    </div>
  );
}
