import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';
import { homePathForRoles } from '../config/access';
import { useI18n } from '../i18n/I18nContext';
import LanguageToggle from '../components/LanguageToggle';

// Feature chips under the sign-in form. Keys resolve against `login.chips.*`.
const CHIPS = ['vehicles', 'maintenance', 'contracts', 'dataHealth'];

// The product name. A brand is never translated — it reads "Faster" in every language.
const BRAND = 'Faster';

export default function Login() {
  const { login } = useAuth();
  const { t } = useI18n();
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
      const loggedIn = await login(email, password);
      // Land on a home the user can actually see (the driver can't open the Dashboard).
      navigate(homePathForRoles(loggedIn?.roles ?? []), { replace: true });
    } catch (err) {
      // `msg` may be a string ("Invalid credentials") OR a Laravel validation bag
      // ({ email: [...], password: [...] }). Flatten the bag to its first message so we
      // never render an object as a React child.
      // No `response` at all means the request never reached the API (backend down,
      // wrong port, DNS). Saying "check your credentials" there sends people hunting
      // for a password problem that doesn't exist — name the real cause instead.
      if (!err.response) {
        setError(t('login.unreachable'));
        return;
      }
      let msg = err.response?.data?.msg;
      if (msg && typeof msg === 'object') msg = Object.values(msg).flat()[0];
      setError(msg || t('login.failed'));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div
      className="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-12 text-white"
      style={{
        background:
          'radial-gradient(120% 90% at 80% -10%, #12203f 0%, transparent 55%),' +
          'radial-gradient(100% 80% at 10% 110%, #0e1b33 0%, transparent 55%),' +
          'linear-gradient(180deg, #070c18 0%, #05080f 100%)',
      }}
    >
      {/* Scoped animations for the ambient scene — self-contained, no global CSS needed. */}
      <style>{`
        @keyframes fv-aurora {
          0%,100% { transform: translate3d(0,0,0) scale(1); }
          33%     { transform: translate3d(6%,-4%,0) scale(1.12); }
          66%     { transform: translate3d(-5%,5%,0) scale(0.94); }
        }
        @keyframes fv-drift {
          0%,100% { transform: translateY(0); }
          50%     { transform: translateY(-22px); }
        }
        @keyframes fv-grid {
          0%   { background-position: 0 0; }
          100% { background-position: 0 -56px; }
        }
        @keyframes fv-sheen {
          0%   { transform: translateX(-120%); }
          60%,100% { transform: translateX(220%); }
        }
        @keyframes fv-spot {
          0%,100% { opacity: 0.7; transform: translate(-50%,-50%) scale(1); }
          50%     { opacity: 1;   transform: translate(-50%,-50%) scale(1.15); }
        }
        @keyframes fv-twinkle {
          0%,100% { opacity: 0.15; transform: translateY(0); }
          50%     { opacity: 0.9;  transform: translateY(-14px); }
        }
        @media (prefers-reduced-motion: reduce) {
          .fv-anim { animation: none !important; }
        }
      `}</style>

      {/* ---- Ambient scene ---- */}
      {/* Overhead spotlight cone from the top */}
      <div
        className="fv-anim pointer-events-none absolute left-1/2 top-0 h-[46rem] w-[46rem] -translate-x-1/2 -translate-y-1/3"
        style={{ background: 'radial-gradient(circle, rgba(250,204,21,0.10), rgba(250,204,21,0.04) 35%, transparent 62%)', animation: 'fv-spot 12s ease-in-out infinite' }}
      />
      {/* Aurora blobs */}
      <div
        className="fv-anim pointer-events-none absolute -start-40 -top-40 h-[38rem] w-[38rem] rounded-full blur-3xl"
        style={{ background: 'radial-gradient(circle, rgba(250,204,21,0.20), transparent 62%)', animation: 'fv-aurora 18s ease-in-out infinite' }}
      />
      <div
        className="fv-anim pointer-events-none absolute -bottom-48 -end-32 h-[42rem] w-[42rem] rounded-full blur-3xl"
        style={{ background: 'radial-gradient(circle, rgb(var(--brand-500) / 0.34), transparent 60%)', animation: 'fv-aurora 22s ease-in-out infinite reverse' }}
      />
      <div
        className="fv-anim pointer-events-none absolute start-[62%] top-2/3 h-96 w-96 rounded-full blur-3xl"
        style={{ background: 'radial-gradient(circle, rgb(var(--accent-500) / 0.16), transparent 65%)', animation: 'fv-aurora 26s ease-in-out infinite' }}
      />
      {/* Moving grid */}
      <div
        className="fv-anim pointer-events-none absolute inset-0 opacity-30"
        style={{
          backgroundImage:
            'linear-gradient(to right, rgba(255,255,255,0.05) 1px, transparent 1px), linear-gradient(to bottom, rgba(255,255,255,0.05) 1px, transparent 1px)',
          backgroundSize: '56px 56px',
          maskImage: 'radial-gradient(ellipse 80% 70% at 50% 40%, black, transparent 100%)',
          WebkitMaskImage: 'radial-gradient(ellipse 80% 70% at 50% 40%, black, transparent 100%)',
          animation: 'fv-grid 8s linear infinite',
        }}
      />
      {/* Floating particles */}
      <div className="pointer-events-none absolute inset-0">
        {[
          { l: '12%', t: '22%', s: 3, d: 7, delay: 0 },
          { l: '24%', t: '68%', s: 2, d: 9, delay: 1.5 },
          { l: '38%', t: '14%', s: 4, d: 11, delay: 0.6 },
          { l: '52%', t: '80%', s: 2, d: 8, delay: 2.2 },
          { l: '66%', t: '30%', s: 3, d: 10, delay: 1 },
          { l: '78%', t: '58%', s: 2, d: 12, delay: 0.3 },
          { l: '86%', t: '20%', s: 4, d: 9, delay: 1.8 },
          { l: '18%', t: '44%', s: 2, d: 13, delay: 2.6 },
          { l: '70%', t: '82%', s: 3, d: 8, delay: 0.9 },
          { l: '44%', t: '52%', s: 2, d: 11, delay: 3 },
        ].map((p, i) => (
          <span
            key={i}
            className="fv-anim absolute rounded-full bg-[#facc15]"
            style={{
              left: p.l,
              top: p.t,
              width: p.s,
              height: p.s,
              boxShadow: '0 0 8px 1px rgba(250,204,21,0.6)',
              animation: `fv-twinkle ${p.d}s ease-in-out ${p.delay}s infinite`,
            }}
          />
        ))}
      </div>
      {/* Edge vignette for depth */}
      <div
        className="pointer-events-none absolute inset-0"
        style={{ background: 'radial-gradient(ellipse 70% 60% at 50% 45%, transparent 40%, rgba(3,5,10,0.55) 100%)' }}
      />
      {/* Top hairline glow */}
      <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-[#facc15]/40 to-transparent" />

      {/* Language switch — must be reachable BEFORE signing in, otherwise an
          Arabic speaker has no way to read the login form. */}
      <div className="absolute end-4 top-4 z-10 sm:end-6 sm:top-6">
        <LanguageToggle />
      </div>

      {/* ---- Card ---- */}
      <div className="relative w-full max-w-md animate-fade-in-up">
        {/* Gradient border wrapper */}
        <div
          className="rounded-3xl p-px shadow-[0_30px_80px_-20px_rgba(0,0,0,0.65)]"
          style={{ background: 'linear-gradient(160deg, rgba(250,204,21,0.5), rgba(255,255,255,0.08) 30%, rgba(255,255,255,0.03) 60%, rgb(var(--brand-500) / 0.4))' }}
        >
          <div className="relative overflow-hidden rounded-[calc(1.5rem-1px)] bg-navy-900/80 p-8 backdrop-blur-2xl sm:p-10">
            {/* Card sheen sweep */}
            <div className="pointer-events-none absolute inset-0 overflow-hidden">
              <div
                className="fv-anim absolute inset-y-0 w-1/3 -skew-x-12"
                style={{ background: 'linear-gradient(90deg, transparent, rgba(255,255,255,0.06), transparent)', animation: 'fv-sheen 7s ease-in-out infinite 1.2s' }}
              />
            </div>

            <div className="relative">
              {/* Logo */}
              <div className="flex flex-col items-center text-center">
                <div
                  className="fv-anim flex h-16 w-16 items-center justify-center overflow-hidden rounded-2xl bg-white/10 p-2.5 ring-1 ring-inset ring-[#facc15]/30 backdrop-blur"
                  style={{ animation: 'fv-drift 6s ease-in-out infinite' }}
                >
                  <img
                    src="/brand-logo.webp"
                    alt={BRAND}
                    className="h-full w-full object-contain drop-shadow-[0_0_14px_rgba(250,204,21,0.55)]"
                  />
                </div>
                <h1 className="mt-5 font-display text-3xl font-bold tracking-tight">
                  {t('login.welcome')} <span className="text-[#facc15]">{BRAND}</span>
                </h1>
                <p className="mt-2 text-sm text-white/60">
                  {t('login.subtitle')}
                </p>
              </div>

              <form onSubmit={submit} className="mt-8 space-y-5">
                {error && (
                  <div className="animate-fade flex items-start gap-2 rounded-xl bg-red-500/15 px-3.5 py-3 text-sm text-red-200 ring-1 ring-inset ring-red-400/30">
                    <svg className="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8"><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h16.9a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" /></svg>
                    {error}
                  </div>
                )}

                <div>
                  <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-white/50">{t('login.email')}</label>
                  <div className="relative">
                    <span className="pointer-events-none absolute start-3.5 top-1/2 -translate-y-1/2 text-white/40">
                      <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.6"><rect x="3" y="5" width="18" height="14" rx="2" /><path strokeLinecap="round" strokeLinejoin="round" d="m3 7 9 6 9-6" /></svg>
                    </span>
                    <input
                      type="email"
                      value={email}
                      onChange={(e) => setEmail(e.target.value)}
                      required
                      autoFocus
                      className="w-full rounded-xl border border-white/10 bg-white/5 py-3 ps-11 pe-3.5 text-sm text-white placeholder-white/30 outline-none transition focus:border-[#facc15]/60 focus:bg-white/10 focus:ring-4 focus:ring-[#facc15]/15"
                      placeholder="you@example.com"
                    />
                  </div>
                </div>

                <div>
                  <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-white/50">{t('login.password')}</label>
                  <div className="relative">
                    <span className="pointer-events-none absolute start-3.5 top-1/2 -translate-y-1/2 text-white/40">
                      <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.6"><rect x="4" y="11" width="16" height="10" rx="2" /><path strokeLinecap="round" strokeLinejoin="round" d="M8 11V7a4 4 0 1 1 8 0v4" /></svg>
                    </span>
                    <input
                      type={show ? 'text' : 'password'}
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      required
                      className="w-full rounded-xl border border-white/10 bg-white/5 py-3 ps-11 pe-11 text-sm text-white placeholder-white/30 outline-none transition focus:border-[#facc15]/60 focus:bg-white/10 focus:ring-4 focus:ring-[#facc15]/15"
                      placeholder="••••••••"
                    />
                    <button
                      type="button"
                      onClick={() => setShow((v) => !v)}
                      className="absolute end-2 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-white/40 transition hover:bg-white/10 hover:text-white/80"
                      title={show ? t('login.hidePassword') : t('login.showPassword')}
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
                  className="group relative flex w-full items-center justify-center gap-2 overflow-hidden rounded-xl bg-[#facc15] px-4 py-3 text-sm font-bold text-navy-950 shadow-[0_10px_30px_-8px_rgba(250,204,21,0.6)] transition hover:bg-[#fde047] active:translate-y-px disabled:opacity-70 disabled:active:translate-y-0"
                >
                  {loading ? (
                    <>
                      <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z" />
                      </svg>
                      {t('login.signingIn')}
                    </>
                  ) : (
                    <>
                      {t('login.signIn')}
                      <svg className="h-4 w-4 transition-transform group-hover:translate-x-0.5 rtl:-scale-x-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.2"><path strokeLinecap="round" strokeLinejoin="round" d="M5 12h14m-6-6 6 6-6 6" /></svg>
                    </>
                  )}
                </button>
              </form>

              <div className="mt-7 flex flex-wrap items-center justify-center gap-2">
                {CHIPS.map((c) => (
                  <span key={c} className="rounded-full bg-white/5 px-2.5 py-1 text-[11px] font-medium text-white/55 ring-1 ring-inset ring-white/10">
                    {t(`login.chips.${c}`)}
                  </span>
                ))}
              </div>
            </div>
          </div>
        </div>

        <p className="mt-6 text-center text-xs text-white/35">
          {t('login.footer', { year: new Date().getFullYear() })}
        </p>
      </div>
    </div>
  );
}
