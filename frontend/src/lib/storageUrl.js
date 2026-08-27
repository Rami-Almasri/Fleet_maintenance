// Resolving stored-file URLs (photos, scans) to something the browser can actually load.
//
// The API returns whatever the storage disk says. On the local `public` disk that is an APP_URL
// path — which in development points at a host the dev server is NOT running on — so a raw
// `/storage/...` URL renders as a broken image. Rewriting it onto the API client's own origin makes
// it resolve to the dev backend regardless of APP_URL. S3 signed URLs live on a different host and
// carry a signature, so they are left exactly as-is.

import api from '../api/client';

/** The backend origin behind the API client, e.g. http://127.0.0.1:8000 */
const API_ORIGIN = (api.defaults.baseURL || '').replace(/\/api\/?$/, '');

export default function storageSrc(url) {
  if (!url) return null;
  try {
    const u = new URL(url, API_ORIGIN || window.location.origin);
    if (API_ORIGIN && u.pathname.startsWith('/storage/')) return `${API_ORIGIN}${u.pathname}`;
    return u.href;
  } catch {
    return url;
  }
}
