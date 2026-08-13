import { useCallback, useEffect, useMemo, useState } from 'react';
import api from '../api/client';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from './ui/Toast';
import Button from './ui/Button';
import Badge from './ui/Badge';
import Modal from './ui/Modal';
import { Card, Spinner } from './ui/Misc';
import { Input, Select, Textarea } from './ui/Field';
import SearchSelect from './ui/SearchSelect';
import { aed2, fmtDate } from '../lib/format';
import { useI18n } from '../i18n/I18nContext';

// Workshop stages (event_status). 'IN' = the car came back; everything else is in-progress.
// The value is the API enum; the same word is used as the translation key for display.
const STAGES = ['OUT', 'Follow up', 'Change', 'Delay', 'Test', 'Under Test', 'IN'];
const STAGE_TONE = {
  OUT: 'blue', IN: 'green', 'Follow up': 'amber', Change: 'violet',
  Delay: 'red', Test: 'cyan', 'Under Test': 'cyan',
};

// Keyword-driven priority (reason -> level).
const LEVEL = {
  critical: { label: 'Critical', tone: 'red', emoji: '🔴', rank: 0 },
  special: { label: 'Special', tone: 'violet', emoji: '🟣', rank: 1 },
  minor: { label: 'Minor', tone: 'amber', emoji: '🟡', rank: 2 },
  routine: { label: 'Routine', tone: 'green', emoji: '🟢', rank: 3 },
};

const EMPTY = {
  event_status: 'OUT', vendor_id: '', out_date: '', expected_return_date: '',
  follow_date: '', actual_in_date: '', cost: '', responsible: '', maintenance_type: '', severity: '',
  visit_context: '', maintenance_notes: '',
};

// "Rental-First" tag. Only 'routine' is kept off the foresight Act-now / Chronic lists.
const VISIT_CONTEXTS = [
  { value: '', label: 'Standard repair' },
  { value: 'routine', label: 'Routine service (oil, filters, periodic)' },
  { value: 'accident_rental', label: 'Accident repair (during a live rental)' },
];

/**
 * The garage log for one vehicle, owned by the dashboard. Lists every workshop event
 * (hand-entered + synced from the sheet) newest-first, and — for users who can manage
 * maintenance — lets them add / edit / delete the hand-entered ones. The chosen issue
 * keyword drives each event's priority; picking the "IN" stage records the return.
 */
export default function WorkshopEvents({ vehicleId, contractId, defaultDate, expectedReturn }) {
  const { t } = useI18n();
  const { can } = usePermissions();
  const toast = useToast();
  const canManage = can('maintenance.manage');

  const [events, setEvents] = useState([]);
  const [reasons, setReasons] = useState([]);
  const [vendors, setVendors] = useState([]);
  const [loading, setLoading] = useState(true);

  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState(null); // event being edited, or null for "new"
  const [form, setForm] = useState(EMPTY);
  const [tags, setTags] = useState([]);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    if (!vehicleId) return;
    setLoading(true);
    try {
      // Scope to this contract's visit when given (the problem & fix for one visit);
      // otherwise the car's whole garage log.
      const params = contractId ? { contract_id: contractId } : { vehicle_id: vehicleId };
      const [eRes, rRes, vRes] = await Promise.all([
        api.get('/Maintenance/events', { params }),
        api.get('/Maintenance/reasons').catch(() => ({ data: { data: [] } })),
        api.get('/Vendor').catch(() => ({ data: { data: [] } })),
      ]);
      setEvents(eRes.data.data || []);
      setReasons(rRes.data.data || []);
      setVendors(vRes.data.data || []);
    } catch (e) {
      toast.error(t('Failed to load workshop events'));
    } finally {
      setLoading(false);
    }
  }, [vehicleId, contractId, toast, t]);

  useEffect(() => { load(); }, [load]);

  const vendorOptions = useMemo(
    () => vendors.map((v) => ({ id: v.id, label: v.name || `#${v.id}`, sub: v.type || v.phone || '' })),
    [vendors],
  );
  const levelOf = (text) => reasons.find((r) => r.reason?.toLowerCase() === String(text).toLowerCase())?.level || null;
  const predicted = tags.map(levelOf).filter(Boolean).sort((a, b) => LEVEL[a].rank - LEVEL[b].rank)[0] || null;

  const set = (field) => (e) => setForm((f) => ({ ...f, [field]: e.target.value }));
  const setVal = (field, value) => setForm((f) => ({ ...f, [field]: value }));
  const err = (f) => (errors[f] ? errors[f][0] : '');

  const openNew = () => {
    setEditing(null);
    setForm({ ...EMPTY, out_date: defaultDate ? String(defaultDate).slice(0, 10) : '', expected_return_date: expectedReturn ? String(expectedReturn).slice(0, 10) : '' });
    setTags([]);
    setErrors({});
    setOpen(true);
  };

  const openEdit = (ev) => {
    setEditing(ev);
    setForm({
      event_status: ev.stage || 'OUT',
      vendor_id: ev.vendor_id || '',
      out_date: ev.out_date || '',
      expected_return_date: ev.expected_return_date || '',
      follow_date: ev.follow_date || '',
      actual_in_date: ev.actual_in_date || '',
      cost: ev.cost ?? '',
      responsible: ev.responsible || '',
      maintenance_type: ev.maintenance_type || '',
      severity: ev.severity || '',
      visit_context: ev.visit_context || '',
      maintenance_notes: ev.notes || '',
    });
    setTags(Array.isArray(ev.issues) ? ev.issues : []);
    setErrors({});
    setOpen(true);
  };

  const addTag = (text) => {
    const v = (text || '').trim();
    if (!v) return;
    setTags((x) => (x.some((y) => y.toLowerCase() === v.toLowerCase()) ? x : [...x, v]));
  };
  const removeTag = (text) => setTags((x) => x.filter((y) => y !== text));

  const submit = async () => {
    setSaving(true);
    setErrors({});
    try {
      const payload = {
        vehicle_id: vehicleId,
        event_status: form.event_status,
        issues: tags,
        vendor_id: form.vendor_id || null,
        out_date: form.out_date || null,
        expected_return_date: form.expected_return_date || null,
        follow_date: form.follow_date || null,
        actual_in_date: form.actual_in_date || null,
        cost: form.cost === '' ? null : Number(form.cost),
        responsible: form.responsible || null,
        maintenance_type: form.maintenance_type || null,
        severity: form.severity || null,
        visit_context: form.visit_context || null,
        maintenance_notes: form.maintenance_notes || null,
      };
      if (editing) {
        await api.post(`/Maintenance/events/${editing.id}`, payload);
        toast.success(t('Workshop event updated'));
      } else {
        await api.post('/Maintenance/events', payload);
        toast.success(t('Workshop event added'));
      }
      setOpen(false);
      load();
    } catch (e) {
      const r = e.response?.data;
      if (r?.errors) { setErrors(r.errors); toast.error(t('Please fix the highlighted fields')); }
      else toast.error(r?.message || r?.msg || t('Could not save the event'));
    } finally {
      setSaving(false);
    }
  };

  const remove = async (ev) => {
    // Hand-entered events are truly deleted; sheet-synced ones are tombstoned (hidden +
    // skipped on future syncs) and can be restored, so the warning differs.
    const msg = ev.editable
      ? t('Delete this workshop event? This cannot be undone.')
      : t('Remove this synced sheet event? It will be hidden and kept out of future syncs — you can restore it later.');
    if (!window.confirm(msg)) return;
    try {
      await api.delete(`/Maintenance/events/${ev.id}`);
      toast.success(ev.editable ? t('Workshop event deleted') : t('Event removed — restore it anytime'));
      load();
    } catch (e) {
      toast.error(e.response?.data?.message || t('Could not remove the event'));
    }
  };

  const restore = async (ev) => {
    try {
      await api.post(`/Maintenance/events/tombstones/${ev.tombstone_id}/restore`);
      toast.success(t('Event restored'));
      load();
    } catch (e) {
      toast.error(e.response?.data?.message || t('Could not restore the event'));
    }
  };

  if (loading) return <Card className="flex justify-center p-8"><Spinner className="h-6 w-6" /></Card>;

  return (
    <Card className="p-6">
      <div className="mb-4 flex items-center justify-between">
        <div>
          <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-400">{t('Workshop Events')}</h3>
          <p className="mt-0.5 text-xs text-slate-400">
            {contractId
              ? t('What happened on this visit — the problem and the fix, newest first.')
              : t('The garage log for this car — newest first. The issue keyword sets the priority.')}
          </p>
        </div>
        {canManage && <Button variant="secondary" onClick={openNew}>{t('+ Add event')}</Button>}
      </div>

      {events.length === 0 ? (
        <p className="py-6 text-center text-sm text-slate-400">
          {t('No workshop events yet.')}{canManage ? ` ${t('Click “+ Add event” to log the first one.')}` : ''}
        </p>
      ) : (
        <ol className="relative space-y-3 border-s-2 border-slate-100 ps-5">
          {events.map((ev) => {
            const lvl = LEVEL[ev.priority];
            return (
              <li key={ev.tombstoned ? `t${ev.tombstone_id}` : ev.id} className="relative">
                <span className={`absolute -start-[27px] top-1.5 h-3 w-3 rounded-full ring-4 ring-white ${ev.tombstoned ? 'bg-slate-300' : (ev.stage === 'IN' ? 'bg-emerald-500' : 'bg-indigo-400')}`} />
                <div className={`rounded-xl border p-4 shadow-soft ${ev.tombstoned ? 'border-dashed border-slate-200 bg-slate-50/70' : 'border-slate-100 bg-white'}`}>
                  <div className={`flex flex-wrap items-center gap-2 ${ev.tombstoned ? 'opacity-60' : ''}`}>
                    <Badge tone={STAGE_TONE[ev.stage] || 'slate'}>{ev.stage ? t(ev.stage) : '—'}</Badge>
                    {lvl && <Badge tone={lvl.tone}>{lvl.emoji} {t(lvl.label)}</Badge>}
                    {ev.visit_context === 'routine' && <Badge tone="green" title={t('Planned upkeep — excluded from foresight Act-now/Chronic')}>{t('Routine')}</Badge>}
                    {ev.visit_context === 'accident_rental' && <Badge tone="amber" title={t('Accident repair logged during a live rental')}>{t('Accident · rental')}</Badge>}
                    {/* No SLA / "Overdue" badge here: this is a historical garage log (a car
                        often goes back for another visit). Live overdue lives on the board. */}
                    {ev.tombstoned
                      ? <Badge tone="red" title={t('Removed — hidden from the board and kept out of future syncs')}>🗑 {t('Removed')}</Badge>
                      : !ev.editable && <Badge tone="gray" title={t('Synced from the Google Sheet — read-only here')}>📄 {t('Sheet')}</Badge>}
                    <span className="ms-auto text-xs text-slate-400">{ev.out_date ? fmtDate(ev.out_date) : '—'}</span>
                  </div>

                  <div className={ev.tombstoned ? 'opacity-60' : ''}>
                    {ev.issues?.length > 0 && (
                      <div className="mt-2 flex flex-wrap gap-1.5">
                        {ev.issues.map((iss) => (
                          <span key={iss} className="rounded-full bg-slate-50 px-2.5 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-200">{iss}</span>
                        ))}
                      </div>
                    )}

                    <div className="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
                      {ev.garage && <Info label={t('Garage')} value={ev.garage} />}
                      {ev.expected_return_date && <Info label={t('Expected')} value={fmtDate(ev.expected_return_date)} />}
                      {ev.actual_in_date && <Info label={t('Returned')} value={fmtDate(ev.actual_in_date)} />}
                      {ev.cost != null && <Info label={t('Cost')} value={aed2(ev.cost)} />}
                      {ev.responsible && <Info label={t('Responsible')} value={ev.responsible} />}
                      {ev.maintenance_type && <Info label={t('Type')} value={ev.maintenance_type} />}
                    </div>

                    {ev.notes && <p className="mt-2 whitespace-pre-line text-sm text-slate-600">{ev.notes}</p>}
                  </div>

                  {canManage && (ev.tombstoned ? (
                    <div className="mt-3 flex items-center gap-2">
                      <button onClick={() => restore(ev)} className="text-xs font-medium text-emerald-600 hover:text-emerald-700">↺ {t('Restore')}</button>
                      <span className="text-xs text-slate-400">{t('— removed from the board & future syncs')}</span>
                    </div>
                  ) : (
                    <div className="mt-3 flex gap-2">
                      {ev.editable && (
                        <>
                          <button onClick={() => openEdit(ev)} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">{t('Edit')}</button>
                          <span className="text-slate-200">·</span>
                        </>
                      )}
                      <button onClick={() => remove(ev)} className="text-xs font-medium text-red-500 hover:text-red-600">
                        {ev.editable ? t('Delete') : t('Remove')}
                      </button>
                    </div>
                  ))}
                </div>
              </li>
            );
          })}
        </ol>
      )}

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title={editing ? t('Edit workshop event') : t('Add workshop event')}
        subtitle={t('The issue keyword sets the priority · choosing “{stage}” records the return', { stage: t('IN') })}
        size="lg"
        footer={(
          <>
            <Button variant="secondary" onClick={() => setOpen(false)} disabled={saving}>{t('Cancel')}</Button>
            <Button onClick={submit} loading={saving}>{editing ? t('Save changes') : t('Add event')}</Button>
          </>
        )}
      >
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Select label={t('Stage')} value={form.event_status} onChange={set('event_status')} error={err('event_status')}>
              {STAGES.map((s) => <option key={s} value={s}>{t(s)}</option>)}
            </Select>
            <label className="block">
              <span className="mb-1 block text-sm font-medium text-slate-700">{t('Garage / Vendor')}</span>
              <SearchSelect value={form.vendor_id} onChange={(v) => setVal('vendor_id', v)} options={vendorOptions} placeholder={t('Search garage / vendor…')} />
              {err('vendor_id') && <span className="mt-1 block text-xs text-red-600">{err('vendor_id')}</span>}
            </label>
          </div>

          {/* Issue keywords — each sets the priority via the reason vocabulary */}
          <div>
            <div className="mb-1.5 flex items-center justify-between">
              <span className="text-sm font-medium text-slate-700">{t('Issue keywords')}</span>
              {predicted && (
                <Badge tone={LEVEL[predicted].tone}>
                  {LEVEL[predicted].emoji} {t('Priority: {level}', { level: t(LEVEL[predicted].label) })}
                </Badge>
              )}
            </div>
            <div className="flex flex-wrap gap-2">
              {tags.map((tag) => {
                const lvl = levelOf(tag);
                return (
                  <span key={tag} className="inline-flex items-center gap-1 rounded-full bg-slate-50 px-3 py-1 text-sm font-medium text-slate-700 ring-1 ring-inset ring-slate-200">
                    {lvl && <span aria-hidden>{LEVEL[lvl].emoji}</span>}
                    {tag}
                    <button type="button" onClick={() => removeTag(tag)} aria-label={t('Remove keyword')} className="opacity-50 hover:opacity-100">×</button>
                  </span>
                );
              })}
              {tags.length === 0 && <span className="text-sm text-slate-400">{t('Pick one or more below — the most severe sets the priority.')}</span>}
            </div>
            {reasons.length > 0 && (
              <div className="mt-2 flex flex-wrap gap-1.5">
                {reasons
                  .filter((r) => !tags.some((x) => x.toLowerCase() === r.reason.toLowerCase()))
                  .map((r) => (
                    <button
                      type="button"
                      key={r.reason}
                      onClick={() => addTag(r.reason)}
                      title={LEVEL[r.level] ? t(LEVEL[r.level].label) : ''}
                      className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-200"
                    >
                      + {r.reason}
                    </button>
                  ))}
              </div>
            )}
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Input label={t('Out date')} type="date" value={form.out_date} onChange={set('out_date')} error={err('out_date')} />
            <Input label={t('Expected return')} type="date" value={form.expected_return_date} onChange={set('expected_return_date')} error={err('expected_return_date')} />
            <Input label={t('Follow-up date')} type="date" value={form.follow_date} onChange={set('follow_date')} error={err('follow_date')} />
          </div>

          {/* Closing the event ('IN') is guarded: cost, vendor and the returned date are mandatory
              so profit can be tracked and a garage can be held accountable. */}
          {form.event_status === 'IN' && (
            <div className="rounded-xl border border-emerald-200 bg-emerald-50/60 p-4">
              <p className="mb-3 text-xs font-medium text-emerald-700">
                {t('Closing this event — Cost, Garage/Vendor and the Returned date are required to keep profit tracking accurate.')}
              </p>
              <Input
                label={t('Returned date (Actual In) *')}
                type="date"
                value={form.actual_in_date}
                onChange={set('actual_in_date')}
                error={err('actual_in_date')}
              />
            </div>
          )}

          <div>
            <Select label={t('Visit context')} value={form.visit_context} onChange={set('visit_context')} error={err('visit_context')}>
              {VISIT_CONTEXTS.map((c) => <option key={c.value} value={c.value}>{t(c.label)}</option>)}
            </Select>
            {form.visit_context === 'routine' && (
              <p className="mt-1 text-xs text-emerald-600">{t('Planned upkeep — kept off the “Act now”/“Chronic” foresight lists.')}</p>
            )}
            {form.visit_context === 'accident_rental' && (
              <p className="mt-1 text-xs text-amber-600">{t('Logged on the car; the rental keeps running (billing isn’t interrupted).')}</p>
            )}
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Input label={t('Cost (AED)')} type="number" step="0.01" value={form.cost} onChange={set('cost')} error={err('cost')} />
            <Input label={t('Responsible')} value={form.responsible} onChange={set('responsible')} error={err('responsible')} />
            <Input label={t('Type')} value={form.maintenance_type} onChange={set('maintenance_type')} placeholder={t('e.g. Breakdown')} error={err('maintenance_type')} />
          </div>

          <Textarea label={t('Notes')} rows={3} value={form.maintenance_notes} onChange={set('maintenance_notes')} error={err('maintenance_notes')} />
        </div>
      </Modal>
    </Card>
  );
}

function Info({ label, value }) {
  return (
    <div>
      <p className="text-xs text-slate-400">{label}</p>
      <p className="font-medium text-slate-800">{value}</p>
    </div>
  );
}
