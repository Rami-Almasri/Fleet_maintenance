import { useEffect, useRef } from 'react';
import { useLocation } from 'react-router-dom';
import api from '../api/client';

// How often the tab reports "still here" while visible. Kept in step with the
// backend's 5-minute online window (UserActivityService::ONLINE_WINDOW).
const HEARTBEAT_MS = 60000;

// Reports the signed-in user's presence to the backend so the Workforce
// Operations Center shows REAL "who's online / what are they using" data.
// Two signals:
//   • navigation — one per route change; appends a row to the activity log.
//   • heartbeat  — every 60s while the tab is visible; only refreshes last-seen.
// All failures are swallowed — activity telemetry must never disrupt the app,
// and it no-ops entirely when logged out.
export default function useActivityTracker(resolveLabel) {
  const location = useLocation();
  const path = location.pathname;
  const labelRef = useRef(null);
  labelRef.current = (resolveLabel && resolveLabel(path)) || null;

  const ping = (navigation) => {
    if (!localStorage.getItem('token')) return;
    if (!navigation && document.visibilityState !== 'visible') return;
    api.post('/auth/activity', { path, page: labelRef.current, navigation }).catch(() => {});
  };

  // Navigation ping whenever the route changes.
  useEffect(() => {
    ping(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [path]);

  // Steady heartbeat while the tab stays open + visible.
  useEffect(() => {
    const id = setInterval(() => ping(false), HEARTBEAT_MS);
    const onVisible = () => ping(false);
    document.addEventListener('visibilitychange', onVisible);
    return () => {
      clearInterval(id);
      document.removeEventListener('visibilitychange', onVisible);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [path]);
}
