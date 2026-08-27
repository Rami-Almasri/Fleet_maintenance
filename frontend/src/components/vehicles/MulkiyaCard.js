// The car's Mulkiya — the UAE Vehicle Licence (رخصة مركبة) scan, on the vehicle profile.
//
// One card per car, changeable. Uploading a replacement does not overwrite the old scan: the API
// supersedes it, and the previous licences stay behind "Previous licences" as the renewal trail —
// because a card is re-issued on every renewal, plate change or ownership edit, and the old one is
// what the car was operated under until then.
//
// The card does NOT re-type the dates printed on the licence. Registration and insurance expiry
// already live on the vehicle_registrations record synced from OfficeManager and are shown by the
// overview dashboard's Registration & Insurance block; this is the scan itself, for the moment
// someone actually has to see the document.
//
// Read needs vehicles.view (it renders nothing on a 403); every write needs vehicles.manage, so a
// viewer sees the licence but no upload controls.

import { useCallback, useEffect, useRef, useState } from 'react';
import { useI18n } from '../../i18n/I18nContext';
import { usePermissions } from '../../hooks/usePermissions';
import { useToast } from '../ui/Toast';
import { SectionCard } from '../ui/Table';
import Button from '../ui/Button';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import { Skeleton } from '../ui/Skeleton';
import { formatBytes } from '../../lib/imageCompression';
import storageSrc from '../../lib/storageUrl';
import { getVehicleDocuments, uploadVehicleDocument, deleteVehicleDocument, MULKIYA } from '../../lib/vehicleDocuments';

// What the file picker offers. Mirrors the API's `mimes:` rule — keep the two in step.
const ACCEPT = 'image/jpeg,image/png,image/webp,image/heic,application/pdf';

const stamp = (iso, locale) => {
  if (!iso) return null;
  const d = new Date(iso);
  return isNaN(d.getTime()) ? null : d.toLocaleString(locale === 'ar' ? 'ar-AE' : 'en-GB', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
  });
};

// "Filed by Rami · 27 Aug 2026, 14:02 · 480 KB" — who put this scan on the record and when.
function Provenance({ doc }) {
  const { t, lang } = useI18n();
  const when = stamp(doc.uploaded_at, lang);
  const bits = [
    doc.uploaded_by ? t('Filed by {name}', { name: doc.uploaded_by }) : null,
    when,
    doc.file_size ? formatBytes(doc.file_size) : null,
  ].filter(Boolean);

  return <p className="text-xs text-slate-500">{bits.join(' · ') || t('No upload details recorded')}</p>;
}

export default function MulkiyaCard({ vehicleId, plateHint }) {
  const { t, tp, lang } = useI18n();
  const toast = useToast();
  const { can } = usePermissions();
  const canManage = can('vehicles.manage');

  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [denied, setDenied] = useState(false);   // 403: render nothing rather than an error box
  const [busy, setBusy] = useState(false);
  const [dragging, setDragging] = useState(false);
  const [showHistory, setShowHistory] = useState(false);
  const [zoomed, setZoomed] = useState(null);
  const inputRef = useRef(null);

  const load = useCallback(async () => {
    if (!vehicleId) return;
    setLoading(true);
    try {
      setData(await getVehicleDocuments(vehicleId, MULKIYA));
    } catch (e) {
      if (e.response?.status === 403) setDenied(true);
    } finally {
      setLoading(false);
    }
  }, [vehicleId]);

  useEffect(() => { load(); }, [load]);

  const upload = async (file) => {
    if (!file || busy) return;
    setBusy(true);
    try {
      const fresh = await uploadVehicleDocument(vehicleId, file, { kind: MULKIYA });
      setData(fresh);
      toast.success(data?.current ? t('Mulkiya replaced — the previous licence is kept in the history') : t('Mulkiya saved'));
    } catch (e) {
      toast.error(e.response?.data?.message || t('Could not upload the Mulkiya'));
    } finally {
      setBusy(false);
    }
  };

  const remove = async (documentId) => {
    if (busy) return;
    setBusy(true);
    try {
      setData(await deleteVehicleDocument(vehicleId, documentId));
      toast.success(t('Scan removed'));
    } catch (e) {
      toast.error(e.response?.data?.message || t('Could not remove the scan'));
    } finally {
      setBusy(false);
    }
  };

  const onDrop = (e) => {
    e.preventDefault();
    setDragging(false);
    if (!canManage) return;
    const file = e.dataTransfer?.files?.[0];
    if (file) upload(file);
  };

  if (denied) return null;

  const current = data?.current || null;
  const history = data?.history || [];
  const src = current && !current.is_pdf ? storageSrc(current.url) : null;

  return (
    <>
      <SectionCard
        title={t('Mulkiya')}
        subtitle={t('The car’s UAE Vehicle Licence — the scan currently in force')}
        actions={canManage ? (
          <div className="flex items-center gap-2">
            <Button
              size="sm"
              variant={current ? 'secondary' : 'primary'}
              loading={busy}
              onClick={() => inputRef.current?.click()}
            >
              {current ? <Icon.Refresh className="h-4 w-4" /> : <Icon.Plus className="h-4 w-4" />}
              {current ? t('Change photo') : t('Add photo')}
            </Button>
            <input
              ref={inputRef}
              type="file"
              accept={ACCEPT}
              onChange={(e) => {
                const file = e.target.files?.[0];
                e.target.value = ''; // so re-picking the same file still fires onChange
                if (file) upload(file);
              }}
              className="hidden"
            />
          </div>
        ) : null}
      >
        {loading ? (
          <div className="space-y-3 p-4">
            <Skeleton className="h-48 w-full" />
            <Skeleton className="h-3 w-1/3" />
          </div>
        ) : (
          <div
            className={`p-4 ${dragging ? 'rounded-xl bg-indigo-50/70 ring-2 ring-inset ring-indigo-400' : ''}`}
            onDragOver={(e) => { if (canManage) { e.preventDefault(); setDragging(true); } }}
            onDragLeave={() => setDragging(false)}
            onDrop={onDrop}
          >
            {current ? (
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-[minmax(0,320px)_1fr]">
                {/* The scan. Portrait card, so object-contain in a fixed frame — never cropped,
                    because the cropped-off corner is exactly where the chassis no. sits. */}
                {src ? (
                  <button
                    type="button"
                    onClick={() => setZoomed(src)}
                    className="group block overflow-hidden rounded-xl bg-slate-50 ring-1 ring-slate-200 transition hover:ring-indigo-400"
                    title={t('Click to view full size')}
                  >
                    <img
                      src={src}
                      alt={t('Mulkiya for {plate}', { plate: plateHint || data?.vehicle?.plate_no || '' })}
                      className="max-h-80 w-full object-contain transition group-hover:scale-[1.02]"
                    />
                  </button>
                ) : (
                  <div className="flex max-h-80 flex-col items-center justify-center gap-2 rounded-xl bg-slate-50 p-8 text-center ring-1 ring-slate-200">
                    <Icon.Invoice className="h-8 w-8 text-slate-400" />
                    <p className="text-xs text-slate-500">
                      {current.is_pdf ? t('PDF document — no inline preview') : t('Preview unavailable')}
                    </p>
                  </div>
                )}

                <div className="flex flex-col justify-between gap-4">
                  <div className="space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                      <Badge tone="emerald">{t('In force')}</Badge>
                      {current.is_pdf && <Badge tone="slate">{t('PDF')}</Badge>}
                    </div>
                    <Provenance doc={current} />
                    {current.original_name && (
                      <p className="truncate text-xs text-slate-400" title={current.original_name}>{current.original_name}</p>
                    )}
                    {current.note && <p className="text-sm text-slate-600">{current.note}</p>}
                  </div>

                  <div className="flex flex-wrap items-center gap-2">
                    {current.url && (
                      <a
                        href={storageSrc(current.url)}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold text-indigo-600 ring-1 ring-inset ring-indigo-200 transition hover:bg-indigo-50"
                      >
                        <Icon.ArrowUpRight className="h-4 w-4" />
                        {t('Open full size')}
                      </a>
                    )}
                    {canManage && (
                      <Button size="sm" variant="ghost" loading={busy} onClick={() => remove(current.id)}>
                        {t('Remove')}
                      </Button>
                    )}
                  </div>
                </div>
              </div>
            ) : (
              // Nothing on file. State the gap plainly — a car without its licence on the record is
              // a real operational hole, not an empty widget.
              <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-slate-300 px-6 py-10 text-center">
                <Icon.Card className="h-9 w-9 text-slate-300" />
                <div>
                  <p className="text-sm font-semibold text-slate-700">{t('No Mulkiya on file for this car')}</p>
                  <p className="mt-0.5 text-xs text-slate-500">
                    {canManage
                      ? t('Upload a photo or PDF of the licence card — drag it here, or use Add photo.')
                      : t('Ask a fleet manager to add the licence scan.')}
                  </p>
                </div>
                {canManage && (
                  <Button size="sm" loading={busy} onClick={() => inputRef.current?.click()}>
                    <Icon.Camera className="h-4 w-4" />
                    {t('Add photo')}
                  </Button>
                )}
              </div>
            )}

            {/* The renewal trail — every licence this car carried before the current one. */}
            {history.length > 0 && (
              <div className="mt-4 border-t border-slate-100 pt-3">
                <button
                  type="button"
                  onClick={() => setShowHistory((v) => !v)}
                  className="flex w-full items-center justify-between text-xs font-semibold text-slate-500 transition hover:text-slate-700"
                >
                  <span>{tp('vehicleProfile.mulkiya.previousCount', history.length)}</span>
                  <Icon.ChevronDown className={`h-4 w-4 transition ${showHistory ? 'rotate-180' : ''}`} />
                </button>

                {showHistory && (
                  <ul className="mt-3 space-y-2">
                    {history.map((d) => {
                      const thumb = d.is_pdf ? null : storageSrc(d.url);
                      return (
                        <li key={d.id} className="flex items-center gap-3 rounded-lg bg-slate-50 p-2">
                          {thumb ? (
                            <button type="button" onClick={() => setZoomed(thumb)} className="shrink-0">
                              <img src={thumb} alt="" className="h-12 w-16 rounded object-cover ring-1 ring-slate-200" />
                            </button>
                          ) : (
                            <div className="flex h-12 w-16 shrink-0 items-center justify-center rounded bg-slate-100 text-[10px] text-slate-400">
                              {t('PDF')}
                            </div>
                          )}
                          <div className="min-w-0 flex-1">
                            <Provenance doc={d} />
                            <p className="text-[11px] text-slate-400">
                              {t('Replaced {when}', { when: stamp(d.superseded_at, lang) || '—' })}
                            </p>
                          </div>
                          {d.url && (
                            <a
                              href={storageSrc(d.url)}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="shrink-0 text-xs font-semibold text-indigo-600 hover:text-indigo-700"
                            >
                              {t('Open scan')}
                            </a>
                          )}
                          {canManage && (
                            <button
                              type="button"
                              onClick={() => remove(d.id)}
                              disabled={busy}
                              className="shrink-0 rounded p-1 text-slate-400 transition hover:bg-slate-200 hover:text-red-600 disabled:opacity-50"
                              aria-label={t('Remove')}
                            >
                              <Icon.X className="h-4 w-4" />
                            </button>
                          )}
                        </li>
                      );
                    })}
                  </ul>
                )}
              </div>
            )}
          </div>
        )}
      </SectionCard>

      {/* Full-size view — the small print on a Mulkiya (chassis no., policy no.) is unreadable at
          card size, and reading it is the whole reason the scan is here. */}
      {zoomed && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-6"
          onClick={() => setZoomed(null)}
          role="presentation"
        >
          <img src={zoomed} alt={t('Mulkiya')} className="max-h-[90vh] max-w-[95vw] rounded-xl object-contain shadow-2xl" />
        </div>
      )}
    </>
  );
}
