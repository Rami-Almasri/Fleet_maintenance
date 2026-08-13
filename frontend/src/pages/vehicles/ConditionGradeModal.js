import { useEffect, useState } from 'react';
import api from '../../api/client';
import Modal from '../../components/ui/Modal';
import Button from '../../components/ui/Button';
import { useToast } from '../../components/ui/Toast';
import { useI18n } from '../../i18n/I18nContext';

// Visual Condition Grading (Abu Marouf) — grade a car's cosmetic/physical condition,
// independent of the OM lifecycle status and the live movement. The grade drives the
// rental pool (Red is hidden) and the handover prompt (Orange warns the operator).
const GRADES = [
  {
    key: 'green', title: 'Green — Perfect', emoji: '✅',
    desc: 'Fully available, no issues.',
    ring: 'ring-emerald-300 bg-emerald-50', text: 'text-emerald-800', chip: 'bg-emerald-500',
  },
  {
    key: 'orange', title: 'Orange — Cosmetic / Serviceable', emoji: '⚠️',
    desc: 'Fully available for rent, but has minor scratches / aesthetic issues. Ops will be prompted to have the customer acknowledge them before handover.',
    ring: 'ring-orange-300 bg-orange-50', text: 'text-orange-800', chip: 'bg-orange-500',
  },
  {
    key: 'yellow', title: 'Yellow — Maintenance needed', emoji: '🔧',
    desc: 'Showing symptoms and needs scheduled maintenance. Blocked from renting and hidden from Available — route it to the garage.',
    ring: 'ring-yellow-300 bg-yellow-50', text: 'text-yellow-800', chip: 'bg-yellow-500',
  },
  {
    key: 'red', title: 'Red — Critical / Grounded', emoji: '⛔',
    desc: 'Unsafe or a major fault. Immediately blocked from the rental interface and hidden from the Available counts.',
    ring: 'ring-red-300 bg-red-50', text: 'text-red-800', chip: 'bg-red-500',
  },
];

export default function ConditionGradeModal({ open, vehicle, onClose, onSaved }) {
  const { t } = useI18n();
  const toast = useToast();
  const [grade, setGrade] = useState('green');
  const [note, setNote] = useState('');
  const [saving, setSaving] = useState(false);

  // Seed the picker with the car's current grade every time it opens.
  useEffect(() => {
    if (open && vehicle) {
      setGrade(vehicle.condition_grade || 'green');
      setNote(vehicle.condition_note || '');
    }
  }, [open, vehicle]);

  if (!vehicle) return null;

  const save = async () => {
    setSaving(true);
    try {
      await api.post(`/Vehicle/${vehicle.id}/condition`, {
        condition_grade: grade,
        // Green carries no cosmetic note; the backend also clears it.
        condition_note: grade === 'green' ? null : (note.trim() || null),
      });
      toast.success(t('Condition grade updated'));
      onSaved?.();
      onClose();
    } catch (err) {
      toast.error(err.response?.data?.message || t('Could not update the condition grade'));
    } finally {
      setSaving(false);
    }
  };

  const carLabel = [vehicle.make, vehicle.model].filter(Boolean).join(' ') || vehicle.vin;

  return (
    <Modal
      open={open}
      onClose={() => !saving && onClose()}
      title={t('Condition grade')}
      subtitle={`${vehicle.plate_no || vehicle.vin} · ${carLabel}`}
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>{t('Cancel')}</Button>
          <Button onClick={save} loading={saving}>{t('Save grade')}</Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="space-y-2">
          {GRADES.map((g) => {
            const on = grade === g.key;
            return (
              <button
                key={g.key}
                type="button"
                onClick={() => setGrade(g.key)}
                className={`flex w-full items-start gap-3 rounded-xl border px-4 py-3 text-start transition ${
                  on ? `border-transparent ring-2 ${g.ring}` : 'border-slate-200 bg-white hover:bg-slate-50'
                }`}
              >
                <span className={`mt-0.5 h-3 w-3 shrink-0 rounded-full ${g.chip}`} />
                <span className="min-w-0">
                  <span className={`block text-sm font-semibold ${on ? g.text : 'text-slate-800'}`}>
                    {g.emoji} {t(g.title)}
                  </span>
                  <span className="mt-0.5 block text-xs text-slate-500">{t(g.desc)}</span>
                </span>
              </button>
            );
          })}
        </div>

        {grade !== 'green' && (
          <label className="block">
            <span className="mb-1 block text-sm font-medium text-slate-700">
              {t('Condition note')} {grade === 'orange' && <span className="text-slate-400">{t('(shown to ops at handover)')}</span>}
            </span>
            <textarea
              rows={3}
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder={
                grade === 'orange'
                  ? t('e.g. Scratch on rear bumper, small dent on driver door…')
                  : grade === 'yellow'
                    ? t('e.g. Brake noise, service light on, pulling slightly right…')
                    : t('e.g. Engine warning + overheating, unsafe to drive…')
              }
              className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </label>
        )}
      </div>
    </Modal>
  );
}
