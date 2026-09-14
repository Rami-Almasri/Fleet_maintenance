import { Link } from 'react-router-dom';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../ui/Icon';

/**
 * "This vehicle is currently under warranty until 29 Apr 2029. Consider sending it to the
 * authorized dealer."
 *
 * ── A RECOMMENDATION. NOT A BLOCK. ─────────────────────────────────────────────────────────────
 *
 * This is the entire warranty behaviour inside the maintenance cycle, and the restraint is the
 * design. It does not stop the dispatch, does not gate the garage picker, does not demand an
 * override and does not require anybody to answer it. The supervisor reads it, and then routes the
 * car wherever they judge best — which may very well be the usual garage, because the dealer is a
 * three-week wait and the customer needs the car on Thursday.
 *
 * The earlier cut of this feature DID block, and that was the mistake: a gate on a judgement call
 * that the system cannot make. What the system CAN do is make sure nobody routes a covered car to a
 * paid garage without knowing it was covered. That is one sentence, shown at the one moment it is
 * actionable.
 *
 * Renders nothing when the car has no live cover, which is most of the fleet — `vehicle_warranty` is
 * null on the ticket payload and this component disappears entirely rather than showing an
 * "all clear" nobody needs.
 *
 * @param {object} cover ticket.vehicle_warranty — { provider, expires_on, contact_phone, … }
 */
export default function WarrantyRecommendation({ cover, vehicleId }) {
  const { t } = useI18n();

  if (!cover) return null;

  // Formatted per the reader's locale via the existing date pipeline rather than a raw ISO string —
  // "29 Apr 2029" is what the sentence promises, not "2029-04-29".
  const until = cover.expires_on
    ? new Date(cover.expires_on).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
    : '—';

  return (
    <div className="rounded-lg border border-blue-200 bg-blue-50/60 p-3">
      <div className="flex items-start gap-3">
        <Icon.Shield className="mt-0.5 h-4 w-4 shrink-0 text-blue-600" />
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium text-blue-900">
            {t('warranty.cycleBanner', { date: until })}
          </p>

          <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-blue-800/90">
            {cover.provider && <span>{t('warranty.cycleBannerProvider', { provider: cover.provider })}</span>}
            {/* The phone number is the point of showing the provider at all — it is what turns
                "consider the dealer" into something somebody can act on in the next minute. */}
            {cover.contact_phone && (
              <a href={`tel:${cover.contact_phone}`} className="font-medium underline">
                {t('warranty.cycleBannerCall', { phone: cover.contact_phone })}
              </a>
            )}
            {vehicleId && (
              <Link to={`/vehicles/${vehicleId}`} className="font-medium underline">
                {t('warranty.cardTitle')}
              </Link>
            )}
          </div>

          {/* Said explicitly, because a blue box in this app usually means something is required. */}
          <p className="mt-1.5 text-[11px] text-blue-700/80">{t('warranty.cycleBannerNote')}</p>
        </div>
      </div>
    </div>
  );
}
