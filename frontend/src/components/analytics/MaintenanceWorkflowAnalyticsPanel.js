// Dashboard-embeddable version of the Maintenance Cycle charts. It self-fetches the live board and
// feeds MaintenanceWorkflowAnalytics the SAME lanes the /maintenance-workflow board renders, so the
// Dashboard funnel and the board columns can never disagree. Renders nothing until there's live work
// (or if the viewer can't see the board), so it never leaves an empty frame on the Dashboard.
import { useCallback, useMemo } from 'react';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { useLanes, buildLanes } from '../../config/maintenanceLanes';
import MaintenanceWorkflowAnalytics from './MaintenanceWorkflowAnalytics';

export default function MaintenanceWorkflowAnalyticsPanel() {
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
          Maintenance Pipeline
        </h2>
      </div>
      <MaintenanceWorkflowAnalytics lanes={lanes} />
    </div>
  );
}
