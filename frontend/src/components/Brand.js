// Brand slot for the top-left of the app shell — the Faster lockup.
//
//  The mark lives at  frontend/public/brand-logo.webp  (the yellow Faster
//  fin). It sits on a near-black tile so the electric yellow pops — the
//  signature black-and-yellow performance lockup. A vector fallback shows
//  if the asset ever fails to load.
//
// Responsive: in the collapsed icon-rail (lg) only the mark shows; the wordmark
// (Sora) + tagline (Inter) appear when expanded or in the mobile drawer.

import { useState } from 'react';
import { useI18n } from '../i18n/I18nContext';

const LOGO_SRC = '/brand-logo.webp';

export default function Brand({ collapsed = false, markOnly = false }) {
  const { t } = useI18n();
  const [imgOk, setImgOk] = useState(true);

  return (
    <div className={`flex items-center gap-3 ${collapsed ? 'lg:justify-center' : ''}`}>
      {/* Mark / logo — fixed 36px square on a dark tile so the yellow pops. */}
      <div className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-navy-950 p-1 shadow-sm ring-1 ring-accent-400/25">
        {imgOk ? (
          <img
            src={LOGO_SRC}
            alt="Faster"
            className="h-full w-full object-contain drop-shadow-[0_0_6px_rgba(250,204,21,0.35)]"
            onError={() => setImgOk(false)}
          />
        ) : (
          <svg className="h-5 w-5 text-accent-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <path d="M3 13l2-5a3 3 0 0 1 2.8-2h8.4A3 3 0 0 1 19 8l2 5M5 13h14M5 13v4m14-4v4M7 17h.01M17 17h.01" />
          </svg>
        )}
      </div>

      {/* Wordmark (Sora) + tagline (Inter) — hidden in the collapsed rail and
          when the caller asks for the mark only (e.g. a compact header). */}
      {!markOnly && (
      <div className={`min-w-0 leading-tight ${collapsed ? 'lg:hidden' : ''}`}>
        <p className="truncate font-display text-[16px] font-bold tracking-tight text-white">Faster</p>
        <p className="truncate text-[10px] font-semibold uppercase tracking-[0.16em] text-accent-400/90">{t('Fleet Maintenance')}</p>
      </div>
      )}
    </div>
  );
}
