// A small segmented toggle for chart cards — "Top earners / Biggest losses",
// "Cost per km / Cost per day". Lives in a SectionCard's `actions` slot, so it
// stays visually quieter than the page-level filter pills.
//
//   <Segmented
//     value={view} onChange={setView}
//     options={[{ key: 'top', label: 'Top earners' }, { key: 'bottom', label: 'Biggest losses' }]}
//   />

export default function Segmented({ value, onChange, options = [], className = '' }) {
  return (
    <div className={`inline-flex rounded-lg bg-slate-100 p-0.5 ${className}`} role="tablist">
      {options.map((o) => {
        const on = o.key === value;
        return (
          <button
            key={o.key}
            type="button"
            role="tab"
            aria-selected={on}
            onClick={() => onChange(o.key)}
            className={`rounded-[6px] px-2.5 py-1 text-xs font-semibold transition ${
              on ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'
            }`}
          >
            {o.label}
          </button>
        );
      })}
    </div>
  );
}
