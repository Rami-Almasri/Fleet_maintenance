import { Fragment, useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import { Card, PageHeader, Spinner, EmptyState, SearchInput } from '../components/ui/Misc';
import { Select } from '../components/ui/Field';
import DamageAnalytics from '../components/analytics/DamageAnalytics';
import { aed2, fmtDate, num } from '../lib/format';

const FAULTS = [
  { value: '', label: 'All faults' },
  { value: 'renter', label: 'Renter at fault (red)' },
  { value: 'third_party', label: 'Third party (green)' },
  { value: 'unspecified', label: 'Not specified' },
];

const LEVELS = [
  { value: '', label: 'All categories' },
  { value: 'critical', label: 'Critical' },
  { value: 'minor', label: 'Minor' },
  { value: 'routine', label: 'Routine' },
  { value: 'special', label: 'Special' },
];

// Tone for a Maintenance Reason level (the cross-referenced category).
const LEVEL_TONE = { critical: 'red', minor: 'amber', routine: 'gray', special: 'violet' };

// The category resolved by cross-referencing MAIN with the Maintenance Reason table.
function ReasonBadge({ reason, level }) {
  if (!reason) return <span className="text-slate-300">—</span>;
  return <Badge tone={LEVEL_TONE[level] || 'slate'}>{reason}</Badge>;
}

// Red = renter fault · Green = third party · Grey = the record doesn't say.
function FaultBadge({ fault, liable }) {
  if (fault === 'renter') return <Badge tone="red" dot>Renter at fault</Badge>;
  if (fault === 'third_party') return <Badge tone="green" dot>Third party</Badge>;
  return <Badge tone="gray" dot>{liable || 'Not specified'}</Badge>;
}

function Stat({ label, value, tone = 'slate' }) {
  const ring = { slate: 'ring-slate-200', red: 'ring-red-200', green: 'ring-emerald-200', amber: 'ring-amber-200' }[tone];
  const text = { slate: 'text-slate-900', red: 'text-red-600', green: 'text-emerald-600', amber: 'text-amber-600' }[tone];
  return (
    <Card className={`ring-1 ${ring}`}>
      <div className="px-5 py-4">
        <p className="text-xs font-medium text-slate-500">{label}</p>
        <p className={`mt-1 text-2xl font-bold ${text}`}>{value}</p>
      </div>
    </Card>
  );
}

function IncidentRow({ inc, open, onToggle }) {
  const details = [inc.service_sup, inc.service_main].filter(Boolean);
  return (
    <Fragment>
      <tr className="cursor-pointer transition-colors hover:bg-indigo-50/40" onClick={onToggle}>
        <td className="px-4 py-3">
          <Link to={`/vehicles/${inc.vehicle_id}`} onClick={(e) => e.stopPropagation()} className="font-medium text-indigo-600 hover:text-indigo-700">
            {inc.plate || `#${inc.vehicle_id}`}
          </Link>
          <div className="text-xs text-slate-400">{inc.car || '—'}</div>
        </td>
        <td className="px-4 py-3 whitespace-nowrap text-slate-500">{fmtDate(inc.date)}</td>
        <td className="px-4 py-3">
          <div className="text-slate-700">{inc.type || '—'}</div>
          <div className="mt-0.5 flex flex-wrap gap-1">
            {inc.severity && <Badge tone={inc.severity === 'High' ? 'red' : inc.severity === 'Medium' ? 'amber' : 'gray'}>{inc.severity}</Badge>}
            {inc.damage_location && <Badge tone="slate">{inc.damage_location}</Badge>}
            {inc.insurance && <Badge tone={inc.insurance === 'with' ? 'green' : 'red'}>{inc.insurance === 'with' ? 'Insured' : 'Uninsured'}</Badge>}
          </div>
        </td>
        <td className="px-4 py-3">
          <ReasonBadge reason={inc.reason} level={inc.level} />
          {inc.service_main && <div className="mt-0.5 text-xs text-slate-400" title={details.join(' · ')}>{inc.service_main}</div>}
        </td>
        <td className="px-4 py-3"><FaultBadge fault={inc.fault} liable={inc.liable_party} isAccident={inc.is_accident} /></td>
        <td className="px-4 py-3 text-slate-500">{inc.garage || '—'}</td>
        <td className="px-4 py-3 text-end text-slate-600">{inc.cost > 0 ? aed2(inc.cost) : '—'}</td>
        <td className="px-4 py-3 text-slate-400">
          <svg className={`h-4 w-4 transition-transform ${open ? 'rotate-90' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M9 18l6-6-6-6" /></svg>
        </td>
      </tr>
      {open && (
        <tr>
          <td colSpan={8} className="bg-slate-50/50 px-4 py-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-1.5 text-sm">
                <Field label="Category" value={inc.reason ? `${inc.reason}${inc.level ? ` (${inc.level})` : ''}` : '— not categorized —'} />
                <Field label="Recorded as" value={inc.type} />
                <Field label="MAIN (key)" value={inc.service_main} />
                <Field label="SUP (refine)" value={inc.service_sup} />
                <Field label="Severity" value={inc.severity} />
                <Field label="Location on car" value={inc.damage_location} />
                <Field label="Driver (recorded)" value={inc.driver} />
                <Field label="Liability (raw)" value={inc.liable_party} />
                <Field label="Garage" value={inc.garage} />
              </div>
              <div className="text-sm">
                <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Notes</p>
                <p className="whitespace-pre-wrap rounded-lg border border-slate-200 bg-white p-3 text-slate-600">{inc.notes || '— No notes recorded —'}</p>
              </div>
            </div>
          </td>
        </tr>
      )}
    </Fragment>
  );
}

function Field({ label, value }) {
  return (
    <div className="flex gap-2">
      <span className="w-32 shrink-0 text-xs font-medium text-slate-400">{label}</span>
      <span className="text-slate-700">{value || '—'}</span>
    </div>
  );
}

const PAGE = 60;

export default function DamageAccidents() {
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Maintenance/incidents');
    return data.data;
  }, []);
  const { data, loading, error } = useFetch(fetcher);
  const [expanded, setExpanded] = useState(() => new Set());
  const [fault, setFault] = useState('');
  const [level, setLevel] = useState('');
  const [accidentsOnly, setAccidentsOnly] = useState(false);
  const [query, setQuery] = useState('');
  const [limit, setLimit] = useState(PAGE);

  const toggle = (id) => setExpanded((prev) => {
    const next = new Set(prev);
    next.has(id) ? next.delete(id) : next.add(id);
    return next;
  });

  const incidents = useMemo(() => {
    const list = data?.incidents || [];
    const q = query.trim().toLowerCase();
    return list.filter((i) => {
      if (fault && i.fault !== fault) return false;
      if (level && i.level !== level) return false;
      if (accidentsOnly && !i.is_accident) return false;
      if (!q) return true;
      return [i.plate, i.car, i.type, i.reason, i.service_sup, i.service_main, i.notes, i.driver]
        .some((v) => (v || '').toLowerCase().includes(q));
    });
  }, [data, query, fault, level, accidentsOnly]);

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;

  const s = data?.summary || {};
  const shown = incidents.slice(0, limit);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Damage & Accidents"
          subtitle="Every accident and damage record from the maintenance log — shown exactly as recorded, colour-coded by who is at fault."
        />

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
          <Stat label="Records" value={num(s.incidents)} />
          <Stat label="Renter at fault" value={num(s.renter)} tone="red" />
          <Stat label="Third party" value={num(s.third_party)} tone="green" />
          <Stat label="Categorized" value={num(s.categorized)} />
          <Stat label="Critical" value={num(s.by_level?.critical)} tone="red" />
        </div>

        {/* Legend — the red/green key, stated plainly. */}
        <div className="rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-2.5 text-xs text-slate-600">
          <span className="me-4 inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-full bg-red-500" /> <b>Red</b> — renter at fault</span>
          <span className="me-4 inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-full bg-emerald-500" /> <b>Green</b> — third party (insured accident)</span>
          <span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-full bg-slate-400" /> <b>Grey</b> — the record doesn’t state fault</span>
        </div>

        {/* Filters */}
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
          <SearchInput className="flex-1" value={query} onChange={setQuery} placeholder="Search plate, car, damage, notes…" />
          <Select className="sm:w-52" value={fault} onChange={(e) => setFault(e.target.value)}>
            {FAULTS.map((f) => <option key={f.value} value={f.value}>{f.label}</option>)}
          </Select>
          <Select className="sm:w-44" value={level} onChange={(e) => setLevel(e.target.value)}>
            {LEVELS.map((l) => <option key={l.value} value={l.value}>{l.label}</option>)}
          </Select>
          <label className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-600 shadow-sm">
            <input type="checkbox" checked={accidentsOnly} onChange={(e) => setAccidentsOnly(e.target.checked)} className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
            Accidents only
          </label>
        </div>

        {/* Analytics — the filtered records, matching the table below. */}
        {incidents.length > 0 && <DamageAnalytics incidents={incidents} />}

        {incidents.length === 0 ? (
          <Card><EmptyState title="No records match" message="Try clearing the fault filter or search." /></Card>
        ) : (
          <Card>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-100 text-sm">
                <thead className="bg-slate-50/90">
                  <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <th className="px-4 py-3">Vehicle</th>
                    <th className="px-4 py-3">Date</th>
                    <th className="px-4 py-3">What happened</th>
                    <th className="px-4 py-3">Category (سبب الصيانة)</th>
                    <th className="px-4 py-3">Fault</th>
                    <th className="px-4 py-3">Garage</th>
                    <th className="px-4 py-3 text-end">Cost</th>
                    <th className="px-4 py-3 w-8"></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-50">
                  {shown.map((inc) => (
                    <IncidentRow key={inc.maint_id} inc={inc} open={expanded.has(inc.maint_id)} onToggle={() => toggle(inc.maint_id)} />
                  ))}
                </tbody>
              </table>
            </div>
            <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-xs text-slate-400">
              <span>Showing {num(shown.length)} of {num(incidents.length)}{incidents.length !== (data?.incidents?.length || 0) ? ` (filtered from ${num(data?.incidents?.length || 0)})` : ''}</span>
              {shown.length < incidents.length && (
                <Button variant="secondary" size="sm" onClick={() => setLimit((l) => l + PAGE)}>Show more</Button>
              )}
            </div>
          </Card>
        )}
      </div>
    </div>
  );
}
