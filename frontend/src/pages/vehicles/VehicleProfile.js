import { Fragment, useCallback, useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { useToast } from '../../components/ui/Toast';
import Badge, { VehicleStatusBadge, ContractTypeBadge, ContractStateBadge } from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import Modal from '../../components/ui/Modal';
import { Input } from '../../components/ui/Field';
import SearchSelect from '../../components/ui/SearchSelect';
import { Card, Spinner } from '../../components/ui/Misc';
import { useCountUp } from '../../components/ui/Gauge';
import { aed, aed2, fmtDate, dayBadge, num } from '../../lib/format';

// Battery is due for change one year after it was last changed.
const batteryNextChange = (lastChanged) => {
  if (!lastChanged) return null;
  const d = new Date(lastChanged);
  if (isNaN(d)) return null;
  d.setFullYear(d.getFullYear() + 1);
  return d;
};

// Human text for the strict km-based service-due verdict (Vehicle::serviceStatus on the API).
const serviceStatusText = (s) => {
  if (!s || s.status === 'no_data') return 'No Data';
  if (s.status === 'service_due') return `Service Due (${num(s.overdue_km)} km overdue)`;
  return `OK (${num(s.remaining)} km left)`;
};

const AV_DOT = { available: 'bg-emerald-500', rented: 'bg-blue-500', maintenance: 'bg-amber-500', busy: 'bg-gray-400', out_of_fleet: 'bg-gray-400' };
// Tone per maintenance-log event status (from the sheet's OUT/IN column).
const EVENT_TONE = { OUT: 'amber', IN: 'green', 'Follow up': 'blue', Change: 'indigo', Delay: 'red', Test: 'gray', 'Under Test': 'gray', Delivery: 'green' };

// Solid/soft marker colors for the maintenance timeline, keyed by the EVENT_TONE value.
const EVENT_STYLE = {
  amber:  { dot: 'bg-amber-500',   soft: 'bg-amber-100',   text: 'text-amber-600',   ring: 'ring-amber-200' },
  green:  { dot: 'bg-emerald-500', soft: 'bg-emerald-100', text: 'text-emerald-600', ring: 'ring-emerald-200' },
  blue:   { dot: 'bg-blue-500',    soft: 'bg-blue-100',    text: 'text-blue-600',    ring: 'ring-blue-200' },
  indigo: { dot: 'bg-indigo-500',  soft: 'bg-indigo-100',  text: 'text-indigo-600',  ring: 'ring-indigo-200' },
  red:    { dot: 'bg-red-500',     soft: 'bg-red-100',     text: 'text-red-600',     ring: 'ring-red-200' },
  violet: { dot: 'bg-violet-500',  soft: 'bg-violet-100',  text: 'text-violet-600',  ring: 'ring-violet-200' },
  gray:   { dot: 'bg-slate-400',   soft: 'bg-slate-100',   text: 'text-slate-500',   ring: 'ring-slate-200' },
};

// A small glyph per workshop event type, so the timeline reads at a glance.
const EVENT_ICON = {
  OUT: 'M17 8l4 4m0 0l-4 4m4-4H3',                                  // arrow-out
  IN: 'M11 16l-4-4m0 0l4-4m-4 4h14',                                // arrow-in
  'Follow up': 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0zM12 7v5l3 3',  // clock
  Change: 'M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15', // refresh
  Delay: 'M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z', // alert
  Test: 'M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 7h10v10H7z', // chip
  'Under Test': 'M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 7h10v10H7z',
  Delivery: 'M9 17a2 2 0 1 1-4 0 2 2 0 0 1 4 0zm10 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0zM13 16V6a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v10h10zm0-6h5l3 3v3h-3', // truck
};
const DEFAULT_EVENT_ICON = 'M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828z'; // wrench/pencil

// Multi-line workshop notes -> a tidy bulleted list; lines made only of -, *, = … become dividers.
function NotesList({ text }) {
  const lines = String(text || '').split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  if (lines.length === 0) return <span className="text-sm text-slate-400">No notes</span>;
  return (
    <ul className="space-y-1.5">
      {lines.map((line, i) =>
        /^[-*_=.~•\s]{2,}$/.test(line) ? (
          <li key={i} aria-hidden className="!mt-2 border-t border-dashed border-slate-200" />
        ) : (
          <li key={i} className="flex gap-2 text-sm leading-relaxed text-slate-600">
            <span className="mt-[7px] h-1.5 w-1.5 shrink-0 rounded-full bg-slate-300" />
            <span>{line}</span>
          </li>
        )
      )}
    </ul>
  );
}

// Maintenance situation severity (from the reason -> status link).
const PRIO = {
  critical: { label: 'Critical', tone: 'red', emoji: '🔴' },
  special: { label: 'Special', tone: 'violet', emoji: '🟣' },
  minor: { label: 'Minor', tone: 'amber', emoji: '🟡' },
  routine: { label: 'Routine', tone: 'green', emoji: '🟢' },
};

function Field({ label, value }) {
  return (
    <div className="flex justify-between gap-4 py-1.5 text-sm">
      <span className="text-gray-500">{label}</span>
      <span className="text-right font-medium text-gray-900">{value ?? '—'}</span>
    </div>
  );
}

function CoverageRow({ label, date, days }) {
  const b = dayBadge(days);
  return (
    <div className="flex items-center justify-between py-2">
      <div>
        <p className="text-sm font-medium text-gray-700">{label}</p>
        <p className="text-xs text-gray-400">{fmtDate(date)}</p>
      </div>
      <Badge tone={b.tone}>{b.text === '—' ? 'None' : b.text}</Badge>
    </div>
  );
}

// A number that rolls up from 0 on mount.
function CountUp({ value, format }) {
  const v = useCountUp(Number(value) || 0);
  return <>{format ? format(v) : Math.round(v).toLocaleString()}</>;
}

const STAT_TONE = {
  gray: { bg: 'bg-slate-100 text-slate-500', text: 'text-slate-900' },
  indigo: { bg: 'bg-indigo-50 text-indigo-600', text: 'text-slate-900' },
  emerald: { bg: 'bg-emerald-50 text-emerald-600', text: 'text-emerald-600' },
  red: { bg: 'bg-red-50 text-red-600', text: 'text-red-600' },
};

function Stat({ label, value, icon, tone = 'gray', format, highlight }) {
  const t = STAT_TONE[tone] || STAT_TONE.gray;
  return (
    <div className="hover-lift rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft">
      <div className="flex items-center justify-between gap-2">
        <p className="text-xs font-medium text-slate-500">{label}</p>
        {icon && (
          <span className={`flex h-8 w-8 items-center justify-center rounded-lg ${t.bg}`}>
            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={icon} /></svg>
          </span>
        )}
      </div>
      <p className={`mt-1.5 text-2xl font-bold tracking-tight ${highlight ? t.text : 'text-slate-900'}`}>
        <CountUp value={value} format={format} />
      </p>
    </div>
  );
}

// A frosted "fact" chip for the dark hero header.
function SpecPill({ label, value }) {
  if (value === null || value === undefined || value === '') return null;
  return (
    <div className="rounded-xl bg-white/10 px-3 py-1.5 ring-1 ring-inset ring-white/15 backdrop-blur">
      <span className="block text-[10px] font-medium uppercase tracking-wide text-white/50">{label}</span>
      <span className="text-sm font-semibold text-white">{value}</span>
    </div>
  );
}

export default function VehicleProfile() {
  const { id } = useParams();
  const fetcher = useCallback(async () => {
    const { data } = await api.get(`/Vehicle/${id}/profile`);
    return data.data;
  }, [id]);
  const { data, loading, error, reload } = useFetch(fetcher, [id]);
  const toast = useToast();
  const [vendors, setVendors] = useState([]);
  const [maintOpen, setMaintOpen] = useState(false);
  const [maintForm, setMaintForm] = useState({ vendor_id: '', expected_return_date: '' });
  const [conflict, setConflict] = useState(null); // reservation clash returned by the API (409)
  const [busy, setBusy] = useState(false);
  const [openVisits, setOpenVisits] = useState({}); // expanded maintenance-history rows (by visit id)
  const [logEvent, setLogEvent] = useState(null); // maintenance-log event opened in the detail modal
  const [showAllVisits, setShowAllVisits] = useState(false); // collapse the Maintenance History table by default
  const [showAllLog, setShowAllLog] = useState(false); // collapse the Maintenance Log timeline by default
  const [showAllContracts, setShowAllContracts] = useState(false); // collapse the Contract History table by default

  const VISITS_PREVIEW = 5;    // rows shown before "Show all"
  const LOG_PREVIEW = 4;       // timeline events shown before "Show all"
  const CONTRACTS_PREVIEW = 5; // contract rows shown before "Show all"

  const toggleVisit = (vid) => setOpenVisits((o) => ({ ...o, [vid]: !o[vid] }));

  useEffect(() => { api.get('/Vendor').then((r) => setVendors(r.data.data || [])).catch(() => {}); }, []);

  const closeMaint = () => { setMaintOpen(false); setConflict(null); };

  const sendToMaintenance = async (force = false) => {
    setBusy(true);
    try {
      await api.post(`/Vehicle/${id}/operation`, {
        category: 'maintenance',
        vendor_id: maintForm.vendor_id || undefined,
        expected_return_date: maintForm.expected_return_date || undefined,
        force: force || undefined,
      });
      toast.success('Car sent to maintenance');
      setMaintOpen(false);
      setConflict(null);
      setMaintForm({ vendor_id: '', expected_return_date: '' });
      reload();
    } catch (e) {
      const res = e.response;
      if (res?.status === 409 && res.data?.data?.conflict) {
        // car is reserved and the timing clashes — show it and let the operator override
        setConflict({ message: res.data.message, reservations: res.data.data.reservations || [] });
      } else {
        toast.error(res?.data?.message || 'Could not update status');
      }
    } finally {
      setBusy(false);
    }
  };

  const markAvailable = async (openContractId) => {
    setBusy(true);
    try {
      await api.post(`/Contract/${openContractId}/close`, {});
      toast.success('Car marked available');
      reload();
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not update status');
    } finally {
      setBusy(false);
    }
  };

  if (loading) {
    return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;
  }
  if (error || !data) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-12">
        <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error || 'Not found'}</div>
        <Link to="/vehicles" className="mt-4 inline-block text-sm font-medium text-indigo-600">← Back to vehicles</Link>
      </div>
    );
  }

  const v = data.vehicle;
  const reg = data.registration;
  const av = data.availability || {};
  const contracts = data.contracts || [];
  // Newest first — sort by the most recent date on the contract (out, falling back to in).
  const contractTime = (c) => { const t = new Date(c.out_date || c.in_date || 0).getTime(); return isNaN(t) ? 0 : t; };
  const sortedContracts = [...contracts].sort((a, b) => contractTime(b) - contractTime(a));
  const maintenance = data.maintenance || [];
  const maintenanceLog = data.maintenance_log || [];
  const analytics = data.maintenance_analytics || [];
  const stats = data.stats || {};

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Back */}
        <Link to="/vehicles" className="inline-flex items-center gap-1 text-sm font-medium text-slate-500 transition hover:text-slate-700">
          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M15 19l-7-7 7-7" /></svg>
          Vehicles
        </Link>

        {/* Hero header */}
        <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 p-6 shadow-card sm:p-8">
          <div className="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-indigo-500/20 blur-3xl" />
          <div className="pointer-events-none absolute -bottom-24 left-1/4 h-64 w-64 rounded-full bg-violet-500/10 blur-3xl" />
          <div className="relative flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
            {/* Identity */}
            <div className="flex items-start gap-4">
              <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-white/10 ring-1 ring-inset ring-white/15 backdrop-blur">
                <svg className="h-8 w-8 text-indigo-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M5 13l1.5-4.5A2 2 0 0 1 8.4 7h7.2a2 2 0 0 1 1.9 1.5L19 13m-14 0h14m-14 0a2 2 0 0 0-2 2v3a1 1 0 0 0 1 1h1m14-6a2 2 0 0 1 2 2v3a1 1 0 0 1-1 1h-1m-12 0v1a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-1m2 0h10M7.5 16h.01M16.5 16h.01" />
                </svg>
              </div>
              <div className="min-w-0">
                <h1 className="text-2xl font-bold tracking-tight text-white sm:text-3xl">{[v.make, v.model].filter(Boolean).join(' ') || 'Vehicle'}</h1>
                <div className="mt-2 flex flex-wrap items-center gap-2">
                  {v.plate_no && <span className="rounded-lg bg-white/15 px-2.5 py-1 font-mono text-sm font-bold tracking-wider text-white ring-1 ring-inset ring-white/20">{v.plate_no}</span>}
                  {v.vin && <span className="font-mono text-xs text-white/45">{v.vin}</span>}
                </div>
                <div className="mt-3 flex flex-wrap items-center gap-2">
                  <VehicleStatusBadge status={v.status} />
                  {v.for_sale && <Badge tone="amber">🏷️ For sale</Badge>}
                </div>
                <div className="mt-4 flex flex-wrap gap-2">
                  <SpecPill label="Year" value={v.year} />
                  <SpecPill label="Odometer" value={v.odometer != null ? `${num(v.odometer)} km` : null} />
                  <SpecPill label="Category" value={v.category} />
                  <SpecPill label="Color" value={v.color} />
                </div>
              </div>
            </div>

            {/* Availability + actions */}
            <div className="w-full shrink-0 rounded-2xl bg-white/5 p-4 ring-1 ring-inset ring-white/10 backdrop-blur lg:w-80">
              <div className="flex items-start gap-2.5">
                <span className="relative mt-1 flex h-2.5 w-2.5">
                  <span className={`absolute inline-flex h-full w-full animate-ping rounded-full opacity-60 ${AV_DOT[av.state] || 'bg-gray-400'}`} />
                  <span className={`relative inline-flex h-2.5 w-2.5 rounded-full ${AV_DOT[av.state] || 'bg-gray-400'}`} />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="flex items-center gap-2 text-sm font-semibold text-white">
                    {av.state === 'available' ? 'Available for rent'
                      : av.state === 'rented' ? `Rented${av.customer ? ` · ${av.customer}` : ''}`
                      : av.state === 'maintenance' ? 'In maintenance'
                      : (av.label || '—')}
                    {av.overdue && <Badge tone="red">Overdue</Badge>}
                  </p>
                  <p className="mt-0.5 text-xs text-white/55">
                    {av.state === 'maintenance'
                      ? `${av.garage ? `at ${av.garage} · ` : ''}${av.due ? `due back ${fmtDate(av.due)}` : 'no return date set'}`
                      : av.state === 'rented'
                        ? `out since ${fmtDate(av.since)}${av.due ? ` · est. return ${fmtDate(av.due)}${av.days ? ` (${av.days}-day rental)` : ''}` : ' · no rental days recorded'}`
                      : av.state === 'available' ? 'No open contract — ready to rent or service'
                      : (av.since ? `since ${fmtDate(av.since)}` : '')}
                  </p>
                </div>
              </div>
              {(av.state !== 'maintenance' && av.state !== 'out_of_fleet') || av.open_contract_id ? (
                <div className="mt-4 flex flex-col gap-2">
                  {av.state !== 'maintenance' && av.state !== 'out_of_fleet' && (
                    <Button variant="secondary" className="w-full justify-center" onClick={() => setMaintOpen(true)} disabled={busy}>🔧 Send to Maintenance</Button>
                  )}
                  {av.open_contract_id && (
                    <Button variant="success" className="w-full justify-center" onClick={() => markAvailable(av.open_contract_id)} loading={busy}>✓ Mark Available</Button>
                  )}
                </div>
              ) : null}
            </div>
          </div>
        </div>

        {/* Stats */}
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Stat label="Total Contracts" value={stats.contracts_count} tone="indigo" icon="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2z" />
          <Stat label="Open Now" value={stats.open_count} tone="emerald" highlight={stats.open_count > 0} icon="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
          <Stat label="Lifetime Income" value={stats.lifetime_income} format={aed} tone="emerald" icon="M12 8c-1.7 0-3 .9-3 2s1.3 2 3 2 3 .9 3 2-1.3 2-3 2m0-8V6m0 12v-2m9-4a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
          <Stat label="Outstanding Fines" value={reg ? reg.fines_count : 0} tone="red" highlight={reg && reg.fines_count > 0} icon="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" />
        </div>

        {/* Specs + Registration */}
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          <Card className="p-6 lg:col-span-2">
            <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Specifications</h3>
            <div className="grid grid-cols-1 gap-x-8 sm:grid-cols-2">
              <div>
                <Field label="VIN / Chassis" value={v.vin} />
                <Field label="Plate No." value={v.plate_no} />
                <Field label="Year" value={v.year} />
                <Field label="Color" value={v.color} />
                <Field label="Category" value={v.category} />
                <Field label="Odometer" value={v.odometer != null ? `${num(v.odometer)} km` : '—'} />
              </div>
              <div>
                <Field label="Source" value={v.source} />
                <Field label="Purchase Price" value={v.purchase_price ? aed2(v.purchase_price) : '—'} />
                <Field label="Purchase Date" value={fmtDate(v.purchase_date)} />
                <Field label="Replacement Due" value={fmtDate(v.replacement_due_date)} />
                <Field label="Warranty End" value={fmtDate(v.warranty_end_date)} />
                {/* API car card: battery last-replacement date (only battery field OM exposes). */}
                <Field label="Battery Last Changed" value={fmtDate(v.battery_last_changed)} />
                {/* Battery is due one year after the last change. */}
                <Field label="Next Battery Change" value={fmtDate(batteryNextChange(v.battery_last_changed))} />
                {/* Oil Change sheet: per-car interval (Validity) + last-service baseline. */}
                <Field label="Service Interval (Validity)" value={v.service_interval_km != null ? `${num(v.service_interval_km)} km` : '—'} />
                <Field label="Last Change" value={v.last_service_odometer != null ? `${num(v.last_service_odometer)} km` : '—'} />
                <Field label="Service Status" value={serviceStatusText(v.service_status)} />
              </div>
            </div>
          </Card>

          <Card className="p-6">
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Registration & Insurance</h3>
            {reg ? (
              <>
                <CoverageRow label="Registration (Mulkiya)" date={reg.expiry_date} days={reg.registration_days_left} />
                <CoverageRow label="Insurance" date={reg.insurance_expiry} days={reg.insurance_days_left} />
                <div className="mt-3 border-t border-gray-100 pt-3">
                  <Field label="Insurer" value={reg.insurer} />
                  <Field label="Reg. Status" value={reg.status} />
                  <Field label="Mortgaged By" value={reg.mortgaged_by} />
                  <Field label="Violations / Fines" value={`${num(reg.fines_count)} · ${aed2(reg.fines_amount)}`} />
                </div>
              </>
            ) : (
              <div className="rounded-lg bg-amber-50 px-3 py-3 text-sm text-amber-700 ring-1 ring-inset ring-amber-600/20">
                No registration record — this car has no Mulkiya or insurance on file.
              </div>
            )}
          </Card>
        </div>

        {/* Maintenance history — the full "story" for this car */}
        <Card>
          <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-6 py-4">
            <h3 className="text-base font-semibold text-gray-900">Maintenance History</h3>
            <Badge tone="gray">{num(maintenance.length)} {maintenance.length === 1 ? 'visit' : 'visits'}</Badge>
          </div>

          {/* All-time spend KPI */}
          <div className="px-6 py-4">
            <div className="inline-flex flex-col rounded-2xl border border-amber-100 bg-amber-50 px-5 py-4">
              <span className="text-xs font-medium text-amber-700">Total Maintenance Spent (All-time)</span>
              <span className="mt-1 text-2xl font-bold tracking-tight text-amber-900">{aed2(stats.maintenance_total)}</span>
              <span className="mt-0.5 text-xs text-amber-600">across {num(stats.maintenance_count)} {Number(stats.maintenance_count) === 1 ? 'visit' : 'visits'}</span>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-100 text-sm stagger-rows">
              <thead className="bg-gray-50/60">
                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                  <th className="px-6 py-3">Visit</th>
                  <th className="px-6 py-3">Out / In</th>
                  <th className="px-6 py-3">Priority</th>
                  <th className="px-6 py-3">Status</th>
                  <th className="px-6 py-3">Garage</th>
                  <th className="px-6 py-3">Issues</th>
                  <th className="px-6 py-3 text-right">Total Cost</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {(showAllVisits ? maintenance : maintenance.slice(0, VISITS_PREVIEW)).map((m) => {
                  const p = PRIO[m.priority] || PRIO.routine;
                  const events = m.events || [];
                  const expandable = events.length > 0;
                  const open = !!openVisits[m.id];
                  return (
                    <Fragment key={m.id}>
                      <tr
                        className={`hover:bg-gray-50/60 ${expandable ? 'cursor-pointer' : ''}`}
                        onClick={expandable ? () => toggleVisit(m.id) : undefined}
                      >
                        <td className="px-6 py-3 font-medium">
                          <div className="flex items-center gap-2">
                            <span className={`text-gray-400 transition-transform ${open ? 'rotate-90' : ''} ${expandable ? '' : 'invisible'}`}>▶</span>
                            <Link to={`/contracts/${m.id}`} onClick={(e) => e.stopPropagation()} className="text-indigo-600 hover:text-indigo-700">#{m.contract_no || m.id}</Link>
                          </div>
                        </td>
                        <td className="px-6 py-3 text-gray-500">
                          {fmtDate(m.date)}
                          {m.in_date && <span className="text-gray-400"> → {fmtDate(m.in_date)}</span>}
                        </td>
                        <td className="px-6 py-3">
                          <Badge tone={p.tone} title={m.reason || ''}>{p.emoji} {p.label}</Badge>
                        </td>
                        <td className="px-6 py-3">
                          {m.stage ? <Badge tone={EVENT_TONE[m.stage] || 'gray'}>{m.stage}</Badge> : <span className="text-xs text-gray-300">—</span>}
                          {m.event_count > 1 && <span className="ml-1 text-xs text-gray-400">×{m.event_count}</span>}
                        </td>
                        <td className="px-6 py-3 text-gray-700">{m.garage || '—'}</td>
                        <td className="px-6 py-3">
                          <div className="flex flex-wrap gap-1">
                            {(m.tags || []).slice(0, 4).map((t) => <Badge key={t} tone="indigo">{t}</Badge>)}
                            {(m.tags || []).length > 4 && <span className="text-xs text-gray-400">+{m.tags.length - 4}</span>}
                            {(!m.tags || m.tags.length === 0) && <span className="text-xs text-gray-300">—</span>}
                          </div>
                        </td>
                        <td className="px-6 py-3 text-right font-medium text-gray-900">{aed2(m.total)}</td>
                      </tr>
                      {open && expandable && (
                        <tr className="bg-slate-50/60">
                          <td colSpan="7" className="px-6 py-4">
                            <div className="space-y-2">
                              <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Workshop events for this visit</p>
                              {events.map((e) => (
                                <div key={e.id} className="flex flex-wrap items-start gap-x-4 gap-y-1 rounded-xl bg-white px-4 py-2.5 text-sm shadow-soft ring-1 ring-inset ring-slate-100">
                                  <Badge tone={EVENT_TONE[e.event] || 'gray'}>{e.event || '—'}</Badge>
                                  <span className="text-gray-500">
                                    {e.date ? fmtDate(e.date) : 'No date'}
                                    {e.actual_in && e.actual_in !== e.date && <span className="text-gray-400"> → returned {fmtDate(e.actual_in)}</span>}
                                  </span>
                                  {e.garage && <span className="font-medium text-slate-600">{e.garage}</span>}
                                  {e.type && <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{e.type}</span>}
                                  {(e.issues || []).map((t) => <Badge key={t} tone="indigo">{t}</Badge>)}
                                  {e.cost != null && Number(e.cost) > 0 && <span className="font-semibold text-slate-700">{aed2(e.cost)}</span>}
                                  {e.notes && <div className="w-full pt-1"><NotesList text={e.notes} /></div>}
                                </div>
                              ))}
                            </div>
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  );
                })}
                {maintenance.length === 0 && (
                  <tr><td colSpan="7" className="px-6 py-8 text-center text-gray-400">No maintenance recorded for this vehicle yet.</td></tr>
                )}
              </tbody>
            </table>
          </div>
          {maintenance.length > VISITS_PREVIEW && (
            <div className="border-t border-gray-100 px-6 py-3 text-center">
              <button
                type="button"
                onClick={() => setShowAllVisits((s) => !s)}
                className="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 transition hover:text-indigo-700"
              >
                {showAllVisits ? 'Show less' : `Show all ${num(maintenance.length)} visits`}
                <svg className={`h-4 w-4 transition-transform ${showAllVisits ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M19 9l-7 7-7-7" /></svg>
              </button>
            </div>
          )}
        </Card>

        {/* Maintenance Log — a timeline of every workshop event for this car. Click any event for full detail. */}
        {maintenanceLog.length > 0 && (
          <Card>
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
              <div>
                <h3 className="text-base font-semibold text-slate-900">Maintenance Log</h3>
                <p className="mt-0.5 text-xs text-slate-400">Every workshop event for this car, newest first · tap any event for the full record.</p>
              </div>
              <Badge tone="gray">{num(maintenanceLog.length)} {maintenanceLog.length === 1 ? 'event' : 'events'}</Badge>
            </div>

            <div className="relative px-6 py-6">
              {/* the connecting line behind the markers */}
              <span aria-hidden className="pointer-events-none absolute bottom-8 left-[2.625rem] top-8 w-px bg-gradient-to-b from-slate-200 via-slate-200 to-transparent" />
              <ol className="stagger space-y-3">
                {(showAllLog ? maintenanceLog : maintenanceLog.slice(0, LOG_PREVIEW)).map((m) => {
                  const tone = EVENT_TONE[m.event] || 'gray';
                  const st = EVENT_STYLE[tone] || EVENT_STYLE.gray;
                  const icon = EVENT_ICON[m.event] || DEFAULT_EVENT_ICON;
                  return (
                    <li key={m.id} className="relative flex gap-4">
                      {/* timeline marker — now an icon chip */}
                      <span className={`relative z-10 mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full ring-4 ring-white ${st.soft} ${st.text}`}>
                        <svg className="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={icon} /></svg>
                      </span>

                      {/* event card — a button so the whole row is clickable & keyboard-accessible */}
                      <button
                        type="button"
                        onClick={() => setLogEvent(m)}
                        className={`group min-w-0 flex-1 rounded-2xl border border-slate-200/60 bg-white p-4 text-left shadow-soft transition-all duration-200 hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-card hover:ring-2 ${st.ring} focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400`}
                      >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                          <div className="flex flex-wrap items-center gap-2">
                            <Badge tone={tone}>{m.event || '—'}</Badge>
                            {(m.type || m.severity) && (
                              <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{m.type || m.severity}</span>
                            )}
                          </div>
                          <div className="flex items-center gap-2">
                            <span className="text-xs font-medium text-slate-400">{m.date ? fmtDate(m.date) : 'No date'}</span>
                            <svg className="h-4 w-4 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M9 5l7 7-7 7" /></svg>
                          </div>
                        </div>

                        <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
                          {m.garage && (
                            <span className="inline-flex items-center gap-1.5">
                              <svg className="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d="M3 9l9-6 9 6v11a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z" /></svg>
                              <span className="font-medium text-slate-600">{m.garage}</span>
                            </span>
                          )}
                          {m.cost != null && (
                            <span className="font-semibold text-slate-700">{aed2(m.cost)}</span>
                          )}
                        </div>

                        {m.notes && (
                          <div className="mt-3 rounded-xl bg-slate-50/70 p-3 ring-1 ring-inset ring-slate-100">
                            <p className="line-clamp-2 text-sm leading-relaxed text-slate-600">
                              {String(m.notes).split(/\r?\n/).map((l) => l.trim()).filter(Boolean).join(' · ')}
                            </p>
                            <span className="mt-1.5 inline-block text-[11px] font-medium text-indigo-500 opacity-0 transition group-hover:opacity-100">Read full notes →</span>
                          </div>
                        )}
                      </button>
                    </li>
                  );
                })}
              </ol>

              {maintenanceLog.length > LOG_PREVIEW && (
                <div className="mt-4 flex justify-center">
                  <button
                    type="button"
                    onClick={() => setShowAllLog((s) => !s)}
                    className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-4 py-1.5 text-sm font-medium text-indigo-600 shadow-soft transition hover:border-slate-300 hover:text-indigo-700"
                  >
                    {showAllLog ? 'Show less' : `Show all ${num(maintenanceLog.length)} events`}
                    <svg className={`h-4 w-4 transition-transform ${showAllLog ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M19 9l-7 7-7-7" /></svg>
                  </button>
                </div>
              )}
            </div>
          </Card>
        )}

        {/* Cost analysis — per-service price trend & vs-fleet comparison */}
        {analytics.length > 0 && (
          <Card>
            <div className="border-b border-gray-100 px-6 py-4">
              <h3 className="text-base font-semibold text-gray-900">Cost Analysis</h3>
              <p className="mt-0.5 text-xs text-gray-400">Latest price vs the previous one, and this car vs the fleet average.</p>
            </div>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-100 text-sm stagger-rows">
                <thead className="bg-gray-50/60">
                  <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <th className="px-6 py-3">Service</th>
                    <th className="px-6 py-3 text-center">Visits</th>
                    <th className="px-6 py-3 text-right">Latest</th>
                    <th className="px-6 py-3 text-right">Trend (vs previous)</th>
                    <th className="px-6 py-3 text-right">This car avg</th>
                    <th className="px-6 py-3 text-right">Fleet avg</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {analytics.map((s) => {
                    const arrow = s.trend === 'up' ? '▲' : s.trend === 'down' ? '▼' : '–';
                    const tone = s.trend === 'up' ? 'text-red-600' : s.trend === 'down' ? 'text-emerald-600' : 'text-gray-400';
                    const vsFleet = (s.fleet_avg != null && s.avg_cost != null) ? s.avg_cost - s.fleet_avg : null;
                    return (
                      <tr key={s.service} className="hover:bg-gray-50/60">
                        <td className="px-6 py-3 font-medium text-gray-900">{s.service}</td>
                        <td className="px-6 py-3 text-center text-gray-500">{s.visits}</td>
                        <td className="px-6 py-3 text-right font-medium text-gray-900">{aed2(s.latest_cost)}</td>
                        <td className={`px-6 py-3 text-right font-medium ${tone}`}>
                          {s.delta != null ? `${arrow} ${aed2(Math.abs(s.delta))}` : '—'}
                        </td>
                        <td className="px-6 py-3 text-right text-gray-700">{aed2(s.avg_cost)}</td>
                        <td className="px-6 py-3 text-right text-gray-700">
                          {s.fleet_avg != null ? aed2(s.fleet_avg) : '—'}
                          {vsFleet != null && vsFleet !== 0 && (
                            <span className={`ml-1 text-xs ${vsFleet > 0 ? 'text-red-500' : 'text-emerald-600'}`}>
                              {vsFleet > 0 ? '(above)' : '(below)'}
                            </span>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </Card>
        )}

        {/* Contract history */}
        <Card>
          <div className="flex items-center justify-between border-b border-gray-100 px-6 py-4">
            <h3 className="text-base font-semibold text-gray-900">Contract History</h3>
            <Badge tone="gray">{num(contracts.length)} total</Badge>
          </div>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-100 text-sm stagger-rows">
              <thead className="bg-gray-50/60">
                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                  <th className="px-6 py-3">Contract</th>
                  <th className="px-6 py-3">Customer</th>
                  <th className="px-6 py-3">Type</th>
                  <th className="px-6 py-3">State</th>
                  <th className="px-6 py-3">Out</th>
                  <th className="px-6 py-3">In</th>
                  <th className="px-6 py-3 text-right">Debit</th>
                  <th className="px-6 py-3 text-right">Credit</th>
                  <th className="px-6 py-3 text-right">Balance</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {(showAllContracts ? sortedContracts : sortedContracts.slice(0, CONTRACTS_PREVIEW)).map((c) => (
                  <tr key={c.id} className="hover:bg-gray-50/60">
                    <td className="px-6 py-3 font-medium">
                      <Link to={`/contracts/${c.id}`} className="text-indigo-600 hover:text-indigo-700">#{c.contract_no || c.id}</Link>
                    </td>
                    <td className="px-6 py-3">
                      {c.customer_id
                        ? <Link to={`/customers/${c.customer_id}`} className="text-indigo-600 hover:text-indigo-700">{c.customer || `#${c.customer_id}`}</Link>
                        : <span className="text-gray-400">—</span>}
                    </td>
                    <td className="px-6 py-3"><ContractTypeBadge type={c.contract_type} /></td>
                    <td className="px-6 py-3"><ContractStateBadge state={c.state} /></td>
                    <td className="px-6 py-3 text-gray-500">{fmtDate(c.out_date)}</td>
                    <td className="px-6 py-3 text-gray-500">{fmtDate(c.in_date)}</td>
                    <td className="px-6 py-3 text-right text-gray-600">{aed2(c.debit)}</td>
                    <td className="px-6 py-3 text-right text-gray-600">{aed2(c.credit)}</td>
                    <td className="px-6 py-3 text-right">
                      <Badge tone={Number(c.balance) > 0 ? 'red' : Number(c.balance) < 0 ? 'green' : 'gray'}>{aed2(c.balance)}</Badge>
                    </td>
                  </tr>
                ))}
                {contracts.length === 0 && (
                  <tr><td colSpan="9" className="px-6 py-8 text-center text-gray-400">No contracts for this vehicle.</td></tr>
                )}
              </tbody>
            </table>
          </div>
          {contracts.length > CONTRACTS_PREVIEW && (
            <div className="border-t border-gray-100 px-6 py-3 text-center">
              <button
                type="button"
                onClick={() => setShowAllContracts((s) => !s)}
                className="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 transition hover:text-indigo-700"
              >
                {showAllContracts ? 'Show less' : `Show all ${num(contracts.length)} contracts`}
                <svg className={`h-4 w-4 transition-transform ${showAllContracts ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M19 9l-7 7-7-7" /></svg>
              </button>
            </div>
          )}
        </Card>
      </div>

      {/* Send to Maintenance */}
      <Modal
        open={maintOpen}
        onClose={() => !busy && closeMaint()}
        title="Send to Maintenance"
        subtitle={v.plate_no || v.vin}
        footer={(
          <>
            <Button variant="secondary" onClick={closeMaint} disabled={busy}>Cancel</Button>
            {conflict
              ? <Button variant="danger" onClick={() => sendToMaintenance(true)} loading={busy}>Send anyway</Button>
              : <Button onClick={() => sendToMaintenance(false)} loading={busy}>Send to Maintenance</Button>}
          </>
        )}
      >
        <div className="space-y-4">
          {conflict && (
            <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm">
              <p className="font-medium text-red-700">⚠ This car is reserved</p>
              <p className="mt-1 text-red-600">{conflict.message}</p>
              {conflict.reservations?.length > 0 && (
                <ul className="mt-2 space-y-1 text-xs text-red-600">
                  {conflict.reservations.map((r, i) => (
                    <li key={i}>• Reservation {r.label}{r.customer ? ` — ${r.customer}` : ''} ({r.reason})</li>
                  ))}
                </ul>
              )}
              <p className="mt-2 text-xs text-gray-500">Set an expected return date before the reservation starts, or press <span className="font-medium">Send anyway</span> to override.</p>
            </div>
          )}
          <p className="text-sm text-gray-500">This closes any open rental and opens a maintenance record — the car immediately shows as "in maintenance".</p>
          <label className="block">
            <span className="mb-1 block text-sm font-medium text-gray-700">Garage</span>
            <SearchSelect
              value={maintForm.vendor_id}
              onChange={(val) => setMaintForm((f) => ({ ...f, vendor_id: val }))}
              options={vendors.map((vn) => ({ id: vn.id, label: vn.name || `#${vn.id}`, sub: vn.type || '' }))}
              placeholder="Search garage…"
            />
          </label>
          <Input
            label="Expected Return"
            type="date"
            value={maintForm.expected_return_date}
            onChange={(e) => { setMaintForm((f) => ({ ...f, expected_return_date: e.target.value })); setConflict(null); }}
          />
        </div>
      </Modal>

      {/* Maintenance Log — event detail */}
      <Modal
        open={!!logEvent}
        onClose={() => setLogEvent(null)}
        size="lg"
        title="Workshop Event"
        subtitle={logEvent ? `${[v.make, v.model].filter(Boolean).join(' ')}${v.plate_no ? ` · ${v.plate_no}` : ''}` : ''}
        footer={<Button variant="secondary" onClick={() => setLogEvent(null)}>Close</Button>}
      >
        {logEvent && (() => {
          const tone = EVENT_TONE[logEvent.event] || 'gray';
          const st = EVENT_STYLE[tone] || EVENT_STYLE.gray;
          const icon = EVENT_ICON[logEvent.event] || DEFAULT_EVENT_ICON;
          return (
            <div className="space-y-5">
              {/* headline */}
              <div className="flex items-start gap-4 rounded-2xl border border-slate-200/60 bg-slate-50/60 p-4">
                <span className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-xl ${st.soft} ${st.text}`}>
                  <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={icon} /></svg>
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge tone={tone}>{logEvent.event || '—'}</Badge>
                    {(logEvent.type || logEvent.severity) && (
                      <span className="rounded-md bg-white px-2 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-200">{logEvent.type || logEvent.severity}</span>
                    )}
                  </div>
                  <p className="mt-1.5 text-sm text-slate-500">
                    {logEvent.date ? fmtDate(logEvent.date) : 'No date recorded'}
                    {logEvent.actual_in && logEvent.actual_in !== logEvent.date && <span className="text-slate-400"> → returned {fmtDate(logEvent.actual_in)}</span>}
                  </p>
                </div>
                {logEvent.cost != null && Number(logEvent.cost) > 0 && (
                  <div className="shrink-0 text-right">
                    <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">Cost</p>
                    <p className="text-lg font-bold text-slate-900">{aed2(logEvent.cost)}</p>
                  </div>
                )}
              </div>

              {/* facts grid */}
              <div className="grid grid-cols-1 gap-x-8 sm:grid-cols-2">
                <Field label="Event" value={logEvent.event} />
                <Field label="Date" value={logEvent.date ? fmtDate(logEvent.date) : '—'} />
                <Field label="Garage" value={logEvent.garage} />
                <Field label="Returned" value={logEvent.actual_in ? fmtDate(logEvent.actual_in) : '—'} />
                <Field label="Type" value={logEvent.type} />
                <Field label="Severity" value={logEvent.severity} />
                <Field label="Cost" value={logEvent.cost != null ? aed2(logEvent.cost) : '—'} />
                {logEvent.contract_id && (
                  <div className="flex justify-between gap-4 py-1.5 text-sm">
                    <span className="text-gray-500">Contract</span>
                    <Link to={`/contracts/${logEvent.contract_id}`} className="font-medium text-indigo-600 hover:text-indigo-700">#{logEvent.contract_no || logEvent.contract_id}</Link>
                  </div>
                )}
              </div>

              {/* issue tags */}
              {(logEvent.issues || logEvent.tags || []).length > 0 && (
                <div>
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Issues</p>
                  <div className="flex flex-wrap gap-1.5">
                    {(logEvent.issues || logEvent.tags).map((t) => <Badge key={t} tone="indigo">{t}</Badge>)}
                  </div>
                </div>
              )}

              {/* full notes */}
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Workshop Notes</p>
                <div className="rounded-xl bg-slate-50/70 p-4 ring-1 ring-inset ring-slate-100">
                  <NotesList text={logEvent.notes} />
                </div>
              </div>
            </div>
          );
        })()}
      </Modal>
    </div>
  );
}
