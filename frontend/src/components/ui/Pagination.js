import Button from './Button';
import { useI18n } from '../../i18n/I18nContext';

// Full pagination when the total is known (client-side data sets).
export default function Pagination({ page, pageCount, total, pageSize, onPage }) {
  const { t } = useI18n();
  if (!pageCount || pageCount <= 1) {
    return (
      <div className="flex items-center justify-between border-t border-slate-100 px-6 py-3 text-sm text-slate-500">
        <span>{total === 1 ? t('1 result') : t('{n} results', { n: total })}</span>
      </div>
    );
  }
  const from = (page - 1) * pageSize + 1;
  const to = Math.min(page * pageSize, total);
  return (
    <div className="flex items-center justify-between border-t border-slate-100 px-6 py-3">
      <span className="text-sm text-slate-500">
        {t('Showing {from}–{to} of {total}', { from, to, total })}
      </span>
      <div className="flex items-center gap-2">
        <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>
          {t('Previous')}
        </Button>
        <span className="px-2 text-sm text-slate-600">{t('Page {page} of {total}', { page, total: pageCount })}</span>
        <Button variant="secondary" size="sm" disabled={page >= pageCount} onClick={() => onPage(page + 1)}>
          {t('Next')}
        </Button>
      </div>
    </div>
  );
}

// Prev/next only — for server pages where the total is unknown.
export function CursorPagination({ page, onPage, hasNext, count, total }) {
  const { t, lang } = useI18n();
  return (
    <div className="flex items-center justify-between border-t border-slate-100 px-6 py-3">
      <span className="text-sm text-slate-500">
        {t('Page {page} · {count} on this page', { page, count })}
        {total != null && ` · ${t('{n} total', { n: total.toLocaleString(lang === 'ar' ? 'ar-AE-u-nu-latn' : undefined) })}`}
      </span>
      <div className="flex items-center gap-2">
        <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>
          {t('Previous')}
        </Button>
        <Button variant="secondary" size="sm" disabled={!hasNext} onClick={() => onPage(page + 1)}>
          {t('Next')}
        </Button>
      </div>
    </div>
  );
}
