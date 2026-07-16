import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import api from '../api/client';
import { useAuth } from '../auth/AuthContext';
import { useToast } from '../components/ui/Toast';

const NotificationsContext = createContext(null);

// How often the bell polls while the tab is focused. Hidden tabs don't poll at all;
// they catch up instantly on refocus. 12s keeps the badge near-realtime without load.
const POLL_MS = 12000;

// Map a payload severity to the toast tone we have available.
const toastTone = (sev) => (sev === 'critical' ? 'error' : sev === 'success' ? 'success' : 'info');

export function NotificationsProvider({ children }) {
  const { isAuthenticated } = useAuth();
  const toast = useToast();

  const [unreadCount, setUnreadCount] = useState(0);
  const [latest, setLatest] = useState([]);   // most recent few (for the dropdown)
  const [ready, setReady] = useState(false);   // first poll completed

  // Ids we've already shown the user — so a fresh arrival toasts exactly once.
  const seenIds = useRef(null);        // null until the first poll seeds the baseline
  const latestTopId = useRef(null);    // newest id we hold, so the API can flag anything newer
  const pollingRef = useRef(false);

  const poll = useCallback(async () => {
    if (pollingRef.current) return;
    pollingRef.current = true;
    try {
      const after = latestTopId.current;
      const { data } = await api.get('/notifications/poll', { params: after ? { after } : {} });
      const payload = data.data || {};
      const items = payload.latest || [];

      setUnreadCount(payload.unread_count || 0);
      setLatest(items);
      latestTopId.current = items[0]?.id || null;

      // Toast genuinely new, still-unread arrivals — but never on the very first poll.
      if (seenIds.current) {
        const fresh = items.filter((n) => !n.read && !seenIds.current.has(n.id));
        if (fresh.length) {
          const head = fresh[0];
          const more = fresh.length > 1 ? ` (+${fresh.length - 1} more)` : '';
          toast[toastTone(head.severity)](`${head.title}${more}`);
        }
      }
      seenIds.current = new Set(items.map((n) => n.id));
      setReady(true);
    } catch (_) {
      // Network hiccup — keep the last good state and try again next tick.
    } finally {
      pollingRef.current = false;
    }
  }, [toast]);

  // Drive the poll loop only while authenticated + tab visible.
  useEffect(() => {
    if (!isAuthenticated) {
      setUnreadCount(0);
      setLatest([]);
      seenIds.current = null;
      latestTopId.current = null;
      setReady(false);
      return;
    }

    let timer = null;
    const tick = () => {
      if (document.visibilityState === 'visible') poll();
    };

    poll(); // immediate first load
    timer = setInterval(tick, POLL_MS);

    const onVisible = () => {
      if (document.visibilityState === 'visible') poll();
    };
    document.addEventListener('visibilitychange', onVisible);

    return () => {
      clearInterval(timer);
      document.removeEventListener('visibilitychange', onVisible);
    };
  }, [isAuthenticated, poll]);

  // ── Actions (optimistic, then reconciled by the next poll) ──────────────────

  const markRead = useCallback(async (id) => {
    setLatest((list) => list.map((n) => (n.id === id ? { ...n, read: true } : n)));
    setUnreadCount((c) => Math.max(0, c - 1));
    try {
      const { data } = await api.post(`/notifications/${id}/read`);
      if (data?.data?.unread_count != null) setUnreadCount(data.data.unread_count);
    } catch (_) {
      poll();
    }
  }, [poll]);

  const markAllRead = useCallback(async () => {
    setLatest((list) => list.map((n) => ({ ...n, read: true })));
    setUnreadCount(0);
    try {
      await api.post('/notifications/read-all');
    } catch (_) {
      poll();
    }
  }, [poll]);

  const dismiss = useCallback(async (id) => {
    setLatest((list) => list.filter((n) => n.id !== id));
    try {
      const { data } = await api.delete(`/notifications/${id}`);
      if (data?.data?.unread_count != null) setUnreadCount(data.data.unread_count);
    } catch (_) {
      poll();
    }
  }, [poll]);

  // Clear the whole feed, or just one inbox category (routine|complaints|test_drive).
  // Always scoped to the authenticated user on the backend.
  const clearAll = useCallback(async (category) => {
    if (!category) {
      setLatest([]);
      setUnreadCount(0);
    }
    try {
      await api.post('/notifications/clear', category ? { category } : {});
    } finally {
      poll(); // reconcile badge + dropdown (needed for a category-scoped clear)
    }
  }, [poll]);

  const sendDemo = useCallback(async () => {
    try {
      await api.post('/notifications/demo');
      await poll();
    } catch (_) {
      toast.error('Could not send demo notification');
    }
  }, [poll, toast]);

  // Memoize so consumers only re-render when the data they read actually changes,
  // not on every unrelated parent render. (Actions are stable useCallbacks.)
  const value = useMemo(
    () => ({ unreadCount, latest, ready, refresh: poll, markRead, markAllRead, dismiss, clearAll, sendDemo }),
    [unreadCount, latest, ready, poll, markRead, markAllRead, dismiss, clearAll, sendDemo]
  );

  return <NotificationsContext.Provider value={value}>{children}</NotificationsContext.Provider>;
}

export function useNotifications() {
  const ctx = useContext(NotificationsContext);
  if (!ctx) throw new Error('useNotifications must be used within <NotificationsProvider>');
  return ctx;
}
