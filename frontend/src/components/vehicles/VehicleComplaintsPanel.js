// VehicleComplaintsPanel — the "Complaint History" tab for one car. View A of the shared complaint entity:
// every customer complaint ever raised on this vehicle, newest first, each opening the same timeline drawer
// the Complaints Center uses. Self-contained: give it a vehicleId and it fetches /complaints?vehicle_id=.

import { useCallback, useState } from 'react';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import Badge from '../ui/Badge';
import { Skeleton } from '../ui/Skeleton';
import { EmptyState } from '../ui/Misc';
import Icon from '../ui/Icon';
import ComplaintDetailDrawer from '../complaints/ComplaintDetailDrawer';
import { STAGE_META } from '../complaints/stages';
import { useI18n } from '../../i18n/I18nContext';

// Arabic still shows a Gregorian date in Latin digits so it lines up with the rest of the timeline.
function fmtDate(iso, lang) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleDateString(lang === 'ar' ? 'ar-AE-u-ca-gregory-nu-latn' : undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

export default function VehicleComplaintsPanel({ vehicleId }) {
  const { t, lang } = useI18n();
  const [openId, setOpenId] = useState(null);
  const fetcher = useCallback(
    async () => (await api.get('/complaints', { params: { vehicle_id: vehicleId } })).data.data,
    [vehicleId],
  );
  const { data, loading, error, reload } = useFetch(fetcher, [vehicleId], { paused: () => !!openId });

  const rows = data?.rows || [];

  if (loading && !data) {
    return <div className="space-y-3"><Skeleton className="h-20 rounded-xl" /><Skeleton className="h-20 rounded-xl" /></div>;
  }
  if (error) {
    return <p className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{error}</p>;
  }
  if (!rows.length) {
    return <EmptyState icon={<span aria-hidden className="text-2xl">📣</span>} title={t('No complaints')} message={t('No customer complaints have been raised on this vehicle.')} />;
  }

  return (
    <div className="space-y-3">
      {rows.map((r) => {
        const m = STAGE_META[r.status] || { label: r.status, emoji: '•', tone: 'slate' };
        return (
          <button
            key={r.id}
            type="button"
            onClick={() => setOpenId(r.id)}
            className="flex w-full items-start gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-start shadow-soft transition hover:border-indigo-300 hover:shadow-md"
          >
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-mono text-xs font-semibold text-slate-400">#{r.id}</span>
                <Badge tone={m.tone}>{m.emoji} {m.label}</Badge>
                {r.customer && <span className="text-xs text-slate-500">{r.customer}</span>}
                <span className="text-xs text-slate-400">· {fmtDate(r.created_at, lang)}</span>
              </div>
              {r.complaint && <p className="mt-1 truncate text-sm italic text-slate-600">“{r.complaint}”</p>}
            </div>
            <Icon.ArrowRight className="mt-1 h-4 w-4 shrink-0 text-slate-300 rtl:-scale-x-100" />
          </button>
        );
      })}

      <ComplaintDetailDrawer id={openId} open={!!openId} onChanged={() => reload({ silent: true })} onClose={() => { setOpenId(null); reload({ silent: true }); }} />
    </div>
  );
}
