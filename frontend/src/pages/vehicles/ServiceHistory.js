import { useCallback, useMemo, useState } from 'react';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { SectionCard } from '../../components/ui/Table';
import Badge from '../../components/ui/Badge';
import Icon from '../../components/ui/Icon';
import { SearchInput } from '../../components/ui/Misc';
import { Skeleton } from '../../components/ui/Skeleton';
import { fmtDate } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

/**
 * The vehicle's Technical Service Log — every part/service done on the car (money-FREE), newest
 * first, each with its date and the garage. Searchable, so "when did we last change the engine oil?"
 * is one box away; the garage on every row lets you compare what was done across garages.
 * Fed by GET /Vehicle/{id}/service-history (invoice work items).
 */
export default function ServiceHistory({ vehicleId }) {
  const { t } = useI18n();
  const [q, setQ] = useState('');
  const fetcher = useCallback(async () => (await api.get(`/Vehicle/${vehicleId}/service-history`)).data.data, [vehicleId]);
  const { data, loading } = useFetch(fetcher, [vehicleId]);
  const items = useMemo(() => data?.items || [], [data]);

  const filtered = useMemo(() => {
    const s = q.trim().toLowerCase();
    if (!s) return items;
    return items.filter((it) => (it.description || '').toLowerCase().includes(s) || (it.garage || '').toLowerCase().includes(s));
  }, [items, q]);

  return (
    <SectionCard
      title={t('Service History')}
      subtitle={t('Every part / service done on this car — newest first. Search to answer “when did we last…?”.')}
      actions={<Badge tone="gray">{items.length === 1 ? t('1 record') : t('{n} records', { n: items.length })}</Badge>}
      bodyClass="p-4 sm:p-5"
    >
      <div className="mb-3 max-w-sm">
        <SearchInput value={q} onChange={setQ} placeholder={t('Search a part / service or garage… e.g. engine oil')} />
      </div>
      {loading ? (
        <div className="space-y-2"><Skeleton className="h-12 rounded-lg" /><Skeleton className="h-12 rounded-lg" /></div>
      ) : filtered.length === 0 ? (
        <p className="py-8 text-center text-sm text-slate-400">
          {items.length === 0 ? t('No service records yet for this car — add them from a contract’s “Service Records”.') : t('No items match your search.')}
        </p>
      ) : (
        <ul className="divide-y divide-slate-100">
          {filtered.map((it) => (
            <li key={it.id} className="flex items-center justify-between gap-3 py-2.5">
              <div className="min-w-0">
                <p className="truncate text-sm font-medium text-slate-800">{it.description}</p>
                <p className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-slate-400">
                  <span className="inline-flex items-center gap-1"><Icon.Calendar className="h-3 w-3" /> {it.date ? fmtDate(it.date) : t('No date')}</span>
                  {it.garage && <span className="inline-flex items-center gap-1"><Icon.Wrench className="h-3 w-3" /> {it.garage}</span>}
                  {it.invoice_ref && <span className="text-slate-300">· {it.invoice_ref}</span>}
                </p>
              </div>
              {it.category_key && <Badge tone="slate">{it.category_key}</Badge>}
            </li>
          ))}
        </ul>
      )}
    </SectionCard>
  );
}
