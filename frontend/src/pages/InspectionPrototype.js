import { useRef, useState, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';
import { PageHeader, Card } from '../components/ui/Misc';
import VehicleDiagram, { VEHICLE_ZONES, DiagramLegend } from '../components/inspection/VehicleDiagram';
import BeforeAfterSlider from '../components/inspection/BeforeAfterSlider';
import DamageFlagModal from '../components/inspection/DamageFlagModal';
import FuelGaugeSlider from '../components/inspection/FuelGaugeSlider';
import InspectionSummary from '../components/inspection/InspectionSummary';
import { compressImage, formatBytes } from '../lib/imageCompression';
import { damageTypeLabel, severityMeta, computeFuelAudit, fuelStatusMeta } from '../lib/inspections';

// ── Vehicle Inspection Workflow — interactive prototype ──────────────────────
//
// Demonstrates the two signature UI pieces end-to-end, fully client-side (no
// backend yet): the hotspot vehicle diagram and the before/after slider. Tapping
// a zone opens the device camera, compresses the shot in-browser, and auto-tags
// it with zone + contract + timestamp + user — the exact record we'll persist to
// `inspection_records` (image bytes to S3, metadata to the DB) in the next phase.

// Interior items can't be shown from a top-down diagram, so they live as chips.
const INTERIOR_ZONES = [
  { id: 'dashboard', label: 'Dashboard' },
  { id: 'odometer', label: 'Odometer' },
  { id: 'fuel_gauge', label: 'Fuel Gauge' },
  { id: 'seats', label: 'Seats / Interior' },
];

const ALL_ZONES = [...VEHICLE_ZONES, ...INTERIOR_ZONES];
const labelFor = (id) => ALL_ZONES.find((z) => z.id === id)?.label || id;

// Maps the local prototype state for one zone to the exact `inspection_records`
// row the backend persists (see App\Models\InspectionRecord). The s3_key shown is
// illustrative — in the live flow it's returned by the /Inspections/presign call.
function buildRecordPayload(rec, zoneId, phase, contractId, vehicleId, userId) {
  const last = rec.photos?.[rec.photos.length - 1] || null;
  const d = rec.damage || null;
  const [w, h] = last ? String(last.dims).split('×').map(Number) : [null, null];
  return {
    contract_id: contractId,
    vehicle_id: vehicleId,
    inspector_id: userId,
    phase,
    body_part: zoneId,
    s3_key: last ? `inspections/${contractId}/${phase}/${zoneId}/<uuid>.jpg` : null,
    mime_type: last ? 'image/jpeg' : null,
    file_size: last ? last.size : null,
    width: w,
    height: h,
    damage_flagged: !!d,
    damage_type: d?.type ?? null,
    severity: d?.severity ?? null,
    damage_origin: d?.origin ?? null,
    invoice_id: d?.invoiceId ?? null,
    note: d?.note ?? null,
    captured_at: last ? last.ts : new Date().toISOString(),
  };
}

// No mock contract — the prototype starts unlinked so the page loads completely clean. When a real
// contract's "Fix" link sends us here (e.g. Booking Readiness's pending pre-rental inspection check),
// its info arrives as query params (?vehicle_id=&contract_id=&contract_no=&plate=&vehicle=&phase=) and
// buildSessionFromParams() below carries it into the page instead of this placeholder.
const PROTOTYPE_SESSION = { id: null, vehicleId: null, contract_no: '—', vehicle: 'Not linked (prototype)', plate: null };
const PHASES = [
  { id: 'pre', label: 'Pre-rental' },
  { id: 'post', label: 'Post-return' },
];

/** Build the session context from the URL's query params, falling back to the unlinked placeholder. */
function buildSessionFromParams(searchParams) {
  const vehicleId = searchParams.get('vehicle_id');
  if (!vehicleId) return PROTOTYPE_SESSION;
  const plate = searchParams.get('plate');
  const label = searchParams.get('vehicle') || plate || 'Vehicle';
  return {
    id: searchParams.get('contract_id') ? Number(searchParams.get('contract_id')) : null,
    vehicleId: Number(vehicleId),
    contract_no: searchParams.get('contract_no') || '—',
    vehicle: plate ? `${label} (${plate})` : label,
    plate,
  };
}

export default function InspectionPrototype() {
  const { user } = useAuth();
  const [searchParams] = useSearchParams();
  const session = useMemo(() => buildSessionFromParams(searchParams), [searchParams]);
  const fileInputRef = useRef(null);
  const [phase, setPhase] = useState(() => (searchParams.get('phase') === 'post' ? 'post' : 'pre'));
  const [selected, setSelected] = useState('front_bumper');
  // records: { [zoneId]: { photos: [...], damage: {type, severity, note} | null } }
  const [records, setRecords] = useState({});
  const [busy, setBusy] = useState(false);
  const [flagOpen, setFlagOpen] = useState(false);
  // Fuel levels (0–100) captured per phase, and the derived audit + Fuel Charge.
  const [fuel, setFuel] = useState({ pre: null, post: null });
  const [finalized, setFinalized] = useState(false);
  const fuelAudit = useMemo(() => computeFuelAudit(fuel.pre, fuel.post), [fuel]);

  // Diagram needs a {zoneId: {state, count, severity}} map derived from records.
  const zoneStates = useMemo(() => {
    const out = {};
    for (const [id, rec] of Object.entries(records)) {
      const count = rec.photos?.length || 0;
      out[id] = {
        state: rec.damage ? 'damage' : count > 0 ? 'captured' : 'empty',
        count,
        severity: rec.damage?.severity,
      };
    }
    return out;
  }, [records]);

  const selectedRec = records[selected] || { photos: [], damage: null };
  const damageCount = Object.values(records).filter((r) => r.damage).length;
  const photoCount = Object.values(records).reduce((n, r) => n + (r.photos?.length || 0), 0);

  // Tapping "Capture" opens the native camera on mobile (capture="environment").
  const openCamera = () => fileInputRef.current?.click();

  const onFilePicked = async (e) => {
    const file = e.target.files?.[0];
    e.target.value = ''; // allow re-picking the same file
    if (!file) return;
    setBusy(true);
    try {
      const c = await compressImage(file, { maxDimension: 1600, quality: 0.72 });
      // This is the auto-tagged record we will POST in the backend phase.
      const photo = {
        url: c.url,
        ts: new Date().toISOString(),
        size: c.compressedSize,
        originalSize: c.originalSize,
        dims: `${c.width}×${c.height}`,
        // tags
        zone: selected,
        phase,
        contractId: session.id,
        userId: user?.id ?? null,
      };
      setRecords((prev) => {
        const rec = prev[selected] || { photos: [], damage: null };
        return { ...prev, [selected]: { ...rec, photos: [...rec.photos, photo] } };
      });
    } finally {
      setBusy(false);
    }
  };

  // Save / update the rich damage flag for the selected zone.
  const saveFlag = (flag) => {
    setRecords((prev) => {
      const rec = prev[selected] || { photos: [], damage: null };
      return { ...prev, [selected]: { ...rec, damage: flag } };
    });
    setFlagOpen(false);
  };

  const removeFlag = () => {
    setRecords((prev) => {
      const rec = prev[selected] || { photos: [], damage: null };
      return { ...prev, [selected]: { ...rec, damage: null } };
    });
    setFlagOpen(false);
  };

  const removePhoto = (idx) => {
    setRecords((prev) => {
      const rec = prev[selected];
      if (!rec) return prev;
      const photos = rec.photos.filter((_, i) => i !== idx);
      return { ...prev, [selected]: { ...rec, photos } };
    });
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title="Vehicle Inspection"
        subtitle="Prototype · hotspot capture, rich damage flagging (type/severity/note) & before/after comparison. Photos compress in-browser; the inspection_records table + S3 upload API are live, pending wiring to a real contract."
      >
        <span className="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700 ring-1 ring-amber-200">
          Preview
        </span>
      </PageHeader>

      {/* session context bar */}
      <Card className="p-4">
        <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
          <Meta label="Contract" value={session.contract_no} />
          <Meta label="Vehicle" value={session.vehicle} />
          <Meta label="Inspector" value={user?.name || 'You'} />
          <div className="ml-auto flex items-center gap-2">
            {PHASES.map((p) => (
              <button
                key={p.id}
                onClick={() => setPhase(p.id)}
                className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                  phase === p.id
                    ? 'bg-indigo-600 text-white shadow-sm'
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                }`}
              >
                {p.label}
              </button>
            ))}
          </div>
        </div>
      </Card>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {/* ── Hotspot diagram ─────────────────────────────────────────── */}
        <Card className="p-5">
          <div className="mb-3 flex items-center justify-between">
            <h2 className="text-sm font-semibold text-slate-900">Inspection View</h2>
            <div className="flex gap-3 text-xs">
              <span className="font-semibold text-emerald-600">{photoCount} photos</span>
              <span className="font-semibold text-rose-600">{damageCount} flagged</span>
            </div>
          </div>

          <div className="flex justify-center py-2">
            <VehicleDiagram zones={zoneStates} selected={selected} onSelect={setSelected} />
          </div>

          <DiagramLegend className="mt-2 justify-center" />

          <div className="mt-4 border-t border-slate-100 pt-4">
            <p className="mb-2 text-xs font-medium text-slate-500">Interior & controls</p>
            <div className="flex flex-wrap gap-2">
              {INTERIOR_ZONES.map((z) => {
                const st = zoneStates[z.id]?.state;
                return (
                  <button
                    key={z.id}
                    onClick={() => setSelected(z.id)}
                    className={`rounded-lg border px-3 py-1.5 text-xs font-medium transition ${
                      selected === z.id
                        ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                        : st === 'damage'
                        ? 'border-rose-300 bg-rose-50 text-rose-700'
                        : st === 'captured'
                        ? 'border-emerald-300 bg-emerald-50 text-emerald-700'
                        : 'border-slate-200 text-slate-600 hover:border-slate-300'
                    }`}
                  >
                    {z.label}
                  </button>
                );
              })}
            </div>
          </div>
        </Card>

        {/* ── Selected zone capture panel ─────────────────────────────── */}
        <Card className="flex flex-col p-5">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-xs font-medium uppercase tracking-wide text-slate-400">Selected zone</p>
              <h2 className="text-lg font-bold text-slate-900">{labelFor(selected)}</h2>
            </div>
            <button
              onClick={() => setFlagOpen(true)}
              className={`rounded-lg px-3 py-2 text-xs font-semibold ring-1 transition ${
                selectedRec.damage
                  ? 'bg-rose-600 text-white ring-rose-600 hover:bg-rose-700'
                  : 'bg-white text-rose-600 ring-rose-200 hover:bg-rose-50'
              }`}
            >
              {selectedRec.damage ? 'Edit flag' : 'Flag damage'}
            </button>
          </div>

          {/* rich damage flag summary */}
          {selectedRec.damage && (
            <div className="mt-3 flex items-start gap-2 rounded-xl bg-rose-50 px-3 py-2.5 ring-1 ring-rose-100">
              <span className={`mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold text-white ${severityMeta(selectedRec.damage.severity).toneActive}`}>
                {severityMeta(selectedRec.damage.severity).label}
              </span>
              <div className="min-w-0 text-xs text-rose-800">
                <span className="font-semibold">{damageTypeLabel(selectedRec.damage.type)}</span>
                <span className="text-rose-500"> · {severityMeta(selectedRec.damage.severity).sub}</span>
                {selectedRec.damage.note && <p className="mt-0.5 text-rose-600">“{selectedRec.damage.note}”</p>}
              </div>
            </div>
          )}

          <button
            onClick={openCamera}
            disabled={busy}
            className="mt-4 flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-60"
          >
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
              <path d="M3 9a2 2 0 0 1 2-2h1.6l1-1.6A2 2 0 0 1 10.3 4h3.4a2 2 0 0 1 1.7 1.4l1 1.6H18a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
              <circle cx="12" cy="13" r="3.2" />
            </svg>
            {busy ? 'Processing…' : `Capture ${labelFor(selected)}`}
          </button>
          <input
            ref={fileInputRef}
            type="file"
            accept="image/*"
            capture="environment"
            onChange={onFilePicked}
            className="hidden"
          />
          <p className="mt-1.5 text-center text-[11px] text-slate-400">
            Opens the camera on mobile · compressed in-browser · auto-tagged with zone, contract, time & user
          </p>

          {/* captured thumbnails for this zone */}
          <div className="mt-4 flex-1">
            {selectedRec.photos.length === 0 ? (
              <div className="flex h-32 items-center justify-center rounded-xl border border-dashed border-slate-200 text-sm text-slate-400">
                No photos captured for this zone yet
              </div>
            ) : (
              <div className="grid grid-cols-3 gap-2">
                {selectedRec.photos.map((p, i) => (
                  <div key={i} className="group relative overflow-hidden rounded-lg ring-1 ring-slate-200">
                    <img src={p.url} alt={`${labelFor(selected)} ${i + 1}`} className="aspect-square w-full object-cover" />
                    <button
                      onClick={() => removePhoto(i)}
                      className="absolute right-1 top-1 hidden h-5 w-5 items-center justify-center rounded-full bg-black/60 text-white group-hover:flex"
                    >
                      ×
                    </button>
                    <div className="absolute inset-x-0 bottom-0 bg-black/55 px-1 py-0.5 text-[8px] leading-tight text-white">
                      {formatBytes(p.size)} · {Math.round((p.size / (p.originalSize || p.size)) * 100)}% of orig
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* the inspection_records object — exactly what gets POSTed to the DB */}
          {(selectedRec.photos.length > 0 || selectedRec.damage) && (
            <div className="mt-3 rounded-lg bg-slate-900 p-3 text-[11px] ring-1 ring-slate-800">
              <p className="mb-1 font-semibold text-slate-300">inspection_records payload (latest)</p>
              <pre className="overflow-x-auto whitespace-pre-wrap break-words font-mono leading-relaxed text-emerald-300">
                {JSON.stringify(buildRecordPayload(selectedRec, selected, phase, session.id, session.vehicleId, user?.id ?? null), null, 1)}
              </pre>
            </div>
          )}
        </Card>
      </div>

      {/* ── Fuel audit ────────────────────────────────────────────────── */}
      <Card className="p-5">
        <FuelAuditSection
          fuel={fuel}
          phase={phase}
          audit={fuelAudit}
          onChange={(p, v) => setFuel((prev) => ({ ...prev, [p]: v }))}
        />
      </Card>

      <DamageFlagModal
        open={flagOpen}
        zoneLabel={labelFor(selected)}
        initial={selectedRec.damage}
        onSave={saveFlag}
        onRemove={removeFlag}
        onClose={() => setFlagOpen(false)}
      />

      {/* ── Before/After comparison ───────────────────────────────────── */}
      <Card className="p-5">
        <ComparisonSection records={records} />
      </Card>

      {/* ── Inspection Summary (Dispute Killer) + Check-in Report ──────── */}
      <Card className="p-5">
        <InspectionSummary
          records={records}
          labelFor={labelFor}
          fuelAudit={fuelAudit}
          phaseLabel={PHASES.find((p) => p.id === phase)?.label || phase}
          session={session}
          inspectorName={user?.name || 'You'}
          finalized={finalized}
          onFinalize={setFinalized}
        />
      </Card>
    </div>
  );
}

// Fuel audit: a delivery gauge (pre) and a return gauge (post). The gauge matching
// the active phase is highlighted; both stay editable so back-filling is painless.
// Once both are set, the live shortage + Fuel Charge surface beneath.
function FuelAuditSection({ fuel, phase, audit, onChange }) {
  const meta = audit ? fuelStatusMeta(audit.status) : null;
  const short = audit?.status === 'shortage';
  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 className="text-sm font-semibold text-slate-900">Fuel audit</h2>
          <p className="text-xs text-slate-500">
            Record the gauge at delivery and at return — a lower return auto-calculates the missing fuel.
          </p>
        </div>
        {audit && (
          <span className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ring-1 ${meta.ring}`}>
            <span className={`h-1.5 w-1.5 rounded-full ${meta.dot}`} />
            {meta.label}
          </span>
        )}
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FuelGaugeSlider
          label="Delivery (Pre-rental)"
          hint="Level when the car was handed over"
          value={fuel.pre}
          compareTo={fuel.post}
          active={phase === 'pre'}
          onChange={(v) => onChange('pre', v)}
        />
        <FuelGaugeSlider
          label="Return (Post-return)"
          hint="Level when the car came back"
          value={fuel.post}
          compareTo={fuel.pre}
          active={phase === 'post'}
          onChange={(v) => onChange('post', v)}
        />
      </div>

      {audit && (
        <div className={`mt-4 rounded-xl px-4 py-3 text-sm ring-1 ${short ? 'bg-rose-50 ring-rose-100' : 'bg-emerald-50 ring-emerald-100'}`}>
          {short ? (
            <p className="font-medium text-rose-800">
              Return is <b>{audit.shortagePct}%</b> below delivery — a shortage of <b>{audit.litresShort} L</b> is linked to a
              <b> Fuel Charge</b> fee.
            </p>
          ) : (
            <p className="font-medium text-emerald-800">
              {audit.surplus ? 'Returned fuller than delivery — no fuel charge.' : 'Fuel is balanced — no shortage to charge.'}
            </p>
          )}
        </div>
      )}
    </div>
  );
}

function Meta({ label, value }) {
  return (
    <div className="flex flex-col">
      <span className="text-[11px] font-medium uppercase tracking-wide text-slate-400">{label}</span>
      <span className="font-semibold text-slate-800">{value}</span>
    </div>
  );
}

// Before/After uses YOUR OWN captured photos: the latest Pre-rental shot vs the
// latest Post-return shot for the chosen zone. Pick a zone that has photos; the
// slider appears only once both phases exist. Falls back to a sample demo when
// nothing has been captured yet, so the component is never empty.
function ComparisonSection({ records }) {
  const zonesWithPhotos = Object.entries(records)
    .filter(([, r]) => r.photos?.length)
    .map(([id]) => id);

  const [picked, setPicked] = useState(null);
  const zone = picked && zonesWithPhotos.includes(picked) ? picked : zonesWithPhotos[0] || null;

  // Nothing captured yet → keep it clean (no sample/mock images), just guidance.
  if (!zone) {
    return (
      <div>
        <h2 className="text-sm font-semibold text-slate-900">Before / After Comparison</h2>
        <p className="mt-0.5 text-xs text-slate-500">
          Capture a <b>Pre-rental</b> and a <b>Post-return</b> photo of the same zone above, then compare them here.
        </p>
        <div className="mx-auto mt-3 flex aspect-[4/3] w-full max-w-xl items-center justify-center rounded-2xl border border-dashed border-slate-200 text-sm text-slate-400">
          No photos captured yet
        </div>
      </div>
    );
  }

  const photos = records[zone].photos;
  const pre = [...photos].reverse().find((p) => p.phase === 'pre');
  const post = [...photos].reverse().find((p) => p.phase === 'post');

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-sm font-semibold text-slate-900">Before / After Comparison</h2>
          <p className="text-xs text-slate-500">Your Pre-rental vs Post-return photo for the same zone — drag the handle to compare.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {zonesWithPhotos.map((id) => (
            <button
              key={id}
              onClick={() => setPicked(id)}
              className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                zone === id ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
              }`}
            >
              {labelFor(id)}
            </button>
          ))}
        </div>
      </div>

      <div className="mx-auto max-w-xl">
        {pre && post ? (
          <BeforeAfterSlider beforeSrc={pre.url} afterSrc={post.url} />
        ) : (
          <MissingPhase have={pre ? 'pre' : 'post'} photo={pre || post} zoneLabel={labelFor(zone)} />
        )}
      </div>
    </div>
  );
}

// Shown when a zone has only one of the two phases. Displays the photo you have
// and tells you exactly how to capture the other so the slider can appear.
function MissingPhase({ have, photo, zoneLabel }) {
  const haveLabel = have === 'pre' ? 'Pre-rental' : 'Post-return';
  const missingLabel = have === 'pre' ? 'Post-return' : 'Pre-rental';
  return (
    <div className="overflow-hidden rounded-2xl ring-1 ring-slate-200">
      <div className="relative aspect-[4/3] bg-slate-900">
        <img src={photo.url} alt={haveLabel} className="absolute inset-0 h-full w-full object-cover" />
        <span className="absolute left-3 top-3 rounded-full bg-emerald-600/90 px-2.5 py-1 text-[11px] font-semibold text-white">
          {haveLabel} ✓
        </span>
      </div>
      <div className="flex items-start gap-2 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        <svg className="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8">
          <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" />
        </svg>
        <p>
          To compare, switch the phase toggle (top of page) to <b>{missingLabel}</b>, select <b>{zoneLabel}</b> on the
          diagram, and capture it. The slider appears automatically once both photos exist.
        </p>
      </div>
    </div>
  );
}

