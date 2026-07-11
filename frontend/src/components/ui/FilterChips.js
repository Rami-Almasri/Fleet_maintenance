// FilterChips — the standard segmented status filter used across list pages
// (All / Overdue / Due soon / OK …). One controlled component so every page's
// filter bar looks and behaves identically.
//
//   <FilterChips
//     value={filter}
//     onChange={setFilter}
//     options={[
//       { key: 'all', label: 'All', count: rows.length },
//       { key: 'overdue', label: 'Overdue', count: overdue, tone: 'red' },
//       { key: 'due_soon', label: 'Due soon', count: dueSoon, tone: 'amber' },
//       { key: 'ok', label: 'OK', count: ok, tone: 'green' },
//     ]}
//   />
//
// `tone` colours the count pill of the ACTIVE chip so an urgent filter reads red.

const COUNT_TONE = {
  red: 'bg-red-100 text-red-700',
  amber: 'bg-amber-100 text-amber-700',
  green: 'bg-emerald-100 text-emerald-700',
  slate: 'bg-slate-200 text-slate-600',
  indigo: 'bg-indigo-100 text-indigo-700',
};

export default function FilterChips({ options = [], value, onChange, className = '' }) {
  return (
    <div className={`inline-flex flex-wrap items-center gap-1 rounded-xl bg-slate-100 p-1 ${className}`}>
      {options.map((o) => {
        const active = value === o.key;
        return (
          <button
            key={o.key}
            type="button"
            onClick={() => onChange(o.key)}
            className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
              active ? 'bg-white text-indigo-600 shadow-sm' : 'text-slate-500 hover:text-slate-700'
            }`}
          >
            {o.label}
            {o.count != null && (
              <span
                className={`inline-flex h-4 min-w-[1rem] items-center justify-center rounded-full px-1 text-[10px] font-bold tabular-nums ${
                  active ? COUNT_TONE[o.tone] || COUNT_TONE.indigo : 'bg-slate-200 text-slate-500'
                }`}
              >
                {o.count}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}
