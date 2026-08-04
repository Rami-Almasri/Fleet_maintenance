import { useCallback, useRef, useState } from 'react';
import { getEvidence } from '../api/intelligence';

/**
 * Drives the Evidence Drawer: one open drawer at a time, addressed by evidence id.
 *
 * State lives in a hook rather than in each page because every intelligence surface needs the same
 * behaviour and the drawer must be a singleton — two open drawers would leave the reader unsure
 * which number they were looking at the proof for.
 *
 * The id is always supplied BY the metric being explained. Nothing here constructs one.
 */
export default function useEvidence() {
  const [queryId, setQueryId] = useState(null);
  const [evidence, setEvidence] = useState(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  // Guards against a slow first request landing after a faster second one and overwriting it.
  const genRef = useRef(0);

  const load = useCallback(async (id, nextPage) => {
    const gen = ++genRef.current;
    setLoading(true);
    setError('');

    try {
      const result = await getEvidence(id, { page: nextPage });
      if (gen === genRef.current) {
        setEvidence(result);
        setPage(nextPage);
      }
    } catch (err) {
      if (gen === genRef.current) {
        // A 404 here means the claim itself is unknown, not that there were no rows. Those look the
        // same to a reader and mean very different things, so the message is passed through rather
        // than flattened into an empty state.
        setError(err.response?.data?.message || err.message || 'Could not load the evidence.');
        setEvidence(null);
      }
    } finally {
      if (gen === genRef.current) setLoading(false);
    }
  }, []);

  const open = useCallback(
    (id) => {
      if (!id) return;
      setQueryId(id);
      setEvidence(null);
      load(id, 1);
    },
    [load]
  );

  const close = useCallback(() => {
    genRef.current += 1; // any in-flight response is now stale
    setQueryId(null);
    setEvidence(null);
    setError('');
    setPage(1);
  }, []);

  const goToPage = useCallback(
    (next) => {
      if (queryId) load(queryId, next);
    },
    [queryId, load]
  );

  return { queryId, evidence, page, loading, error, open, close, goToPage, isOpen: queryId !== null };
}
