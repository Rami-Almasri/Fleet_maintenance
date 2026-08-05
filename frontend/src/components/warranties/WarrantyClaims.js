import { useCallback, useState } from 'react';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import Modal from '../ui/Modal';
import { Input, Select, Textarea } from '../ui/Field';
import { useToast } from '../ui/Toast';
import { useI18n } from '../../i18n/I18nContext';
import { num } from '../../lib/format';

/**
 * Claims against one warranty — filing them, and recording what the counterparty said.
 *
 * TWO RULES THIS COMPONENT EXISTS TO HOLD.
 *
 * 1. THE WINDOW VERDICT IS HISTORY, NOT A FIELD. `was_in_window` and `window_evidence` are computed
 *    by the server at the moment a claim is filed, against the odometer of that day, and frozen.
 *    Nothing here can send them and nothing here can edit them once written — they are rendered
 *    read-only, always. If they could drift, "was it in date when it failed" would change its answer
 *    a year later when the car has done another 40,000 km, which is exactly what they exist to
 *    prevent.
 *
 * 2. A RESOLVED CLAIM IS CLOSED. Once the counterparty has answered, the outcome controls disappear.
 *    Re-adjudicating a settled claim would rewrite the record of what a supplier actually did, and
 *    that record is the only evidence the next contract negotiation has.
 */

const OUTCOME_TONE = { pending: 'amber', accepted: 'green', rejected: 'red', partial: 'blue' };

export default function WarrantyClaims({ warranty, canFile, canAdjudicate, onClose, onChanged }) {
  const { t } = useI18n();
  const toast = useToast();

  const fetcher = useCallback(async () => {
    const { data } = await api.get(`/warranties/${warranty.id}/claims`);
    return data.data || [];
  }, [warranty.id]);
  const { data: claims, loading, reload } = useFetch(fetcher, [warranty.id]);

  const [filing, setFiling] = useState(false);
  const [form, setForm] = useState({ failure_description: '', claimed_on: new Date().toISOString().slice(0, 10), claim_odometer: '' });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);

  const [resolving, setResolving] = useState(null);
  const [outcome, setOutcome] = useState({ outcome: 'accepted', outcome_reason: '', recovered_amount: '', remedy: 'replacement' });

  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));
  const setOut = (k, v) => setOutcome((o) => ({ ...o, [k]: v }));

  const apiError = (err, fallback) => {
    if (!err?.response) return t('warranties.networkError');
    if (err.response.status === 403) return t('warranties.permissionDenied');
    return err.response.data?.message || fallback;
  };

  const file = async () => {
    setBusy(true);
    setErrors({});
    try {
      await api.post(`/warranties/${warranty.id}/claims`, {
        failure_description: form.failure_description.trim(),
        claimed_on: form.claimed_on || null,
        claim_odometer: form.claim_odometer === '' ? null : Number(form.claim_odometer),
      });
      toast.success(t('warranties.claimFiled'));
      setFiling(false);
      setForm({ failure_description: '', claimed_on: new Date().toISOString().slice(0, 10), claim_odometer: '' });
      reload();
      onChanged?.();
    } catch (err) {
      const res = err?.response?.data;
      if (res?.errors) setErrors(res.errors);
      else toast.error(apiError(err, t('warranties.claimError')));
    } finally {
      setBusy(false);
    }
  };

  const resolve = async () => {
    setBusy(true);
    try {
      await api.post(`/warranty-claims/${resolving.id}/resolve`, {
        outcome: outcome.outcome,
        outcome_reason: outcome.outcome_reason?.trim() || null,
        recovered_amount: outcome.recovered_amount === '' ? null : Number(outcome.recovered_amount),
        remedy: outcome.remedy || null,
      });
      toast.success(t('warranties.claimResolved'));
      setResolving(null);
      reload();
      onChanged?.();
    } catch (err) {
      toast.error(apiError(err, t('warranties.claimResolveError')));
    } finally {
      setBusy(false);
    }
  };

  const rows = claims || [];

  return (
    <Modal open onClose={onClose} title={t('warranties.claimsTitle')} subtitle={warranty.subject} size="lg"
      footer={<Button variant="secondary" onClick={onClose}>{t('common.close')}</Button>}
    >
      <div className="space-y-4">
        {/* Filing outside the window is ALLOWED and recorded as such — a claim lost on distance is
            exactly the evidence needed when the next supply contract is written. */}
        {canFile && !filing && (
          <Button size="sm" onClick={() => setFiling(true)}>{t('warranties.fileClaim')}</Button>
        )}

        {filing && (
          <div className="space-y-3 rounded-lg border border-slate-200 p-3">
            <Textarea
              label={t('warranties.fieldFailure')} required rows={2}
              value={form.failure_description}
              error={errors.failure_description?.[0]}
              onChange={(e) => set('failure_description', e.target.value)}
            />
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <Input type="date" label={t('warranties.fieldClaimedOn')} value={form.claimed_on} onChange={(e) => set('claimed_on', e.target.value)} />
              <Input type="number" min="0" label={t('warranties.fieldClaimOdometer')} value={form.claim_odometer} onChange={(e) => set('claim_odometer', e.target.value)} />
            </div>
            <p className="text-xs text-slate-500">{t('warranties.claimWindowHint')}</p>
            <div className="flex gap-2">
              <Button size="sm" onClick={file} loading={busy} disabled={!form.failure_description.trim()}>{t('warranties.submitClaim')}</Button>
              <Button size="sm" variant="secondary" onClick={() => setFiling(false)} disabled={busy}>{t('common.cancel')}</Button>
            </div>
          </div>
        )}

        {loading && <p className="text-sm text-slate-500">{t('warranties.loading')}</p>}

        {!loading && rows.length === 0 && (
          <p className="text-sm text-slate-500">{t('warranties.noClaims')}</p>
        )}

        <div className="space-y-3">
          {rows.map((c) => (
            <div key={c.id} className="rounded-lg border border-slate-200 p-3">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="text-sm text-slate-800" dir="auto">{c.failure_description}</p>
                  <p className="mt-0.5 text-xs text-slate-500">
                    {c.claimed_on}{c.claim_odometer ? ` · ${num(c.claim_odometer)} km` : ''}
                    {c.created_by_name ? ` · ${c.created_by_name}` : ''}
                  </p>
                </div>
                <Badge tone={OUTCOME_TONE[c.outcome] || 'gray'}>{t(`warranties.outcome.${c.outcome}`)}</Badge>
              </div>

              {/* FROZEN AT CLAIM TIME. Read-only by design — never an input, never editable. */}
              <div className="mt-2 rounded bg-slate-50 px-2.5 py-1.5 text-xs ring-1 ring-inset ring-slate-200">
                <span className={c.was_in_window ? 'font-medium text-emerald-700' : 'font-medium text-red-700'}>
                  {c.was_in_window ? t('warranties.wasInWindow') : t('warranties.wasOutOfWindow')}
                </span>
                <span className="text-slate-600"> — {c.window_evidence}</span>
                <div className="mt-0.5 text-slate-400">{t('warranties.frozenNote')}</div>
              </div>

              {c.outcome_reason && (
                <p className="mt-2 text-xs text-slate-600">
                  <span className="font-medium">{t('warranties.theirReason')}:</span> {c.outcome_reason}
                </p>
              )}
              {c.recovered_amount != null && (
                <p className="mt-1 text-xs text-slate-600">
                  {t('warranties.recovered', { amount: num(c.recovered_amount), currency: c.currency })}
                  {c.remedy ? ` · ${t(`warranties.remedy.${c.remedy}`)}` : ''}
                </p>
              )}

              {/* A settled claim is closed. Re-adjudicating would rewrite what a supplier did. */}
              {canAdjudicate && c.outcome === 'pending' && (
                <Button size="sm" variant="secondary" className="mt-2" onClick={() => setResolving(c)}>
                  {t('warranties.recordOutcome')}
                </Button>
              )}
            </div>
          ))}
        </div>
      </div>

      {resolving && (
        <Modal
          open
          onClose={() => !busy && setResolving(null)}
          title={t('warranties.outcomeTitle')}
          size="sm"
          footer={
            <>
              <Button variant="secondary" onClick={() => setResolving(null)} disabled={busy}>{t('common.cancel')}</Button>
              <Button
                onClick={resolve}
                loading={busy}
                disabled={outcome.outcome === 'rejected' && !outcome.outcome_reason.trim()}
              >
                {t('common.save')}
              </Button>
            </>
          }
        >
          <div className="space-y-3">
            <Select label={t('warranties.fieldOutcome')} value={outcome.outcome} onChange={(e) => setOut('outcome', e.target.value)}>
              {['accepted', 'partial', 'rejected'].map((o) => (
                <option key={o} value={o}>{t(`warranties.outcome.${o}`)}</option>
              ))}
            </Select>

            {/* Their reason is the entire value of a lost claim: it turns "they refused" into
                "they refuse corrosion claims after six months", which the next contract can answer. */}
            <Textarea
              label={t('warranties.fieldOutcomeReason')}
              required={outcome.outcome === 'rejected'}
              rows={2}
              value={outcome.outcome_reason}
              onChange={(e) => setOut('outcome_reason', e.target.value)}
            />

            {outcome.outcome !== 'rejected' && (
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Input type="number" min="0" step="0.01" label={t('warranties.fieldRecovered')} value={outcome.recovered_amount} onChange={(e) => setOut('recovered_amount', e.target.value)} />
                <Select label={t('warranties.fieldRemedy')} value={outcome.remedy} onChange={(e) => setOut('remedy', e.target.value)}>
                  {['replacement', 'repair', 'credit', 'refund', 'none'].map((r) => (
                    <option key={r} value={r}>{t(`warranties.remedy.${r}`)}</option>
                  ))}
                </Select>
              </div>
            )}

            {outcome.outcome === 'rejected' && (
              <p className="text-xs text-slate-500">{t('warranties.rejectedNoMoney')}</p>
            )}
          </div>
        </Modal>
      )}
    </Modal>
  );
}
