import { useCallback, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { MetricGridSkeleton } from '../../components/ui/Skeleton';
import { CommandPanel, StatGaugeTile } from '../../components/ops';
import Tabs from '../../components/ui/Tabs';
import Badge from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import { Input } from '../../components/ui/Field';
import Icon from '../../components/ui/Icon';
import { useToast } from '../../components/ui/Toast';
import { usePermissions } from '../../hooks/usePermissions';
import { fmtDate, num } from '../../lib/format';

// Per-check presentation. Statuses mirror the backend ContractEligibilityService: pass / warn /
// manager_override / block. A pending pre-rental inspection surfaces as `warn`.
const CHECK_META = {
  pass:             { tone: 'green', Glyph: Icon.Check,   ring: 'text-emerald-500' },
  warn:             { tone: 'amber', Glyph: Icon.Alert,   ring: 'text-amber-500' },
  manager_override: { tone: 'amber', Glyph: Icon.Shield,  ring: 'text-amber-500' },
  block:            { tone: 'red',   Glyph: Icon.XCircle, ring: 'text-red-500' },
};

// The rolled-up verdict → its headline chip.
const VERDICT_META = {
  ready:           { tone: 'green', label: 'Ready' },
  needs_attention: { tone: 'amber', label: 'Needs attention' },
  blocked:         { tone: 'red',   label: 'Blocked' },
};

// Checks that stay openable even when they pass — so the team can revisit the underlying record (the
// pre-rental inspection, the cleaning before/afters) rather than only reaching them to "fix" a problem.
const OPENABLE_WHEN_PASSING = new Set(['pre_rental_inspection', 'cleaning']);

function CheckRow({ check }) {
  const meta = CHECK_META[check.status] || CHECK_META.warn;
  const Glyph = meta.Glyph;
  const pending = check.status !== 'pass';
  const showLink = check.url && (pending || OPENABLE_WHEN_PASSING.has(check.key));
  return (
    <li className="flex items-start gap-3 py-2">
      <Glyph className={`mt-0.5 h-4 w-4 shrink-0 ${meta.ring}`} />
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium text-slate-800">{check.label}</p>
        <p className="text-xs text-slate-500">{check.detail}</p>
      </div>
      {showLink && (
        <Link to={check.url} className="shrink-0 text-xs font-semibold text-indigo-600 hover:text-indigo-700">
          {pending ? 'Fix →' : 'Open →'}
        </Link>
      )}
    </li>
  );
}

function BookingCard({ b }) {
  const [open, setOpen] = useState(b.verdict !== 'ready'); // pre-expand cars that need attention
  const verdict = VERDICT_META[b.verdict] || VERDICT_META.needs_attention;
  const when = b.days_left <= 0 ? 'Pickup today' : `${b.days_left} day${b.days_left === 1 ? '' : 's'} to pickup`;
  const time = b.out_time ? String(b.out_time).slice(0, 5) : null;

  return (
    <div className={`rounded-2xl border bg-white shadow-soft ${b.urgent ? 'border-l-4 border-l-red-500 border-slate-200/70' : 'border-l-4 border-l-slate-200 border-slate-200/70'}`}>
      <div className="flex flex-wrap items-start gap-3 px-5 py-4">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <Link to={`/vehicles/${b.vehicle.id}`} className="text-sm font-semibold text-slate-900 hover:text-indigo-600">
              {b.vehicle.label}
            </Link>
            {b.vehicle.plate_no && (
              <span className="rounded-lg bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-600">{b.vehicle.plate_no}</span>
            )}
            <Badge tone={verdict.tone}>{verdict.label}</Badge>
          </div>
          <p className="mt-1 text-xs text-slate-500">
            {b.contract_no ? `#${b.contract_no} · ` : ''}{b.customer}
          </p>
        </div>
        <div className="text-right">
          <div className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${b.urgent ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-600'}`}>
            <Icon.Calendar className="h-3.5 w-3.5" />
            {when}
          </div>
          <p className="mt-1 text-xs text-slate-500">{fmtDate(b.out_date)}{time ? ` · ${time}` : ''}</p>
        </div>
      </div>

      <div className="border-t border-slate-100 px-5 py-2">
        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          className="flex w-full items-center justify-between py-1 text-xs font-semibold text-slate-500 hover:text-slate-700"
        >
          <span>
            Readiness checklist
            {b.blockers_count > 0 && <span className="ml-2 text-red-600">{b.blockers_count} blocking</span>}
            {b.warnings_count > 0 && <span className="ml-2 text-amber-600">{b.warnings_count} to review</span>}
            {b.blockers_count === 0 && b.warnings_count === 0 && <span className="ml-2 text-emerald-600">all clear</span>}
          </span>
          <Icon.ChevronDown className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`} />
        </button>
        {open && (
          <ul className="divide-y divide-slate-50 pb-2">
            {/* A clean (Green) condition grade is the default, expected state — showing it as a checklist
                line just adds noise. Still shown whenever it actually needs attention (warn/override/block). */}
            {b.checks.filter((c) => !(c.key === 'condition' && c.status === 'pass')).map((c) => <CheckRow key={c.key} check={c} />)}
          </ul>
        )}
      </div>
    </div>
  );
}

function Board({ data, loading, error }) {
  const [q, setQ] = useState('');
  const [onlyAttention, setOnlyAttention] = useState(false);

  const bookings = useMemo(() => data?.bookings || [], [data]);
  const summary = data?.summary || {};

  const shown = useMemo(() => {
    const needle = q.trim().toLowerCase();
    return bookings.filter((b) => {
      if (onlyAttention && b.verdict === 'ready') return false;
      if (!needle) return true;
      return [b.contract_no, b.vehicle?.plate_no, b.vehicle?.label, b.customer]
        .filter(Boolean).some((s) => String(s).toLowerCase().includes(needle));
    });
  }, [bookings, q, onlyAttention]);

  return (
    <div className="space-y-6">
      {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

      <div className="opx-grid opx-c12">
        {[
          { label: 'Upcoming · 7 Days', value: summary.upcoming || 0, tone: 'cyan', icon: 'calendar' },
          { label: 'Urgent · ≤ Lead', value: summary.urgent || 0, tone: summary.urgent ? 'crit' : 'avail', icon: 'alert' },
          { label: 'Needs Attention', value: summary.needs_attention || 0, tone: summary.needs_attention ? 'maint' : 'avail', icon: 'check' },
          { label: 'Blocked', value: summary.blocked || 0, tone: summary.blocked ? 'crit' : 'avail', icon: 'pause' },
        ].map((k) => (
          <div className="opx-span-3" key={k.label}>
            <StatGaugeTile label={k.label} value={loading ? '—' : num(k.value)} tone={k.tone} icon={k.icon}
              percent={(summary.upcoming || 0) ? (k.value / (summary.upcoming || 1)) * 100 : (k.value ? 100 : 5)} />
          </div>
        ))}
      </div>

      <CommandPanel
        title="Upcoming Bookings"
        dotColor="#22d3ee"
        label="pickup prep"
        meta={`${num(shown.length)} in look-ahead window`}
        bodyFlush
      >
        <div className="opx-toolbar">
          <input
            className="opx-input"
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Search plate, customer, contract…"
          />
          <button
            type="button"
            onClick={() => setOnlyAttention((v) => !v)}
            className="opx-ibtn"
            style={onlyAttention ? { borderColor: 'var(--paused)', color: 'var(--paused)' } : undefined}
          >
            ⚠ Needs attention only
          </button>
        </div>

        <div style={{ padding: '14px 16px' }}>
          {shown.length ? (
            <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
              {shown.map((b) => <BookingCard key={b.id} b={b} />)}
            </div>
          ) : (
            <div className="opx-empty">
              {bookings.length ? 'No bookings match.' : 'No upcoming bookings in the look-ahead window.'}
            </div>
          )}
        </div>
      </CommandPanel>
    </div>
  );
}

function SettingsPanel({ settings, onSaved }) {
  const toast = useToast();
  const [form, setForm] = useState(() => ({
    horizon_days: settings?.horizon_days ?? 7,
    alert_lead_days: settings?.alert_lead_days ?? 2,
    inspection_validity_days: settings?.inspection_validity_days ?? 7,
    excluded_dates: settings?.excluded_dates ?? [],
  }));
  const [newDate, setNewDate] = useState('');
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState({});

  const onField = (k, v) => setForm((f) => ({ ...f, [k]: v }));

  const addDate = () => {
    if (!newDate) return;
    if (form.excluded_dates.includes(newDate)) { setNewDate(''); return; }
    setForm((f) => ({ ...f, excluded_dates: [...f.excluded_dates, newDate].sort() }));
    setNewDate('');
  };
  const removeDate = (d) => setForm((f) => ({ ...f, excluded_dates: f.excluded_dates.filter((x) => x !== d) }));

  const save = async () => {
    setSaving(true);
    setErrors({});
    try {
      await api.post('/booking-readiness/settings', {
        horizon_days: Number(form.horizon_days),
        alert_lead_days: Number(form.alert_lead_days),
        inspection_validity_days: Number(form.inspection_validity_days),
        excluded_dates: form.excluded_dates,
      });
      toast.success('Readiness settings saved');
      onSaved?.();
    } catch (err) {
      const resp = err.response?.data;
      if (resp?.errors) setErrors(resp.errors);
      toast.error(resp?.message || 'Could not save settings');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="max-w-2xl space-y-6">
      <CommandPanel title="Trigger Settings" dotColor="#22d3ee" label="tuning" meta="applies immediately — no redeploy">
        <p className="opx-hint" style={{ marginBottom: 14, letterSpacing: '.03em' }}>Tune how far ahead the board looks and when a booking becomes urgent.</p>
        <div className="grid grid-cols-1 gap-5 sm:grid-cols-3">
          <Input
            type="number" min="1" max="60" label="Look-ahead (days)"
            value={form.horizon_days} onChange={(e) => onField('horizon_days', e.target.value)}
            error={errors.horizon_days?.[0]}
          />
          <Input
            type="number" min="0" max="30" label="Alert lead (working days)"
            value={form.alert_lead_days} onChange={(e) => onField('alert_lead_days', e.target.value)}
            error={errors.alert_lead_days?.[0]}
          />
          <Input
            type="number" min="0" max="90" label="Inspection valid (days)"
            value={form.inspection_validity_days} onChange={(e) => onField('inspection_validity_days', e.target.value)}
            error={errors.inspection_validity_days?.[0]}
          />
        </div>
        <p className="mt-3 text-xs text-slate-500">
          <strong>Look-ahead</strong> is how many days of bookings the board lists. <strong>Alert lead</strong> is how
          many working days before pickup a booking turns urgent (and fires a notification). <strong>Inspection valid</strong>
          {' '}is how long a pre-rental inspection stays "Satisfied" — a car tested within this window is not asked to be re-tested.
        </p>
      </CommandPanel>

      <CommandPanel title="Excluded Dates · Holidays" dotColor="#f5a524" label="office closed" meta="skipped in the working-days lead">
        <div className="flex flex-wrap items-end gap-3">
          <div className="flex-1 min-w-[12rem]">
            <Input type="date" label="Add a holiday" value={newDate} onChange={(e) => setNewDate(e.target.value)} />
          </div>
          <Button variant="secondary" onClick={addDate} className="gap-1.5">
            <Icon.Plus className="h-4 w-4" /> Add
          </Button>
        </div>
        {form.excluded_dates.length ? (
          <div className="mt-4 flex flex-wrap gap-2">
            {form.excluded_dates.map((d) => (
              <span key={d} className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700">
                {fmtDate(d)}
                <button type="button" onClick={() => removeDate(d)} className="text-slate-400 hover:text-red-500">
                  <Icon.XCircle className="h-3.5 w-3.5" />
                </button>
              </span>
            ))}
          </div>
        ) : (
          <p className="mt-4 text-xs text-slate-400">No excluded dates. The lead time counts every calendar day.</p>
        )}
      </CommandPanel>

      <div className="flex justify-end">
        <Button onClick={save} loading={saving} className="gap-1.5">
          <Icon.Check className="h-4 w-4" /> Save settings
        </Button>
      </div>
    </div>
  );
}

/**
 * Booking Readiness — the pickup-prep board. A 7-day look-ahead of upcoming bookings (type-R
 * reservations), each with its car's live readiness checklist and a working-days-to-pickup countdown
 * that skips excluded holidays. Urgent pickups (within the alert lead) stand out in red. A pre-rental
 * inspection already on file (or done recently, within the validity window) reads as Satisfied — the
 * team is never asked to re-test a car that was just tested. The Settings tab tunes all four triggers.
 */
export default function BookingReadiness() {
  const { can } = usePermissions();
  const canManage = can('booking_readiness.manage');

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/booking-readiness');
    return data.data;
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher, [], { refreshInterval: 60000 });

  const tabs = useMemo(() => [
    { key: 'board', label: 'Board', icon: <Icon.Calendar className="h-4 w-4" /> },
    ...(canManage ? [{ key: 'settings', label: 'Settings', icon: <Icon.Filter className="h-4 w-4" /> }] : []),
  ], [canManage]);

  const [searchParams, setSearchParams] = useSearchParams();
  const current = tabs.find((t) => t.key === searchParams.get('tab')) || tabs[0];
  const setActive = (key) => setSearchParams({ tab: key }, { replace: true });

  return (
    <div className="opx py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <div>
          <div className="opx-hint" style={{ letterSpacing: '.16em', textTransform: 'uppercase', marginBottom: 6 }}>Pickup Prep Control</div>
          <h1 className="font-display" style={{ fontSize: 24, fontWeight: 700, letterSpacing: '-.02em', color: 'var(--ink)', margin: 0 }}>Booking Readiness</h1>
          <p style={{ marginTop: 6, maxWidth: 760, fontSize: 13.5, color: 'var(--ink-3)', lineHeight: 1.5 }}>
            Prep cars before the customer arrives. Every upcoming booking with its live readiness checklist — a valid recent inspection counts as done, so you only chase what's actually pending.
          </p>
        </div>

        <Tabs tabs={tabs} active={current?.key} onChange={setActive} ariaLabel="Booking readiness sections" />

        {current?.key === 'settings' && canManage ? (
          data?.settings
            ? <SettingsPanel key={JSON.stringify(data.settings)} settings={data.settings} onSaved={reload} />
            : <MetricGridSkeleton count={2} />
        ) : (
          <Board data={data} loading={loading} error={error} />
        )}
      </div>
    </div>
  );
}
