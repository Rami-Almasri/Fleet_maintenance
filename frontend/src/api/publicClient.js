import axios from 'axios';

// A bare API client for UNAUTHENTICATED, token-gated pages (e.g. the Garage Invoice Portal a garage
// opens with just a link). Deliberately has NO auth-token interceptor and NO 401→/login redirect, so a
// visitor with no session is never bounced to the login screen — the page handles its own states.
const publicApi = axios.create({
  baseURL: process.env.REACT_APP_API_URL || 'http://127.0.0.1:8000/api',
  headers: { Accept: 'application/json' },
});

export default publicApi;
