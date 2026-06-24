// Loading-state placeholders. Built on the `.shimmer` sweep defined in index.css
// so skeletons feel alive instead of static grey blocks — use these instead of a
// bare spinner so the layout doesn't flicker/jump when data arrives.

export function Skeleton({ className = 'h-4 w-full' }) {
  return <div className={`shimmer rounded-md bg-slate-100 ${className}`} />;
}

// A stack of text lines; the last line is shorter for a natural look.
export function SkeletonText({ lines = 3, className = '' }) {
  return (
    <div className={`space-y-2 ${className}`}>
      {Array.from({ length: lines }).map((_, i) => (
        <Skeleton key={i} className={`h-3 ${i === lines - 1 ? 'w-2/3' : 'w-full'}`} />
      ))}
    </div>
  );
}

// Mirrors a MetricCard's footprint so KPI rows don't reflow on load.
export function MetricCardSkeleton() {
  return (
    <div className="rounded-2xl border border-slate-200/60 bg-white p-5 shadow-soft">
      <div className="flex items-start justify-between gap-3">
        <Skeleton className="h-3 w-24" />
        <Skeleton className="h-9 w-9 rounded-xl" />
      </div>
      <Skeleton className="mt-4 h-7 w-28" />
      <Skeleton className="mt-2 h-3 w-32" />
    </div>
  );
}

// A row of N metric-card skeletons (matches MetricGrid spacing).
export function MetricGridSkeleton({ count = 4 }) {
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      {Array.from({ length: count }).map((_, i) => (
        <MetricCardSkeleton key={i} />
      ))}
    </div>
  );
}

export default Skeleton;
