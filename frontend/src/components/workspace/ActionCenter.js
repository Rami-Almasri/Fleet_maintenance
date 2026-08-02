// The Action Center — the "what should I do today?" surface. It folds the
// backend's proactive-flags feed (the same source the notification bell uses)
// into prioritized, deep-linked attention lists, grouped by category.
//
// Panels are ordered by SEVERITY (Critical → Warning → Info), derived from the
// most urgent item each category holds, so the reddest things sit top-left. Each
// category is permission- + flag-gated and only rendered when it actually has
// items; when nothing needs attention a calm "all clear" empty state shows.

import { memo } from 'react';
import { Link } from 'react-router-dom';
import Icon from '../ui/Icon';
import { Skeleton } from '../ui/Skeleton';
import { EmptyState } from '../ui/Misc';
import { usePermissions } from '../../hooks/usePermissions';
import { isFeatureEnabled } from '../../config/features';
import { aed } from '../../lib/format';
import ActionItem from './ActionItem';

const ITEMS_PER_CATEGORY = 4;
const LOADING_PANELS = 2;

// Item tone → severity rank. Lower = more urgent; a category inherits the rank
// of its most-urgent visible item, and panels sort by it.
const TONE_RANK = { red: 0, amber: 1, emerald: 1, blue: 2, slate: 3 };

// Static tone→class maps — Tailwind's JIT purge only keeps class names it can
// see as complete literals, so never build these with string interpolation.
const BUBBLE = {
  amber:   'bg-amber-100 text-amber-600',
  blue:    'bg-blue-100 text-blue-600',
  emerald: 'bg-emerald-100 text-emerald-600',
  slate:   'bg-slate-100 text-slate-600',
};
const ACCENT = ['bg-red-500', 'bg-amber-500', 'bg-blue-500', 'bg-slate-300'];

const dueLabel = (d) => {
  if (d == null) return 'Ending soon';
  if (d <= 0) return d === 0 ? 'Due today' : `${Math.abs(d)}d overdue`;
  if (d === 1) return 'Due tomorrow';
  return `Due in ${d} days`;
};

const etaSub = (it) => {
  const eta = it.eta || {};
  const parts = [];
  if (eta.days_over > 0) parts.push(`${eta.days_over}d over target`);
  else if (eta.days_left != null) parts.push(`${eta.days_left}d to target`);
  else if (it.stage) parts.push(it.stage);
  if (it.garage) parts.push(it.garage);
  return parts.join(' · ');
};

const vehicleTitle = (it, fallback) => it.plate || it.car || fallback;

// Declarative category catalog. `flag: 'financial'` mirrors the money gate used
// everywhere else. `map` turns a raw backend item into ActionItem props.
const CATEGORIES = [
  {
    key: 'in_maintenance',
    title: 'In the Workshop',
    icon: <Icon.Wrench className="h-4 w-4" />,
    tone: 'amber',
    modules: ['maintenance'],
    permission: 'maintenance.view',
    viewAllTo: '/car-status',
    map: (it) => ({
      to: it.id ? `/car-status/${it.id}` : '/car-status',
      title: vehicleTitle(it, 'Vehicle'),
      sub: etaSub(it),
      tone: it.eta?.days_over > 0 ? 'red' : 'amber',
    }),
  },
  {
    key: 'contract_expiry',
    title: 'Rentals Ending Soon',
    icon: <Icon.Calendar className="h-4 w-4" />,
    tone: 'blue',
    modules: ['fleet-operations'],
    permission: 'contracts.view',
    viewAllTo: '/contracts',
    map: (it) => ({
      to: it.id ? `/contracts/${it.id}` : '/contracts',
      title: vehicleTitle(it, `Contract ${it.contract_no || ''}`.trim()),
      sub: [dueLabel(it.days_left), it.customer].filter(Boolean).join(' · '),
      tone: it.days_left <= 0 ? 'red' : 'amber',
    }),
  },
  {
    key: 'inspection_due',
    title: 'Inspections Due',
    icon: <Icon.Shield className="h-4 w-4" />,
    tone: 'emerald',
    modules: ['maintenance', 'fleet-operations'],
    permission: 'inspections.view',
    viewAllTo: '/inspections/schedules',
    map: (it) => ({
      to: '/inspections/schedules',
      title: vehicleTitle(it, 'Vehicle'),
      sub: [it.name, it.label].filter(Boolean).join(' · '),
      tone: it.status === 'overdue' ? 'red' : 'amber',
    }),
  },
  {
    key: 'invoice_overdue',
    title: 'Unpaid Balances',
    icon: <Icon.Cash className="h-4 w-4" />,
    tone: 'slate',
    modules: ['finance'],
    permission: null,
    flag: 'financial',
    viewAllTo: '/contracts',
    map: (it) => ({
      to: it.id ? `/contracts/${it.id}` : '/contracts',
      title: it.customer || vehicleTitle(it, 'Contract'),
      sub: [aed(it.balance), it.plate].filter(Boolean).join(' · '),
      tone: 'red',
    }),
  },
];

function CategoryPanel({ category, rows, total, severity }) {
  const more = total - rows.length;

  return (
    <div className="relative overflow-hidden rounded-2xl border border-slate-200/60 bg-white p-4 ps-5 shadow-soft">
      <span className={`absolute inset-y-0 start-0 w-1 ${ACCENT[severity] || ACCENT[3]}`} aria-hidden="true" />
      <div className="mb-2 flex items-center gap-2.5">
        <span className={`flex h-8 w-8 items-center justify-center rounded-lg ${BUBBLE[category.tone] || BUBBLE.slate}`}>
          {category.icon}
        </span>
        <h3 className="flex-1 text-sm font-semibold text-slate-800">{category.title}</h3>
        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold tabular-nums text-slate-600">{total}</span>
      </div>
      <div className="-mx-1">
        {rows.map((row, i) => (
          <ActionItem key={i} {...row} />
        ))}
      </div>
      {more > 0 && (
        <Link
          to={category.viewAllTo}
          className="mt-1 flex items-center justify-center gap-1 rounded-lg py-1.5 text-xs font-semibold text-indigo-600 outline-none transition hover:bg-indigo-50 focus-visible:ring-2 focus-visible:ring-indigo-500/50"
        >
          View all {total}
          <Icon.ArrowRight className="h-3.5 w-3.5" />
        </Link>
      )}
    </div>
  );
}

function ActionCenter({ data = {}, loading = false, moduleId = null }) {
  const { can } = usePermissions();

  if (loading) {
    return (
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {Array.from({ length: LOADING_PANELS }).map((_, i) => (
          <div key={i} className="rounded-2xl border border-slate-200/60 bg-white p-4 shadow-soft">
            <Skeleton className="mb-3 h-6 w-40" />
            <div className="space-y-3">
              {Array.from({ length: 3 }).map((__, j) => (
                <Skeleton key={j} className="h-8 w-full" />
              ))}
            </div>
          </div>
        ))}
      </div>
    );
  }

  // Build the visible panels: gate → take the top items → map to rows → derive
  // each category's severity from its most-urgent row → sort Critical-first.
  const panels = CATEGORIES
    .filter((c) => (!moduleId || (c.modules || []).includes(moduleId)) && isFeatureEnabled(c.flag) && can(c.permission))
    .map((category) => {
      const bucket = data[category.key] || { count: 0, items: [] };
      const items = bucket.items || [];
      const rows = items.slice(0, ITEMS_PER_CATEGORY).map(category.map);
      const severity = rows.reduce((min, r) => Math.min(min, TONE_RANK[r.tone] ?? 3), 3);
      return { category, rows, total: bucket.count ?? items.length, severity, has: rows.length > 0 };
    })
    .filter((p) => p.has)
    .sort((a, b) => a.severity - b.severity);

  if (!panels.length) {
    return (
      <div className="rounded-2xl border border-slate-200/60 bg-white shadow-soft">
        <EmptyState
          icon={<Icon.Check className="h-6 w-6 text-emerald-500" />}
          title="All clear"
          message="Nothing needs your attention right now. New alerts will appear here as they come up."
        />
      </div>
    );
  }

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      {panels.map((p) => (
        <CategoryPanel key={p.category.key} category={p.category} rows={p.rows} total={p.total} severity={p.severity} />
      ))}
    </div>
  );
}

export default memo(ActionCenter);
