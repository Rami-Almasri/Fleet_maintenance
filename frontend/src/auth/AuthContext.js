import { createContext, useContext, useState, useCallback, useMemo, useEffect } from 'react';
import api from '../api/client';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(() => {
    // Guard the parse: a corrupted `user` entry must NOT throw here. This runs in
    // AuthProvider, which wraps the whole tree ABOVE the ErrorBoundary, so an
    // uncaught throw would white-screen the entire app instead of showing login.
    try {
      const raw = localStorage.getItem('user');
      return raw ? JSON.parse(raw) : null;
    } catch {
      localStorage.removeItem('user');
      return null;
    }
  });
  const [token, setToken] = useState(() => localStorage.getItem('token'));

  const login = useCallback(async (email, password) => {
    const { data } = await api.post('/auth/login', { email, password });
    // backend envelope: { success, msg, data: { user, token } }
    const payload = data.data;
    localStorage.setItem('token', payload.token);
    localStorage.setItem('user', JSON.stringify(payload.user));
    setToken(payload.token);
    setUser(payload.user);
    return payload.user;
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout');
    } catch (_) {
      // ignore — we clear locally regardless
    }
    localStorage.removeItem('token');
    localStorage.removeItem('user');
    setToken(null);
    setUser(null);
  }, []);

  // Refresh the cached account on boot. `user` (roles + permissions) is snapshotted at login and the
  // UI gates whole surfaces on it — the Action Center's lanes, the sidebar, every `can()` call. Without
  // this, a permission granted after the user last signed in stays invisible until they log out and
  // back in. Failures are deliberately silent: an offline/expired call must never drop the session
  // here (the API client already handles 401 globally).
  useEffect(() => {
    if (!token) return;
    let alive = true;
    (async () => {
      try {
        const { data } = await api.get('/auth/me');
        const fresh = data?.data?.user;
        if (!alive || !fresh) return;
        localStorage.setItem('user', JSON.stringify(fresh));
        setUser(fresh);
      } catch (_) {
        // keep the cached user
      }
    })();
    return () => { alive = false; };
  }, [token]);

  // Stable context value — only changes when auth actually changes, so the
  // app-wide consumers of useAuth() don't re-render on unrelated parent renders.
  const value = useMemo(
    () => ({ user, token, isAuthenticated: !!token, login, logout }),
    [user, token, login, logout]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within <AuthProvider>');
  return ctx;
}
