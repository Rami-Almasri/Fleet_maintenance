// ComplaintDetailDrawer — one complaint's full record in a right-side slide-over: the car + customer
// context, tap-to-call contact so the inspector can reach the renter, the complaint itself, and the
// chronological triage timeline (ComplaintTimeline).
//
// It is where the complaint is HANDLED without leaving the Complaints Center. The complaint is a first-class
// entity with its own customer-support lifecycle, so while it's still open an inspector (maintenance.initiate)
// can log a customer conversation, record the triage DECISION (keep driving / bring in / replace / roadside),
// send the car in (which spawns a maintenance ticket), resolve it (no repair needed), or close it. Once a
// complaint has spawned maintenance, the footer deep-links to that ticket in the workflow.
//
// Shared by the Complaints Center and the vehicle's Complaint History tab: pass a complaint `id` and it
// fetches /complaints/{id} itself. `onChanged` fires after any action so the caller's list can refresh.

import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import { usePermissions } from '../../hooks/usePermissions';
import { useToast } from '../ui/Toast';
import Drawer from '../ui/Drawer';
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import Icon from '../ui/Icon';
import { Textarea } from '../ui/Field';
import { Skeleton } from '../ui/Skeleton';
import ComplaintTimeline from './ComplaintTimeline';
import { STAGE_META, DECISION_META } from './stages';

// The triage decision buttons — each records the plan; "Bring for inspection" additionally spawns a ticket.
const DECISIONS = [
  { key: 'continue_driving',     ...DECISION_META.continue_driving },
  { key: 'bring_for_inspection', ...DECISION_META.bring_for_inspection },
  { key: 'replace_vehicle',      ...DECISION_META.replace_vehicle },
  { key: 'roadside_assistance',  ...DECISION_META.roadside_assistance },
];

const OPEN_STATUSES = ['new', 'notified', 'contacted', 'in_maintenance'];

// Where the customer on screen came from — no black boxes (see the traceability rule).
const CONTACT_SOURCE = {
  linked_contract: 'the contract linked to this complaint',
  rental_at_complaint_time: 'the rental this car was on when the complaint was logged',
  current_rental: "the car's current open rental",
  intake_snapshot: 'what was typed in at intake',
};

export default function ComplaintDetailDrawer({ id, open, onClose, onChanged }) {
  const { can } = usePermissions();
  const toast = useToast();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(''); // '' | 'contact' | 'resolve' | 'close' | decision key

  const load = useCallback(async ({ silent = false } = {}) => {
    if (!id) return;
    if (!silent) { setLoading(true); setData(null); }
    setError(null);
    try {
      const res = await api.get(`/complaints/${id}`);
      setData(res.data?.data || null);
    } catch (e) {
      setError(e?.response?.data?.message || 'Could not load this complaint');
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => {
    if (!open || !id) return;
    setNote('');
    load();
  }, [open, id, load]);

  const stage = data ? (STAGE_META[data.status] || null) : null;
  const contact = data?.contact || null;
  const isOpen = data ? OPEN_STATUSES.includes(data.status) : false;
  const canAct = can('maintenance.initiate');
  const decisionMeta = data?.decision ? DECISION_META[data.decision] : null;

  // POST a triage action to /complaints/{id}/{path} and refresh in place.
  const act = useCallback(async (key, path, { success } = {}) => {
    if (busy || !data) return;
    setBusy(key);
    try {
      const body = { note: note.trim() || undefined };
      if (path.startsWith('decision:')) {
        await api.post(`/complaints/${data.id}/decision`, { decision: path.split(':')[1], note: body.note });
      } else {
        await api.post(`/complaints/${data.id}/${path}`, body);
      }
      toast.success(success || 'Done');
      setNote('');
      await load({ silent: true });
      onChanged?.();
    } catch (e) {
      toast.error(e?.response?.data?.message || 'Could not complete that action');
    } finally {
      setBusy('');
    }
  }, [busy, data, note, toast, load, onChanged]);

  return (
    <Drawer
      open={open}
      onClose={onClose}
      eyebrow="Complaint record"
      title={data ? (data.plate || `#${data.id}`) : (loading ? 'Loading…' : 'Complaint')}
      subtitle={data?.car || undefined}
      footer={data && (
        <div className="flex items-center justify-between gap-2">
          <span className="text-xs text-slate-400">Complaint #{data.id}</span>
          {data.maintenance_id && (
            <Link
              to={`/maintenance-workflow/${data.maintenance_id}`}
              className="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-slate-700"
            >
              Open ticket #{data.maintenance_id} <Icon.ArrowRight className="h-3.5 w-3.5" />
            </Link>
          )}
        </div>
      )}
    >
      {loading && (
        <div className="space-y-3">
          <Skeleton className="h-20 rounded-xl" />
          <Skeleton className="h-32 rounded-xl" />
        </div>
      )}

      {error && !loading && (
        <p className="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 ring-1 ring-inset ring-rose-200">{error}</p>
      )}

      {data && !loading && (
        <div className="space-y-5">
          {/* Status + decision + who's handling it */}
          <div className="flex flex-wrap items-center gap-2">
            {stage && <Badge tone={stage.tone}>{stage.emoji} {stage.label}</Badge>}
            {decisionMeta && <Badge tone="slate">{decisionMeta.emoji} {decisionMeta.label}</Badge>}
            {data.assigned && <span className="text-xs text-slate-500">Handled by <span className="font-semibold text-slate-700">{data.assigned}</span></span>}
          </div>

          {/* Customer contact — tap to call / WhatsApp so the inspector can reach the renter. */}
          <div className="rounded-xl border border-slate-200 bg-white p-4">
            <p className="mb-2 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
              <Icon.Users className="h-3.5 w-3.5" /> Customer
            </p>
            {contact?.name || data.customer ? (
              <div className="space-y-2">
                {contact?.customer_id ? (
                  <Link to={`/customers/${contact.customer_id}`} className="text-sm font-semibold text-slate-800 underline decoration-slate-300 underline-offset-2 hover:text-slate-900">
                    {contact.name || data.customer}
                  </Link>
                ) : (
                  <p className="text-sm font-semibold text-slate-800">{contact?.name || data.customer}</p>
                )}
                <div className="flex flex-wrap gap-2">
                  {contact?.mobile && (
                    <a href={`tel:${contact.mobile}`} className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200 transition hover:bg-emerald-100" dir="ltr">
                      <span aria-hidden>📞</span> {contact.mobile}
                    </a>
                  )}
                  {contact?.whatsapp && (
                    <a href={`https://wa.me/${String(contact.whatsapp).replace(/[^\d]/g, '')}`} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 rounded-lg bg-green-50 px-3 py-1.5 text-xs font-semibold text-green-700 ring-1 ring-inset ring-green-200 transition hover:bg-green-100" dir="ltr">
                      <span aria-hidden>💬</span> WhatsApp
                    </a>
                  )}
                  {(data.contract_no || contact?.contract_no) && (
                    <span className="inline-flex items-center rounded-lg bg-slate-50 px-2.5 py-1.5 text-xs font-medium text-slate-500 ring-1 ring-inset ring-slate-200">
                      Contract {data.contract_no || contact.contract_no}
                    </span>
                  )}
                </div>
                {!contact?.mobile && !contact?.whatsapp && (
                  <p className="text-xs text-slate-400">No phone on file for this customer.</p>
                )}
                {CONTACT_SOURCE[contact?.source] && (
                  <p className="text-[11px] text-slate-400">Source: {CONTACT_SOURCE[contact.source]}</p>
                )}
              </div>
            ) : (
              <p className="text-sm text-slate-400">
                No rental covered this car when the complaint was logged, and no customer was entered at intake.
              </p>
            )}
          </div>

          {/* The complaint itself */}
          {data.complaint && (
            <div className="rounded-xl border border-rose-100 bg-rose-50/60 p-4">
              <p className="mb-1 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-rose-600">
                📣 What the customer reported
              </p>
              <p className="text-sm italic text-slate-700">“{data.complaint}”</p>
            </div>
          )}

          {/* Handle it — the triage panel. Available while the complaint is open, initiate-gated. */}
          {isOpen && canAct && (
            <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-4">
              <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Triage this complaint</p>
              <Textarea
                rows={2}
                value={note}
                onChange={(e) => setNote(e.target.value)}
                placeholder="Optional note — what the customer said / what was decided…"
                maxLength={2000}
              />

              {/* Log a conversation */}
              <div className="mt-2.5 flex flex-wrap gap-2">
                <Button variant="secondary" onClick={() => act('contact', 'contact', { success: 'Customer contact logged' })} loading={busy === 'contact'} disabled={busy && busy !== 'contact'}>
                  <span aria-hidden>📞</span> Log contact
                </Button>
              </div>

              {/* The decision — only until one is acted on into maintenance */}
              {data.status !== 'in_maintenance' && (
                <>
                  <p className="mt-3 mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Decision</p>
                  <div className="flex flex-wrap gap-2">
                    {DECISIONS.map((d) => (
                      <Button
                        key={d.key}
                        variant="ghost"
                        onClick={() => act(d.key, `decision:${d.key}`, { success: d.key === 'bring_for_inspection' ? 'Sent in for inspection' : `Decision: ${d.label}` })}
                        loading={busy === d.key}
                        disabled={busy && busy !== d.key}
                      >
                        <span aria-hidden>{d.emoji}</span> {d.label}
                      </Button>
                    ))}
                  </div>
                </>
              )}

              {/* Terminal actions */}
              <div className="mt-3 flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-3">
                <Button variant="success" onClick={() => act('resolve', 'resolve', { success: 'Complaint resolved' })} loading={busy === 'resolve'} disabled={busy && busy !== 'resolve'}>
                  <Icon.Check className="h-4 w-4" /> Resolve — no repair
                </Button>
                <Button variant="secondary" onClick={() => act('close', 'close', { success: 'Complaint closed' })} loading={busy === 'close'} disabled={busy && busy !== 'close'}>
                  Close
                </Button>
              </div>
              <p className="mt-2 text-[11px] text-slate-400">“Resolve” = handled as customer support, no garage trip. “Bring for inspection” sends the car in and opens a maintenance ticket.</p>
            </div>
          )}
          {isOpen && !canAct && (
            <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500 ring-1 ring-inset ring-slate-100">Awaiting the inspector to contact the customer and decide.</p>
          )}

          {/* The record of truth */}
          <div>
            <p className="mb-3 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Timeline</p>
            <ComplaintTimeline timeline={data.timeline || []} />
          </div>
        </div>
      )}
    </Drawer>
  );
}
