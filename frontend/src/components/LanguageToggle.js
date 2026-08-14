// English ⇄ العربية switch for the top bar. Flips the whole UI between LTR and
// RTL via I18nContext (persists to localStorage + sets <html lang/dir>). Shows
// the language you'll switch TO, mirroring the ThemeToggle's placement & style.

import { useI18n } from '../i18n/I18nContext';

export default function LanguageToggle({ className = '' }) {
  const { lang, toggle, t } = useI18n();
  const next = lang === 'en' ? 'العربية' : 'English';
  // On a phone the full word is the single widest thing in the header — it is
  // what pushed the action cluster over the brand. Below sm we show the same
  // switch as a square icon button with a two-letter code instead.
  const nextShort = lang === 'en' ? 'ع' : 'EN';

  return (
    <button
      type="button"
      onClick={toggle}
      title={`${t('common.language')}: ${next}`}
      aria-label={t('common.language')}
      className={`inline-flex h-9 shrink-0 items-center justify-center gap-1.5 rounded-xl border border-slate-200 bg-white px-2 text-xs font-semibold text-slate-500 shadow-sm transition hover:text-slate-700 hover:ring-1 hover:ring-slate-200 sm:px-3 ${className}`}
    >
      <svg className="hidden h-[15px] w-[15px] shrink-0 sm:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
        <circle cx="12" cy="12" r="9" />
        <path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
      </svg>
      <span className="tabular-nums sm:hidden">{nextShort}</span>
      <span className="hidden tabular-nums sm:inline">{next}</span>
    </button>
  );
}
