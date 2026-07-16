// Small shared presentational primitives.

export function Spinner({ className = 'h-5 w-5' }) {
  return (
    <svg className={`animate-spin text-indigo-400 ${className}`} viewBox="0 0 24 24" fill="none">
      <circle className="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
      <path className="opacity-80" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z" />
    </svg>
  );
}

export function PageHeader({ title, subtitle, children }) {
  return (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <div className="min-w-0">
        <div className="flex items-center gap-2.5">
          <span className="h-5 w-1 rounded-full bg-indigo-500" />
          <h1 className="font-display text-2xl font-bold tracking-tight text-slate-900">{title}</h1>
        </div>
        {subtitle && <p className="mt-1.5 text-sm text-slate-500 sm:ps-3.5">{subtitle}</p>}
      </div>
      {children && <div className="flex flex-wrap items-center gap-3">{children}</div>}
    </div>
  );
}

export function EmptyState({ title = 'Nothing here', message, icon, action }) {
  return (
    <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
      <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-slate-100 text-slate-400 ring-1 ring-inset ring-slate-200/70">
        {icon || (
          <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.6">
            <path strokeLinecap="round" strokeLinejoin="round" d="M20 13V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h7M9 9h6M9 13h4" />
          </svg>
        )}
      </div>
      <p className="text-sm font-semibold text-slate-900">{title}</p>
      {message && <p className="mt-1 max-w-sm text-sm text-slate-500">{message}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

// Standard failed-load state — the counterpart to EmptyState for when a fetch
// errors. Calm red accent, a plain message, and an optional Retry action. Use
// this instead of a bare "Something went wrong" string so every page fails the
// same way. `onRetry` renders a subtle retry button when provided.
export function ErrorState({ title = 'Couldn’t load this', message = 'Something went wrong while fetching data. Please try again.', onRetry }) {
  return (
    <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
      <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-red-50 text-red-500 ring-1 ring-inset ring-red-200/70">
        <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7">
          <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" />
        </svg>
      </div>
      <p className="text-sm font-semibold text-slate-900">{title}</p>
      {message && <p className="mt-1 max-w-sm text-sm text-slate-500">{message}</p>}
      {onRetry && (
        <button
          type="button"
          onClick={onRetry}
          className="focus-ring-self mt-4 inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-400"
        >
          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M4 4v6h6M20 9A8 8 0 0 0 6.3 5.3L4 8" /></svg>
          Retry
        </button>
      )}
    </div>
  );
}

export function SearchInput({ value, onChange, placeholder = 'Search…', className = '' }) {
  return (
    <div className={`relative ${className}`}>
      <svg className="pointer-events-none absolute start-3.5 top-2.5 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7">
        <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-4.3-4.3M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16z" />
      </svg>
      <input
        type="search"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        aria-label={placeholder}
        className="w-full rounded-xl border border-slate-200 bg-white py-2.5 ps-11 pe-3 text-sm shadow-sm outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
      />
    </div>
  );
}

// Reusable card wrapper for tables/sections.
export function Card({ children, className = '', id }) {
  return (
    <div id={id} className={`overflow-hidden rounded-2xl border border-slate-200/60 bg-white shadow-soft ${className}`}>
      {children}
    </div>
  );
}

// A row of table-skeleton placeholders while loading.
export function TableSkeleton({ cols = 4, rows = 6 }) {
  return (
    <tbody className="divide-y divide-slate-50">
      {Array.from({ length: rows }).map((_, r) => (
        <tr key={r}>
          {Array.from({ length: cols }).map((__, c) => (
            <td key={c} className="px-6 py-3.5">
              <div className="shimmer h-3 w-full max-w-[150px] rounded-full bg-slate-100" />
            </td>
          ))}
        </tr>
      ))}
    </tbody>
  );
}
