// Mileage Investigation Center (/oversight/mileage) — the enterprise investigation surface over every
// workflow stage where the odometer entered didn't line up with what was expected: a reading that ran
// backwards (a real data error), a big forward jump, a garage test-drive, an authorized override, or a
// rejected (blocked) attempt the workflow threw away. Each row answers, at a glance: what happened,
// between which workflow stages, why, who entered it, who approved it, whether it's still under
// investigation, and — via the side drawer — the complete stage-by-stage timeline. Presentation only:
// all classification (kind/status/severity/reason/source) is derived from the read-only data returned
// by GET /Oversight/mileage-discrepancies. No business logic lives here.

import { useEffect, useMemo, useState } from 'react';
import AuditAnalytics from '../../components/analytics/AuditAnalytics';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../../components/ui/Icon';
import { Skeleton } from '../../components/ui/Skeleton';

const fmtKm = (n) => (n == null ? '—' : `${Number(n).toLocaleString()} km`);
const fmtNum = (n) => (n == null ? '—' : Number(n).toLocaleString());
const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }) : '—');
const fmtDateTime = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');
const fmtTime = (iso) => (iso ? new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) : '');

// The From → To node labels for each captured stage, so a single row reads as a workflow transition
// (Inspection ↓ Test Drive) instead of one flat label. Falls back to the raw stage_label.
const STAGE_FLOW = {
  test_drive: { from: 'Inspection',   to: 'Test Drive' },
  report:     { from: 'Test Drive',   to: 'Diagnosis' },
  dispatch:   { from: 'Diagnosis',    to: 'Garage Dispatch' },
  receive:    { from: 'In Transit',   to: 'Garage Arrival' },
  transfer:   { from: 'Garage',       to: 'Garage Transfer' },
  return:     { from: 'Garage',       to: 'Collected' },
  reinspect:  { from: 'Return',       to: 'Re-Inspection' },
  park:       { from: 'Garage',       to: 'Fleet Park' },
};

// Map a flag stage_key onto the matching node key in the drawer timeline (TIMELINE_SPEC uses the past
// tense for some stages) so the "this reading" node can be highlighted.
const FLAG_TO_TIMELINE = {
  test_drive: 'test_drive', report: 'report', dispatch: 'dispatched',
  receive: 'received', return: 'collected', reinspect: 'closed',
};

const SEVERITY = {
  normal:     { labelKey: 'oversight.mileage.sevNormal',     dot: 'bg-emerald-500', bar: 'border-l-emerald-400', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  small:      { labelKey: 'oversight.mileage.sevSmall',      dot: 'bg-amber-400',   bar: 'border-l-amber-400',   chip: 'bg-amber-50 text-amber-700 ring-amber-200' },
  suspicious: { labelKey: 'oversight.mileage.sevSuspicious', dot: 'bg-orange-500',  bar: 'border-l-orange-500',  chip: 'bg-orange-50 text-orange-700 ring-orange-200' },
  critical:   { labelKey: 'oversight.mileage.sevCritical',   dot: 'bg-red-600',     bar: 'border-l-red-500',     chip: 'bg-red-50 text-red-700 ring-red-200' },
};

// Reason — the plain-language "why" behind a flagged reading, derived from kind + direction.
function reasonOf(r) {
  switch (r.kind) {
    case 'blocked':     return { key: 'blocked',    labelKey: 'oversight.mileage.reasonBlocked',    icon: 'XCircle' };
    case 'discrepancy': return r.direction === 'lower'
      ? { key: 'reverse',    labelKey: 'oversight.mileage.reasonReverse',    icon: 'TrendDown' }
      : { key: 'correction', labelKey: 'oversight.mileage.reasonCorrection', icon: 'Refresh' };
    case 'jump':        return { key: 'roadtest',   labelKey: 'oversight.mileage.reasonRoadTest',   icon: 'TrendUp' };
    case 'test_drive':  return { key: 'testdrive',  labelKey: 'oversight.mileage.reasonTestDrive',  icon: 'Route' };
    case 'deviation':   return { key: 'override',   labelKey: 'oversight.mileage.reasonOverride',   icon: 'Shield' };
    default:            return { key: 'manual',     labelKey: 'oversight.mileage.reasonManual',     icon: 'Info' };
  }
}

// Approval status — the workflow disposition of the reading (accepted / still under investigation /
// rejected / reviewed), color-coded so distinct states read instantly.
function statusOf(r) {
  if (r.kind === 'blocked')     return { key: 'rejected', labelKey: 'oversight.mileage.statRejected', cls: 'bg-red-600 text-white' };
  if (r.kind === 'discrepancy') return { key: 'pending',  labelKey: 'oversight.mileage.statPending',  cls: 'bg-red-100 text-red-700 ring-1 ring-red-200' };
  if (r.kind === 'jump')        return { key: 'pending',  labelKey: 'oversight.mileage.statPending',  cls: 'bg-amber-100 text-amber-800 ring-1 ring-amber-200' };
  if (r.kind === 'test_drive')  return { key: 'reviewed', labelKey: 'oversight.mileage.statReviewed', cls: 'bg-indigo-100 text-indigo-700 ring-1 ring-indigo-200' };
  if (r.kind === 'ack' && r.confirmed === false) return { key: 'pending', labelKey: 'oversight.mileage.statPending', cls: 'bg-amber-100 text-amber-800 ring-1 ring-amber-200' };
  return { key: 'accepted', labelKey: 'oversight.mileage.statAccepted', cls: 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200' };
}

// Severity — how much the reading should draw the eye, by kind + absolute delta.
function severityOf(r) {
  if (r.kind === 'blocked' || (r.kind === 'discrepancy' && r.direction === 'lower')) return 'critical';
  if (r.delta == null) return 'normal';
  const d = Math.abs(r.delta);
  if (d <= 5) return 'normal';
  if (d <= 20) return 'small';
  if (d <= 100) return 'suspicious';
  return 'critical';
}

// Source — the channel the reading came from, derived from which party owns the stage.
function sourceOf(r) {
  if (!r.entered_by) return { labelKey: 'oversight.mileage.srcSystem', icon: 'Info' };
  switch (r.stage_key) {
    case 'receive': case 'transfer': return { labelKey: 'oversight.mileage.srcGarage', icon: 'Wrench' };
    case 'dispatch': case 'return':  return { labelKey: 'oversight.mileage.srcDriver', icon: 'Truck' };
    case 'test_drive': case 'report': case 'reinspect': return { labelKey: 'oversight.mileage.srcInspector', icon: 'Shield' };
    default: return { labelKey: 'oversight.mileage.srcSystem', icon: 'Info' };
  }
}

function flowOf(r) {
  return STAGE_FLOW[r.stage_key] || { from: null, to: r.stage_label };
}

const STATUS_OPTIONS = ['accepted', 'pending', 'reviewed', 'rejected'];

export default function MileageDiscrepancies() {
  const { t } = useI18n();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [lightbox, setLightbox] = useState(null);
  const [selected, setSelected] = useState(null); // the row whose investigation drawer is open

  // Enterprise toolbar filter state.
  const [q, setQ] = useState('');
  const [fVehicle, setFVehicle] = useState('all');
  const [fStage, setFStage] = useState('all');
  const [fType, setFType] = useState('all');
  const [fPerson, setFPerson] = useState('all');
  const [fStatus, setFStatus] = useState('all');
  const [fFrom, setFFrom] = useState('');
  const [fTo, setFTo] = useState('');
  const [onlyBlocked, setOnlyBlocked] = useState(false);

  useEffect(() => {
    let alive = true;
    api.get('/Oversight/mileage-discrepancies')
      .then((res) => { if (alive) setData(res.data.data); })
      .catch(() => {})
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  const allRows = useMemo(() => data?.rows || [], [data]);
  const kpis = data?.kpis || {};

  // How many flags each plate carries — powers the drawer's "isolated vs recurring" answer.
  const perVehicle = useMemo(() => {
    const m = {};
    allRows.forEach((r) => { if (r.plate_no) m[r.plate_no] = (m[r.plate_no] || 0) + 1; });
    return m;
  }, [allRows]);

  // Distinct option lists for the toolbar selects.
  const options = useMemo(() => {
    const vehicles = new Set(), stages = new Set(), types = new Set(), people = new Set();
    allRows.forEach((r) => {
      if (r.plate_no) vehicles.add(r.plate_no);
      if (r.stage_key) stages.add(r.stage_key);
      if (r.kind) types.add(r.kind);
      if (r.entered_by) people.add(r.entered_by);
    });
    return {
      vehicles: [...vehicles].sort(),
      stages: [...stages],
      types: [...types],
      people: [...people].sort(),
    };
  }, [allRows]);

  const rows = useMemo(() => {
    let r = allRows;
    if (onlyBlocked) r = r.filter((x) => x.kind === 'blocked');
    if (fVehicle !== 'all') r = r.filter((x) => x.plate_no === fVehicle);
    if (fStage !== 'all') r = r.filter((x) => x.stage_key === fStage);
    if (fType !== 'all') r = r.filter((x) => x.kind === fType);
    if (fPerson !== 'all') r = r.filter((x) => x.entered_by === fPerson);
    if (fStatus !== 'all') r = r.filter((x) => statusOf(x).key === fStatus);
    if (fFrom) { const from = new Date(fFrom); r = r.filter((x) => x.at && new Date(x.at) >= from); }
    if (fTo) { const to = new Date(fTo); to.setHours(23, 59, 59, 999); r = r.filter((x) => x.at && new Date(x.at) <= to); }
    const term = q.trim().toLowerCase();
    if (term) r = r.filter((x) => `${x.plate_no} ${x.car} ${x.stage_label} ${x.entered_by || ''} ${x.note || ''}`.toLowerCase().includes(term));
    return r;
  }, [allRows, onlyBlocked, fVehicle, fStage, fType, fPerson, fStatus, fFrom, fTo, q]);

  const activeFilters = onlyBlocked || fVehicle !== 'all' || fStage !== 'all' || fType !== 'all' || fPerson !== 'all' || fStatus !== 'all' || fFrom || fTo || q;
  const resetFilters = () => {
    setOnlyBlocked(false); setFVehicle('all'); setFStage('all'); setFType('all');
    setFPerson('all'); setFStatus('all'); setFFrom(''); setFTo(''); setQ('');
  };

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1280px] space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Header */}
        <div>
          <Link to="/apps/reports" className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-400 hover:text-slate-600">
            <Icon.ArrowRight className="h-3 w-3 rotate-180" /> Reports
          </Link>
          <h1 className="font-display text-2xl font-bold tracking-tight text-slate-900">{t('oversight.mileage.centerTitle')}</h1>
          <p className="mt-1 max-w-3xl text-sm text-slate-500">{t('oversight.mileage.subtitle')}</p>
        </div>

        {loading ? (
          <>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4"><Skeleton className="h-24 rounded-2xl" /><Skeleton className="h-24 rounded-2xl" /><Skeleton className="h-24 rounded-2xl" /><Skeleton className="h-24 rounded-2xl" /></div>
            <Skeleton className="h-72 rounded-2xl" />
          </>
        ) : (
          <>
            {/* KPI cards */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <Kpi icon="Flag" label={t('oversight.mileage.kpiFlagged')} value={fmtNum(kpis.flagged ?? data?.total)} />
              <Kpi icon="XCircle" label={t('oversight.mileage.kpiBlocked')} value={fmtNum(kpis.blocked ?? data?.blocked)} tone={(kpis.blocked ?? data?.blocked) ? 'red' : 'slate'} />
              <Kpi
                icon="TrendUp"
                label={t('oversight.mileage.kpiLargest')}
                value={kpis.largest_jump ? `${kpis.largest_jump.delta > 0 ? '+' : ''}${fmtNum(kpis.largest_jump.delta)}` : '—'}
                sub={kpis.largest_jump?.plate_no}
                tone={kpis.largest_jump && Math.abs(kpis.largest_jump.delta) > 100 ? 'amber' : 'slate'}
              />
              <Kpi icon="Gauge" label={t('oversight.mileage.kpiAvg')} value={kpis.avg_deviation != null ? `${fmtNum(kpis.avg_deviation)} km` : '—'} />
            </div>

            {/* Hotspots */}
            <div className="rounded-2xl border border-slate-200/70 bg-white p-4 shadow-soft">
              <div className="mb-3 flex items-center gap-2">
                <Icon.Activity className="h-4 w-4 text-indigo-500" />
                <h2 className="text-sm font-semibold text-slate-800">{t('oversight.mileage.hotspots')}</h2>
              </div>
              <div className="grid gap-4 sm:grid-cols-3">
                <Hotspot t={t} icon="Car" title={t('oversight.mileage.topVehicles')} items={kpis.top_vehicles} />
                <Hotspot t={t} icon="Users" title={t('oversight.mileage.topDrivers')} items={kpis.top_drivers} />
                <Hotspot t={t} icon="Wrench" title={t('oversight.mileage.topGarages')} items={kpis.top_garages} />
              </div>
            </div>

            {/* Enterprise toolbar */}
            <div className="rounded-2xl border border-slate-200/70 bg-white p-4 shadow-soft">
              <div className="mb-3 flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                  <Icon.Filter className="h-4 w-4 text-slate-500" />
                  <h2 className="text-sm font-semibold text-slate-800">{t('oversight.mileage.filters')}</h2>
                  <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">
                    {t('oversight.mileage.resultsCount', { n: rows.length, total: allRows.length })}
                  </span>
                </div>
                {activeFilters ? (
                  <button onClick={resetFilters} className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-slate-500 hover:bg-slate-100 hover:text-slate-700">
                    <Icon.X className="h-3.5 w-3.5" /> {t('oversight.mileage.reset')}
                  </button>
                ) : null}
              </div>

              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {/* Search spans wide */}
                <div className="sm:col-span-2 lg:col-span-2">
                  <Label>{t('common.search')}</Label>
                  <div className="relative">
                    <Icon.Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('common.search')}
                      className="w-full rounded-lg border border-slate-200 bg-white py-2 ps-9 pe-3 text-sm outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100" />
                  </div>
                </div>
                <Select label={t('oversight.mileage.fVehicle')} value={fVehicle} onChange={setFVehicle}
                  options={[['all', t('oversight.mileage.allVehicles')], ...options.vehicles.map((v) => [v, v])]} />
                <Select label={t('oversight.mileage.fStage')} value={fStage} onChange={setFStage}
                  options={[['all', t('oversight.mileage.allStages')], ...options.stages.map((s) => [s, (STAGE_FLOW[s]?.to || s)])]} />
                <Select label={t('oversight.mileage.fType')} value={fType} onChange={setFType}
                  options={[['all', t('oversight.mileage.allTypes')], ...options.types.map((k) => [k, t(`oversight.mileage.kind${k.charAt(0).toUpperCase()}${k.slice(1).replace(/_(\w)/g, (m, c) => c.toUpperCase())}`)])]} />
                <Select label={t('oversight.mileage.fEnteredBy')} value={fPerson} onChange={setFPerson}
                  options={[['all', t('oversight.mileage.allPeople')], ...options.people.map((p) => [p, p])]} />
                <Select label={t('oversight.mileage.fStatus')} value={fStatus} onChange={setFStatus}
                  options={[['all', t('oversight.mileage.allStatuses')], ...STATUS_OPTIONS.map((s) => [s, t(`oversight.mileage.stat${s.charAt(0).toUpperCase()}${s.slice(1)}`)])]} />
                <div>
                  <Label>{t('oversight.mileage.fFrom')}</Label>
                  <input type="date" value={fFrom} onChange={(e) => setFFrom(e.target.value)}
                    className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100" />
                </div>
                <div>
                  <Label>{t('oversight.mileage.fTo')}</Label>
                  <input type="date" value={fTo} onChange={(e) => setFTo(e.target.value)}
                    className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100" />
                </div>
                <label className="flex items-center gap-2 self-end pb-2 text-sm font-medium text-slate-600">
                  <input type="checkbox" checked={onlyBlocked} onChange={(e) => setOnlyBlocked(e.target.checked)}
                    className="h-4 w-4 rounded border-slate-300 text-red-600 focus:ring-red-200" />
                  {t('oversight.mileage.onlyBlocked')}
                </label>
              </div>
            </div>

            {/* Table */}
            {rows.length === 0 ? (
              <Empty t={t} />
            ) : (
              <>
              <AuditAnalytics
                rows={rows}
                title="Cars with the largest odometer drift"
                subtitle="Biggest single discrepancy per car — a large gap on one car is a reading error, the same car repeatedly is a process problem"
                magnitude={(r) => r.delta}
                magnitudeLabel="Largest gap"
                magnitudeFormat={(n) => `${Math.round(n).toLocaleString()} km`}
                color="red"
              />
              <div className="overflow-x-auto rounded-2xl border border-slate-200/60 bg-white shadow-soft">
                <table className="w-full min-w-[1080px] border-separate border-spacing-0 text-sm">
                  <thead>
                    <tr className="text-left">
                      <Th>{t('oversight.common.vehicle')}</Th>
                      <Th>{t('oversight.mileage.colTransition')}</Th>
                      <Th>{t('oversight.mileage.colReason')}</Th>
                      <Th className="text-right">{t('oversight.mileage.colReading')}</Th>
                      <Th>{t('oversight.mileage.colSeverity')}</Th>
                      <Th>{t('oversight.mileage.colSource')}</Th>
                      <Th>{t('oversight.mileage.colApprovedBy')}</Th>
                      <Th>{t('oversight.mileage.colStatus')}</Th>
                      <Th className="text-right"> </Th>
                    </tr>
                  </thead>
                  <tbody>
                    {rows.map((r, i) => {
                      const sev = SEVERITY[severityOf(r)];
                      const reason = reasonOf(r);
                      const status = statusOf(r);
                      const source = sourceOf(r);
                      const flow = flowOf(r);
                      const RIco = Icon[reason.icon] || Icon.Info;
                      const SIco = Icon[source.icon] || Icon.Info;
                      return (
                        <tr
                          key={`${r.ticket_id}-${r.stage_key}-${i}`}
                          onClick={() => setSelected(r)}
                          className={`cursor-pointer border-l-4 ${sev.bar} transition-colors hover:bg-indigo-50/50 ${i % 2 ? 'bg-slate-50/40' : 'bg-white'}`}
                        >
                          {/* Vehicle */}
                          <td className="border-b border-slate-100 px-4 py-3.5">
                            <span className="font-mono font-semibold text-slate-900">{r.plate_no || `#${r.ticket_id}`}</span>
                            {r.car && <p className="text-xs text-slate-400">{r.car}</p>}
                          </td>
                          {/* Transition From → To */}
                          <td className="border-b border-slate-100 px-4 py-3.5">
                            <Transition from={flow.from} to={flow.to} />
                          </td>
                          {/* Reason */}
                          <td className="border-b border-slate-100 px-4 py-3.5">
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                              <RIco className="h-3.5 w-3.5" /> {t(reason.labelKey)}
                            </span>
                            {r.tolerance_waived && <span className="ms-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500">{t('oversight.mileage.waived')}</span>}
                          </td>
                          {/* Reading comparison */}
                          <td className="border-b border-slate-100 px-4 py-3.5">
                            <ReadingCompare r={r} compact />
                          </td>
                          {/* Severity */}
                          <td className="border-b border-slate-100 px-4 py-3.5">
                            <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ${sev.chip}`}>
                              <span className={`h-2 w-2 rounded-full ${sev.dot}`} /> {t(sev.labelKey)}
                            </span>
                          </td>
                          {/* Source */}
                          <td className="border-b border-slate-100 px-4 py-3.5">
                            <span className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-600">
                              <SIco className="h-3.5 w-3.5 text-slate-400" /> {t(source.labelKey)}
                            </span>
                            {r.photo_url && (
                              <span className="ms-1 inline-flex items-center gap-0.5 text-[10px] font-medium text-emerald-600" title={t('oversight.mileage.photoVerified')}>
                                <Icon.Camera className="h-3 w-3" />
                              </span>
                            )}
                          </td>
                          {/* Approved by */}
                          <td className="border-b border-slate-100 px-4 py-3.5">
                            {r.entered_by ? (
                              <>
                                <p className="font-medium text-slate-700">{r.entered_by}</p>
                                <p className="text-[11px] text-slate-400">
                                  {r.entered_by_role || t('oversight.mileage.unknownRole')}{r.at ? ` · ${fmtTime(r.at)}` : ''}
                                </p>
                              </>
                            ) : <span className="text-xs text-slate-300">{t('oversight.common.system')}</span>}
                            <p className="text-[11px] text-slate-400">{fmtDate(r.at)}</p>
                          </td>
                          {/* Status */}
                          <td className="border-b border-slate-100 px-4 py-3.5">
                            <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${status.cls}`}>{t(status.labelKey)}</span>
                          </td>
                          {/* Action */}
                          <td className="border-b border-slate-100 px-4 py-3.5 text-right">
                            <button
                              type="button"
                              onClick={(e) => { e.stopPropagation(); setSelected(r); }}
                              className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-600 transition hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700"
                            >
                              <Icon.Search className="h-3.5 w-3.5" /> {t('oversight.mileage.viewInvestigation')}
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
              </>
            )}
          </>
        )}
      </div>

      {/* Investigation drawer */}
      {selected && (
        <InvestigationDrawer
          t={t}
          row={selected}
          recurrence={perVehicle[selected.plate_no] || 1}
          onClose={() => setSelected(null)}
          onPhoto={(lb) => setLightbox(lb)}
        />
      )}

      {/* Photo lightbox */}
      {lightbox && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/80 p-4 backdrop-blur-sm" onClick={() => setLightbox(null)}>
          <div className="relative max-h-[90vh] max-w-3xl overflow-hidden rounded-2xl bg-white shadow-2xl" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-3">
              <div>
                <p className="font-mono text-sm font-semibold text-slate-900">{lightbox.plate}</p>
                <p className="text-xs text-slate-500">{lightbox.label} · {t('oversight.mileage.odometerPhoto')}</p>
              </div>
              <button type="button" onClick={() => setLightbox(null)} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" aria-label={t('common.close')}>
                <Icon.X className="h-5 w-5" />
              </button>
            </div>
            <img src={lightbox.url} alt={lightbox.label} className="max-h-[75vh] w-auto object-contain" />
          </div>
        </div>
      )}
    </div>
  );
}

// ── Investigation drawer ──────────────────────────────────────────────────────────────────────────
function InvestigationDrawer({ t, row, recurrence, onClose, onPhoto }) {
  const sev = SEVERITY[severityOf(row)];
  const reason = reasonOf(row);
  const status = statusOf(row);
  const source = sourceOf(row);
  const flow = flowOf(row);
  const timeline = row.timeline || [];
  const activeKey = FLAG_TO_TIMELINE[row.stage_key];

  return (
    <div className="fixed inset-0 z-50 flex justify-end">
      <div className="absolute inset-0 bg-slate-900/40 backdrop-blur-[2px]" onClick={onClose} />
      <div className="relative flex h-full w-full max-w-md flex-col overflow-y-auto bg-white shadow-2xl ring-1 ring-slate-200">
        {/* Header */}
        <div className="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur">
          <div>
            <p className="text-[11px] font-semibold uppercase tracking-wide text-indigo-500">{t('oversight.mileage.investigation')}</p>
            <p className="font-mono text-lg font-bold text-slate-900">{row.plate_no || `#${row.ticket_id}`}</p>
            {row.car && <p className="text-xs text-slate-400">{row.car}</p>}
          </div>
          <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" aria-label={t('common.close')}>
            <Icon.X className="h-5 w-5" />
          </button>
        </div>

        <div className="space-y-5 px-5 py-5">
          {/* Reason + status */}
          <div className="flex flex-wrap items-center gap-2">
            <span className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
              {(() => { const RIco = Icon[reason.icon] || Icon.Info; return <RIco className="h-3.5 w-3.5" />; })()} {t(reason.labelKey)}
            </span>
            <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${status.cls}`}>{t(status.labelKey)}</span>
            <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ${sev.chip}`}>
              <span className={`h-2 w-2 rounded-full ${sev.dot}`} /> {t(sev.labelKey)}
            </span>
          </div>

          {/* Transition */}
          <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
            <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('oversight.mileage.colTransition')}</p>
            <Transition from={flow.from} to={flow.to} big />
          </div>

          {/* Mileage comparison */}
          <div className="rounded-xl border border-slate-200 p-4">
            <ReadingCompare r={row} />
          </div>

          {/* Details grid */}
          <div>
            <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('oversight.mileage.detailsTitle')}</p>
            <dl className="space-y-2.5 text-sm">
              <Detail label={t('oversight.mileage.colSource')}>
                {(() => { const SIco = Icon[source.icon] || Icon.Info; return <span className="inline-flex items-center gap-1.5"><SIco className="h-3.5 w-3.5 text-slate-400" />{t(source.labelKey)}</span>; })()}
              </Detail>
              <Detail label={t('oversight.mileage.colApprovedBy')}>
                {row.entered_by || t('oversight.common.system')}
              </Detail>
              {row.entered_by && (
                <Detail label={t('oversight.mileage.roleLabel')}>{row.entered_by_role || t('oversight.mileage.unknownRole')}</Detail>
              )}
              <Detail label={t('oversight.mileage.approvalTime')}>{fmtDateTime(row.at)}</Detail>
              {row.confirmed != null && (
                <Detail label={t('oversight.mileage.confirmed')}>
                  <span className={row.confirmed ? 'text-emerald-600' : 'text-amber-600'}>
                    {row.confirmed ? t('oversight.mileage.confirmed') : t('oversight.mileage.notConfirmed')}
                  </span>
                </Detail>
              )}
            </dl>
            {row.note && (
              <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm italic text-amber-800 ring-1 ring-amber-100">“{row.note}”</p>
            )}
          </div>

          {/* Odometer photo */}
          {row.photo_url && (
            <button
              type="button"
              onClick={() => onPhoto({ url: row.photo_url, label: row.stage_label, plate: row.plate_no || `#${row.ticket_id}` })}
              className="group flex w-full items-center gap-3 rounded-xl border border-slate-200 p-2 text-left transition hover:border-indigo-300 hover:bg-indigo-50/40"
            >
              <img src={row.photo_url} alt={t('oversight.mileage.odometerPhoto')} className="h-16 w-16 rounded-lg object-cover ring-1 ring-slate-200" loading="lazy" />
              <div>
                <p className="text-sm font-semibold text-slate-700">{t('oversight.mileage.odometerPhoto')}</p>
                <p className="text-xs text-emerald-600">{t('oversight.mileage.photoVerified')}</p>
              </div>
            </button>
          )}

          {/* Recurrence hint */}
          <div className={`flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm ring-1 ${recurrence > 1 ? 'bg-red-50 text-red-700 ring-red-200' : 'bg-emerald-50 text-emerald-700 ring-emerald-200'}`}>
            {recurrence > 1 ? <Icon.Alert className="h-4 w-4 shrink-0" /> : <Icon.Check className="h-4 w-4 shrink-0" />}
            <span>{recurrence > 1 ? t('oversight.mileage.recurrence', { n: recurrence }) : t('oversight.mileage.isolated')}</span>
          </div>

          {/* Investigation timeline */}
          <div>
            <p className="mb-3 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('oversight.mileage.timelineTitle')}</p>
            {timeline.length === 0 ? (
              <p className="rounded-lg bg-slate-50 px-3 py-3 text-sm text-slate-400">{t('oversight.mileage.drawerNoTimeline')}</p>
            ) : (
              <ol className="relative ms-1 space-y-0">
                {timeline.map((s, idx) => {
                  const active = activeKey && s.key === activeKey;
                  const last = idx === timeline.length - 1;
                  return (
                    <li key={s.key} className="relative flex gap-3 pb-5">
                      {!last && <span className="absolute start-[7px] top-4 h-full w-px bg-slate-200" />}
                      <span className={`relative z-10 mt-1 h-3.5 w-3.5 shrink-0 rounded-full ring-4 ring-white ${active ? 'bg-indigo-600' : 'bg-slate-300'}`} />
                      <div className={`flex-1 rounded-lg px-3 py-2 ${active ? 'bg-indigo-50 ring-1 ring-indigo-200' : ''}`}>
                        <div className="flex items-baseline justify-between gap-2">
                          <p className={`text-sm font-semibold ${active ? 'text-indigo-700' : 'text-slate-700'}`}>{s.label}</p>
                          {s.at && <span className="shrink-0 text-[11px] text-slate-400">{fmtTime(s.at)}</span>}
                        </div>
                        <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-slate-500">
                          {s.owner && <span>{s.owner}{s.role ? ` · ${s.role}` : ''}</span>}
                          {s.odometer != null && <span className="font-mono font-semibold text-slate-600">{fmtKm(s.odometer)}</span>}
                        </div>
                        {active && <p className="mt-1 text-[10px] font-semibold uppercase tracking-wide text-indigo-500">{t('oversight.mileage.thisReading')}</p>}
                      </div>
                    </li>
                  );
                })}
              </ol>
            )}
          </div>

          {/* Footer */}
          <Link
            to={`/maintenance-workflow/${row.ticket_id}`}
            className="flex w-full items-center justify-center gap-1.5 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700"
          >
            {t('oversight.mileage.openTicket')} <Icon.ArrowRight className="h-4 w-4" />
          </Link>
        </div>
      </div>
    </div>
  );
}

// ── Small building blocks ───────────────────────────────────────────────────────────────────────
function Transition({ from, to, big }) {
  if (!from) return <span className={`font-semibold text-slate-700 ${big ? 'text-base' : ''}`}>{to}</span>;
  return (
    <div className="inline-flex flex-col leading-tight">
      <span className={`font-medium text-slate-400 ${big ? 'text-sm' : 'text-xs'}`}>{from}</span>
      <span className="flex items-center gap-1">
        <Icon.ArrowRight className={`rotate-90 text-slate-300 ${big ? 'h-3.5 w-3.5' : 'h-3 w-3'}`} />
        <span className={`font-semibold text-slate-800 ${big ? 'text-base' : 'text-sm'}`}>{to}</span>
      </span>
    </div>
  );
}

function ReadingCompare({ r, compact }) {
  const { t } = useI18n();
  const negative = r.delta != null && r.delta < 0;
  const blocked = r.outcome === 'blocked';
  if (compact) {
    return (
      <div className="text-right tabular-nums">
        <div className="flex items-center justify-end gap-1.5 text-xs text-slate-400">
          <span>{fmtNum(r.previous)}</span>
          <Icon.ArrowRight className="h-3 w-3" />
          <span className={`font-semibold ${blocked ? 'text-red-600 line-through decoration-red-400' : 'text-slate-900'}`}>{fmtNum(r.reading)}</span>
        </div>
        <p className={`text-sm font-bold ${negative ? 'text-red-600' : 'text-slate-600'}`}>
          {r.delta == null ? '—' : `${r.delta > 0 ? '+' : ''}${fmtNum(r.delta)} km`}
        </p>
      </div>
    );
  }
  return (
    <div className="space-y-2 text-center">
      <div>
        <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('oversight.mileage.prevReading')}</p>
        <p className="font-mono text-xl font-bold text-slate-500 tabular-nums">{fmtKm(r.previous)}</p>
      </div>
      <Icon.ArrowRight className="mx-auto h-4 w-4 rotate-90 text-slate-300" />
      <div>
        <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('oversight.mileage.enteredReading')}</p>
        <p className={`font-mono text-2xl font-extrabold tabular-nums ${blocked ? 'text-red-600 line-through decoration-red-400' : 'text-slate-900'}`}>{fmtKm(r.reading)}</p>
      </div>
      <div className="mt-1 inline-block rounded-lg bg-slate-50 px-3 py-1 ring-1 ring-slate-200">
        <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('oversight.mileage.difference')}: </span>
        <span className={`text-sm font-bold tabular-nums ${negative ? 'text-red-600' : 'text-slate-700'}`}>
          {r.delta == null ? '—' : `${r.delta > 0 ? '+' : ''}${fmtNum(r.delta)} km`}
        </span>
      </div>
    </div>
  );
}

function Detail({ label, children }) {
  return (
    <div className="flex items-start justify-between gap-3">
      <dt className="shrink-0 text-slate-400">{label}</dt>
      <dd className="text-end font-medium text-slate-700">{children}</dd>
    </div>
  );
}

function Kpi({ icon, label, value, sub, tone = 'slate' }) {
  const Ico = Icon[icon] || Icon.Info;
  const toneCls = tone === 'red' ? 'text-red-600' : tone === 'amber' ? 'text-amber-600' : 'text-slate-900';
  const ring = tone === 'red' ? 'ring-red-200 bg-red-50/40' : 'ring-slate-200 bg-white';
  return (
    <div className={`rounded-2xl p-4 shadow-soft ring-1 ${ring}`}>
      <div className="mb-2 flex items-center gap-2">
        <Ico className={`h-4 w-4 ${tone === 'red' ? 'text-red-400' : 'text-slate-400'}`} />
        <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</p>
      </div>
      <p className={`text-2xl font-bold tabular-nums ${toneCls}`}>{value}</p>
      {sub && <p className="mt-0.5 truncate font-mono text-xs text-slate-400">{sub}</p>}
    </div>
  );
}

function Hotspot({ t, icon, title, items }) {
  const Ico = Icon[icon] || Icon.Info;
  const list = items || [];
  const max = list.length ? Math.max(...list.map((i) => i.count)) : 1;
  return (
    <div>
      <div className="mb-2 flex items-center gap-1.5 text-xs font-semibold text-slate-500">
        <Ico className="h-3.5 w-3.5 text-slate-400" /> {title}
      </div>
      {list.length === 0 ? (
        <p className="text-xs text-slate-300">{t('oversight.mileage.noHotspots')}</p>
      ) : (
        <ul className="space-y-1.5">
          {list.map((it, i) => (
            <li key={i} className="flex items-center gap-2">
              <span className="w-28 shrink-0 truncate text-xs font-medium text-slate-600" title={it.label}>{it.label}</span>
              <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                <span className="block h-full rounded-full bg-indigo-400" style={{ width: `${Math.round((it.count / max) * 100)}%` }} />
              </span>
              <span className="w-8 shrink-0 text-end text-xs font-semibold tabular-nums text-slate-500">{t('oversight.mileage.timesN', { n: it.count })}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function Label({ children }) {
  return <label className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{children}</label>;
}

function Select({ label, value, onChange, options }) {
  return (
    <div>
      <Label>{label}</Label>
      <select
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100"
      >
        {options.map(([val, lbl]) => <option key={val} value={val}>{lbl}</option>)}
      </select>
    </div>
  );
}

function Th({ children, className = '' }) {
  return <th className={`whitespace-nowrap border-b border-slate-200 bg-slate-50/90 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500 ${className}`}>{children}</th>;
}

function Empty({ t }) {
  return (
    <div className="flex flex-col items-center justify-center rounded-2xl bg-white py-20 text-center shadow-sm ring-1 ring-slate-200">
      <Icon.Check className="h-10 w-10 text-emerald-500" />
      <p className="mt-3 text-sm font-medium text-slate-700">{t('oversight.common.empty')}</p>
      <p className="text-xs text-slate-400">{t('oversight.common.emptyBody')}</p>
    </div>
  );
}
