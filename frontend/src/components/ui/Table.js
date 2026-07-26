// DataTable — the standard interactive table. One declarative component that gives
// every list the same polish: zebra striping, hover states, optional sticky header,
// graceful horizontal overflow on mobile, a subtle row highlight for rows that
// "need review", plus built-in loading (shimmer) and empty states.
//
//   <DataTable
//     columns={[
//       { key: 'no', header: 'Contract', render: r => <Link to={`/c/${r.id}`}>#{r.no}</Link> },
//       { key: 'net', header: 'Net', align: 'right', cellClass: 'tabular-nums font-semibold text-emerald-700',
//         render: r => aed2(r.net) },
//     ]}
//     rows={rows}
//     rowKey={r => r.id}
//     loading={loading}
//     highlightRow={r => r.status === 'needs_review'}
//     empty="No rentals returned this month."
//   />
//
// `columns[].align` = 'left' | 'right' | 'center'. `render(row, index)` is optional;
// without it the cell shows row[col.key]. `cellClass`/`headerClass` add classes.

import { Tooltip } from './Tooltip';

const ALIGN = { left: 'text-left', right: 'text-right', center: 'text-center' };

export default function DataTable({
  columns = [],
  rows = [],
  rowKey,
  loading = false,
  skeletonRows = 6,
  zebra = true,
  stickyHeader = false,
  dense = false,
  highlightRow,            // row => boolean — subtle amber tint + left accent
  onRowClick,              // row => void — makes rows clickable
  sortKey,                 // key of the currently-sorted column (opt-in)
  sortDir,                 // 'asc' | 'desc' — direction of the active sort
  onSort,                  // key => void — called when a sortable header is clicked
  empty = 'Nothing to show.',
  className = '',
}) {
  const py = dense ? 'py-2.5' : 'py-3.5';
  const colCount = columns.length || 1;

  return (
    <div className={`-mx-px overflow-x-auto ${className}`}>
      <table className="min-w-full border-separate border-spacing-0 text-sm">
        <thead className={stickyHeader ? 'sticky top-0 z-10' : ''}>
          <tr>
            {columns.map((c, i) => {
              const sortable = c.sortable && onSort;
              const active = sortable && sortKey === c.key;
              const justify = (c.align === 'right') ? 'justify-end' : (c.align === 'center') ? 'justify-center' : 'justify-start';
              const label = (
                <>
                  {c.header}
                  {c.tooltip && <Tooltip content={c.tooltip}><span className="inline-flex h-3.5 w-3.5 cursor-help items-center justify-center rounded-full bg-slate-200 text-[9px] font-bold text-slate-500">i</span></Tooltip>}
                  {sortable && (
                    <span className={`text-[10px] leading-none ${active ? 'text-indigo-600' : 'text-slate-300'}`}>
                      {active ? (sortDir === 'asc' ? '▲' : '▼') : '↕'}
                    </span>
                  )}
                </>
              );
              return (
                <th
                  key={c.key ?? i}
                  aria-sort={active ? (sortDir === 'asc' ? 'ascending' : 'descending') : undefined}
                  className={`whitespace-nowrap border-b border-slate-200 bg-slate-50/90 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500 backdrop-blur ${ALIGN[c.align] || ALIGN.left} ${c.headerClass || ''}`}
                >
                  {sortable ? (
                    <button
                      type="button"
                      onClick={() => onSort(c.key)}
                      className={`inline-flex w-full items-center gap-1 ${justify} uppercase tracking-wide transition-colors hover:text-slate-800 ${active ? 'text-indigo-600' : ''}`}
                      title="Sort by this column"
                    >
                      {label}
                    </button>
                  ) : (
                    <span className="inline-flex items-center gap-1">{label}</span>
                  )}
                </th>
              );
            })}
          </tr>
        </thead>

        <tbody>
          {loading ? (
            Array.from({ length: skeletonRows }).map((_, r) => (
              <tr key={`sk-${r}`}>
                {columns.map((c, i) => (
                  <td key={i} className={`border-b border-slate-50 px-5 ${py}`}>
                    <div className="shimmer h-3 w-full max-w-[140px] rounded-full bg-slate-100" />
                  </td>
                ))}
              </tr>
            ))
          ) : rows.length === 0 ? (
            <tr>
              <td colSpan={colCount} className="px-5 py-12 text-center text-sm text-slate-400">
                {empty}
              </td>
            </tr>
          ) : (
            rows.map((row, ri) => {
              const flag = highlightRow ? highlightRow(row) : false;
              const base = flag
                ? 'bg-amber-50/70 hover:bg-amber-50'
                : zebra
                  ? 'bg-white even:bg-slate-50/40 hover:bg-indigo-50/40'
                  : 'bg-white hover:bg-indigo-50/40';
              return (
                <tr
                  key={rowKey ? rowKey(row) : ri}
                  onClick={onRowClick ? () => onRowClick(row) : undefined}
                  className={`group/row transition-colors ${base} ${onRowClick ? 'cursor-pointer' : ''}`}
                >
                  {columns.map((c, ci) => (
                    <td
                      key={c.key ?? ci}
                      className={`border-b border-slate-100 px-5 ${py} text-slate-600 ${ci === 0 && flag ? 'relative before:absolute before:inset-y-0 before:left-0 before:w-1 before:bg-amber-400' : ''} ${ALIGN[c.align] || ALIGN.left} ${c.cellClass || ''}`}
                    >
                      {c.render ? c.render(row, ri) : row[c.key]}
                    </td>
                  ))}
                </tr>
              );
            })
          )}
        </tbody>
      </table>
    </div>
  );
}

// Section card with a header bar (title + optional action area) wrapping a table or
// any content — the repeating "Card > header border-b > body" pattern, standardized.
export function SectionCard({ id, title, subtitle, actions, children, className = '', bodyClass = '' }) {
  return (
    <div id={id} className={`overflow-hidden rounded-2xl border border-slate-200/60 bg-white shadow-soft ${className}`}>
      {(title || actions) && (
        <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
          <div className="min-w-0">
            {title && <h3 className="truncate text-base font-semibold text-slate-900">{title}</h3>}
            {subtitle && <p className="mt-0.5 truncate text-xs text-slate-400">{subtitle}</p>}
          </div>
          {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
        </div>
      )}
      <div className={bodyClass}>{children}</div>
    </div>
  );
}
