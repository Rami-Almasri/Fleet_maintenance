import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Generic data fetcher with loading/error/reload + SWR-style background revalidation.
 *
 * @param fetcher  async () => data   (memoize with useCallback at the call site)
 * @param deps     dependency array that re-triggers a fresh (skeleton) load
 * @param options  { refreshInterval, revalidateOnFocus, paused }
 *   - refreshInterval:   ms between silent background polls (0 / omitted = off)
 *   - revalidateOnFocus: silently revalidate when the tab regains focus (default true)
 *   - paused:            () => boolean — when it returns true, background polling
 *                        (interval + focus) is skipped, so the view never shifts
 *                        under an in-flight action (e.g. while a modal/drawer is open).
 *
 * `reload()`               — foreground refresh (shows the loading skeleton).
 * `reload({ silent:true })`— background refresh: refetch and swap the data in with
 *                            NO loading flip, so scroll / modals / filters stay put.
 * `mutate(next)`           — set data directly for optimistic UI (accepts a value or
 *                            an updater fn, same as a React state setter).
 */
export default function useFetch(fetcher, deps = [], options = {}) {
  const { refreshInterval = 0, revalidateOnFocus = true, paused } = options;

  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);   // foreground (first paint / manual reload)
  const [validating, setValidating] = useState(false); // background revalidation in flight
  const [error, setError] = useState('');

  // eslint-disable-next-line react-hooks/exhaustive-deps
  const memoFetcher = useCallback(fetcher, deps);

  // Latest `paused` predicate, read at poll time without re-arming the interval each render.
  const pausedRef = useRef(paused);
  pausedRef.current = paused;

  const aliveRef = useRef(true);      // guards setState after unmount
  const inFlightRef = useRef(false);  // lets a poll tick skip if a request is still running
  const genRef = useRef(0);           // request generation → stale responses are discarded

  useEffect(() => {
    aliveRef.current = true;
    return () => { aliveRef.current = false; };
  }, []);

  const load = useCallback(async ({ silent = false } = {}) => {
    const gen = ++genRef.current;
    inFlightRef.current = true;
    if (silent) setValidating(true); else setLoading(true);
    setError('');
    try {
      const result = await memoFetcher();
      if (aliveRef.current && gen === genRef.current) setData(result);
    } catch (err) {
      // A failed background poll must not wipe good on-screen data — just surface the error.
      if (aliveRef.current && gen === genRef.current) {
        setError(err.response?.data?.message || err.response?.data?.msg || err.message || 'Request failed');
      }
    } finally {
      if (gen === genRef.current) inFlightRef.current = false;
      if (aliveRef.current && gen === genRef.current) {
        if (silent) setValidating(false); else setLoading(false);
      }
    }
  }, [memoFetcher]);

  // Fresh (skeleton) load whenever the fetcher identity changes.
  useEffect(() => { load(); }, [load]);

  // Silent background polling — skipped while a request is in flight, the tab is hidden,
  // or paused() (e.g. a modal is open), so the UI never shifts under the user.
  useEffect(() => {
    if (!refreshInterval) return undefined;
    const tick = () => {
      if (inFlightRef.current) return;
      if (typeof document !== 'undefined' && document.hidden) return;
      if (pausedRef.current && pausedRef.current()) return;
      load({ silent: true });
    };
    const id = setInterval(tick, refreshInterval);
    return () => clearInterval(id);
  }, [refreshInterval, load]);

  // Catch up silently when the user comes back to the tab.
  useEffect(() => {
    if (!revalidateOnFocus) return undefined;
    const onFocus = () => {
      if (inFlightRef.current) return;
      if (pausedRef.current && pausedRef.current()) return;
      load({ silent: true });
    };
    window.addEventListener('focus', onFocus);
    return () => window.removeEventListener('focus', onFocus);
  }, [revalidateOnFocus, load]);

  return { data, loading, validating, error, reload: load, mutate: setData, setData };
}
