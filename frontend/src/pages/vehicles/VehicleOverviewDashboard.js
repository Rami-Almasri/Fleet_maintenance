// Vehicle Overview — an executive, data-rich dashboard for a single car (SAP / Oracle
// Fleet feel). Everything is derived from the profile payload already loaded by the
// parent (no extra fetch): vehicle + registration + contracts + maintenance visits +
// stats. Money surfaces (cost/revenue architecture, spend) are gated behind
// SHOW_FINANCIALS; the operational widgets (health, fault distribution, service
// cadence) always render.
//
// Sections: Quick KPIs · Vehicle health + Snapshot · Revenue/Expense architecture ·
// Repair trends + Fault distribution · Maintenance summary.

import { useMemo } from 'react';
import { SectionCard } from '../../components/ui/Table';
import { RadialGauge } from '../../components/ui/Gauge';
import CompositionDonut from '../../components/ui/CompositionDonut';
import LeaderDonut from '../../components/ui/LeaderDonut';
import GroupedBarChart from '../../components/ui/GroupedBarChart';
import Icon from '../../components/ui/Icon';
import Badge from '../../components/ui/Badge';
import { InfoTip } from '../../components/ui/Tooltip';
import { aed, aed2, num, fmtDate, dayBadge } from '../../lib/format';
import { categorySegments } from '../../lib/faultCategories';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

// Next battery change is due one year after the last replacement (OM exposes only the last-change date).
const batteryNextChange = (lastChanged) => {
  if (!lastChanged) return null;
  const d = new Date(lastChanged);
  if (isNaN(d.getTime())) return null;
  d.setFullYear(d.getFullYear() + 1);
  return d;
};

// Human text for the strict km-based service-due verdict (Vehicle::serviceStatus on the API).
const serviceStatusText = (s) => {
  if (!s || s.status === 'no_data') return 'No Data';
  if (s.status === 'service_due') return `Service Due (${num(s.overdue_km)} km overdue)`;
  return `OK (${num(s.remaining)} km left)`;
};

// One label→value fact line for the compliance / service block on the health card.
const FactRow = ({ label, value, tip }) => (
  <div className="flex items-center justify-between gap-4 py-1.5 text-sm">
    <span className="flex items-center gap-1 text-slate-500">
      {label}
      {tip && <InfoTip content={tip} />}
    </span>
    <span className="text-right font-medium text-slate-800">{value ?? '—'}</span>
  </div>
);

// A coverage line (Mulkiya / Insurance) — expiry date + a days-left urgency badge.
const CoverageRow = ({ label, date, days }) => {
  const b = dayBadge(days);
  return (
    <div className="flex items-center justify-between py-2">
      <div>
        <p className="text-sm font-medium text-slate-700">{label}</p>
        <p className="text-xs text-slate-400">{fmtDate(date)}</p>
      </div>
      <Badge tone={b.tone}>{b.text === '—' ? 'None' : b.text}</Badge>
    </div>
  );
};

const monthKey = (str) => {
  if (!str) return null;
  const d = new Date(str);
  return isNaN(d.getTime()) ? null : `${d.getFullYear()}-${d.getMonth()}`;
};
const daysUntil = (str) => {
  if (!str) return null;
  const d = new Date(str);
  if (isNaN(d.getTime())) return null;
  return Math.round((d.getTime() - Date.now()) / 86400000);
};
// Whole days a period covers (start → end). An open period (no end) counts up to today.
const spanDays = (start, end) => {
  if (!start) return 0;
  const s = new Date(start);
  if (isNaN(s.getTime())) return 0;
  const e = end ? new Date(end) : new Date();
  if (isNaN(e.getTime())) return 0;
  return Math.max(0, Math.round((e.getTime() - s.getTime()) / 86400000));
};

// One health signal → { key, label, status: good|warn|bad|unknown, detail }.
const STATUS_META = {
  good:    { tone: 'text-emerald-600', dot: 'bg-emerald-500', ring: 'ring-emerald-500/20', chip: 'Healthy' },
  warn:    { tone: 'text-amber-600',   dot: 'bg-amber-500',   ring: 'ring-amber-500/20',   chip: 'Attention' },
  bad:     { tone: 'text-red-600',     dot: 'bg-red-500',     ring: 'ring-red-500/20',     chip: 'Action' },
  unknown: { tone: 'text-slate-400',   dot: 'bg-slate-300',   ring: 'ring-slate-400/20',   chip: 'No data' },
};

function buildHealth({ v, reg, maintenance }) {
  const signals = [];

  // Registration (Mulkiya)
  const regDays = reg ? reg.registration_days_left : null;
  signals.push({
    key: 'registration', label: 'Registration',
    status: regDays == null ? 'unknown' : regDays < 0 ? 'bad' : regDays < 30 ? 'warn' : 'good',
    detail: regDays == null ? 'No record' : regDays < 0 ? `Expired ${Math.abs(regDays)}d ago`
      : `${num(regDays)} days left`,
  });

  // Insurance
  const insDays = reg ? reg.insurance_days_left : null;
  signals.push({
    key: 'insurance', label: 'Insurance',
    status: insDays == null ? 'unknown' : insDays < 0 ? 'bad' : insDays < 30 ? 'warn' : 'good',
    detail: insDays == null ? 'No record' : insDays < 0 ? `Expired ${Math.abs(insDays)}d ago`
      : `${num(insDays)} days left`,
  });

  // Service interval (km-based verdict from the API)
  const svc = v?.service_status;
  signals.push({
    key: 'service', label: 'Service interval',
    status: !svc || svc.status === 'no_data' ? 'unknown' : svc.status === 'service_due' ? 'bad' : 'good',
    detail: !svc || svc.status === 'no_data' ? 'No data'
      : svc.status === 'service_due' ? `${num(svc.overdue_km)} km overdue`
      : `${num(svc.remaining)} km left`,
  });

  // Battery — due one year after last change
  let batStatus = 'unknown', batDetail = 'Not on file';
  if (v?.battery_last_changed) {
    const due = new Date(v.battery_last_changed);
    if (!isNaN(due.getTime())) {
      due.setFullYear(due.getFullYear() + 1);
      const d = daysUntil(due.toISOString());
      batStatus = d < 0 ? 'bad' : d < 30 ? 'warn' : 'good';
      batDetail = d < 0 ? `Overdue ${Math.abs(d)}d` : `${num(d)} days left`;
    }
  }
  signals.push({ key: 'battery', label: 'Battery', status: batStatus, detail: batDetail });

  // Traffic fines
  const fines = reg ? reg.fines_count : 0;
  signals.push({
    key: 'fines', label: 'Traffic fines',
    status: !reg ? 'unknown' : fines > 0 ? 'warn' : 'good',
    detail: !reg ? 'No record' : fines > 0 ? `${num(fines)} unpaid` : 'None outstanding',
  });

  // Open workshop visits (car currently in maintenance)
  const open = (maintenance || []).filter((m) => m.state === 'open' && !m.in_date).length;
  signals.push({
    key: 'open_faults', label: 'Open repairs',
    status: open > 0 ? 'bad' : 'good',
    detail: open > 0 ? `${num(open)} in workshop` : 'None open',
  });

  // Score: start at 100, penalise by severity. Clamped 0–100.
  const penalty = { good: 0, warn: 7, bad: 18, unknown: 3 };
  const score = Math.max(0, Math.min(100, 100 - signals.reduce((a, s) => a + penalty[s.status], 0)));
  const grade = score >= 80 ? 'good' : score >= 55 ? 'warn' : 'bad';

  return { signals, score, grade };
}

// A single big-number stat for the Contract Portfolio's left column — a coloured icon tile, the
// count, and an uppercase caption. Clickable (to the Rent tab) when an onClick is supplied.
function StatBig({ icon, hex, value, label, onClick }) {
  const Wrap = onClick ? 'button' : 'div';
  return (
    <Wrap
      type={onClick ? 'button' : undefined}
      onClick={onClick}
      className={`flex items-center gap-3.5 text-left ${onClick ? 'rounded-xl transition hover:bg-slate-50' : ''}`}
    >
      <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl" style={{ backgroundColor: `${hex}1a`, color: hex }}>
        {icon}
      </span>
      <div className="min-w-0">
        <div className="font-display text-3xl font-bold leading-none tabular-nums text-slate-900">{num(value)}</div>
        <div className="mt-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</div>
      </div>
    </Wrap>
  );
}

// Contract Portfolio — an executive band for the car's lifetime contracts, laid out like a CRM
// leads dashboard: a big-number stat column (Total / Rental / Maintenance / Booking) on the left,
// a by-type bar chart in the middle, and a composition donut with the total in its hole on the
// right. All three read the SAME contract-type split (OfficeManager type: C = rental, U =
// maintenance, R = booking). Money-free — it's a count/mix view, so it always renders.
function ContractPortfolio({ contracts = [], onNavigate }) {
  const total = contracts.length;
  const countOf = (t) => contracts.filter((c) => c.contract_type === t).length;
  const TYPES = [
    { key: 'C', label: 'Rental', value: countOf('C'), hex: '#3b82f6', icon: <Icon.Activity className="h-5 w-5" /> },
    { key: 'U', label: 'Maintenance', value: countOf('U'), hex: '#f59e0b', icon: <Icon.Wrench className="h-5 w-5" /> },
    { key: 'R', label: 'Booking', value: countOf('R'), hex: '#8b5cf6', icon: <Icon.Clock className="h-5 w-5" /> },
  ];
  const barMax = Math.max(1, ...TYPES.map((t) => t.value));
  const donutSegments = TYPES.filter((t) => t.value > 0).map((t) => ({ label: t.label, value: t.value, color: t.hex }));
  const go = onNavigate ? () => onNavigate('financials') : undefined;

  return (
    <SectionCard
      title="Contract portfolio"
      subtitle="Lifetime contracts by type — rental, maintenance & booking"
      actions={go ? <button type="button" onClick={go} className="text-sm font-medium text-indigo-600 hover:text-indigo-700">View all →</button> : null}
      bodyClass="p-0"
    >
      <div className="grid grid-cols-1 divide-y divide-slate-100 lg:grid-cols-[220px_minmax(0,1fr)_minmax(0,420px)] lg:divide-x lg:divide-y-0">
        {/* Left — big-number stat column */}
        <div className="flex flex-col justify-center gap-4 p-6">
          <StatBig icon={<Icon.Calendar className="h-5 w-5" />} hex="#06b6d4" value={total} label="Total contracts" onClick={go} />
          {TYPES.map((t) => (
            <StatBig key={t.key} icon={t.icon} hex={t.hex} value={t.value} label={t.label} onClick={go} />
          ))}
        </div>

        {/* Middle — contracts by type (bar) */}
        <div className="flex flex-col p-6">
          <p className="mb-4 text-center text-[11px] font-semibold uppercase tracking-wide text-slate-400">Contracts by type</p>
          <div className="flex flex-1 items-end justify-around gap-5" style={{ minHeight: 180 }}>
            {TYPES.map((t) => (
              <div key={t.key} className="flex h-full flex-1 flex-col items-center justify-end gap-2">
                <span className="text-sm font-bold tabular-nums text-slate-700">{num(t.value)}</span>
                <div className="flex w-full max-w-[52px] flex-1 items-end">
                  <div
                    className="w-full rounded-t-lg"
                    style={{ height: `${Math.max(4, (t.value / barMax) * 100)}%`, backgroundColor: t.hex, transition: 'height 1s cubic-bezier(0.22,1,0.36,1)' }}
                    title={`${t.label}: ${num(t.value)}`}
                  />
                </div>
                <span className="text-xs font-medium text-slate-500">{t.label}</span>
              </div>
            ))}
          </div>
        </div>

        {/* Right — donut with external leader-line labels and the total in its hole */}
        <div className="flex items-center justify-center p-6">
          {donutSegments.length ? (
            <LeaderDonut
              segments={donutSegments}
              total={total}
              centerLabel="Contracts"
              format={(n) => Math.round(n).toLocaleString()}
            />
          ) : (
            <div className="flex h-[150px] items-center justify-center text-sm text-slate-400">No contracts yet.</div>
          )}
        </div>
      </div>
    </SectionCard>
  );
}

export default function VehicleOverviewDashboard({
  v = {}, stats = {}, reg = null, contracts = [], maintenance = [],
  showFinancials = false, onNavigate,
}) {
  const health = useMemo(() => buildHealth({ v, reg, maintenance }), [v, reg, maintenance]);

  // ── Revenue architecture (money) — from the backend's classified debit buckets ──
  const revenueSegments = useMemo(() => {
    const rb = stats?.revenue_breakdown || {};
    return [
      { label: 'Rents', value: Number(rb.rents || 0), color: 'blue' },
      { label: 'Breaches & Damages', value: Number(rb.breaches_damages || 0), color: 'teal' },
      { label: 'Operational Revenue', value: Number(rb.operational || 0), color: 'purple' },
      { label: 'Deposits', value: Number(rb.deposits || 0), color: 'orange' },
    ].filter((s) => s.value > 0);
  }, [stats]);
  const revenueTotal = revenueSegments.reduce((a, s) => a + s.value, 0);

  // ── Expense architecture (money) — visit cost bucketed by dominant fault category ──
  const expenseSegments = useMemo(() => categorySegments(maintenance, 'cost'), [maintenance]);
  const expenseTotal = expenseSegments.reduce((a, s) => a + s.value, 0);

  // ── Repair & service trend — DAYS on rent vs. DAYS in the workshop, per month ──
  // Each period's total days are attributed to the month it began (out_date / visit date).
  const trend = useMemo(() => {
    const now = new Date();
    const buckets = [];
    const index = {};
    for (let i = 11; i >= 0; i--) {
      const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
      const key = `${d.getFullYear()}-${d.getMonth()}`;
      index[key] = buckets.length;
      buckets.push({ label: MONTHS[d.getMonth()], rentals: 0, service: 0 });
    }
    (contracts || []).forEach((c) => {
      if (c.contract_type === 'U') return;
      const k = index[monthKey(c.out_date || c.in_date)];
      if (k != null) buckets[k].rentals += spanDays(c.out_date, c.in_date);
    });
    (maintenance || []).forEach((m) => {
      const k = index[monthKey(m.date || m.in_date)];
      if (k != null) buckets[k].service += spanDays(m.date, m.in_date || m.actual_in);
    });
    return buckets;
  }, [contracts, maintenance]);
  const hasTrend = trend.some((b) => b.rentals || b.service);

  // Last confirmed workshop visit — the "Last service" date on the Snapshot card.
  const lastVisit = (maintenance || [])
    .map((m) => m.date).filter(Boolean).sort().slice(-1)[0] || null;

  const HS = (s) => STATUS_META[s] || STATUS_META.unknown;

  return (
    <div className="space-y-6">
      {/* ── 1 · Repair trends (full width; Fault distribution now lives on the profile hero) ── */}
      <SectionCard
        title="Repair & service trends"
        subtitle="Days on rent vs. days in the workshop — last 12 months"
        bodyClass="px-4 pb-4 pt-2"
      >
        {hasTrend ? (
          <GroupedBarChart
            data={trend}
            series={[
              { key: 'rentals', label: 'Rental days', color: 'blue' },
              { key: 'service', label: 'Shop days', color: 'amber' },
            ]}
            height={320}
            integer
            format={(n) => `${Math.round(n).toLocaleString()} d`}
          />
        ) : (
          <div className="flex h-[320px] items-center justify-center text-sm text-slate-400">
            No rental or workshop activity in the last 12 months.
          </div>
        )}
      </SectionCard>

      {/* ── 1b · Contract portfolio band (stat column · by-type bars · donut) ── */}
      <ContractPortfolio contracts={contracts} onNavigate={onNavigate} />

      {/* ── 2 · Vehicle health + Snapshot ──────────────────────────────── */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <SectionCard
          className="lg:col-span-2"
          title="Vehicle health summary"
          subtitle="Compliance, service cadence & workshop status at a glance"
          bodyClass="p-6"
        >
          <div className="flex flex-col items-center gap-8 sm:flex-row sm:items-center">
            <div className="shrink-0 text-center">
              <RadialGauge
                value={health.score}
                max={100}
                color={health.grade === 'good' ? 'emerald' : health.grade === 'warn' ? 'amber' : 'red'}
                size={150}
                format={(n) => `${Math.round(n)}`}
              />
              <p className="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Health index</p>
            </div>
            <div className="grid w-full grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-2">
              {health.signals.map((s) => {
                const m = HS(s.status);
                return (
                  <div key={s.key} className="flex items-center gap-3">
                    <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${m.dot}`} />
                    <span className="flex-1 text-sm font-medium text-slate-600">{s.label}</span>
                    <span className={`text-xs font-semibold tabular-nums ${m.tone}`}>{s.detail}</span>
                  </div>
                );
              })}
            </div>
          </div>

          {/* Registration / insurance + compliance & warranty — relocated here from the retired Specs
              tab so all compliance facts live on one card. Split into two balanced groups: coverage
              expiries on the left, the standing compliance & warranty facts on the right. */}
          <div className="mt-6 grid grid-cols-1 gap-x-8 gap-y-6 border-t border-slate-100 pt-6 lg:grid-cols-2">
            <div>
              <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Registration &amp; Insurance</h4>
              {reg ? (
                <>
                  <CoverageRow label="Registration (Mulkiya)" date={reg.expiry_date} days={reg.registration_days_left} />
                  <CoverageRow label="Insurance" date={reg.insurance_expiry} days={reg.insurance_days_left} />
                  <div className="mt-2 border-t border-slate-100 pt-2">
                    <FactRow label="Insurer" value={reg.insurer} />
                  </div>
                </>
              ) : (
                <div className="rounded-lg bg-amber-50 px-3 py-3 text-sm text-amber-700 ring-1 ring-inset ring-amber-600/20">
                  No registration record — this car has no Mulkiya or insurance on file.
                </div>
              )}
            </div>

            <div>
              <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Compliance &amp; Warranty</h4>
              {reg && (
                <>
                  <FactRow label="Reg. Status" value={reg.status} />
                  <FactRow label="Mortgaged By" value={reg.mortgaged_by} />
                  <FactRow label="Violations / Fines" value={`${num(reg.fines_count)} · ${aed2(reg.fines_amount)}`} />
                </>
              )}
              <FactRow label="Warranty End" value={fmtDate(v?.warranty_end_date)} />
            </div>
          </div>
        </SectionCard>

        <SectionCard title="Snapshot" subtitle="Key identity & odometer" bodyClass="divide-y divide-slate-100">
          {[
            { label: 'Odometer', value: v?.odometer != null ? `${num(v.odometer)} km` : '—' },
            { label: 'Model year', value: v?.year || '—' },
            { label: 'Plate', value: v?.plate_display || v?.plate_no || '—' },
            showFinancials && {
              label: 'Purchase price',
              value: v?.purchase_price != null ? aed(Number(v.purchase_price)) : '—',
              tip: 'Acquisition cost from the FASTER Asset register, matched to this car by VIN.',
            },
            {
              label: 'Purchase date',
              value: v?.purchase_date ? fmtDate(v.purchase_date) : '—',
              tip: 'Date this car was acquired, from the FASTER Asset register.',
            },
            {
              label: 'Replacement due',
              value: v?.replacement_due_date ? fmtDate(v.replacement_due_date) : '—',
              tip: 'Planned replacement date for this car, from the FASTER Asset register.',
            },
            {
              label: 'Service interval (Validity)',
              value: v?.service_interval_km != null ? `${num(v.service_interval_km)} km` : '—',
              tip: 'Per-car km service interval from the Oil Change sheet (Validity).',
            },
            {
              label: 'Last change',
              value: v?.last_service_odometer != null ? `${num(v.last_service_odometer)} km` : '—',
              tip: 'Odometer at the last confirmed service — the baseline the next service-due is measured from.',
            },
            {
              label: 'Service status',
              value: serviceStatusText(v?.service_status),
              tip: 'Strict km-based service-due verdict: odometer vs last-service baseline + interval.',
            },
            { label: 'Battery last changed', value: fmtDate(v?.battery_last_changed) },
            {
              label: 'Next battery change',
              value: fmtDate(batteryNextChange(v?.battery_last_changed)),
              tip: 'Due one year after the last battery change.',
            },
            { label: 'Last service', value: lastVisit ? fmtDate(lastVisit) : '—' },
          ].filter(Boolean).map(({ label, value, tip }) => (
            <div key={label} className="flex items-center justify-between px-6 py-3">
              <span className="flex items-center gap-1 text-sm text-slate-500">
                {label}
                {tip && <InfoTip content={tip} />}
              </span>
              <span className="text-sm font-semibold text-slate-800">{value}</span>
            </div>
          ))}
        </SectionCard>
      </div>

      {/* ── 3 · Financial architecture (money-gated) ───────────────────── */}
      {showFinancials && (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <SectionCard
            title="Revenue architecture"
            subtitle="Billed revenue lines classified from the vehicle statement"
            bodyClass="p-6"
          >
            {revenueTotal > 0 ? (
              <CompositionDonut segments={revenueSegments} total={revenueTotal} format={aed2} />
            ) : (
              <div className="flex h-[176px] items-center justify-center text-sm text-slate-400">
                No rental revenue billed to this car yet.
              </div>
            )}
          </SectionCard>

          <SectionCard
            title="Expense architecture"
            subtitle="Workshop spend grouped by dominant fault category"
            bodyClass="p-6"
          >
            {expenseTotal > 0 ? (
              <>
                <CompositionDonut segments={expenseSegments} total={expenseTotal} format={aed2} />
                <p className="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-[11px] leading-relaxed text-slate-400">
                  Estimated — each visit's cost is attributed to its dominant fault category, not a booked per-line split.
                </p>
              </>
            ) : (
              <div className="flex h-[176px] items-center justify-center text-sm text-slate-400">
                No costed workshop visits recorded yet.
              </div>
            )}
          </SectionCard>
        </div>
      )}

    </div>
  );
}
