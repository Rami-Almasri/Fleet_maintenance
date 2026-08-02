// Light "Platinum" ⇄ Dark "Cockpit" switch for the top bar.
// Animated sun/moon swap; persists via ThemeContext (localStorage + <html>).

import { useTheme } from '../theme/ThemeContext';
import { useI18n } from '../i18n/I18nContext';

export default function ThemeToggle({ className = '' }) {
  const { theme, toggle } = useTheme();
  const { t } = useI18n();
  const dark = theme === 'dark';

  return (
    <button
      type="button"
      onClick={toggle}
      title={dark ? t('theme.toLight') : t('theme.toDark')}
      aria-label={t('theme.toggle')}
      className={`group relative inline-flex h-9 w-9 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-white text-slate-500 shadow-sm transition hover:text-slate-700 hover:ring-1 hover:ring-slate-200 ${className}`}
    >
      {/* Sun (light mode) */}
      <svg
        className={`absolute h-[18px] w-[18px] text-amber-500 transition-all duration-300 ${dark ? 'translate-y-6 rotate-90 opacity-0' : 'translate-y-0 rotate-0 opacity-100'}`}
        fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"
      >
        <circle cx="12" cy="12" r="4" />
        <path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.4 1.4M17.6 17.6L19 19M19 5l-1.4 1.4M6.4 17.6L5 19" />
      </svg>
      {/* Moon (dark mode) */}
      <svg
        className={`absolute h-[18px] w-[18px] text-brand-300 transition-all duration-300 ${dark ? 'translate-y-0 rotate-0 opacity-100' : '-translate-y-6 -rotate-90 opacity-0'}`}
        fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round"
      >
        <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" />
      </svg>
    </button>
  );
}
