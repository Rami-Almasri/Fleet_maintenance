// Test Intake — the refined Workflow Hub front door. A single tabbed modal that starts the right kind
// of test, each tab with its own labels + flow:
//
//   • Routine   — an Oil / Battery / Tyres check. Captures the odometer (+ photo) and starts a diagnostic
//                 (trigger_reason = periodic, test_kind = routine_check). The On-Site vs In-Shop choice —
//                 and, if In-Shop, the alert to Waleed & Abdullah — happens later at the Decide step.
//   • Scheduled — a park-time based check. Shows how long the car has been parked (auto-computed from its
//                 last movement) and offers the Breakdown checkbox. Unchecked → a normal diagnostic
//                 (test_kind = scheduled_dormancy). Checked → the car won't start, so it routes to the
//                 Breakdown path (grounds the car; recovery handles the tow) — no test drive / odometer.
//   • Accidents — accident documentation lives in the existing Damage & Accidents log; this tab links
//                 straight there so it's reachable from one place.
//
// Every string comes from the i18n catalog (workflow.testIntake.*) so it mirrors cleanly in Arabic/RTL.

import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Modal from '../ui/Modal';
import Button from '../ui/Button';
import Icon from '../ui/Icon';
import { Input, Textarea } from '../ui/Field';
import VehicleStatusSelect from './VehicleStatusSelect';
import { compressImage, formatBytes } from '../../lib/imageCompression';

const TABS = [
  { key: 'routine',   icon: '🛢️', accent: 'indigo' },
  { key: 'scheduled', icon: '🅿️', accent: 'violet' },
  { key: 'accidents', icon: '💥', accent: 'rose' },
];

export default function TestIntakeModal({ vehicles = [], onClose, onDone }) {
  const { t } = useI18n();
  const navigate = useNavigate();

  const [tab, setTab] = useState('routine');
  const [vehicleId, setVehicleId] = useState('');
  const [odometer, setOdometer] = useState('');
  const [photo, setPhoto] = useState(null);           // { blob, url, width, height, compressedSize }
  const [compressing, setCompressing] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  // Scheduled tab — the auto-computed park duration + the Breakdown branch.
  const [idle, setIdle] = useState(null);             // { days, idle_since, ... } | null
  const [idleLoading, setIdleLoading] = useState(false);
  const [isBreakdown, setIsBreakdown] = useState(false);
  const [breakdownNote, setBreakdownNote] = useState('');

  // Pull the car's idle duration the moment one is picked on the Scheduled tab.
  useEffect(() => {
    if (tab !== 'scheduled' || !vehicleId) { setIdle(null); return; }
    let alive = true;
    setIdleLoading(true);
    api.get(`/maintenance-tickets/vehicle/${Number(vehicleId)}/idle`)
      .then((r) => { if (alive) setIdle(r?.data?.data || null); })
      .catch(() => { if (alive) setIdle(null); })
      .finally(() => { if (alive) setIdleLoading(false); });
    return () => { alive = false; };
  }, [tab, vehicleId]);

  async function onPhoto(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    setCompressing(true);
    setError(null);
    try {
      setPhoto(await compressImage(file, { maxDimension: 1400, quality: 0.7 }));
    } catch {
      setError(t('workflow.photo.readError'));
    } finally {
      setCompressing(false);
    }
  }

  // A diagnostic-starting tab (Routine, or Scheduled without Breakdown) needs the odometer + its photo.
  const needsDiagnostic = tab === 'routine' || (tab === 'scheduled' && !isBreakdown);
  const disabled = saving || compressing || !vehicleId
    || (needsDiagnostic && (!odometer || Number(odometer) <= 0 || !photo))
    || (tab === 'scheduled' && isBreakdown && !breakdownNote.trim());

  async function submit() {
    if (disabled) return;
    setSaving(true);
    setError(null);
    try {
      if (tab === 'scheduled' && isBreakdown) {
        // The car won't start — route to the Breakdown path (grounds it; recovery tows it). No odometer.
        await api.post('/maintenance-tickets/breakdown', {
          vehicle_id: Number(vehicleId),
          fault_description: breakdownNote.trim(),
        });
        onDone?.(t('workflow.testIntake.breakdownSuccess'));
        return;
      }

      // Routine / Scheduled diagnostic — multipart (odometer reading + its photo).
      const fd = new FormData();
      fd.append('vehicle_id', String(Number(vehicleId)));
      fd.append('trigger_reason', 'periodic');
      fd.append('test_kind', tab === 'routine' ? 'routine_check' : 'scheduled_dormancy');
      fd.append('test_odometer', String(Number(odometer)));
      if (photo?.blob) fd.append('odometer_photo', photo.blob, 'odometer.jpg');
      await api.post('/maintenance-tickets', fd);
      onDone?.(t(tab === 'routine' ? 'workflow.testIntake.routineSuccess' : 'workflow.testIntake.scheduledSuccess'));
    } catch (e) {
      setError(e?.response?.data?.message || t('workflow.error.generic'));
    } finally {
      setSaving(false);
    }
  }

  // The odometer photo tile — mirrors the one the driver/inspector steps use.
  const photoTile = photo ? (
    <div className="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 p-2">
      <img src={photo.url} alt={t('workflow.field.odometerPhoto')} className="h-16 w-16 rounded-lg object-cover ring-1 ring-slate-200" />
      <div className="min-w-0 text-xs text-slate-500">
        <p className="font-semibold text-slate-700">{t('workflow.photo.ready')}</p>
        <p className="tabular-nums">{formatBytes(photo.compressedSize)} · {photo.width}×{photo.height}</p>
      </div>
      <button type="button" onClick={() => setPhoto(null)} className="ms-auto rounded-lg px-2 py-1 text-xs font-medium text-red-500 hover:bg-red-50">{t('common.remove')}</button>
    </div>
  ) : (
    <label className={`flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed px-4 py-4 text-sm transition ${compressing ? 'border-slate-200 text-slate-400' : 'border-slate-300 text-slate-500 hover:border-indigo-400 hover:text-indigo-600'}`}>
      <Icon.Gauge className="h-5 w-5" />
      {compressing ? t('workflow.photo.processing') : t('workflow.photo.scan')}
      <input type="file" accept="image/*" capture="environment" className="hidden" onChange={onPhoto} disabled={compressing} />
    </label>
  );

  const vehiclePicker = (
    <div>
      <span className="mb-1 block text-sm font-medium text-gray-700">
        {t('workflow.field.vehicle')}<span className="ms-0.5 text-red-500">*</span>
      </span>
      <VehicleStatusSelect value={vehicleId} onChange={setVehicleId} vehicles={vehicles} placeholder={t('workflow.ph.searchVehicle')} />
    </div>
  );

  const odometerFields = (
    <>
      <Input
        label={t('workflow.field.odometerKm')}
        type="number"
        min="1"
        required
        value={odometer}
        onChange={(e) => setOdometer(e.target.value)}
        placeholder={t('workflow.ph.odometerExample')}
      />
      <div>
        <span className="mb-1 block text-sm font-medium text-gray-700">{t('workflow.field.odometerPhoto')}<span className="ms-0.5 text-red-500">*</span></span>
        {photoTile}
      </div>
    </>
  );

  // Footer button changes per tab (Accidents redirects instead of submitting).
  const footer = tab === 'accidents' ? (
    <>
      <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
      <Button onClick={() => { onClose?.(); navigate('/damage-accidents'); }}>
        <Icon.ArrowRight className="h-4 w-4" /> {t('workflow.testIntake.openAccidentLog')}
      </Button>
    </>
  ) : (
    <>
      <Button variant="ghost" onClick={onClose} disabled={saving}>{t('common.cancel')}</Button>
      <Button
        variant={tab === 'scheduled' && isBreakdown ? 'danger' : 'primary'}
        onClick={submit}
        disabled={disabled}
        loading={saving}
      >
        {tab === 'scheduled' && isBreakdown
          ? <><span aria-hidden>⚠️</span> {t('workflow.testIntake.reportBreakdown')}</>
          : t('workflow.testIntake.startTest')}
      </Button>
    </>
  );

  return (
    <Modal
      open
      onClose={onClose}
      size="md"
      title={t('workflow.testIntake.title')}
      subtitle={t('workflow.testIntake.subtitle')}
      footer={footer}
    >
      {/* Tabs */}
      <div className="mb-4 flex gap-1 rounded-xl bg-slate-100 p-1">
        {TABS.map((tb) => {
          const active = tab === tb.key;
          return (
            <button
              key={tb.key}
              type="button"
              onClick={() => { setTab(tb.key); setError(null); }}
              className={`flex flex-1 items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold transition ${active ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-700'}`}
            >
              <span aria-hidden>{tb.icon}</span>
              {t(`workflow.testIntake.tab.${tb.key}`)}
            </button>
          );
        })}
      </div>

      <div className="space-y-4">
        {/* ── ROUTINE ─────────────────────────────────────────────────────── */}
        {tab === 'routine' && (
          <>
            <p className="flex items-start gap-1.5 rounded-lg bg-indigo-50 px-3 py-2 text-xs text-indigo-700 ring-1 ring-inset ring-indigo-100">
              <Icon.Info className="mt-0.5 h-3.5 w-3.5 shrink-0 text-indigo-500" />
              <span>{t('workflow.testIntake.routineHint')}</span>
            </p>
            {vehiclePicker}
            {odometerFields}
          </>
        )}

        {/* ── SCHEDULED (park-time) ───────────────────────────────────────── */}
        {tab === 'scheduled' && (
          <>
            <p className="flex items-start gap-1.5 rounded-lg bg-violet-50 px-3 py-2 text-xs text-violet-700 ring-1 ring-inset ring-violet-100">
              <Icon.Info className="mt-0.5 h-3.5 w-3.5 shrink-0 text-violet-500" />
              <span>{t('workflow.testIntake.scheduledHint')}</span>
            </p>
            {vehiclePicker}

            {/* Auto-computed park duration for the chosen car. */}
            {vehicleId && (
              <div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
                {idleLoading ? (
                  <p className="text-sm text-slate-400">{t('common.loading')}</p>
                ) : idle?.days != null ? (
                  <div className="flex items-center gap-2">
                    <Icon.Clock className="h-4 w-4 text-violet-500" />
                    <p className="text-sm text-slate-700">
                      <span className="font-display text-lg font-bold tabular-nums text-slate-900">{idle.days}</span>
                      {' '}{t('workflow.testIntake.daysParked')}
                      {idle.idle_since && <span className="text-slate-400"> · {t('workflow.testIntake.since')} {idle.idle_since}</span>}
                    </p>
                  </div>
                ) : (
                  <p className="text-sm text-slate-400">{t('workflow.testIntake.parkUnknown')}</p>
                )}
              </div>
            )}

            {/* Breakdown checkbox — the car won't start → the Recovery path. */}
            <label className={`flex cursor-pointer items-start gap-2.5 rounded-xl border px-3 py-2.5 transition ${isBreakdown ? 'border-red-300 bg-red-50' : 'border-slate-200 hover:border-slate-300'}`}>
              <input
                type="checkbox"
                checked={isBreakdown}
                onChange={(e) => setIsBreakdown(e.target.checked)}
                className="mt-0.5 h-4 w-4 rounded border-slate-300 text-red-600 focus:ring-red-500"
              />
              <span className="min-w-0">
                <span className="block text-sm font-semibold text-slate-800">{t('workflow.testIntake.breakdownLabel')}</span>
                <span className="block text-xs text-slate-500">{t('workflow.testIntake.breakdownCaption')}</span>
              </span>
            </label>

            {isBreakdown ? (
              <>
                <Textarea
                  label={t('workflow.breakdown.faultLabel')}
                  required
                  rows={3}
                  value={breakdownNote}
                  onChange={(e) => setBreakdownNote(e.target.value)}
                  placeholder={t('workflow.breakdown.faultPlaceholder')}
                  maxLength={2000}
                />
                <p className="flex items-start gap-1.5 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 ring-1 ring-inset ring-red-100">
                  <Icon.Info className="mt-0.5 h-3.5 w-3.5 shrink-0 text-red-500" />
                  <span>{t('workflow.testIntake.breakdownConsequence')}</span>
                </p>
              </>
            ) : (
              odometerFields
            )}
          </>
        )}

        {/* ── ACCIDENTS ───────────────────────────────────────────────────── */}
        {tab === 'accidents' && (
          <div className="rounded-xl border border-rose-100 bg-rose-50/60 px-4 py-5 text-center">
            <div className="mx-auto mb-2 flex h-11 w-11 items-center justify-center rounded-full bg-rose-100 text-xl" aria-hidden>💥</div>
            <p className="text-sm font-semibold text-slate-800">{t('workflow.testIntake.accidentsTitle')}</p>
            <p className="mx-auto mt-1 max-w-sm text-xs text-slate-500">{t('workflow.testIntake.accidentsBody')}</p>
          </div>
        )}

        {error && <p className="text-sm text-red-600">{error}</p>}
      </div>
    </Modal>
  );
}
