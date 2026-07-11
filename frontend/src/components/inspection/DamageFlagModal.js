import { useEffect, useState } from 'react';
import { DAMAGE_TYPES, SEVERITIES, damageStatus, damageStatusMeta } from '../../lib/inspections';

// Rich manual damage flag (Option A). Captures the three things the Fleet Health
// reports need — what kind of damage, how severe, and a free-text note — for one
// body zone. Opens as a centred modal over the inspection view.
//
// Controlled by the parent: `initial` pre-fills when editing an existing flag;
// `onSave({ type, severity, note })` persists it; `onRemove` clears the flag.
export default function DamageFlagModal({ open, zoneLabel, initial, onSave, onRemove, onClose }) {
  const [type, setType] = useState(initial?.type || null);
  const [severity, setSeverity] = useState(initial?.severity || null);
  const [note, setNote] = useState(initial?.note || '');
  // Dispute-killer dimension: was this here at delivery (existing) or found at
  // return (new)? An optional invoice link promotes it to "Charged".
  const [origin, setOrigin] = useState(initial?.origin || 'new');
  const [invoiceId, setInvoiceId] = useState(initial?.invoiceId || '');

  // Re-sync local state whenever the modal (re)opens for a different zone/flag.
  useEffect(() => {
    if (open) {
      setType(initial?.type || null);
      setSeverity(initial?.severity || null);
      setNote(initial?.note || '');
      setOrigin(initial?.origin || 'new');
      setInvoiceId(initial?.invoiceId || '');
    }
  }, [open, initial]);

  // Close on Escape for keyboard users.
  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => e.key === 'Escape' && onClose?.();
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) return null;

  const canSave = type && severity;
  const editing = !!initial;
  const trimmedInvoice = invoiceId.trim();
  // Live preview of the resulting status, so the inspector knows what they're setting.
  const liveStatus = damageStatus({ origin, invoiceId: trimmedInvoice || null });
  const statusMeta = damageStatusMeta(liveStatus);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      {/* backdrop */}
      <div className="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" onClick={onClose} />

      <div className="relative w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200">
        {/* header */}
        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
          <div className="flex items-center gap-2.5">
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-100 text-rose-600">
              <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" />
              </svg>
            </span>
            <div>
              <h2 className="text-sm font-bold text-slate-900">Flag damage</h2>
              <p className="text-xs text-slate-500">{zoneLabel}</p>
            </div>
          </div>
          <button onClick={onClose} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2"><path strokeLinecap="round" d="M6 6l12 12M18 6 6 18" /></svg>
          </button>
        </div>

        <div className="space-y-5 px-5 py-5">
          {/* Damage type */}
          <div>
            <label className="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Damage type</label>
            <div className="grid grid-cols-2 gap-2">
              {DAMAGE_TYPES.map((t) => (
                <button
                  key={t.id}
                  onClick={() => setType(t.id)}
                  className={`rounded-xl border px-3 py-2.5 text-sm font-medium transition ${
                    type === t.id
                      ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200'
                      : 'border-slate-200 text-slate-600 hover:border-slate-300'
                  }`}
                >
                  {t.label}
                </button>
              ))}
            </div>
          </div>

          {/* Severity */}
          <div>
            <label className="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Severity</label>
            <div className="grid grid-cols-3 gap-2">
              {SEVERITIES.map((s) => (
                <button
                  key={s.id}
                  onClick={() => setSeverity(s.id)}
                  className={`rounded-xl border px-2 py-2.5 text-center transition ${
                    severity === s.id ? s.toneActive : s.tone + ' hover:brightness-95'
                  }`}
                >
                  <span className="block text-sm font-semibold">{s.label}</span>
                  <span className="block text-[10px] opacity-80">{s.sub}</span>
                </button>
              ))}
            </div>
          </div>

          {/* Note */}
          <div>
            <label className="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">
              Quick note <span className="font-normal normal-case text-slate-400">(optional)</span>
            </label>
            <textarea
              value={note}
              onChange={(e) => setNote(e.target.value)}
              rows={3}
              maxLength={1000}
              placeholder="e.g. 8 cm scratch above the handle, paint not broken"
              className="w-full resize-none rounded-xl border border-slate-200 px-3 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
            />
          </div>

          {/* Status — the dispute-killer dimension */}
          <div>
            <label className="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Status</label>
            <div className="grid grid-cols-2 gap-2">
              <button
                onClick={() => setOrigin('existing')}
                className={`rounded-xl border px-3 py-2.5 text-left text-sm font-medium transition ${
                  origin === 'existing'
                    ? 'border-slate-500 bg-slate-100 text-slate-800 ring-1 ring-slate-300'
                    : 'border-slate-200 text-slate-600 hover:border-slate-300'
                }`}
              >
                Existing
                <span className="block text-[10px] font-normal text-slate-400">Pre-existing at delivery</span>
              </button>
              <button
                onClick={() => setOrigin('new')}
                className={`rounded-xl border px-3 py-2.5 text-left text-sm font-medium transition ${
                  origin === 'new'
                    ? 'border-amber-500 bg-amber-50 text-amber-700 ring-1 ring-amber-200'
                    : 'border-slate-200 text-slate-600 hover:border-slate-300'
                }`}
              >
                New
                <span className="block text-[10px] font-normal text-slate-400">Found at return · needs assessment</span>
              </button>
            </div>

            {/* Invoice link → promotes the flag to "Charged" */}
            <div className="mt-2.5">
              <label className="mb-1 block text-[11px] font-medium text-slate-500">
                Link invoice <span className="font-normal text-slate-400">(optional · marks it Charged)</span>
              </label>
              <div className="flex items-center gap-2">
                <input
                  value={invoiceId}
                  onChange={(e) => setInvoiceId(e.target.value)}
                  placeholder="e.g. INV-2026-0142"
                  className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
                />
                <span className={`shrink-0 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide ${statusMeta.toneActive}`}>
                  {statusMeta.label}
                </span>
              </div>
            </div>
          </div>
        </div>

        {/* footer */}
        <div className="flex items-center justify-between gap-2 border-t border-slate-100 bg-slate-50 px-5 py-3">
          {editing ? (
            <button
              onClick={onRemove}
              className="rounded-lg px-3 py-2 text-sm font-semibold text-rose-600 transition hover:bg-rose-50"
            >
              Remove flag
            </button>
          ) : (
            <span />
          )}
          <div className="flex items-center gap-2">
            <button onClick={onClose} className="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100">
              Cancel
            </button>
            <button
              onClick={() => canSave && onSave({ type, severity, note: note.trim() || null, origin, invoiceId: trimmedInvoice || null })}
              disabled={!canSave}
              className="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-rose-700 disabled:opacity-40"
            >
              {editing ? 'Update flag' : 'Save flag'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
