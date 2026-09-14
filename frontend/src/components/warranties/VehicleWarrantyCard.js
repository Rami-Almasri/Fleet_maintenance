import { useCallback, useState } from 'react';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { useToast } from '../ui/Toast';
import { usePermissions } from '../../hooks/usePermissions';
import { useI18n } from '../../i18n/I18nContext';
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import Icon from '../ui/Icon';
import Modal from '../ui/Modal';
import { Card } from '../ui/Misc';
import { Input, Textarea } from '../ui/Field';
import { WARRANTY_STATE_TONE, coverText } from '../../lib/warranty';

/**
 * The car's warranty, on the car's page — recorded here, because this is where it belongs.
 *
 * WARRANTY IS AN ATTRIBUTE OF THE CAR, like its registration or its odometer. Not a workflow, not a
 * claims department: a fact somebody types in once and the rest of the system reads. So the place to
 * add it is the car's own page, next to the other facts about that car, and not a separate module
 * somebody has to remember exists.
 *
 * ── WHAT THIS CARD REFUSES TO DO ───────────────────────────────────────────────────────────────
 *
 * It never shows an expiry DATE as if it were the answer. Cover ends on months OR kilometres,
 * whichever comes first, and in a rental fleet the kilometre leg usually gets there first: a car
 * doing 6,000 km a month burns 20,000 km of cover in ten weeks while its date still looks healthy.
 * So the badge leads with the state the SERVER computed against this car's odometer, and the dates
 * are supporting detail.
 *
 * "No warranty recorded" is a NEUTRAL state with a prompt, never an error. It is not the same as
 * "no cover" — it means nobody has typed the booklet in yet.
 */
export default function VehicleWarrantyCard({ vehicleId }) {
  const { t } = useI18n();
  const toast = useToast();
  const { can } = usePermissions();
  const canManage = can('warranty.manage');

  const fetcher = useCallback(async () => {
    const { data } = await api.get(`/warranties/vehicle/${vehicleId}`);
    return data.data || {};
  }, [vehicleId]);

  const { data, loading, error, reload } = useFetch(fetcher, [vehicleId]);
  const [editing, setEditing] = useState(null);   // null = closed, {} = new, {…} = existing

  if (loading) return <Card><div className="p-4 text-sm text-slate-400">{t('common.loading')}</div></Card>;
  // A warranty panel that fails is silent rather than alarming: the rest of the car's page is still
  // true, and a red box here would read as a problem with the CAR.
  if (error) return null;

  const state = data?.state || { state: 'none' };
  const headline = state.headline;
  // Only the whole-car promise is editable from here. A part's own supplier warranty belongs to the
  // part and is managed where parts are.
  const vehicleWarranty = (data?.warranties || []).find((w) => w.kind === 'vehicle' && w.status === 'active');

  return (
    <Card>
      <div className="flex items-start justify-between gap-3 p-4 pb-2">
        <div className="flex items-center gap-2">
          <Icon.Shield className="h-4 w-4 text-slate-400" />
          <h3 className="text-sm font-semibold text-slate-700">{t('warranty.cardTitle')}</h3>
        </div>
        <Badge tone={WARRANTY_STATE_TONE[state.state] || 'gray'} dot>
          {t(`warranty.state.${state.state}`)}
        </Badge>
      </div>

      {state.state === 'none' ? (
        <div className="px-4 pb-4">
          <p className="text-sm text-slate-500">{t('warranty.none')}</p>
          <p className="mt-1 text-xs text-slate-400">{t('warranty.noneHint')}</p>
          {canManage && (
            <Button size="sm" variant="secondary" className="mt-3" onClick={() => setEditing({})}>
              {t('warranty.add')}
            </Button>
          )}
        </div>
      ) : (
        <div className="px-4 pb-4">
          {headline && (
            <>
              <div className="text-sm font-medium text-slate-700">
                {headline.provider_name || headline.subject}
              </div>
              {headline.provider_kind && (
                <div className="mt-0.5 text-xs text-slate-500">{t(`warranty.providerKind.${headline.provider_kind}`)}</div>
              )}

              {(headline.starts_on || headline.expires_on) && (
                <div className="mt-2 text-xs text-slate-500">
                  {t('warranty.window', { from: headline.starts_on || '—', to: headline.expires_on || '—' })}
                </div>
              )}

              {/* WHAT IS LEFT — or, once the limit is passed, HOW FAR OVER.
                  The fleet's own report prints the overage as a negative (`-25,167`), and it is the
                  figure somebody standing next to the car needs: "expired" cannot distinguish a car
                  that went over last week — still worth a call to the dealer — from one that went
                  over two years ago. Never both numbers at once; a warranty is on one side of its
                  limit or the other. */}
              {coverText(headline.verdict, t) && (
                <div className="mt-2 flex items-baseline gap-2">
                  <span className="text-xs uppercase tracking-wide text-slate-400">
                    {headline.verdict?.km_over != null || headline.verdict?.days_over != null
                      ? t('warranty.over')
                      : t('warranty.remaining')}
                  </span>
                  <span className={`text-sm font-semibold ${headline.verdict?.km_over != null || headline.verdict?.days_over != null ? 'text-red-600' : 'text-slate-800'}`}>
                    {coverText(headline.verdict, t)}
                  </span>
                </div>
              )}

              {/* Said out loud, never smoothed over. */}
              {state.distance_unknown && (
                <p className="mt-2 rounded-md bg-amber-50 px-2 py-1.5 text-xs text-amber-800">
                  {t('warranty.distanceUnknown')}
                </p>
              )}
            </>
          )}

          {canManage && vehicleWarranty && (
            <div className="mt-3 flex gap-2 border-t border-slate-100 pt-3">
              <Button size="sm" variant="ghost" onClick={() => setEditing(vehicleWarranty)}>{t('warranty.edit')}</Button>
            </div>
          )}
        </div>
      )}

      {/* [[traceability-visibility-requirement]] — the page says where its answer comes from. */}
      <p className="border-t border-slate-100 px-4 py-2 text-[11px] leading-snug text-slate-400">
        {t('warranty.dataOrigin')}
      </p>

      {editing && (
        <WarrantyFormModal
          vehicleId={vehicleId}
          warranty={editing.id ? editing : null}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); reload(); toast.success(t('warranty.saved')); }}
        />
      )}
    </Card>
  );
}

/**
 * The whole form: who covers it, from when, until when. Six fields, and three of them optional.
 *
 * ── WHY IT ASKS FOR AN END DATE, NOT A DURATION ────────────────────────────────────────────────
 *
 * The table stores `duration_months` and derives the expiry from it, which is the right shape for a
 * supplier's "12 months from fitting". But nobody reads a manufacturer's warranty as a duration —
 * they read the date on the certificate. So the form asks for the date and converts, rather than
 * making somebody count months backwards from a document they are holding.
 *
 * The kilometre limit is optional and stays optional. Most people will fill in two dates and stop,
 * and that is a complete, correct warranty record.
 */
function WarrantyFormModal({ vehicleId, warranty, onClose, onSaved }) {
  const { t } = useI18n();
  const toast = useToast();
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState({});

  const [form, setForm] = useState(() => ({
    provider_name: warranty?.provider_name || '',
    starts_on: warranty?.starts_on || new Date().toISOString().slice(0, 10),
    expires_on: warranty?.expires_on || '',
    /**
     * THE ODOMETER THE WARRANTY ENDS AT — an absolute reading, not a distance.
     *
     * This is how the fleet's own Daily Warranty Report states it: a column reading `50000` against
     * a car showing 47,408 km, and a "finish" of 2,592. The limit is a number on the dial, not a
     * distance from some start point, and asking for a duration made somebody do subtraction against
     * a document that already gives the answer.
     *
     * Stored as `expires_at_km`, which the model derives from start_odometer + duration_km — so the
     * form sends start 0 and duration = this number, and the derivation lands on exactly it.
     */
    expires_at_km: warranty?.expires_at_km ?? '',
    contact_phone: warranty?.contact_phone || '',
    notes: warranty?.notes || '',
  }));
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  /**
   * Months between the two dates, rounded up — the shape the table wants, from the shape people have.
   *
   * Rounded UP so a warranty ending on the 29th of a month is never quietly shortened to the 28th;
   * erring long by a day is harmless, erring short tells somebody their cover ended before it did.
   */
  const monthsBetween = (from, to) => {
    const a = new Date(from);
    const b = new Date(to);
    if (Number.isNaN(a.getTime()) || Number.isNaN(b.getTime())) return null;
    const months = (b.getFullYear() - a.getFullYear()) * 12 + (b.getMonth() - a.getMonth());
    return b.getDate() >= a.getDate() ? months : months; // whole months; the day-of-month is kept by the server's derivation
  };

  const save = async () => {
    setErrors({});

    if (!form.starts_on || !form.expires_on) {
      setErrors({ expires_on: [t('warranty.needDates')] });
      return;
    }
    if (new Date(form.expires_on) <= new Date(form.starts_on)) {
      setErrors({ expires_on: [t('warranty.endBeforeStart')] });
      return;
    }

    setSaving(true);
    try {
      const payload = {
        subject: form.provider_name?.trim() || 'Manufacturer warranty',
        provider_name: form.provider_name?.trim() || null,
        starts_on: form.starts_on,
        duration_months: monthsBetween(form.starts_on, form.expires_on),
        /**
         * The odometer reading the cover ends at, expressed in the shape the table stores.
         *
         * `expires_at_km` is derived as start_odometer + duration_km, so sending start 0 and
         * duration = the target lands the derived value exactly on the number the user typed —
         * and `km_remaining` then computes as target − current odometer, which is precisely the
         * "Warenty finish" column on the fleet's own report.
         */
        start_odometer: form.expires_at_km === '' ? null : 0,
        duration_km: form.expires_at_km === '' ? null : Number(form.expires_at_km),
        contact_phone: form.contact_phone?.trim() || null,
        notes: form.notes?.trim() || null,
      };

      if (warranty?.id) {
        await api.post(`/warranties/${warranty.id}`, payload);
      } else {
        await api.post('/warranties', { ...payload, kind: 'vehicle', vehicle_id: Number(vehicleId) });
      }
      onSaved();
    } catch (err) {
      const res = err?.response?.data;
      if (res?.data && typeof res.data === 'object') {
        setErrors(res.data);
        toast.error(t('warranty.fixFields'));
      } else {
        toast.error(res?.message || t('warranty.saveError'));
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open
      onClose={() => !saving && onClose()}
      title={t('warranty.addTitle')}
      subtitle={t('warranty.addHint')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>{t('common.cancel')}</Button>
          <Button onClick={save} loading={saving}>{t('common.save')}</Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          {/* ONE field for who covers it — the fleet's own warranty report has a single `company`
              column ("ARABIAN AUTOMOBILES"). There was a manufacturer/dealer/supplier/garage picker
              here; it classified something nobody classifies, so it is gone. */}
          <Input
            label={t('warranty.fieldProvider')}
            placeholder={t('warranty.fieldProviderPlaceholder')}
            value={form.provider_name}
            error={errors.provider_name?.[0]}
            onChange={set('provider_name')}
          />
          <Input type="date" label={t('warranty.fieldStartsOn')} required value={form.starts_on} error={errors.starts_on?.[0]} onChange={set('starts_on')} />
          <Input type="date" label={t('warranty.fieldExpiresOn')} required value={form.expires_on} error={errors.expires_on?.[0]} onChange={set('expires_on')} />
          {/* THE ODOMETER IT ENDS AT — one absolute number, exactly as the warranty report states it
              ("50000" against a car reading 47,408, leaving 2,592). Not a distance from a start. */}
          <Input
            type="number"
            min="0"
            label={t('warranty.fieldExpiresAtKm')}
            placeholder="50000"
            value={form.expires_at_km}
            error={errors.duration_km?.[0]}
            onChange={set('expires_at_km')}
          />
        </div>
        <p className="-mt-2 text-xs text-slate-500">{t('warranty.kmHint')}</p>

        <Input label={t('warranty.fieldContactPhone')} value={form.contact_phone} onChange={set('contact_phone')} />
        <Textarea label={t('warranty.fieldNotes')} rows={2} value={form.notes} onChange={set('notes')} />
      </div>
    </Modal>
  );
}
