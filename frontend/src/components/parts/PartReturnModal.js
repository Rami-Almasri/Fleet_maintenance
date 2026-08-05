// Send a part back — recorded as an event beside the purchase, never by deleting it.
//
// The buy stays. This form logs WHY it went back, HOW MANY, and how much money comes back, and the ticket
// then shows the credit next to the charge it reverses (+400 / −400 / net 0) instead of quietly showing a
// smaller number. That is what keeps "which supplier keeps sending the wrong part" answerable later.
//
// The money question is deliberately explicit, because timing matters: logging a return is not the same
// event as being refunded for it. Until the refund lands, the ticket keeps the full cost — so the form
// asks which of the two just happened rather than assuming.

import { useEffect, useMemo, useState } from 'react';
import api from '../../api/client';
import { useToast } from '../ui/Toast';
import Modal from '../ui/Modal';
import Button from '../ui/Button';
import { Input, Select, Textarea } from '../ui/Field';
import { aed } from '../../lib/format';

const REASONS = [
  { value: 'wrong_part', label: 'Wrong part supplied' },
  { value: 'faulty_part', label: 'Faulty / defective' },
  { value: 'wrong_fitment', label: "Didn't fit this vehicle" },
  { value: 'not_needed', label: 'No longer needed' },
  { value: 'over_ordered', label: 'Over-ordered' },
  { value: 'other', label: 'Other' },
];

export default function PartReturnModal({ open, purchase, onClose, onDone }) {
  const toast = useToast();
  const [form, setForm] = useState({});
  const [saving, setSaving] = useState(false);

  const purchased = Number(purchase?.quantity || 1);
  const unit = Number(purchase?.purchase_price || 0);

  useEffect(() => {
    if (!open) return;
    setForm({
      quantity: String(purchased),
      reason_code: 'wrong_part',
      reason_note: '',
      restocking_fee: '',
      status: 'refunded',   // the counter usually refunds on the spot
      refund_amount: '',
    });
  }, [open, purchased]);

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e?.target ? e.target.value : e }));

  // What we expect back if nothing else is said: the value of what's going back, less any fee kept.
  const expected = useMemo(() => {
    const qty = Number(form.quantity || 0);
    const fee = Number(form.restocking_fee || 0);
    return Math.max(0, Math.round((unit * qty - fee) * 100) / 100);
  }, [form.quantity, form.restocking_fee, unit]);

  const refund = form.refund_amount === '' ? expected : Number(form.refund_amount || 0);
  const kept = Math.max(0, Math.round((unit * Number(form.quantity || 0) - refund) * 100) / 100);

  const submit = async () => {
    if (!purchase) return;
    setSaving(true);
    try {
      await api.post(`/part-purchases/${purchase.id}/returns`, {
        quantity: Number(form.quantity || 1),
        reason_code: form.reason_code,
        reason_note: form.reason_note || null,
        restocking_fee: form.restocking_fee === '' ? 0 : Number(form.restocking_fee),
        refund_amount: form.refund_amount === '' ? null : Number(form.refund_amount),
        status: form.status,
      });
      toast.success(
        form.status === 'refunded'
          ? `Return recorded — ${aed(refund)} credited back to the ticket.`
          : 'Return logged. The ticket keeps the cost until the refund lands.',
      );
      onDone?.();
      onClose?.();
    } catch (e) {
      toast.error(e?.response?.data?.message || 'Could not record the return.');
    } finally {
      setSaving(false);
    }
  };

  if (!purchase) return null;

  return (
    <Modal open={open} onClose={onClose} title={`Return — ${purchase.part_name}`} size="md">
      <div className="space-y-4">
        <div className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
          Bought from <span className="font-medium text-slate-800">{purchase.supplier || purchase.source_name || 'supplier'}</span>
          {' · '}{purchased} × {aed(unit)} = <span className="font-medium text-slate-800">{aed(unit * purchased)}</span>
          {purchase.installed_at && <span className="ml-1 text-amber-600">· already fitted</span>}
        </div>

        <div className="grid grid-cols-2 gap-3">
          <Input
            label="Quantity returned"
            type="number"
            min="0.01"
            max={String(purchased)}
            step="0.01"
            value={form.quantity || ''}
            onChange={set('quantity')}
          />
          <Select label="Reason" value={form.reason_code || ''} onChange={set('reason_code')}>
            {REASONS.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
          </Select>
        </div>

        <Textarea
          label="Details (optional)"
          rows={2}
          value={form.reason_note || ''}
          onChange={set('reason_note')}
          placeholder="What was wrong with it?"
        />

        <div className="grid grid-cols-2 gap-3">
          <Input
            label="Restocking fee kept by supplier"
            type="number"
            min="0"
            step="0.01"
            value={form.restocking_fee || ''}
            onChange={set('restocking_fee')}
            placeholder="0.00"
          />
          <Input
            label="Refund amount"
            type="number"
            min="0"
            step="0.01"
            value={form.refund_amount || ''}
            onChange={set('refund_amount')}
            placeholder={String(expected)}
          />
        </div>

        {/* The one question that decides whether the ticket total moves today. */}
        <Select label="Has the money come back?" value={form.status || ''} onChange={set('status')}>
          <option value="refunded">Yes — refunded now (credits the ticket)</option>
          <option value="sent">Not yet — part sent back, awaiting refund</option>
          <option value="requested">Not yet — return logged, part still with us</option>
        </Select>

        <div className="rounded-lg border border-slate-200 bg-white p-3 text-sm">
          <div className="flex justify-between py-0.5">
            <span className="text-slate-600">Purchase</span>
            <span className="tabular-nums text-slate-800">+ {aed(unit * purchased)}</span>
          </div>
          <div className="flex justify-between py-0.5">
            <span className="text-slate-600">Return credit</span>
            <span className="tabular-nums text-emerald-700">
              {form.status === 'refunded' ? `− ${aed(refund)}` : '— (not yet)'}
            </span>
          </div>
          {kept > 0 && form.status === 'refunded' && (
            <div className="flex justify-between py-0.5 text-[12px]">
              <span className="text-slate-500">Kept by the supplier (stays a real cost)</span>
              <span className="tabular-nums text-slate-500">{aed(kept)}</span>
            </div>
          )}
          <div className="mt-1 flex justify-between border-t border-slate-100 pt-1.5 font-semibold">
            <span className="text-slate-700">Net cost of this part</span>
            <span className="tabular-nums text-slate-900">
              {aed(form.status === 'refunded' ? unit * purchased - refund : unit * purchased)}
            </span>
          </div>
        </div>

        <p className="text-[11px] text-slate-500">
          The purchase is never deleted. It stays on the ticket with this return recorded beside it, so the
          history of what was bought and what came back is always readable.
        </p>

        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button loading={saving} onClick={submit}>Record return</Button>
        </div>
      </div>
    </Modal>
  );
}
