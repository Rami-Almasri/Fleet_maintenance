import { useEffect, useMemo } from 'react';

/**
 * Shared plumbing for every Operations Hub tab.
 *
 * Each tab builds its own card models but normalises two fields so the hub's persistent
 * Search + Filter bars can drive all three tabs identically:
 *   • `bucket`   — one of 'urgent' | 'attention' | 'ok' (the tab maps its own status onto this)
 *   • `haystack` — a pre-lowercased string of everything the search box should match
 *
 * The hook counts the buckets, reports the tally UP to the hub (so the persistent FilterChips show
 * the ACTIVE tab's counts), and returns the searched + filtered list the tab renders. Because the
 * search/filter state lives on the hub and only the item data lives here, switching tabs never loses
 * the search context. `onStats` is the hub's stable state setter, so this never loops.
 */
export default function usePanelFilter(items, { search, filter, onStats }) {
  const stats = useMemo(() => {
    const s = { all: items.length, urgent: 0, attention: 0, ok: 0 };
    items.forEach((i) => { if (s[i.bucket] != null) s[i.bucket] += 1; });
    return s;
  }, [items]);

  useEffect(() => { onStats(stats); }, [stats, onStats]);

  return useMemo(() => {
    const needle = search.trim().toLowerCase();
    return items.filter((i) => {
      if (filter !== 'all' && i.bucket !== filter) return false;
      if (!needle) return true;
      return i.haystack.includes(needle);
    });
  }, [items, search, filter]);
}
