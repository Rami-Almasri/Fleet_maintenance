import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import { SectionCard } from './ui/Table';
import { Skeleton } from './ui/Skeleton';
import { InfoTip } from './ui/Tooltip';
import { num } from '../lib/format';

// A short glyph per service so the row reads at a glance — "🛢️ Oil Change", "🔋 Battery".
const GLYPH = {
  oil_change: '🛢️',
  oil_filter: '🧴',
  air_filter: '💨',
  brake_pads: '🛑',
  tire_rotation: '🛞',
  tire_change: '🛞',
  battery: '🔋',
  ac_service: '❄️',
  transmission: '⚙️',
  general: '🔧',
};

// Overdue reads red, due-soon amber, un-trustable data grey — the same three-state
// language used for the row accent, the chips and the summary pills.
const TONE = {
  overdue:  { chip: 'bg-red-50 text-red-700 ring-red-600/20',        accent: 'bg-red-500',    dot: 'bg-red-500',    pill: 'bg-red-50 text-red-700 ring-red-600/20' },
  due_soon: { chip: 'bg-amber-50 text-amber-700 ring-amber-600/20',  accent: 'bg-amber-400',  dot: 'bg-amber-400',  pill: 'bg-amber-50 text-amber-700 ring-amber-600/20' },
  suspect:  { chip: 'bg-slate-100 text-slate-500 ring-slate-300/60', accent: 'bg-slate-300',  dot: 'bg-slate-300',  pill: 'bg-slate-100 text-slate-500 ring-slate-300/60' },
};

/**
 * "3,200 km over" / "410 km left" / "6d left" — whichever axis the reminder actually tracks.
 * A service the API flagged as data_suspect shows no figure at all: an impossible distance
 * means a broken odometer or last-service anchor, and printing it would be a lie.
 */
function distance(s) {
  if (s.data_suspect) return 'check odometer';
  if (s.km_remaining != null) return `${num(Math.abs(s.km_remaining))} km ${s.km_remaining < 0 ? 'over' : 'left'}`;
  if (s.days_remaining != null) return `${Math.abs(s.days_remaining)}d ${s.days_remaining < 0 ? 'over' : 'left'}`;
  return null;
}

const toneOf = (s) => (s.data_suspect ? TONE.suspect : (TONE[s.status] || TONE.due_soon));

/** Small count pill in the header strip — only rendered when the count is non-zero. */
function Pill({ n, label, tone }) {
  if (!n) return null;
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${tone.pill}`}>
      <span className={`h-1.5 w-1.5 rounded-full ${tone.dot}`} />
      {num(n)} {label}
    </span>
  );
}

/**
 * "Cars needing a check" — the dashboard face of Service Reminders. One block per CAR listing the
 * services actually due on it (oil, battery, tires…), worst car first. Folded per-vehicle by the
 * API (GET /ServiceReminders/due-by-vehicle) so a car with four due services is one row, not four.
 *
 * The left accent bar carries the car's worst state, so the column scans as a severity gradient
 * before any number is read.
 */
export default function ServiceDueCard({ limit = 8 }) {
  const [data, setData] = useState({ items: [], total_cars: 0, overdue_cars: 0, due_soon_cars: 0, suspect_cars: 0 });
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let alive = true;
    api.get('/ServiceReminders/due-by-vehicle', { params: { limit } })
      .then((res) => { if (alive) setData(res.data.data || { items: [] }); })
      .catch(() => { if (alive) setData({ items: [], total_cars: 0, overdue_cars: 0, due_soon_cars: 0, suspect_cars: 0 }); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, [limit]);

  // The three counts are disjoint on the API — each car is tallied once, by its worst state.
  const {
    items = [],
    total_cars: total = 0,
    overdue_cars: overdueCars = 0,
    due_soon_cars: dueSoonCars = 0,
    suspect_cars: suspectCars = 0,
  } = data;

  return (
    <SectionCard
      title={
        <span className="flex items-center gap-1.5">
          Cars Needing a Check
          <InfoTip content="Cars with a service reminder that is overdue or due soon — oil, filters, brakes, tires, battery. Due points come from each car's own interval and its last-service anchor, measured against the live odometer; cars with no interval or no reading are never guessed. Readings that are impossibly far past due are shown as “check odometer” rather than a fabricated distance, and never counted as overdue. Muted reminders are excluded." />
        </span>
      }
      subtitle="What each car actually needs, worst first · live from the service reminders"
      actions={
        <Link to="/service-reminders" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">All reminders →</Link>
      }
    >
      {loading ? (
        <ul className="space-y-2.5">
          {Array.from({ length: limit }).map((_, i) => <li key={i}><Skeleton className="h-[68px] rounded-xl" /></li>)}
        </ul>
      ) : items.length === 0 ? (
        <div className="py-10 text-center">
          <p className="text-2xl">🎉</p>
          <p className="mt-2 text-sm font-medium text-slate-600">All caught up</p>
          <p className="mt-0.5 text-xs text-slate-400">Every car is inside its service interval.</p>
        </div>
      ) : (
        <>
          {/* Header strip — the fleet-level tally behind the list below. */}
          <div className="mb-4 flex flex-wrap items-center gap-2 border-b border-slate-100 pb-4">
            <span className="text-2xl font-extrabold tabular-nums text-slate-900">{num(total)}</span>
            <span className="me-1 text-sm text-slate-500">car{total === 1 ? '' : 's'} due</span>
            <Pill n={overdueCars} label="overdue" tone={TONE.overdue} />
            <Pill n={dueSoonCars} label="due soon" tone={TONE.due_soon} />
            <Pill n={suspectCars} label="check data" tone={TONE.suspect} />
          </div>

          <ul className="space-y-2.5">
            {items.map((c) => {
              // The car's worst state drives the accent bar: red if anything is genuinely
              // overdue, grey if all we have is bad data, amber otherwise.
              const worst = c.overdue_count > 0
                ? TONE.overdue
                : (c.due_count === c.suspect_count ? TONE.suspect : TONE.due_soon);

              return (
                <li key={c.vehicle_id}>
                  <Link
                    to={`/vehicles/${c.vehicle_id}`}
                    className="group flex overflow-hidden rounded-xl border border-slate-200/80 bg-white transition-all hover:border-slate-300 hover:shadow-sm"
                  >
                    <span className={`w-1 shrink-0 ${worst.accent}`} aria-hidden />
                    <div className="min-w-0 flex-1 px-3.5 py-3">
                      <div className="flex items-baseline justify-between gap-3">
                        <div className="min-w-0">
                          <span className="text-sm font-bold text-slate-800 group-hover:text-indigo-600">
                            {c.plate || `#${c.vehicle_id}`}
                          </span>
                          <span className="ms-2 truncate text-[11px] text-slate-400">
                            {c.car || '—'}{c.odometer != null && ` · ${num(c.odometer)} km`}
                          </span>
                        </div>
                        <span className="shrink-0 whitespace-nowrap text-[11px] font-medium tabular-nums text-slate-400">
                          {num(c.due_count)} service{c.due_count === 1 ? '' : 's'}
                        </span>
                      </div>

                      {/* The point of the card: WHAT this car needs, spelled out. */}
                      <div className="mt-2 flex flex-wrap items-center gap-1.5">
                        {c.services.map((s) => {
                          const d = distance(s);
                          const t = toneOf(s);
                          return (
                            <span
                              key={s.id}
                              title={d ? `${s.name} — ${d}` : s.name}
                              className={`inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs ring-1 ring-inset ${t.chip}`}
                            >
                              <span aria-hidden>{GLYPH[s.service_type] || '🔧'}</span>
                              <span className="font-semibold">{s.name}</span>
                              {d && <span className="tabular-nums opacity-70">{d}</span>}
                            </span>
                          );
                        })}
                      </div>
                    </div>
                  </Link>
                </li>
              );
            })}
          </ul>

          {total > items.length && (
            <p className="mt-4 text-center text-xs text-slate-400">
              Showing the {num(items.length)} most urgent of {num(total)} —{' '}
              <Link to="/service-reminders" className="font-medium text-indigo-600 hover:text-indigo-700">see them all</Link>
            </p>
          )}
        </>
      )}
    </SectionCard>
  );
}
