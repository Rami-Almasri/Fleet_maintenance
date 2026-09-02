import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { useI18n } from '../../i18n/I18nContext';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import { Card } from '../ui/Misc';
import { WARRANTY_STATE_TONE, STAGE_TONE, remainingText } from '../../lib/warranty';

/**
 * The car's warranty, on the car's own page, without a second click.
 *
 * THE REQUIREMENT WAS "the status should be visible without opening another page", and that is not
 * a convenience. The moment somebody decides to spend money on a car is the moment they are looking
 * at it, and a warranty that lives one navigation away is a warranty nobody checks.
 *
 * ── WHAT THIS CARD REFUSES TO DO ───────────────────────────────────────────────────────────────
 *
 * It never shows an expiry DATE as the answer. Cover ends when either leg runs out — months or
 * kilometres, whichever comes first — and in a rental fleet the distance leg is usually the binding
 * one: a car doing 6,000 km a month burns 20,000 km of cover in ten weeks while its date still looks
 * healthy. So the badge leads with the server's computed state and the dates are supporting detail.
 *
 * Where the server says `distance_unknown`, the card says so out loud. A km-bounded warranty judged
 * with no odometer reading is not "probably fine", and the one thing worse than not knowing is a
 * green badge that implies we do.
 *
 * "No warranty recorded" is shown as a NEUTRAL state with a prompt, never as an absence. It is not
 * the same as "no cover": it means nobody has typed the booklet in yet, and until they do nothing
 * can be claimed against it.
 */
export default function VehicleWarrantyCard({ vehicleId, canRecord = false, onRecord }) {
  const { t } = useI18n();

  const fetcher = useCallback(async () => {
    const { data } = await api.get(`/warranties/vehicle/${vehicleId}`);
    return data.data || {};
  }, [vehicleId]);

  const { data, loading, error } = useFetch(fetcher, [vehicleId]);

  if (loading) return <Card><div className="p-4 text-sm text-slate-400">{t('warranties.loading')}</div></Card>;
  // A warranty panel that fails is silent rather than alarming: the rest of the vehicle page is
  // still true, and a red box here would read as a problem with the CAR.
  if (error) return null;

  const state = data?.state || { state: 'none' };
  const warranties = data?.warranties || [];
  const openCases = data?.open_cases || [];
  const headline = state.headline;

  return (
    <Card>
      <div className="flex items-start justify-between gap-3 p-4 pb-2">
        <div className="flex items-center gap-2">
          <Icon.Shield className="h-4 w-4 text-slate-400" />
          <h3 className="text-sm font-semibold text-slate-700">{t('warrantyOps.vehicle.title')}</h3>
        </div>
        <Badge tone={WARRANTY_STATE_TONE[state.state] || 'gray'} dot>
          {t(`warrantyOps.state.${state.state}`)}
        </Badge>
      </div>

      {state.state === 'none' ? (
        <div className="px-4 pb-4">
          <p className="text-sm text-slate-500">{t('warrantyOps.vehicle.none')}</p>
          {/* The prompt matters more than the empty state: an unrecorded warranty is unclaimable. */}
          <p className="mt-1 text-xs text-slate-400">{t('warrantyOps.vehicle.noneHint')}</p>
          {canRecord && (
            <button type="button" onClick={onRecord} className="mt-3 text-xs font-medium text-indigo-600 hover:underline">
              {t('warrantyOps.vehicle.addWarranty')}
            </button>
          )}
        </div>
      ) : (
        <div className="px-4 pb-4">
          {headline && (
            <>
              <div className="text-sm font-medium text-slate-700">{headline.subject}</div>
              <div className="mt-0.5 text-xs text-slate-500">
                {headline.provider_kind ? t(`warrantyOps.providerKind.${headline.provider_kind}`) : null}
                {headline.provider_name ? ` · ${headline.provider_name}` : ''}
              </div>

              {(headline.starts_on || headline.expires_on) && (
                <div className="mt-2 text-xs text-slate-500">
                  {t('warrantyOps.vehicle.window', { from: headline.starts_on || '—', to: headline.expires_on || '—' })}
                </div>
              )}

              {/* WHAT IS LEFT — the number somebody can act on, on both legs. */}
              {remainingText(headline.verdict, t) && (
                <div className="mt-2 flex items-baseline gap-2">
                  <span className="text-xs uppercase tracking-wide text-slate-400">{t('warrantyOps.vehicle.remaining')}</span>
                  <span className="text-sm font-semibold text-slate-800">{remainingText(headline.verdict, t)}</span>
                </div>
              )}

              {/* Said out loud, never smoothed over. */}
              {state.distance_unknown && (
                <p className="mt-2 rounded-md bg-amber-50 px-2 py-1.5 text-xs text-amber-800">
                  {t('warrantyOps.vehicle.distanceUnknown')}
                </p>
              )}
            </>
          )}

          {/* Every other promise on the car — the tyre's supplier cover, the garage's repair
              warranty. Listed because on this page they are exactly as claimable as the car's own,
              and they are the ones people forget. */}
          {warranties.length > 1 && (
            <ul className="mt-3 space-y-1 border-t border-slate-100 pt-3">
              {warranties.filter((w) => w.id !== headline?.id).map((w) => (
                <li key={w.id} className="flex items-center justify-between gap-2 text-xs">
                  <span className="truncate text-slate-600">{w.subject}</span>
                  <Badge tone={w.verdict?.state === 'active' ? 'green' : 'slate'}>
                    {t(`warranties.state.${w.verdict?.state || 'expired'}`)}
                  </Badge>
                </li>
              ))}
            </ul>
          )}

          {openCases.length > 0 && (
            <div className="mt-3 border-t border-slate-100 pt-3">
              <div className="text-xs uppercase tracking-wide text-slate-400">{t('warrantyOps.vehicle.openCases')}</div>
              <ul className="mt-1 space-y-1">
                {openCases.map((c) => (
                  <li key={c.id} className="flex items-center justify-between gap-2 text-xs">
                    <Link to={`/warranty/cases/${c.id}`} className="truncate text-indigo-600 hover:underline">{c.subject}</Link>
                    <Badge tone={STAGE_TONE[c.stage] || 'gray'}>{t(`warrantyOps.stage.${c.stage}`)}</Badge>
                  </li>
                ))}
              </ul>
            </div>
          )}

          <div className="mt-3 flex gap-3 border-t border-slate-100 pt-3 text-xs">
            <Link to={`/parts?tab=warranties&vehicle=${vehicleId}`} className="font-medium text-indigo-600 hover:underline">
              {t('warrantyOps.vehicle.viewWarranty')}
            </Link>
            <Link to={`/warranty/cases?vehicle_id=${vehicleId}&open=0`} className="font-medium text-slate-500 hover:underline">
              {t('warrantyOps.vehicle.history')}
            </Link>
          </div>
        </div>
      )}
    </Card>
  );
}
