// The second pair of eyes on a supplier bill.
//
// Buying a part and checking the bill for it are two jobs for two people, and until now they were
// one. Whoever bought the part typed the figures, and the only comparison the system ever made was
// between two numbers THAT SAME PERSON had typed — so a total misread off the paper agreed with
// itself and passed. The photo was always stored. Nobody was ever asked to look at it.
//
// This is that missing stage. A checker who did not key the bill sees the photo on one side and the
// figures on the other, and says whether they agree. The backend refuses a self-check (you cannot
// check a bill you recorded) and refuses to call anything checked when no photo was ever attached —
// "verified" would otherwise mean "read the same numbers back", which is the failure this exists to
// stop.
//
// Disagreeing is a first-class outcome, not an error path. A check that can only ever pass is not a
// check; a bill whose photo does not say what was keyed has to be able to end in "disputed" and
// stay visible, rather than force the checker to lie or to leave it unchecked forever.

import { useCallback, useState } from 'react';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { usePermissions } from '../../hooks/usePermissions';
import { useI18n } from '../../i18n/I18nContext';
import { useToast } from '../ui/Toast';
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import Icon from '../ui/Icon';
import Modal from '../ui/Modal';
import { Textarea } from '../ui/Field';
import { aed2, fmtAgo } from '../../lib/format';

const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);

export default function InvoiceMatchDesk({ onChecked }) {
  const { t } = useI18n();
  const { can } = usePermissions();
  const canCheck = can('parts.investigate') || can('maintenance.manage');

  const [checking, setChecking] = useState(null);

  const fetcher = useCallback(async () => {
    const [waiting, disputed] = await Promise.all([
      api.get('/part-invoices', { params: { awaiting_match: 1, per_page: 50 } }),
      api.get('/part-invoices', { params: { disputed: 1, per_page: 50 } }),
    ]);
    return {
      waiting: payload(waiting)?.invoices || [],
      disputed: payload(disputed)?.invoices || [],
    };
  }, []);

  const { data, loading, reload } = useFetch(fetcher, [], { paused: () => !!checking });

  const waiting = data?.waiting || [];
  const disputed = data?.disputed || [];

  if (!canCheck) return null;
  if (loading && !data) return null;
  if (!waiting.length && !disputed.length) return null;

  const done = () => { reload({ silent: true }); onChecked?.(); };

  return (
    <>
      {disputed.length > 0 && (
        <div className="overflow-hidden rounded-2xl border border-rose-200 bg-white shadow-soft">
          <div className="flex items-center gap-2 border-b border-rose-100 bg-rose-50/60 px-5 py-3">
            <Icon.Alert className="h-4 w-4 text-rose-600" />
            <h3 className="text-sm font-semibold text-rose-900">
              {t('{n} bills did not match the paper', { n: disputed.length })}
            </h3>
          </div>
          <ul className="divide-y divide-slate-100">
            {disputed.map((inv) => (
              <li key={inv.id} className="px-5 py-3">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-semibold text-slate-900">{inv.invoice_no || t('No number')}</span>
                  <span className="text-sm text-slate-500">{inv.supplier || '—'}</span>
                  <Badge tone="red">{t('Disputed')}</Badge>
                </div>
                <p className="mt-1 text-sm text-rose-700">{inv.match_note}</p>
                <p className="mt-0.5 text-xs text-slate-400">
                  {t('checked by {name}', { name: inv.matched_by || '—' })}
                  {inv.matched_at ? ` · ${fmtAgo(inv.matched_at)}` : ''}
                </p>
              </li>
            ))}
          </ul>
        </div>
      )}

      {waiting.length > 0 && (
        <div className="overflow-hidden rounded-2xl border border-amber-200 bg-white shadow-soft">
          <div className="flex flex-wrap items-center justify-between gap-2 border-b border-amber-100 bg-amber-50/60 px-5 py-3">
            <div className="flex items-center gap-2">
              <Icon.Invoice className="h-4 w-4 text-amber-600" />
              <h3 className="text-sm font-semibold text-amber-900">
                {t('{n} bills are waiting to be checked against their photo', { n: waiting.length })}
              </h3>
            </div>
            {/* Oldest first — a bill nobody has checked gets more urgent with age, not less. */}
            <span className="text-xs text-amber-800">{t('Oldest first')}</span>
          </div>
          <ul className="divide-y divide-slate-100">
            {waiting.map((inv) => (
              <li key={inv.id} className="flex flex-wrap items-center gap-3 px-5 py-3">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-semibold text-slate-900">{inv.invoice_no || t('No number')}</span>
                    <span className="text-sm text-slate-500">{inv.supplier || '—'}</span>
                    {!inv.photo_url && <Badge tone="gray">{t('No photo')}</Badge>}
                  </div>
                  <p className="mt-0.5 text-xs text-slate-400">
                    {t('keyed by {name}', { name: inv.recorded_by || '—' })}
                    {inv.recorded_at ? ` · ${fmtAgo(inv.recorded_at)}` : ''}
                    {` · ${aed2(inv.total_amount ?? 0)}`}
                  </p>
                </div>
                <Button size="sm" variant="secondary" onClick={() => setChecking(inv)} disabled={!inv.photo_url}>
                  {inv.photo_url ? t('Check it') : t('Needs a photo first')}
                </Button>
              </li>
            ))}
          </ul>
        </div>
      )}

      <MatchModal invoice={checking} onClose={() => setChecking(null)} onDone={done} />
    </>
  );
}

/**
 * The check itself: the paper on the left, the figures on the right, and two answers.
 *
 * The figures are laid out to be READ OFF THE PHOTO in the order they appear on a bill — supplier,
 * number, date, then the total. Anything that asked the checker to hold a number in their head
 * while they scrolled would be re-typing, not checking.
 */
function MatchModal({ invoice, onClose, onDone }) {
  const { t } = useI18n();
  const toast = useToast();
  const [note, setNote] = useState('');
  const [saving, setSaving] = useState(false);

  const submit = async (result) => {
    if (result === 'disputed' && !note.trim()) {
      toast.error(t('Say what does not agree.'));
      return;
    }
    setSaving(true);
    try {
      await api.post(`/part-invoices/${invoice.id}/match`, { result, note: note.trim() || null });
      toast.success(result === 'matches' ? t('Marked as matching the paper') : t('Marked as not matching'));
      setNote('');
      onDone?.();
      onClose();
    } catch (err) {
      const res = err.response?.data;
      toast.error(res?.message || res?.msg || t('Could not record the check'));
    } finally {
      setSaving(false);
    }
  };

  if (!invoice) return null;

  const gap = invoice.stated_total === null || invoice.stated_total === undefined
    ? null
    : Math.round(((invoice.total_amount ?? 0) - invoice.stated_total) * 100) / 100;

  return (
    <Modal
      open={!!invoice}
      onClose={() => !saving && onClose()}
      title={t('Check this bill against its photo')}
      subtitle={t('You are the second pair of eyes. Read the photo, not the figures.')}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>{t('Cancel')}</Button>
          <Button variant="danger" onClick={() => submit('disputed')} loading={saving}>{t('It does not match')}</Button>
          <Button onClick={() => submit('matches')} loading={saving}>{t('It matches')}</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <a href={invoice.photo_url} target="_blank" rel="noreferrer" className="group block" title={t('Open the photo full size')}>
          <img
            src={invoice.photo_url}
            alt={t('Photo of the bill')}
            className="h-72 w-full rounded-xl border border-slate-200 bg-slate-50 object-contain transition group-hover:border-indigo-300"
          />
          <span className="mt-1 block text-xs font-medium text-indigo-600 group-hover:underline">
            {t('Open the photo full size')}
          </span>
        </a>

        <div className="space-y-2 text-sm">
          <Field label={t('Supplier')} value={invoice.supplier || '—'} />
          <Field label={t('Invoice number')} value={invoice.invoice_no || '—'} />
          <Field label={t('Invoice date')} value={invoice.invoice_date || '—'} />
          <Field label={t('Parts keyed on it')} value={aed2(invoice.subtotal ?? 0)} />
          <Field label={t('VAT / tax')} value={aed2(invoice.tax_amount ?? 0)} />
          <div className="flex items-center justify-between border-t border-slate-200 pt-2 font-semibold text-slate-900">
            <span>{t('Total keyed')}</span>
            <span className="tabular-nums">{aed2(invoice.total_amount ?? 0)}</span>
          </div>
          <Field
            label={t('Total they typed off the paper')}
            value={invoice.stated_total === null || invoice.stated_total === undefined ? t('Not recorded') : aed2(invoice.stated_total)}
          />

          {gap !== null && Math.abs(gap) > 0.01 && (
            <div className="rounded-lg bg-amber-50 px-2.5 py-2 text-xs text-amber-800">
              {t('These two already disagree by {gap} — the reason given was: {why}', {
                gap: aed2(Math.abs(gap)),
                why: invoice.variance_explanation || t('nothing'),
              })}
            </div>
          )}

          {invoice.items?.length > 0 && (
            <ul className="mt-2 max-h-32 space-y-1 overflow-y-auto border-t border-slate-100 pt-2 text-xs text-slate-500">
              {invoice.items.map((it) => (
                <li key={it.purchase_id} className="flex justify-between gap-2">
                  <span className="truncate">{it.quantity} × {it.part_name}</span>
                  <span className="tabular-nums">{aed2(it.line_total ?? 0)}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      <div className="mt-4">
        <Textarea
          label={t('What does not agree? (needed only if it does not match)')}
          rows={2}
          value={note}
          onChange={(e) => setNote(e.target.value)}
        />
      </div>
    </Modal>
  );
}

function Field({ label, value }) {
  return (
    <div className="flex items-center justify-between gap-2 text-slate-600">
      <span>{label}</span>
      <span className="truncate text-end font-medium text-slate-800">{value}</span>
    </div>
  );
}
