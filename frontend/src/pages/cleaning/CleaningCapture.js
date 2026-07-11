import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { PageHeader, Card } from '../../components/ui/Misc';
import Button from '../../components/ui/Button';
import Badge from '../../components/ui/Badge';
import Icon from '../../components/ui/Icon';
import { useToast } from '../../components/ui/Toast';
import { usePermissions } from '../../hooks/usePermissions';
import BeforeAfterSlider from '../../components/inspection/BeforeAfterSlider';
import { formatBytes } from '../../lib/imageCompression';
import { getCleaning, uploadCleaningPhoto, deleteCleaningPhoto, setCleaningStatus } from '../../lib/cleaning';

// ── Vehicle Cleaning — before / after capture ────────────────────────────────
//
// The page behind the Booking Readiness "Cleaning" point's Fix link. The prep crew shoots the dirty
// car (Before), cleans it, shoots it again (After), then marks it Clean — which clears the readiness
// blocker. Photos compress in-browser and upload multipart to the app server (S3 or local disk).

const STATUS_META = {
  clean:   { tone: 'green', label: 'Clean' },
  dirty:   { tone: 'red',   label: 'Dirty' },
  pending: { tone: 'amber', label: 'Pending' },
  unset:   { tone: 'slate', label: 'Not yet assessed' },
};

// One capture column (Before or After): a camera button, a note field and the captured thumbnails.
function CaptureColumn({ phase, title, hint, photos, busy, onCapture, onDelete }) {
  const inputRef = useRef(null);
  const accent = phase === 'before' ? 'from-amber-500 to-orange-600' : 'from-emerald-500 to-teal-600';

  return (
    <Card className="flex flex-col p-5">
      <div className="flex items-center justify-between">
        <div>
          <p className="text-xs font-medium uppercase tracking-wide text-slate-400">{phase === 'before' ? 'Step 1' : 'Step 2'}</p>
          <h2 className="text-lg font-bold text-slate-900">{title}</h2>
        </div>
        <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-500">{photos.length} photo{photos.length === 1 ? '' : 's'}</span>
      </div>
      <p className="mt-1 text-xs text-slate-500">{hint}</p>

      <button
        type="button"
        onClick={() => inputRef.current?.click()}
        disabled={busy}
        className={`mt-4 flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r ${accent} px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:opacity-95 disabled:opacity-60`}
      >
        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
          <path d="M3 9a2 2 0 0 1 2-2h1.6l1-1.6A2 2 0 0 1 10.3 4h3.4a2 2 0 0 1 1.7 1.4l1 1.6H18a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
          <circle cx="12" cy="13" r="3.2" />
        </svg>
        {busy ? 'Uploading…' : `Take ${title} photo`}
      </button>
      <input
        ref={inputRef}
        type="file"
        accept="image/*"
        capture="environment"
        onChange={(e) => {
          const file = e.target.files?.[0];
          e.target.value = '';
          if (file) onCapture(file);
        }}
        className="hidden"
      />
      <p className="mt-1.5 text-center text-[11px] text-slate-400">Opens the camera on mobile · compressed in-browser before upload</p>

      <div className="mt-4 flex-1">
        {photos.length === 0 ? (
          <div className="flex h-32 items-center justify-center rounded-xl border border-dashed border-slate-200 text-sm text-slate-400">
            No {phase} photos yet
          </div>
        ) : (
          <div className="grid grid-cols-3 gap-2">
            {photos.map((p) => (
              <div key={p.id} className="group relative overflow-hidden rounded-lg ring-1 ring-slate-200">
                {p.url ? (
                  <img src={p.url} alt={`${title}`} className="aspect-square w-full object-cover" />
                ) : (
                  <div className="flex aspect-square w-full items-center justify-center bg-slate-100 text-[10px] text-slate-400">no preview</div>
                )}
                <button
                  type="button"
                  onClick={() => onDelete(p.id)}
                  className="absolute right-1 top-1 hidden h-5 w-5 items-center justify-center rounded-full bg-black/60 text-white group-hover:flex"
                  aria-label="Delete photo"
                >
                  ×
                </button>
                {p.file_size ? (
                  <div className="absolute inset-x-0 bottom-0 bg-black/55 px-1 py-0.5 text-[8px] leading-tight text-white">
                    {formatBytes(p.file_size)}
                  </div>
                ) : null}
              </div>
            ))}
          </div>
        )}
      </div>
    </Card>
  );
}

export default function CleaningCapture() {
  const [searchParams] = useSearchParams();
  const toast = useToast();
  const { can } = usePermissions();
  const canManage = can('vehicles.manage') || can('contracts.manage');

  const vehicleId = searchParams.get('vehicle_id');
  const plateHint = searchParams.get('plate');
  const labelHint = searchParams.get('vehicle') || plateHint || 'Vehicle';

  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState({ before: false, after: false });
  const [savingStatus, setSavingStatus] = useState(false);

  const load = useCallback(async () => {
    if (!vehicleId) { setLoading(false); setError('No vehicle selected. Open this page from a booking’s Cleaning “Fix” link.'); return; }
    setLoading(true);
    try {
      const d = await getCleaning(vehicleId);
      setData(d);
      setError('');
    } catch (e) {
      setError(e.response?.data?.message || 'Could not load the cleaning record.');
    } finally {
      setLoading(false);
    }
  }, [vehicleId]);

  useEffect(() => { load(); }, [load]);

  const capture = async (phase, file) => {
    setBusy((b) => ({ ...b, [phase]: true }));
    try {
      const d = await uploadCleaningPhoto(vehicleId, file, phase);
      setData(d);
      toast.success(`${phase === 'before' ? 'Before' : 'After'} photo saved`);
    } catch (e) {
      toast.error(e.response?.data?.message || 'Upload failed');
    } finally {
      setBusy((b) => ({ ...b, [phase]: false }));
    }
  };

  const remove = async (photoId) => {
    try {
      const d = await deleteCleaningPhoto(vehicleId, photoId);
      setData(d);
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not delete the photo');
    }
  };

  const mark = async (status) => {
    setSavingStatus(true);
    try {
      const d = await setCleaningStatus(vehicleId, status);
      setData(d);
      toast.success(status === 'clean' ? 'Marked clean — the booking’s Cleaning check is now cleared' : `Marked ${status}`);
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not update the cleaning status');
    } finally {
      setSavingStatus(false);
    }
  };

  const before = data?.before || [];
  const after = data?.after || [];
  const status = data?.vehicle?.cleaning_status || 'unset';
  const statusMeta = STATUS_META[status] || STATUS_META.unset;
  const carLabel = data?.vehicle?.label || labelHint;
  const plate = data?.vehicle?.plate_no || plateHint;

  // Latest before + latest after → the comparison slider (only once both exist).
  const compare = useMemo(() => {
    const b = [...(data?.before || [])].reverse().find((p) => p.url);
    const a = [...(data?.after || [])].reverse().find((p) => p.url);
    return b && a ? { before: b.url, after: a.url } : null;
  }, [data]);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Vehicle Cleaning"
          subtitle="Capture the car before and after cleaning, then mark it clean to clear the pickup readiness check."
        >
          <Link to="/booking-readiness" className="inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-600 hover:text-indigo-700">
            ← Back to Booking Readiness
          </Link>
        </PageHeader>

        {/* car + status bar */}
        <Card className="p-4">
          <div className="flex flex-wrap items-center gap-x-6 gap-y-3">
            <div className="min-w-0">
              <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">Vehicle</p>
              <div className="flex items-center gap-2">
                <span className="font-semibold text-slate-900">{carLabel}</span>
                {plate && <span className="rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-600">{plate}</span>}
              </div>
            </div>
            <div>
              <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">Cleaning status</p>
              <Badge tone={statusMeta.tone}>{statusMeta.label}</Badge>
            </div>
            {canManage && (
              <div className="ml-auto flex flex-wrap items-center gap-2">
                <Button variant="secondary" onClick={() => mark('dirty')} loading={savingStatus} disabled={!vehicleId} className="gap-1.5">
                  Mark dirty
                </Button>
                <Button onClick={() => mark('clean')} loading={savingStatus} disabled={!vehicleId} className="gap-1.5">
                  <Icon.Check className="h-4 w-4" /> Mark clean
                </Button>
              </div>
            )}
          </div>
        </Card>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {loading ? (
          <div className="py-16 text-center text-sm text-slate-400">Loading…</div>
        ) : !vehicleId ? null : (
          <>
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
              <CaptureColumn
                phase="before" title="Before" hint="Shoot the car as it arrived — dirty, so the after is undeniable."
                photos={before} busy={busy.before} onCapture={(f) => capture('before', f)} onDelete={remove}
              />
              <CaptureColumn
                phase="after" title="After" hint="Shoot the same angles once the car is clean and ready for handover."
                photos={after} busy={busy.after} onCapture={(f) => capture('after', f)} onDelete={remove}
              />
            </div>

            <Card className="p-5">
              <h2 className="text-sm font-semibold text-slate-900">Before / After comparison</h2>
              <p className="mt-0.5 text-xs text-slate-500">Your latest before vs latest after photo — drag the handle to compare.</p>
              <div className="mx-auto mt-3 max-w-xl">
                {compare ? (
                  <BeforeAfterSlider beforeSrc={compare.before} afterSrc={compare.after} beforeLabel="Before" afterLabel="After" />
                ) : (
                  <div className="flex aspect-[4/3] w-full items-center justify-center rounded-2xl border border-dashed border-slate-200 text-sm text-slate-400">
                    Capture a Before and an After photo to compare them here
                  </div>
                )}
              </div>
            </Card>
          </>
        )}
      </div>
    </div>
  );
}
