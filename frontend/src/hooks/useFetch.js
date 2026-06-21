import { useCallback, useEffect, useState } from 'react';

/**
 * Generic data fetcher with loading/error/reload.
 * @param fetcher async () => data   (memoize with useCallback at the call site)
 */
export default function useFetch(fetcher, deps = []) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  // eslint-disable-next-line react-hooks/exhaustive-deps
  const memoFetcher = useCallback(fetcher, deps);

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const result = await memoFetcher();
      setData(result);
    } catch (err) {
      setError(err.response?.data?.message || err.response?.data?.msg || err.message || 'Request failed');
    } finally {
      setLoading(false);
    }
  }, [memoFetcher]);

  useEffect(() => {
    let active = true;
    (async () => {
      setLoading(true);
      setError('');
      try {
        const result = await memoFetcher();
        if (active) setData(result);
      } catch (err) {
        if (active) setError(err.response?.data?.message || err.response?.data?.msg || err.message || 'Request failed');
      } finally {
        if (active) setLoading(false);
      }
    })();
    return () => {
      active = false;
    };
  }, [memoFetcher]);

  return { data, loading, error, reload: load, setData };
}
