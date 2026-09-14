import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { useI18n } from '../../i18n/I18nContext';

/**
 * "THIS CAR CANNOT BE RENTED" — said on the car's own page, before anybody promises it to a customer.
 *
 * This is what survives of the retired Accidents tab, and it is the part that was never a history
 * view. The crashes themselves read on the Timeline now, in sequence with everything else that
 * happened to the car; a LIST of past accidents belongs there. But an unresolved case is not history
 * — it is a fact about the car's present standing, and the person who needs it earliest is whoever
 * is looking at the car. A refusal discovered at the counter with a customer standing there is a
 * refusal discovered too late, so this renders on Overview rather than behind a filter.
 *
 * `restricted` is ContractEligibilityService's own answer — the same authority the booking gate
 * reads — so this banner can never disagree with what happens at the counter. It is a warning, not a
 * control: there is nothing to override here, because resolving the case is what releases the car.
 *
 * Renders nothing on a car with no hold, which is almost every car.
 */
export default function VehicleAccidentHoldBanner({ vehicleId, onOpenAccidents }) {
  const { t } = useI18n();

  const fetcher = useCallback(
    () => api.get(`/accidents/vehicle/${vehicleId}`).then((r) => r.data.data),
    [vehicleId],
  );
  const { data } = useFetch(fetcher, [vehicleId]);

  // Silent while loading and silent on error: a banner that says "this car is held" is only worth
  // showing when we KNOW it is. Failing open here is safe — the booking gate still refuses.
  if (!data?.restricted) return null;

  // The case actually holding the car, so the reader can go and resolve the specific one rather than
  // going hunting. Falls back to the accident list if the payload names none.
  const holding = (data.cases || []).find((c) => c.stage && c.stage !== 'closed');

  return (
    <div className="rounded-xl border-2 border-red-400 bg-red-50 p-4 text-red-900">
      <p className="flex items-center gap-2 text-sm font-bold">
        <span aria-hidden>🚫</span>{t('This vehicle is held out of the rental pool')}
      </p>
      <p className="mt-1 text-xs leading-relaxed">
        {t('An accident case on this car is still unresolved, so a new rental or booking will be refused. Resolving the case releases it — nothing here needs overriding.')}
      </p>
      <div className="mt-2.5 flex flex-wrap items-center gap-3 text-xs font-semibold">
        {holding && (
          <Link to={`/accidents/${holding.id}`} className="text-red-800 underline-offset-2 hover:underline">
            {t('Open case {ref}', { ref: holding.reference || `#${holding.id}` })}
          </Link>
        )}
        {/* The crashes themselves, where they now live. */}
        <button type="button" onClick={onOpenAccidents} className="text-red-700 underline-offset-2 hover:underline">
          {t('See every accident on the Timeline')}
        </button>
      </div>
    </div>
  );
}
