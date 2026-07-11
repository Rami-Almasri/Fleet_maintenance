// Garage Invoice Portal — the PUBLIC, mobile-first page a garage opens from a secure link (no login) to
// submit its own itemised invoice. It shows only the car + the fault list, reuses the shared Parts/Labor
// editor (with the receipt-total variance gate), takes an optional receipt photo + note, and posts it
// into the team's REVIEW QUEUE — it never touches the ticket's real cost. See GarageInvoiceController.
//
// Uses `publicApi` (no auth token, no 401 redirect) so a garage with no session is never bounced to login.

import { useCallback, useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import publicApi from '../api/publicClient';
import { useI18n } from '../i18n/I18nContext';
import Icon from '../components/ui/Icon';
import { Textarea } from '../components/ui/Field';
import LineItemsEditor, {
  serializeLineItems,
  lineItemsUnlinked,
  lineItemsHaveZeroCost,
  invoiceVarianceBlocked,
} from '../components/workflow/LineItemsEditor';

// A full-screen status card for the non-form states (loading / invalid / expired / already done / success).
function StatusCard({ icon, tone = 'slate', title, children }) {
  const tones = {
    slate: 'text-slate-400', emerald: 'text-emerald-500', amber: 'text-amber-500', red: 'text-red-500',
  };
  return (
    <div className="mx-auto flex min-h-[70vh] max-w-md flex-col items-center justify-center px-6 text-center">
      <div className={`mb-4 ${tones[tone]}`}>{icon}</div>
      <h1 className="text-lg font-semibold text-slate-800">{title}</h1>
      {children && <div className="mt-2 text-sm text-slate-500">{children}</div>}
    </div>
  );
}

export default function GarageInvoicePortal() {
  const { token } = useParams();
  const { t, lang, toggle } = useI18n();

  const [loading, setLoading] = useState(true);
  const [info, setInfo] = useState(null);       // { state, vehicle, findings, categories, garage }
  const [loadError, setLoadError] = useState(false);

  // Form state
  const [lineItems, setLineItems] = useState([]);
  const [receiptTotal, setReceiptTotal] = useState('');
  const [variance, setVariance] = useState('');
  const [note, setNote] = useState('');
  const [photo, setPhoto] = useState(null);
  const [photoPreview, setPhotoPreview] = useState('');

  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [done, setDone] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(false);
    try {
      const { data } = await publicApi.get(`/garage-invoice/${token}`);
      setInfo(data.data || {});
    } catch (_) {
      setLoadError(true);
    } finally {
      setLoading(false);
    }
  }, [token]);

  useEffect(() => { load(); }, [load]);

  const onPickPhoto = (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setPhoto(file);
    setPhotoPreview(URL.createObjectURL(file));
  };

  const rows = useMemo(() => serializeLineItems(lineItems), [lineItems]);
  const blocked =
    rows.length === 0 ||
    lineItemsUnlinked(lineItems) ||
    lineItemsHaveZeroCost(rows) ||
    invoiceVarianceBlocked({ rows, receiptTotal, variance });

  const submit = async () => {
    if (blocked || submitting) return;
    setSubmitting(true);
    setSubmitError('');
    try {
      const fd = new FormData();
      rows.forEach((r, i) => {
        Object.entries(r).forEach(([k, v]) => fd.append(`line_items[${i}][${k}]`, v));
      });
      fd.append('receipt_total', String(Number(receiptTotal)));
      if (variance.trim()) fd.append('variance_explanation', variance.trim());
      if (note.trim()) fd.append('garage_note', note.trim());
      if (photo) fd.append('receipt_photo', photo);

      await publicApi.post(`/garage-invoice/${token}`, fd, { headers: { 'Content-Type': 'multipart/form-data' } });
      setDone(true);
    } catch (err) {
      setSubmitError(err.response?.data?.msg || t('garagePortal.submitError'));
    } finally {
      setSubmitting(false);
    }
  };

  // ── Non-form states ─────────────────────────────────────────────────────────
  if (loading) {
    return <StatusCard icon={<Icon.Clock className="h-10 w-10 animate-pulse" />} title={t('garagePortal.loading')} />;
  }
  if (loadError) {
    return (
      <StatusCard icon={<Icon.Alert className="h-10 w-10" />} tone="red" title={t('garagePortal.loadError')}>
        <button onClick={load} className="mt-3 rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white">{t('garagePortal.retry')}</button>
      </StatusCard>
    );
  }
  if (done || info?.state === 'submitted') {
    return (
      <StatusCard icon={<Icon.Check className="h-12 w-12" />} tone="emerald" title={t('garagePortal.thanksTitle')}>
        {t('garagePortal.thanksBody')}
      </StatusCard>
    );
  }
  if (info?.state === 'expired') {
    return <StatusCard icon={<Icon.Alert className="h-10 w-10" />} tone="amber" title={t('garagePortal.expiredTitle')}>{t('garagePortal.expiredBody')}</StatusCard>;
  }
  if (info?.state !== 'open') {
    // invalid / closed
    return <StatusCard icon={<Icon.Alert className="h-10 w-10" />} tone="red" title={t('garagePortal.invalidTitle')}>{t('garagePortal.invalidBody')}</StatusCard>;
  }

  // ── The form ────────────────────────────────────────────────────────────────
  const v = info.vehicle || {};
  return (
    <div className="min-h-screen bg-slate-100 pb-28">
      {/* Header */}
      <header className="bg-slate-900 px-4 py-4 text-white">
        <div className="mx-auto flex max-w-md items-center justify-between">
          <div className="flex items-center gap-2">
            <Icon.Wrench className="h-5 w-5 text-indigo-300" />
            <span className="text-sm font-semibold">{t('garagePortal.title')}</span>
          </div>
          <button onClick={toggle} className="rounded-lg border border-white/20 px-2 py-1 text-xs font-medium text-white/80">
            {lang === 'en' ? 'ع' : 'EN'}
          </button>
        </div>
      </header>

      <main className="mx-auto max-w-md space-y-4 px-4 py-4">
        {/* Car + garage */}
        <div className="rounded-2xl bg-white p-4 shadow-sm">
          <p className="text-xs uppercase tracking-wide text-slate-400">{t('garagePortal.vehicle')}</p>
          <p className="mt-0.5 text-xl font-bold text-slate-900">{v.plate || '—'}</p>
          {v.car && <p className="text-sm text-slate-500">{v.car}</p>}
          {info.garage && <p className="mt-2 text-xs text-slate-400">{t('garagePortal.forGarage')}: <span className="font-medium text-slate-600">{info.garage}</span></p>}
        </div>

        {/* Fault list */}
        <div className="rounded-2xl bg-white p-4 shadow-sm">
          <p className="mb-2 text-sm font-semibold text-slate-700">{t('garagePortal.faults')}</p>
          {(info.findings || []).length === 0 ? (
            <p className="text-xs text-slate-400">{t('garagePortal.noFaults')}</p>
          ) : (
            <ul className="space-y-1.5">
              {info.findings.map((f, i) => (
                <li key={i} className="flex items-start gap-2 text-sm text-slate-700">
                  <Icon.Alert className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-500" />
                  <span>{f.text}{f.source ? <span className="text-slate-400"> · {f.source}</span> : null}</span>
                </li>
              ))}
            </ul>
          )}
          <p className="mt-2 text-[11px] text-slate-400">{t('garagePortal.linkHint')}</p>
        </div>

        {/* Parts + Labor + Receipt validation (shared editor) */}
        <div className="rounded-2xl bg-white p-4 shadow-sm">
          <LineItemsEditor
            value={lineItems}
            onChange={setLineItems}
            catalog={info.categories || []}
            findings={info.findings || []}
            requireReceipt
            receiptTotal={receiptTotal}
            onReceiptTotalChange={setReceiptTotal}
            variance={variance}
            onVarianceChange={setVariance}
          />
        </div>

        {/* Receipt photo + note */}
        <div className="space-y-3 rounded-2xl bg-white p-4 shadow-sm">
          <div>
            <p className="mb-1.5 text-sm font-medium text-slate-700">{t('garagePortal.receiptPhoto')}</p>
            {photoPreview ? (
              <div className="relative">
                <img src={photoPreview} alt="receipt" className="max-h-56 w-full rounded-lg object-contain ring-1 ring-slate-200" />
                <button onClick={() => { setPhoto(null); setPhotoPreview(''); }} className="mt-2 text-xs font-medium text-red-500">{t('garagePortal.removePhoto')}</button>
              </div>
            ) : (
              <label className="flex cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-300 px-4 py-6 text-sm text-slate-500">
                <Icon.Invoice className="h-5 w-5" />
                {t('garagePortal.uploadReceipt')}
                <input type="file" accept="image/*" capture="environment" onChange={onPickPhoto} className="hidden" />
              </label>
            )}
          </div>
          <Textarea label={t('garagePortal.note')} value={note} onChange={(e) => setNote(e.target.value)} rows={2} placeholder={t('garagePortal.notePlaceholder')} />
        </div>

        {submitError && (
          <div className="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-600 ring-1 ring-inset ring-red-600/10">{submitError}</div>
        )}
      </main>

      {/* Sticky submit */}
      <div className="fixed inset-x-0 bottom-0 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur">
        <div className="mx-auto max-w-md">
          <button
            onClick={submit}
            disabled={blocked || submitting}
            className="w-full rounded-xl bg-indigo-600 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300"
          >
            {submitting ? t('garagePortal.submitting') : t('garagePortal.submit')}
          </button>
          {blocked && rows.length > 0 && (
            <p className="mt-1.5 text-center text-[11px] text-slate-400">{t('garagePortal.blockedHint')}</p>
          )}
        </div>
      </div>
    </div>
  );
}
