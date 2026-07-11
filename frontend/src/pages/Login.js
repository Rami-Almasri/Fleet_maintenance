import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';

export default function Login() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [show, setShow] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      await login(email, password);
      navigate('/', { replace: true });
    } catch (err) {
      // `msg` may be a string ("Invalid credentials") OR a Laravel validation bag
      // ({ email: [...], password: [...] }). Flatten the bag to its first message so we
      // never render an object as a React child.
      let msg = err.response?.data?.msg;
      if (msg && typeof msg === 'object') msg = Object.values(msg).flat()[0];
      setError(msg || 'Login failed. Check your credentials.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="grid min-h-screen lg:grid-cols-2">
      {/* Brand panel */}
      <div className="relative hidden overflow-hidden bg-slate-900 lg:block">
        <div className="absolute inset-0 bg-gradient-to-br from-indigo-600 via-violet-600 to-fuchsia-600 opacity-90" />
        {/* floating orbs */}
        <div className="absolute -left-20 -top-20 h-80 w-80 animate-float rounded-full bg-white/10 blur-2xl" />
        <div className="absolute bottom-0 right-0 h-96 w-96 animate-float rounded-full bg-indigo-300/20 blur-3xl" style={{ animationDelay: '1.5s' }} />
        <div className="relative flex h-full flex-col justify-between p-12 text-white">
          <div className="flex items-center gap-3">
            <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-white/15 text-xl font-bold backdrop-blur">F</div>
            <span className="text-xl font-bold tracking-tight">FleetView</span>
          </div>
          <div>
            <h2 className="max-w-md text-4xl font-bold leading-tight tracking-tight">
              Your fleet, fully in sync.
            </h2>
            <p className="mt-4 max-w-md text-base text-white/80">
              Cars, contracts, invoices and customers — live from OfficeManager, in one clean dashboard.
            </p>
            <div className="mt-8 flex flex-wrap gap-2">
              {['Vehicles', 'Contracts', 'Invoices', 'Data Health'].map((t) => (
                <span key={t} className="rounded-full bg-white/10 px-3 py-1 text-sm font-medium backdrop-blur ring-1 ring-inset ring-white/15">
                  {t}
                </span>
              ))}
            </div>
          </div>
          <p className="text-sm text-white/50">© {new Date().getFullYear()} FleetView · Management Suite</p>
        </div>
      </div>

      {/* Form panel */}
      <div className="flex items-center justify-center px-4 py-12">
        <div className="w-full max-w-sm animate-fade-in-up">
          <div className="mb-8 flex flex-col items-center lg:hidden">
            <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-500 text-xl font-bold text-white shadow-glow">F</div>
          </div>

          <h1 className="text-2xl font-bold tracking-tight text-slate-900">Welcome back</h1>
          <p className="mt-1 text-sm text-slate-500">Sign in to your dashboard to continue.</p>

          <form onSubmit={submit} className="mt-8 space-y-5">
            {error && (
              <div className="flex items-start gap-2 rounded-xl bg-red-50 px-3.5 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">
                <svg className="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8"><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h16.9a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" /></svg>
                {error}
              </div>
            )}

            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Email</label>
              <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                autoFocus
                className="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm shadow-sm outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
                placeholder="you@example.com"
              />
            </div>

            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Password</label>
              <div className="relative">
                <input
                  type={show ? 'text' : 'password'}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  required
                  className="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 pr-11 text-sm shadow-sm outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
                  placeholder="••••••••"
                />
                <button
                  type="button"
                  onClick={() => setShow((v) => !v)}
                  className="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                  title={show ? 'Hide password' : 'Show password'}
                >
                  {show ? (
                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7"><path strokeLinecap="round" strokeLinejoin="round" d="M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.9 4.2A9.8 9.8 0 0 1 12 4c5 0 9 4.5 9 8a12 12 0 0 1-2.2 3.3M6.6 6.6A12 12 0 0 0 3 12c0 3.5 4 8 9 8a9.6 9.6 0 0 0 3.4-.6" /></svg>
                  ) : (
                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7"><path strokeLinecap="round" strokeLinejoin="round" d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" /></svg>
                  )}
                </button>
              </div>
            </div>

            <button
              type="submit"
              disabled={loading}
              className="flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-b from-indigo-500 to-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-indigo-600/25 transition-all duration-150 hover:to-indigo-700 active:scale-[0.98] disabled:opacity-60 disabled:active:scale-100"
            >
              {loading && (
                <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z" />
                </svg>
              )}
              {loading ? 'Signing in…' : 'Sign in'}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
