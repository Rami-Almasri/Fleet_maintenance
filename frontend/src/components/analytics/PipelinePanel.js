// The Dashboard's maintenance pipeline strip. It self-fetches the live board and feeds
// PipelineAnalytics the SAME lanes the /maintenance-workflow board renders, so the Dashboard shape
// and the board columns can never disagree. Renders nothing until there's live work (or if the viewer
// can't see the board), so it never leaves an empty frame on the Dashboard.
//
// This is where the old /car-status stage board's three charts now live — the Dashboard is the one
// place a manager reads "where is everything and who is holding it".
import { useCallback, useMemo } from 'react';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { useLanes, buildLanes } from '../../config/maintenanceLanes';
import { useI18n } from '../../i18n/I18nContext';
import PipelineAnalytics from './PipelineAnalytics';

export default function PipelinePanel() {
  const { t } = useI18n();
  const fetcher = useCallback(async () => (await api.get('/maintenance-tickets/board')).data.data, []);
  const { data, loading } = useFetch(fetcher, [], { refreshInterval: 15000 });

  const laneDefs = useLanes();
  const lanes = useMemo(() => buildLanes(laneDefs.all, data?.columns || {}), [laneDefs, data]);
  const hasTickets = useMemo(() => lanes.some((l) => l.tickets.length), [lanes]);

  if (loading || !hasTickets) return null;

  return (
    <div className="space-y-3">
      <div className="flex items-center gap-2.5">
        <span className="h-5 w-1 rounded-full bg-orange-500" />
        <h2 className="font-display text-lg font-bold tracking-tight" style={{ color: 'var(--ink)' }}>
          {t('Maintenance Pipeline')}
        </h2>
      </div>
      <PipelineAnalytics lanes={lanes} />
    </div>
  );
}
