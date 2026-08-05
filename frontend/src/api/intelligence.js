// The Fleet Intelligence API surface.
//
// One function per endpoint, each unwrapping the { data, success, message } envelope so call sites
// never see it. Kept separate from the page components because a page that builds its own URLs ends
// up owning knowledge of the API shape, and the next endpoint change breaks it silently.

import api from './client';

/** Unwrap the standard response envelope. */
const data = (res) => res.data?.data ?? res.data;

/**
 * The evidence behind any published figure.
 *
 * `queryId` comes FROM the metric itself (every scorecard card and matrix cell carries its own
 * `evidence_query_id`). The frontend never assembles one: the moment it does, it owns knowledge of
 * how evidence is addressed, and the first metric whose evidence needs a different shape breaks it
 * without anybody noticing.
 */
export async function getEvidence(queryId, { page = 1, perPage = 50 } = {}) {
  return data(
    await api.get(`/intelligence/evidence/${encodeURIComponent(queryId)}`, {
      params: { page, per_page: perPage },
    })
  );
}

/** The garage scorecard report — cards, domains, leaderboards, fleet baselines and provenance. */
export async function getGarageScorecards() {
  return data(await api.get('/Maintenance/garage-scorecards'));
}

/**
 * Executive Home — the whole page in one call.
 *
 * Deliberately NOT one request per panel. Six calls would let panels land in a different order on
 * every load, and — the real risk — could straddle a nightly rebuild, so the spend card and the
 * garage table would quietly describe two different corpora. One payload, one `as_of`.
 */
export async function getExecutiveDashboard() {
  return data(await api.get('/intelligence/executive'));
}
