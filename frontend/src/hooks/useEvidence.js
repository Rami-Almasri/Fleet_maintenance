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
  // Sibling views of the same subject — [{ key, id }]. Faults and Services are two populations of one
  // corpus, and a garage's scheduled work is not a footnote to its repairs, so they are TABS rather
  // than a filter hidden inside one table. Empty for every other claim; the drawer shows no tab bar.
  const [tabs, setTabs] = useState([]);
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
    (id, siblingTabs = []) => {
      if (!id) return;
      setQueryId(id);
      setTabs(siblingTabs);
      setEvidence(null);
      load(id, 1);
    },
    [load]
  );

  /**
   * Move to a sibling view. Page resets to 1 — carrying page 4 across to a tab with two rows lands
   * the reader on an empty table that looks like missing data.
   */
  const switchTab = useCallback(
    (id) => {
      if (!id || id === queryId) return;
      setQueryId(id);
      setEvidence(null);
      load(id, 1);
    },
    [queryId, load]
  );

  const close = useCallback(() => {
    genRef.current += 1; // any in-flight response is now stale
    setQueryId(null);
    setTabs([]);
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

  return {
    queryId, evidence, page, loading, error, open, close, goToPage, switchTab, tabs,
    isOpen: queryId !== null,
  };
}
