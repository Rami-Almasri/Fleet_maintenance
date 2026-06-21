import Button from './Button';

// Full pagination when the total is known (client-side data sets).
export default function Pagination({ page, pageCount, total, pageSize, onPage }) {
  if (!pageCount || pageCount <= 1) {
    return (
      <div className="flex items-center justify-between border-t border-gray-100 px-6 py-3 text-sm text-gray-500">
        <span>{total} {total === 1 ? 'result' : 'results'}</span>
      </div>
    );
  }
  const from = (page - 1) * pageSize + 1;
  const to = Math.min(page * pageSize, total);
  return (
    <div className="flex items-center justify-between border-t border-gray-100 px-6 py-3">
      <span className="text-sm text-gray-500">
        Showing <span className="font-medium text-gray-700">{from}–{to}</span> of{' '}
        <span className="font-medium text-gray-700">{total}</span>
      </span>
      <div className="flex items-center gap-2">
        <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>
          Previous
        </Button>
        <span className="px-2 text-sm text-gray-600">Page {page} of {pageCount}</span>
        <Button variant="secondary" size="sm" disabled={page >= pageCount} onClick={() => onPage(page + 1)}>
          Next
        </Button>
      </div>
    </div>
  );
}

// Prev/next only — for server pages where the total is unknown.
export function CursorPagination({ page, onPage, hasNext, count, total }) {
  return (
    <div className="flex items-center justify-between border-t border-gray-100 px-6 py-3">
      <span className="text-sm text-gray-500">
        Page {page} · {count} on this page
        {total != null && <> · <span className="font-medium text-gray-700">{total.toLocaleString()}</span> total</>}
      </span>
      <div className="flex items-center gap-2">
        <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>
          Previous
        </Button>
        <Button variant="secondary" size="sm" disabled={!hasNext} onClick={() => onPage(page + 1)}>
          Next
        </Button>
      </div>
    </div>
  );
}
