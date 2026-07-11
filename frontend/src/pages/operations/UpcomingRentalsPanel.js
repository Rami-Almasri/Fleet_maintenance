import { useCallback, useMemo } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import Badge from '../../components/ui/Badge';
import Icon from '../../components/ui/Icon';
import { EmptyState } from '../../components/ui/Misc';
import { MetricGridSkeleton } from '../../components/ui/Skeleton';
import { fmtDate, fmtTime } from '../../lib/format';
import usePanelFilter from './usePanelFilter';

// The 2-day pickup horizon this tab focuses on (booking-readiness returns a wider look-ahead).
const HORIZON_DAYS = 2;

// BookingReadinessService verdict → readiness chip + shared urgency bucket.
const VERDICT = {
  blocked:         { label: 'Blocked',   tone: 'red',   bucket: 'urgent' },
  needs_attention: { label: 'Attention', tone: 'amber', bucket: 'attention' },
  ready:           { label: 'Ready',     tone: 'green', bucket: 'ok' },
};

// "0 → Today", "1 → Tomorrow", else "In Nd".
function whenLabel(days) {
  if (days <= 0) return 'Today';
  if (days === 1) return 'Tomorrow';
  return `In ${days}d`;
}

// Card optimised for the 2-day horizon: [Vehicle] | [Customer] | [Pickup Time] | [Readiness Verdict].
function UpcomingRentalCard({ c }) {
  return (
    <Link
      to={`/contracts/${c.id}`}
      className="hover-lift block rounded-2xl border border-slate-200/60 bg-white p-4 shadow-soft"
    >
      <div className="flex items-start justify-between gap-2">
        <p className="truncate text-sm font-semibold text-slate-800" title={c.vehicle}>{c.vehicle}</p>
        <Badge tone={c.verdict.tone}>{c.verdict.label}</Badge>
      </div>
      <p className="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500">
        <Icon.Users className="h-3.5 w-3.5 shrink-0 text-slate-400" />
        <span className="truncate" title={c.customer}>{c.customer}</span>
      </p>

      <div className="mt-3 flex items-center justify-between border-t border-slate-100 pt-2.5">
        <span className="flex items-center gap-1.5 text-xs font-medium text-slate-600">
          <Icon.Calendar className="h-3.5 w-3.5 text-slate-400" />
          {fmtDate(c.pickupDate)}{c.pickupTime ? ` · ${fmtTime(c.pickupTime)}` : ''}
        </span>
        <Badge tone={c.urgent ? 'red' : 'slate'}>{c.when}</Badge>
      </div>
    </Link>
  );
}

/**
 * Tab 3 — Upcoming Rentals. The next-2-days pickup horizon from GET /booking-readiness, each booking
 * carrying the live Ready / Attention / Blocked verdict the eligibility guard computes. The endpoint's
 * look-ahead is wider (default 7d), so we window it down to the 2-day horizon here.
 */
export default function UpcomingRentalsPanel({ search, filter, onStats }) {
  const fetcher = useCallback(async () => (await api.get('/booking-readiness')).data.data, []);
  const { data, loading, error } = useFetch(fetcher, [], { refreshInterval: 60000 });

  const items = useMemo(() => (data?.bookings || [])
    .filter((b) => Number(b.days_left) <= HORIZON_DAYS)
    .map((b) => {
      const verdict = VERDICT[b.verdict] || VERDICT.needs_attention;
      const vehicle = b.vehicle?.label || b.vehicle?.plate_no || [b.vehicle?.make, b.vehicle?.model].filter(Boolean).join(' ') || 'Vehicle';
      return {
        id: b.id,
        vehicle,
        customer: b.customer || 'Customer',
        pickupDate: b.out_date,
        pickupTime: b.out_time,
        when: whenLabel(Number(b.days_left)),
        urgent: !!b.urgent,
        verdict,
        bucket: verdict.bucket,
        haystack: [b.contract_no, vehicle, b.vehicle?.make, b.vehicle?.model, b.customer].filter(Boolean).join(' ').toLowerCase(),
      };
    }), [data]);

  const shown = usePanelFilter(items, { search, filter, onStats });

  if (loading && !items.length) return <MetricGridSkeleton count={6} />;
  if (error) {
    return <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>;
  }
  if (!shown.length) {
    return (
      <EmptyState
        icon={<Icon.Calendar className="h-7 w-7" />}
        title={items.length ? 'No pickups match' : 'No pickups in the next 2 days'}
        message={items.length ? 'Try clearing the search or filter.' : 'Nothing is booked for pickup within the 2-day horizon.'}
      />
    );
  }

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
      {shown.map((c) => <UpcomingRentalCard key={c.id} c={c} />)}
    </div>
  );
}
