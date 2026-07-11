// English ⇄ العربية switch for the top bar. Flips the whole UI between LTR and
// RTL via I18nContext (persists to localStorage + sets <html lang/dir>). Shows
// the language you'll switch TO, mirroring the ThemeToggle's placement & style.

import { useI18n } from '../i18n/I18nContext';

export default function LanguageToggle({ className = '' }) {
  const { lang, toggle, t } = useI18n();
  const next = lang === 'en' ? 'العربية' : 'English';

  return (
    <button
      type="button"
      onClick={toggle}
      title={`${t('common.language')}: ${next}`}
      aria-label={t('common.language')}
      className={`inline-flex h-9 items-center justify-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-500 shadow-sm transition hover:text-slate-700 hover:ring-1 hover:ring-slate-200 ${className}`}
    >
      <svg className="h-[15px] w-[15px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
        <circle cx="12" cy="12" r="9" />
        <path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
      </svg>
      <span className="tabular-nums">{next}</span>
    </button>
  );
}
