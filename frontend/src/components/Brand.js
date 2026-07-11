// Brand slot for the top-left of the app shell — logo-ready.
//
//  → To use your logo: drop a file at  frontend/public/brand-logo.svg
//    (or .png). It will render automatically, sized to the slot. Until then,
//    a gradient "fleet" mark + the Sora wordmark show as a placeholder.
//
// Responsive: in the collapsed icon-rail (lg) only the mark shows; the wordmark
// (Sora) + tagline (Inter) appear when expanded or in the mobile drawer.

import { useState } from 'react';

const LOGO_SRC = '/brand-logo.svg'; // swap to '/brand-logo.png' if you use a PNG

export default function Brand({ collapsed = false }) {
  const [imgOk, setImgOk] = useState(true);

  return (
    <div className={`flex items-center gap-3 ${collapsed ? 'lg:justify-center' : ''}`}>
      {/* Mark / logo — fixed 36px square so alignment never shifts. */}
      <div className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gradient-to-br from-brand-500 to-violet-500 text-white shadow-glow ring-1 ring-white/15">
        {imgOk ? (
          <img
            src={LOGO_SRC}
            alt="Logo"
            className="h-full w-full object-contain"
            onError={() => setImgOk(false)}
          />
        ) : (
          <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <path d="M3 13l2-5a3 3 0 0 1 2.8-2h8.4A3 3 0 0 1 19 8l2 5M5 13h14M5 13v4m14-4v4M7 17h.01M17 17h.01" />
          </svg>
        )}
      </div>

      {/* Wordmark (Sora) + tagline (Inter) — hidden in the collapsed rail. */}
      <div className={`min-w-0 leading-tight ${collapsed ? 'lg:hidden' : ''}`}>
        <p className="truncate font-display text-[16px] font-bold tracking-tight text-white">FleetView</p>
        <p className="truncate text-[10px] font-semibold uppercase tracking-[0.16em] text-steel-500">Command Center</p>
      </div>
    </div>
  );
}
