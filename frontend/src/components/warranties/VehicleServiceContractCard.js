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
import { SERVICE_STATE_TONE, serviceCoverText } from '../../lib/warranty';

/**
 * The prepaid servicing bought with the car — "5 Lube Service / 5 Yrs".
 *
 * ── NOT A SECOND WARRANTY CARD ─────────────────────────────────────────────────────────────────
 *
 * It sits beside the warranty card and deliberately shows different things, because it answers a
 * different question. A warranty asks *"if it breaks, who pays?"*; this asks *"how many free
 * services are left?"* — a count that goes down, which is the figure people actually hit. So the
 * count leads, the odometer limit follows, and the date comes last: a contract with two years left
 * on paper is finished the moment its fifth service is used.
 *
 * The coverage LABEL is shown verbatim ("5 Lube Service/5Yrs"). It is what the dealer and the driver
 * both call the thing, and any parse of it into a number is a convenience that must never replace
 * the words on the document.
 */
export default function VehicleServiceContractCard({ vehicleId }) {
  const { t } = useI18n();
  const toast = useToast();
  const { can } = usePermissions();
  const canManage = can('warranty.manage');

  const fetcher = useCallback(async () => {
    const { data } = await api.get(`/vehicles/${vehicleId}/service-contracts`);
    return data.data || {};
  }, [vehicleId]);

  const { data, loading, error, reload } = useFetch(fetcher, [vehicleId]);
  const [editing, setEditing] = useState(null);
  const [busy, setBusy] = useState(false);

  if (loading) return <Card><div className="p-4 text-sm text-slate-400">{t('common.loading')}</div></Card>;
  // Silent on failure: the rest of the car's page is still true, and a red box here would read as a
  // problem with the CAR.
  if (error) return null;

  const contracts = data?.contracts || [];
  const live = contracts.find((c) => c.status === 'active') || contracts[0] || null;

  const recordOneService = async (contract) => {
    setBusy(true);
    try {
      await api.post(`/service-contracts/${contract.id}/use`);
      toast.success(t('serviceContract.serviceRecorded'));
      reload();
    } catch (err) {
      toast.error(err?.response?.data?.message || t('serviceContract.saveError'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card>
      <div className="flex items-start justify-between gap-3 p-4 pb-2">
        <div className="flex items-center gap-2">
          <Icon.Wrench className="h-4 w-4 text-slate-400" />
          <h3 className="text-sm font-semibold text-slate-700">{t('serviceContract.cardTitle')}</h3>
        </div>
        {live && (
          <Badge tone={SERVICE_STATE_TONE[live.verdict?.state] || 'gray'} dot>
            {t(`serviceContract.state.${live.verdict?.state || 'ended'}`)}
          </Badge>
        )}
      </div>

      {!live ? (
        <div className="px-4 pb-4">
          <p className="text-sm text-slate-500">{t('serviceContract.none')}</p>
          <p className="mt-1 text-xs text-slate-400">{t('serviceContract.noneHint')}</p>
          {canManage && (
            <Button size="sm" variant="secondary" className="mt-3" onClick={() => setEditing({})}>
              {t('serviceContract.add')}
            </Button>
          )}
        </div>
      ) : (
        <div className="px-4 pb-4">
          {/* The words on the document, verbatim. */}
          <div className="text-sm font-medium text-slate-700">
            {live.coverage_label || t('serviceContract.unnamed')}
          </div>
          {live.provider_name && <div className="mt-0.5 text-xs text-slate-500">{live.provider_name}</div>}

          {/* WHAT IS LEFT — services first, then the odometer, then the date. */}
          {serviceCoverText(live.verdict, live, t) && (
            <div className="mt-2 text-sm font-semibold text-slate-800">
              {serviceCoverText(live.verdict, live, t)}
            </div>
          )}

          <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 border-t border-slate-100 pt-3 text-xs">
            {live.ends_at_km != null && (
              <Fact label={t('serviceContract.endsAtKm')} value={`${Number(live.ends_at_km).toLocaleString()} km`} />
            )}
            {live.ends_on && <Fact label={t('serviceContract.endsOn')} value={live.ends_on} />}
            {live.interval_km != null && (
              <Fact label={t('serviceContract.interval')} value={t('serviceContract.everyKm', { n: Number(live.interval_km).toLocaleString() })} />
            )}
            {live.last_service_odometer != null && (
              <Fact label={t('serviceContract.lastService')} value={`${Number(live.last_service_odometer).toLocaleString()} km`} />
            )}
            {/* The one derived figure worth showing: last change + the contract's own interval —
                which may differ from the fleet's service schedule, and this is the dealer's. */}
            {live.next_service_due_at_km != null && (
              <Fact label={t('serviceContract.nextDue')} value={`${Number(live.next_service_due_at_km).toLocaleString()} km`} />
            )}
          </dl>

          {live.verdict?.distance_unknown && (
            <p className="mt-2 rounded-md bg-amber-50 px-2 py-1.5 text-xs text-amber-800">
              {t('serviceContract.distanceUnknown')}
            </p>
          )}

          {canManage && (
            <div className="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3">
              {live.verdict?.state === 'active' && (
                <Button size="sm" variant="secondary" disabled={busy} onClick={() => recordOneService(live)}>
                  {t('serviceContract.useOne')}
                </Button>
              )}
              <Button size="sm" variant="ghost" onClick={() => setEditing(live)}>{t('common.edit')}</Button>
            </div>
          )}
        </div>
      )}

      <p className="border-t border-slate-100 px-4 py-2 text-[11px] leading-snug text-slate-400">
        {t('serviceContract.dataOrigin')}
      </p>

      {editing && (
        <ServiceContractModal
          vehicleId={vehicleId}
          contract={editing.id ? editing : null}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); reload(); toast.success(t('serviceContract.saved')); }}
        />
      )}
    </Card>
  );
}

function Fact({ label, value }) {
  return (
    <div className="min-w-0">
      <dt className="text-[10px] uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className="truncate font-medium text-slate-700">{value}</dd>
    </div>
  );
}

/**
 * The form. Everything is optional except the car.
 *
 * The paperwork arrives incomplete far more often than not — a contract with nothing but
 * "5 Lube Service/5Yrs" and a provider name is still worth recording, and refusing it would mean
 * the fact never gets written down at all.
 */
function ServiceContractModal({ vehicleId, contract, onClose, onSaved }) {
  const { t } = useI18n();
  const toast = useToast();
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState({});

  const [form, setForm] = useState(() => ({
    coverage_label: contract?.coverage_label || '',
    provider_name: contract?.provider_name || '',
    services_total: contract?.services_total ?? '',
    services_used: contract?.services_used ?? 0,
    interval_km: contract?.interval_km ?? '',
    ends_on: contract?.ends_on || '',
    // An ABSOLUTE odometer reading, exactly as the fleet's report states it (50000).
    ends_at_km: contract?.ends_at_km ?? '',
    last_service_odometer: contract?.last_service_odometer ?? '',
    last_service_on: contract?.last_service_on || '',
    contact_phone: contract?.contact_phone || '',
    notes: contract?.notes || '',
  }));
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const numOrNull = (v) => (v === '' || v === null ? null : Number(v));

  const save = async () => {
    setSaving(true);
    setErrors({});
    try {
      const payload = {
        coverage_label: form.coverage_label?.trim() || null,
        provider_name: form.provider_name?.trim() || null,
        contact_phone: form.contact_phone?.trim() || null,
        services_total: numOrNull(form.services_total),
        services_used: numOrNull(form.services_used) ?? 0,
        interval_km: numOrNull(form.interval_km),
        ends_on: form.ends_on || null,
        ends_at_km: numOrNull(form.ends_at_km),
        last_service_odometer: numOrNull(form.last_service_odometer),
        last_service_on: form.last_service_on || null,
        notes: form.notes?.trim() || null,
      };

      if (contract?.id) await api.post(`/service-contracts/${contract.id}`, payload);
      else await api.post(`/vehicles/${vehicleId}/service-contracts`, payload);

      onSaved();
    } catch (err) {
      const res = err?.response?.data;
      if (res?.data && typeof res.data === 'object') { setErrors(res.data); toast.error(t('serviceContract.fixFields')); }
      else toast.error(res?.message || t('serviceContract.saveError'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open
      onClose={() => !saving && onClose()}
      title={t('serviceContract.addTitle')}
      subtitle={t('serviceContract.addHint')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>{t('common.cancel')}</Button>
          <Button onClick={save} loading={saving}>{t('common.save')}</Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          {/* The words on the document. Shown verbatim everywhere, never replaced by the parse. */}
          <Input
            label={t('serviceContract.fieldCoverage')}
            placeholder={t('serviceContract.fieldCoveragePlaceholder')}
            value={form.coverage_label}
            error={errors.coverage_label?.[0]}
            onChange={set('coverage_label')}
          />
          <Input
            label={t('serviceContract.fieldProvider')}
            placeholder={t('serviceContract.fieldProviderPlaceholder')}
            value={form.provider_name}
            onChange={set('provider_name')}
          />
        </div>

        <div className="rounded-lg border border-slate-200 p-3">
          <p className="mb-2 text-sm font-medium text-slate-700">{t('serviceContract.countSection')}</p>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Input type="number" min="0" label={t('serviceContract.fieldServicesTotal')} value={form.services_total} error={errors.services_total?.[0]} onChange={set('services_total')} />
            <Input type="number" min="0" label={t('serviceContract.fieldServicesUsed')} value={form.services_used} error={errors.services_used?.[0]} onChange={set('services_used')} />
            <Input type="number" min="0" label={t('serviceContract.fieldInterval')} placeholder="10000" value={form.interval_km} error={errors.interval_km?.[0]} onChange={set('interval_km')} />
          </div>
          <p className="mt-2 text-xs text-slate-500">{t('serviceContract.countHint')}</p>
        </div>

        <div className="rounded-lg border border-slate-200 p-3">
          <p className="mb-2 text-sm font-medium text-slate-700">{t('serviceContract.limitSection')}</p>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Input type="date" label={t('serviceContract.endsOn')} value={form.ends_on} error={errors.ends_on?.[0]} onChange={set('ends_on')} />
            <Input type="number" min="0" label={t('serviceContract.endsAtKm')} placeholder="50000" value={form.ends_at_km} error={errors.ends_at_km?.[0]} onChange={set('ends_at_km')} />
          </div>
          <p className="mt-2 text-xs text-slate-500">{t('serviceContract.limitHint')}</p>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input type="number" min="0" label={t('serviceContract.lastServiceKm')} value={form.last_service_odometer} error={errors.last_service_odometer?.[0]} onChange={set('last_service_odometer')} />
          <Input type="date" label={t('serviceContract.lastServiceOn')} value={form.last_service_on} onChange={set('last_service_on')} />
        </div>

        <Input label={t('serviceContract.fieldPhone')} value={form.contact_phone} onChange={set('contact_phone')} />
        <Textarea label={t('serviceContract.fieldNotes')} rows={2} value={form.notes} onChange={set('notes')} />
      </div>
    </Modal>
  );
}
