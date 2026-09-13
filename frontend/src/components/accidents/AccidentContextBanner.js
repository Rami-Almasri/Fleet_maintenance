import { Link } from 'react-router-dom';
import { useI18n } from '../../i18n/I18nContext';
import { fmtDate } from '../../lib/format';

/**
 * "THE VEHICLE WAS WITH A CUSTOMER" — the first thing anybody sees on an accident case, and the
 * single most consequential fact on it.
 *
 * ── WHY IT IS A BANNER AND NOT A TAB ───────────────────────────────────────────────────────────
 *
 * Because it changes what every other decision on the page means. A liability verdict, an insurance
 * claim and a repair estimate all read differently when a paying customer was at the wheel, and the
 * person opening this page needs that context before they read anything else — not after they think
 * to click "Rental". Buried in a secondary tab, it is a fact that gets discovered late, which is
 * exactly how a contract keeps billing for three weeks while nobody decides what to do about the
 * hire.
 *
 * ── EVERYTHING HERE IS THE FROZEN SNAPSHOT ─────────────────────────────────────────────────────
 *
 * The name, the contract number and the rental window are what was true at the moment of the crash,
 * copied onto the case then and never recomputed. The live state of that contract is shown
 * SEPARATELY and labelled as such, because "open at the time" and "still open today" are two
 * different facts and an insurer will eventually ask about both.
 *
 * The reassurance line at the bottom is deliberate. People assume reporting a crash ends the hire;
 * it does not, and saying so here is cheaper than answering it every time.
 */
export default function AccidentContextBanner({ context }) {
  const { t } = useI18n();
  if (!context) return null;

  const withCustomer = context.was_with_customer;
  const party = String(context.responsible_party_type || 'unknown').replace(/_/g, ' ');

  if (!withCustomer) {
    return (
      <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
        <p className="text-sm font-semibold text-slate-700">
          {t('Responsibility at the time')}: <span className="capitalize">{t(party)}</span>
        </p>
        {(context.driver_name || context.driver_phone) && (
          <p className="mt-1 text-xs text-slate-500">
            {t('Driver')}: {context.driver_name || '—'}{context.driver_phone ? ` · ${context.driver_phone}` : ''}
          </p>
        )}
        {context.note && <p className="mt-1 text-xs text-slate-500">{context.note}</p>}
        <p className="mt-2 text-[11px] text-slate-400">
          {context.detected
            ? t('Detected from the vehicle’s records at the time of the accident.')
            : t('Stated by the person who reported the accident.')}
        </p>
      </div>
    );
  }

  const c = context.customer || {};
  const k = context.contract || {};
  const now = context.contract_now;

  return (
    <div className="rounded-xl border-2 border-amber-400 bg-amber-50 p-4 text-amber-950">
      <p className="flex items-center gap-2 text-base font-bold uppercase tracking-wide">
        <span aria-hidden className="text-lg">⚠️</span>
        {t('Vehicle was with a customer at the time of the accident')}
      </p>

      <dl className="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <dt className="text-[11px] font-semibold uppercase tracking-wide text-amber-700">{t('Customer')}</dt>
          <dd className="font-semibold">
            {/* The live id is only for the link; the NAME is the snapshot and is shown either way. */}
            {c.id
              ? <Link to={`/customers/${c.id}`} className="underline hover:no-underline">{c.name || t('Unnamed')}</Link>
              : (c.name || t('Unnamed'))}
            {c.phone && <span className="ms-2 font-normal text-amber-800">{c.phone}</span>}
          </dd>
        </div>
        <div>
          <dt className="text-[11px] font-semibold uppercase tracking-wide text-amber-700">{t('Rental contract')}</dt>
          <dd className="font-semibold">
            {k.id
              ? <Link to={`/contracts/${k.id}`} className="underline hover:no-underline">{k.contract_no || `#${k.id}`}</Link>
              : (k.contract_no || '—')}
          </dd>
        </div>
        <div>
          <dt className="text-[11px] font-semibold uppercase tracking-wide text-amber-700">{t('Contract status')}</dt>
          <dd className="font-semibold capitalize">
            {t(k.state_at_accident || 'unknown')}
            {/* Two facts, never merged: what it was then, and what it is now. */}
            {now?.state && now.state !== k.state_at_accident && (
              <span className="ms-2 font-normal text-amber-800">
                ({t('now')}: {t(now.state)}{now.in_date ? ` ${t('on')} ${fmtDate(now.in_date)}` : ''})
              </span>
            )}
          </dd>
        </div>
        <div>
          <dt className="text-[11px] font-semibold uppercase tracking-wide text-amber-700">{t('Rental period')}</dt>
          <dd className="font-semibold">
            {fmtDate(k.out_date)} → {k.in_date ? fmtDate(k.in_date) : t('open-ended')}
          </dd>
        </div>
      </dl>

      <p className="mt-3 border-t border-amber-300 pt-2 text-[11px] leading-relaxed">
        {t('The rental contract is independent of this accident. It has not been closed, charges continue under its own terms, and the accident’s costs are settled separately — deciding what happens to the hire is a commercial decision, taken on the contract.')}
      </p>
    </div>
  );
}
