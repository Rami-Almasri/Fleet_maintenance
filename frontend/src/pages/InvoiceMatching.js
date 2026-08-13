// Invoice Matching Desk (/invoice-matching) — the car is back from the garage; now make the paper agree
// with the work.
//
// Two halves, side by side, on purpose. On the LEFT of the workspace is WHAT WE DID: every work item on
// the ticket, the garage that did it, and whether it sits on a bill yet. On the RIGHT is THE PAPER: the
// invoices keyed so far (a car worked in two garages comes back with two bills — see One Ticket → Many
// Invoices) plus the line items each one charged. Hovering a bill lights up the work it covers, and
// hovering a work item lights up its bill, so "does this invoice match what was done to the car" is
// answered by looking, not by remembering.
//
// Nothing here is inferred. A work item counts as billed only because maintenance_tasks.maintenance_invoice_id
// points at an invoice; a bill is "off" only because its own printed receipt total disagrees with its keyed
// lines. Keying an invoice goes through the same InvoicesPanel the ticket drawer uses — one write path.
//
// Backed by GET /maintenance-tickets/invoice-matching (queue) + GET /maintenance-tickets/{id} (detail).

import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { usePermissions } from '../hooks/usePermissions';
import { useI18n } from '../i18n/I18nContext';
import Icon from '../components/ui/Icon';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import { Skeleton } from '../components/ui/Skeleton';
import InvoicesPanel from '../components/workflow/InvoicesPanel';
import { fmtDuration, fmtDateTime } from '../components/workflow/meta';
import { getTicketCheckpoints, useCheckpointVocab } from '../lib/maintenanceCheckpoints';

const money = (n) => `AED ${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const compactMoney = (n) => `AED ${Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;

// Gregorian calendar + Latin digits under Arabic — a bare locale would render Hijri.
const fmtDate = (iso, lang) => (iso
  ? new Date(iso).toLocaleDateString(lang === 'ar' ? 'ar-AE-u-ca-gregory-nu-latn' : undefined, { day: '2-digit', month: 'short', year: 'numeric' })
  : '—');

// The four states a returned car can be in, in the order the desk works them. Each is DERIVED on the
// backend from stored rows only (InvoiceMatchingService); the colours here just carry that decision.
// (`cyan` rather than `violet` for a receipt gap — the theme remaps `violet` onto the yellow accent,
// which would make a money mismatch read as a warning tone it isn't.)
const STATES = {
  no_invoice: { label: 'No bill yet',       dot: 'bg-rose-500',    chip: 'bg-rose-50 text-rose-700 ring-rose-600/20',          stripe: 'border-s-rose-400',    ring: 'text-rose-500' },
  partial:    { label: 'Work not billed',   dot: 'bg-amber-500',   chip: 'bg-amber-50 text-amber-700 ring-amber-600/20',       stripe: 'border-s-amber-400',   ring: 'text-amber-500' },
  variance:   { label: 'Receipt disagrees', dot: 'bg-cyan-500',  chip: 'bg-cyan-50 text-cyan-700 ring-cyan-600/20',    stripe: 'border-s-cyan-400',  ring: 'text-cyan-500' },
  matched:    { label: 'Matched',           dot: 'bg-emerald-500', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', stripe: 'border-s-emerald-400', ring: 'text-emerald-500' },
};

const NON_REPAIR = ['cancelled', 'not_found'];

// A ticket carries WORK of four kinds and an oil change is not a fault — the desk groups and counts by
// each item's own kind (MaintenanceTask::KIND_META, carried on the task as kind / kind_meta).
const KIND_ORDER = ['fault', 'service', 'inspection', 'damage'];
const KIND_LABEL = { fault: 'Faults', service: 'Services', inspection: 'Checks', damage: 'Damage' };
const KIND_ONE = { fault: 'fault', service: 'service', inspection: 'check', damage: 'damage' };
const KIND_COUNT = { fault: '{n} faults', service: '{n} services', inspection: '{n} checks', damage: '{n} damage items' };
const KIND_STRIPE = { fault: 'border-s-rose-400', service: 'border-s-blue-400', inspection: 'border-s-amber-400', damage: 'border-s-cyan-400' };
const kindOf = (task) => (KIND_ORDER.includes(task?.kind) ? task.kind : 'fault');

// Garage initials for the avatar chips — "AJMAN STICAR SHOP" → "AS". Purely decorative; the full name is
// always printed next to it.
const initials = (name) => (name || '?')
  .split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();

/* ── small pieces ─────────────────────────────────────────────────────────────────────────────── */

function StateChip({ state, className = '' }) {
  const { t } = useI18n();
  const meta = STATES[state] || STATES.no_invoice;
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset ${meta.chip} ${className}`}>
      <span className={`h-1.5 w-1.5 rounded-full ${meta.dot}`} />
      {t(meta.label)}
    </span>
  );
}

// The desk's pulse — what share of the cars that came back are fully matched. One ring, read at a glance,
// with the real counts underneath it so it is never just a mood.
function MatchRing({ matched = 0, total = 0, size = 92 }) {
  const { t } = useI18n();
  const pct = total > 0 ? Math.round((matched / total) * 100) : 0;
  const r = (size - 12) / 2;
  const c = 2 * Math.PI * r;

  return (
    <div className="flex items-center gap-3">
      <div className="relative" style={{ width: size, height: size }}>
        <svg width={size} height={size} className="-rotate-90">
          <circle cx={size / 2} cy={size / 2} r={r} strokeWidth="7" className="fill-none stroke-slate-200" />
          <circle
            cx={size / 2}
            cy={size / 2}
            r={r}
            strokeWidth="7"
            strokeLinecap="round"
            strokeDasharray={c}
            strokeDashoffset={c - (c * pct) / 100}
            className="fill-none stroke-emerald-500 transition-[stroke-dashoffset] duration-700 ease-out"
          />
        </svg>
        <div className="absolute inset-0 flex flex-col items-center justify-center">
          <span className="font-display text-lg font-bold tabular-nums text-slate-900">{pct}%</span>
        </div>
      </div>
      <div className="text-xs">
        <p className="font-semibold text-slate-700">{t('Matched')}</p>
        <p className="tabular-nums text-slate-400">{t('{billed} of {total} billed', { billed: matched, total })}</p>
      </div>
    </div>
  );
}

// A lane filter — reads as a number, works as a filter.
function LanePill({ label, value, active, tone = 'slate', onClick }) {
  const tones = {
    slate:   { dot: 'bg-slate-400',   on: 'bg-slate-900 text-white ring-slate-900' },
    rose:    { dot: 'bg-rose-500',    on: 'bg-rose-600 text-white ring-rose-600' },
    amber:   { dot: 'bg-amber-500',   on: 'bg-amber-500 text-white ring-amber-500' },
    cyan:  { dot: 'bg-cyan-500',  on: 'bg-cyan-600 text-white ring-cyan-600' },
    emerald: { dot: 'bg-emerald-500', on: 'bg-emerald-600 text-white ring-emerald-600' },
  };
  const tn = tones[tone] || tones.slate;

  return (
    <button
      type="button"
      onClick={onClick}
      className={`focus-ring-self inline-flex items-center gap-2 rounded-full px-3.5 py-2 text-xs font-semibold ring-1 transition ${active
        ? `${tn.on} shadow-card`
        : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50 hover:ring-slate-300'}`}
    >
      {!active && <span className={`h-1.5 w-1.5 rounded-full ${tn.dot}`} />}
      <span className="tabular-nums">{value}</span>
      <span className={active ? 'opacity-90' : 'text-slate-400'}>{label}</span>
    </button>
  );
}

// Coverage bar — how much of the work is on a bill. Segments, not a percentage word, because "3 of 5
// billed" is the thing the user has to act on.
function CoverageBar({ billed = 0, total = 0 }) {
  const cells = Math.max(total, 1);
  return (
    <div className="flex gap-0.5">
      {Array.from({ length: cells }).map((_, i) => (
        <span
          key={i}
          className={`h-1.5 flex-1 rounded-full transition-colors ${i < billed ? 'bg-emerald-500' : 'bg-slate-200'}`}
        />
      ))}
    </div>
  );
}

// The plate, set like a plate — the one identifier everyone on the floor speaks in.
function PlateTag({ plate, size = 'sm' }) {
  return (
    <span className={`inline-flex items-center rounded-lg border border-slate-300 bg-slate-50 font-mono font-bold tracking-wider text-slate-900 ${size === 'lg' ? 'px-3 py-1 text-lg' : 'px-2 py-0.5 text-sm'}`}>
      {plate}
    </span>
  );
}

/* ── queue rail ───────────────────────────────────────────────────────────────────────────────── */

function QueueRow({ row, active, onSelect }) {
  const { t, lang } = useI18n();
  const meta = STATES[row.match_state] || STATES.no_invoice;

  return (
    <button
      type="button"
      onClick={() => onSelect(row.ticket_id)}
      className={`hover-lift focus-ring-self w-full rounded-2xl border border-s-4 p-3 text-start transition ${meta.stripe} ${active
        ? 'border-indigo-300 bg-indigo-50/60 shadow-card'
        : 'border-slate-200 bg-white hover:bg-slate-50/60'}`}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <PlateTag plate={row.plate || `#${row.ticket_id}`} />
          <p className="mt-1 truncate text-[11px] text-slate-400">{row.car || t('Vehicle')}</p>
        </div>
        <StateChip state={row.match_state} />
      </div>

      <div className="mt-2.5">
        <CoverageBar billed={row.faults_billed} total={row.faults_total} />
        <div className="mt-1.5 flex items-center justify-between text-[11px]">
          <span className="text-slate-500">
            {t('{billed} of {total} billed', { billed: row.faults_billed, total: row.faults_total })}
          </span>
          <span className="font-semibold tabular-nums text-slate-700">{compactMoney(row.invoiced_amount)}</span>
        </div>
      </div>

      <div className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-slate-400">
        <span className="inline-flex items-center gap-1">
          <Icon.Clock className="h-3 w-3" />
          {row.days_back != null ? t('back {n}d', { n: row.days_back }) : fmtDate(row.back_at, lang)}
        </span>
        {row.sla_overdue && (
          <span className="inline-flex items-center gap-1 rounded-full bg-rose-50 px-1.5 py-0.5 font-semibold text-rose-600">
            <Icon.Alert className="h-3 w-3" />{t('Past SLA')}
          </span>
        )}
        {row.garages?.length > 0 && (
          <span className="inline-flex min-w-0 items-center gap-1">
            <Icon.Wrench className="h-3 w-3" /><span className="truncate">{row.garages.join(' · ')}</span>
          </span>
        )}
      </div>
    </button>
  );
}

/* ── work side: one work item ─────────────────────────────────────────────────────────────────── */

function WorkCard({ fault, invoice, dimmed, highlighted, onHover }) {
  const { t } = useI18n();
  const repaired = !NON_REPAIR.includes(fault.status) && !fault.is_incorrect;
  const done = fault.status === 'completed';
  const kind = kindOf(fault);

  return (
    <div
      onMouseEnter={() => onHover(invoice?.id || null)}
      onMouseLeave={() => onHover(null)}
      className={`rounded-xl border border-s-4 bg-white p-3 shadow-soft transition duration-200 ${KIND_STRIPE[kind]} ${highlighted
        ? 'border-indigo-300 ring-2 ring-indigo-200'
        : 'border-slate-200'} ${dimmed ? 'opacity-35' : ''}`}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="text-sm font-semibold text-slate-800">
            {/* The item's own kind leads — a planned service is never dressed as a fault. Severity only
                belongs to work that can HAVE a severity, i.e. an unplanned fault or damage. */}
            {fault.kind_meta?.emoji && <span className="me-1">{fault.kind_meta.emoji}</span>}
            {fault.symptom}
          </p>
          <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-slate-500">
            <span className="rounded bg-slate-100 px-1.5 py-0.5 font-medium text-slate-500">{t(KIND_ONE[kind])}</span>
            {kind !== 'service' && fault.severity_label && (
              <span>{fault.severity_emoji} {fault.severity_label}</span>
            )}
            {/* The location line, only when it says something the symptom does not already say. */}
            {fault.display && fault.display !== fault.symptom && <span>{fault.display}</span>}
            {fault.current_garage && (
              <span className="inline-flex items-center gap-1"><Icon.Wrench className="h-3 w-3 text-slate-300" />{fault.current_garage}</span>
            )}
            {fault.quantity > 1 && <span>× {fault.quantity}</span>}
          </div>
        </div>
        <Badge tone={done ? 'green' : repaired ? 'amber' : 'slate'}>
          {done
            ? (kind === 'service' ? t('Done') : t('Fixed'))
            : repaired
              ? t('Open')
              : (kind === 'service' ? t('Not performed') : t('Not repaired'))}
        </Badge>
      </div>

      {/* HOW LONG THIS ONE TOOK. Custody is the shared garage clock (it starts at dispatch, so it
          includes transit); `work` only exists when this item had its own start signal — and when it
          doesn't, nothing is shown rather than the custody number wearing a label it hasn't earned.
          A second attempt means it came back — the single biggest reason a car is still in the shop. */}
      {(fault.repair_time?.cumulative_custody_seconds != null || fault.repair_time?.attempt_count > 1) && (
        <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-500">
          {fault.repair_time.cumulative_custody_seconds != null && (
            <span className="inline-flex items-center gap-1">
              <Icon.Clock className="h-3 w-3 text-slate-400" />
              {t('{time} at the garage', { time: fmtDuration(fault.repair_time.cumulative_custody_seconds) })}
            </span>
          )}
          {fault.repair_time.cumulative_work_seconds != null && (
            <span>{t('{time} being worked', { time: fmtDuration(fault.repair_time.cumulative_work_seconds) })}</span>
          )}
          {fault.repair_time.cumulative_labor_hours != null && (
            <span>{t('{n}h labour', { n: fault.repair_time.cumulative_labor_hours })}</span>
          )}
          {fault.repair_time.attempt_count > 1 && (
            <span className="rounded-full bg-amber-50 px-1.5 py-0.5 font-semibold text-amber-700">
              {t('{n} attempts', { n: fault.repair_time.attempt_count })}
            </span>
          )}
          {fault.repair_time.open_attempt && (
            <span className="rounded-full bg-blue-50 px-1.5 py-0.5 font-semibold text-blue-700">{t('still running')}</span>
          )}
        </div>
      )}

      {fault.resolution_note && (
        <p className="mt-2 rounded-lg bg-slate-50 px-2.5 py-1.5 text-[11px] italic text-slate-600">“{fault.resolution_note}”</p>
      )}

      {fault.parts?.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1">
          {fault.parts.map((p) => (
            <span key={p.id} className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-medium text-blue-700 ring-1 ring-inset ring-blue-600/10">
              <Icon.Coins className="h-2.5 w-2.5" />{p.part_name}{p.quantity > 1 ? ` × ${p.quantity}` : ''}
            </span>
          ))}
        </div>
      )}

      {/* The match line — the whole point of the page. */}
      <div className="mt-2.5 border-t border-slate-100 pt-2 text-[11px]">
        {invoice ? (
          <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/10">
            <Icon.Invoice className="h-3.5 w-3.5" />
            {t('Billed on {name}', {
              name: invoice.invoice_no || invoice.vendor_name || t('invoice #{id}', { id: invoice.id }),
            })}
          </span>
        ) : repaired ? (
          <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/10">
            <Icon.Alert className="h-3.5 w-3.5" />{t('On no bill')}
          </span>
        ) : (
          <span className="inline-flex items-center gap-1.5 text-slate-400">
            <Icon.Info className="h-3.5 w-3.5" />{t('Nothing to bill — this was never carried out')}
          </span>
        )}
      </div>
    </div>
  );
}

/* ── paper side: every charged line, grouped by bill ──────────────────────────────────────────── */

function ChargedLines({ invoices, activeInvoiceId, onHover }) {
  const { t } = useI18n();
  const withLines = invoices.filter((inv) => (inv.line_items || []).length > 0);
  if (withLines.length === 0) return null;

  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-soft">
      <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">{t('Every line on the paper')}</p>
      <div className="space-y-4">
        {withLines.map((inv) => (
          <div
            key={inv.id}
            onMouseEnter={() => onHover(inv.id)}
            onMouseLeave={() => onHover(null)}
            className={`rounded-xl p-2 transition ${activeInvoiceId === inv.id ? 'bg-indigo-50/70 ring-1 ring-indigo-200' : ''}`}
          >
            <p className="mb-1.5 text-[11px] font-semibold text-slate-500">
              {inv.invoice_no || inv.vendor_name || t('invoice #{id}', { id: inv.id })}
            </p>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[420px] text-[11px]">
                <tbody>
                  {inv.line_items.map((li) => (
                    <tr key={li.id} className="border-b border-slate-100 last:border-0">
                      <td className="py-1.5 pe-2">
                        <span className={`me-1.5 inline-block rounded px-1 py-0.5 text-[9px] font-bold uppercase ${li.kind === 'labor' ? 'bg-cyan-100 text-cyan-700' : 'bg-blue-100 text-blue-700'}`}>
                          {li.kind === 'labor' ? t('Labor') : t('Part')}
                        </span>
                        <span className="text-slate-700">{li.description}</span>
                        {li.finding_text && <span className="ms-1 text-slate-400">· {li.finding_text}</span>}
                      </td>
                      <td className="whitespace-nowrap py-1.5 text-end tabular-nums text-slate-400">
                        {li.quantity != null && li.unit_price != null ? `${li.quantity} × ${Number(li.unit_price).toLocaleString()}` : ''}
                      </td>
                      <td className="whitespace-nowrap py-1.5 ps-2 text-end font-semibold tabular-nums text-slate-700">
                        {money(li.line_total)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ── one slot per garage ──────────────────────────────────────────────────────────────────────── */

// A car worked in two garages owes TWO bills, and neither garage can bill the other's work. So the desk
// gives each garage its own slot: the work that garage did, the bill it handed us (if any), a Create-bill
// button that opens the form with only ITS unbilled work ticked, and its own tokenised link to submit the
// invoice itself. One row per garage is the whole billing model, made visible.
function GarageSlot({ slot, canManage, onCreateBill, onIssueLink, linkBusy, copiedFor, onCopy, onHover, active }) {
  const { t } = useI18n();
  const state = slot.invoices.length === 0 ? 'no_invoice' : slot.unbilled.length > 0 ? 'partial' : 'matched';
  const meta = STATES[state];
  const total = slot.invoices.reduce((a, i) => a + Number(i.amount || 0), 0);

  return (
    <div
      onMouseEnter={() => onHover?.(slot)}
      onMouseLeave={() => onHover?.(null)}
      className={`rounded-2xl border border-s-4 p-3.5 shadow-soft transition ${meta.stripe} ${active
        ? 'border-indigo-300 ring-2 ring-indigo-200'
        : state === 'matched' ? 'border-emerald-200 bg-emerald-50/30' : 'border-slate-200 bg-white'}`}
    >
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="flex min-w-0 items-center gap-2.5">
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-slate-900 font-display text-[11px] font-bold text-white">
            {initials(slot.name)}
          </span>
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold text-slate-800">{slot.name || t('No garage recorded')}</p>
            <p className="mt-0.5 text-[11px] text-slate-500">
              {t('{billed} of {total} billed', { billed: slot.items.length - slot.unbilled.length, total: slot.items.length })}
              {slot.invoices.length > 0 && <span className="font-semibold text-slate-700"> · {money(total)}</span>}
            </p>
          </div>
        </div>
        <StateChip state={state} />
      </div>

      {/* The work this garage did — named, so the bill is checked against it and not against a count. */}
      <div className="mt-2.5 flex flex-wrap gap-1">
        {slot.items.map((f) => {
          const billed = !slot.unbilled.some((u) => u.id === f.id);
          return (
            <span
              key={f.id}
              className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] ring-1 ring-inset ${billed
                ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/10'
                : 'bg-amber-50 text-amber-700 ring-amber-600/10'}`}
            >
              {f.kind_meta?.emoji && <span className="text-[9px]">{f.kind_meta.emoji}</span>}
              {f.symptom}
            </span>
          );
        })}
      </div>

      {/* Its bills, if any */}
      {slot.invoices.length > 0 && (
        <div className="mt-2 space-y-1">
          {slot.invoices.map((inv) => (
            <p key={inv.id} className="text-[11px] text-slate-500">
              <Icon.Invoice className="me-1 inline h-3 w-3 text-slate-400" />
              {inv.invoice_no || t('invoice #{id}', { id: inv.id })} · <span className="font-semibold tabular-nums text-slate-700">{money(inv.amount)}</span>
            </p>
          ))}
        </div>
      )}

      {canManage && (
        <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2.5">
          {slot.unbilled.length > 0 && (
            <Button size="sm" variant="primary" onClick={() => onCreateBill(slot)}>
              <Icon.Plus className="h-3.5 w-3.5" />{t('Key this garage’s bill')}
            </Button>
          )}
          {/* Or let the garage fill it in itself — the same tokenised link the ticket issues, scoped to
              this garage so it only ever sees (and bills) its own work. */}
          {slot.vendor_id && (
            slot.link ? (
              <button
                type="button"
                onClick={() => onCopy(slot)}
                className="focus-ring-self inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2.5 py-1.5 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-200"
              >
                {copiedFor === slot.vendor_id ? <Icon.Check className="h-3.5 w-3.5 text-emerald-600" /> : <Icon.Route className="h-3.5 w-3.5" />}
                {copiedFor === slot.vendor_id ? t('Link copied') : t('Copy garage link')}
              </button>
            ) : (
              <Button size="sm" variant="secondary" loading={linkBusy === slot.vendor_id} onClick={() => onIssueLink(slot)}>
                <Icon.Route className="h-3.5 w-3.5" />{t('Send link to garage')}
              </Button>
            )
          )}
        </div>
      )}
    </div>
  );
}

/* ── how long it took ─────────────────────────────────────────────────────────────────────────── */

// The ticket's milestones, in the order they happen. Each is a STAMPED moment on the ticket
// (`handoffs.*`), so the gap between two of them is a fact about the record, not a modelled duration —
// which matters, because this app has several legitimately different "how long did it take" clocks and a
// fourth invented one would just add to the confusion.
// How a fault LEFT a garage — the stint's stored outcome, said in the words the floor uses. These are the
// four the workflow can write (plus a failed re-inspection), and each one is a different story about that
// garage: it fixed it, it passed it on, it refused it, or the fault was dropped while it sat there.
const OUTCOME_LABEL = {
  resolved: 'fixed here',
  transferred_out: 'moved on',
  unable: 'garage could not do it',
  cancelled: 'dropped here',
  failed_reinspection: 'failed the re-check',
};
const OUTCOME_STYLE = {
  resolved: 'bg-emerald-50 text-emerald-700',
  transferred_out: 'bg-amber-50 text-amber-700',
  unable: 'bg-rose-50 text-rose-700',
  cancelled: 'bg-slate-100 text-slate-500',
  failed_reinspection: 'bg-rose-50 text-rose-700',
};

const MILESTONES = [
  { key: 'requested',              label: 'Requested' },
  { key: 'reviewed',               label: 'Reviewed' },
  { key: 'inspected',              label: 'Inspected' },
  { key: 'dispatched',             label: 'Picked up' },
  { key: 'repair_started',         label: 'Arrived at garage' },
  { key: 'ready',                  label: 'Garage finished' },
  { key: 'picked_up_from_garage',  label: 'Collected from garage' },
  { key: 'park_arrived',           label: 'Back in our park' },
  { key: 'closed',                 label: 'Ticket closed' },
];

// GARAGE BY GARAGE, including the transfers.
//
// The ticket's own stamps only ever describe the CURRENT garage — `repair_started_at` is re-stamped at
// each arrival and cleared on a transfer, so a car that moved between two shops has the first shop's time
// nowhere in them. The truth of "where was it, and for how long" lives one level down, in the per-fault
// garage stints (maintenance_task_assignments): each stint is one fault's custody at one garage, with a
// stored outcome that says how it ended (`transferred_out` = it was moved on).
//
// Stints for different faults at the same shop run in parallel, so they are merged by garage into VISITS:
// a visit opens when the first fault arrives and closes when the last one leaves. The gap between one
// visit closing and the next opening is the move itself — the time the car was neither here nor there,
// which is precisely the time that goes missing when only the last garage is counted.
function buildGarageVisits(tasks = [], ticket = null) {
  const stints = [];
  tasks.forEach((task) => {
    (task.assignments || []).forEach((a) => {
      if (!a.assigned_at) return;
      // A garage's clock starts when the CAR GOT THERE (`arrived_at`). On a transfer that is not when
      // the fault was pointed at the garage — the car was still across town. Where no arrival is on
      // record (every stint written before the column existed) we fall back to the dispatch instant and
      // FLAG it, so custody time is never quietly presented as time at the garage.
      const arrived = a.arrived_at ? new Date(a.arrived_at).getTime() : null;
      stints.push({
        ...a,
        task_id: task.id,
        symptom: task.symptom,
        kind_meta: task.kind_meta,
        from: arrived ?? new Date(a.assigned_at).getTime(),
        fromDispatch: arrived === null,
        assignedAt: new Date(a.assigned_at).getTime(),
        to: a.released_at ? new Date(a.released_at).getTime() : Date.now(),
      });
    });
  });
  if (stints.length === 0) return [];

  stints.sort((a, b) => a.from - b.from);

  const visits = [];
  stints.forEach((s) => {
    // Same garage and the windows touch → the same visit, not a second trip. A LATER return to a garage
    // it already left is a genuine second visit and gets its own row.
    const open = visits.find((v) => String(v.vendor_id) === String(s.vendor_id) && s.from <= v.to);
    const visit = open || (visits.push({
      vendor_id: s.vendor_id,
      name: s.garage,
      from: s.from,
      to: s.to,
      items: [],
      isOpen: false,
      transferredOut: false,
      reason: null,
      fromDispatch: false,
    }), visits[visits.length - 1]);

    visit.from = Math.min(visit.from, s.from);
    visit.to = Math.max(visit.to, s.to);
    // One un-stamped stint makes the whole visit's start a dispatch time, and the visit says so.
    if (s.fromDispatch) visit.fromDispatch = true;
    if (!visit.name && s.garage) visit.name = s.garage;
    if (s.is_open || !s.released_at) visit.isOpen = true;
    if (s.outcome === 'transferred_out') {
      visit.transferredOut = true;
      if (s.reason) visit.reason = s.reason;
    }
    // Each item keeps its OWN clock at this garage. Two faults sent to the same shop rarely leave it
    // together — one is fixed and the other is moved on — so a single per-visit number would hide the
    // very thing you are looking for.
    visit.items.push({
      id: s.task_id,
      symptom: s.symptom,
      kind_meta: s.kind_meta,
      outcome: s.outcome,
      seconds: Math.max(0, (s.to - s.from) / 1000),
      fromDispatch: s.fromDispatch,
      isOpen: !s.released_at,
    });
  });

  visits.sort((a, b) => a.from - b.from);

  // The ticket's own transit stamps for the CURRENT leg: the car was collected (dispatched_at) and
  // checked in at the destination (repair_started_at). Single columns, so they only ever describe the
  // latest move — but for that one move they are the real measurement.
  const legOut = ticket?.stage_timing?.dispatched_at ? new Date(ticket.stage_timing.dispatched_at).getTime() : null;
  const legIn = ticket?.stage_timing?.repair_started_at ? new Date(ticket.stage_timing.repair_started_at).getTime() : null;

  return visits.map((v, i) => {
    const prev = visits[i - 1];
    let transferSeconds = null;
    let transferSource = null;

    if (prev) {
      // On a PLANNED transfer the ledger re-points the faults at the arrival check-in, so the old stint
      // closes and the new one opens in the same instant: a zero here means "recorded as one moment",
      // not "delivered instantly". Only a positive gap is a measurement.
      const gap = (v.from - prev.to) / 1000;
      if (gap > 0) {
        transferSeconds = gap;
        transferSource = 'stints';
      } else if (i === visits.length - 1 && legOut && legIn && legIn > legOut && v.from >= legIn) {
        // …and for the most recent move the ticket DID record the drive: collected → checked in.
        transferSeconds = (legIn - legOut) / 1000;
        transferSource = 'ticket';
      }
    }

    return {
      ...v,
      seconds: Math.max(0, (v.to - v.from) / 1000),
      transferSeconds,
      transferSource,
      // There WAS a move, but nothing on record times it.
      transferUnmeasured: !!prev && transferSeconds === null,
    };
  });
}

/**
 * The MOVES, as events on the ticket's timeline.
 *
 * The ticket's own milestone columns are single-valued — `dispatched_at` and `repair_started_at` are
 * re-stamped at each new garage — so a car that was moved has only its LAST journey in them, and the
 * walk silently skips "and then it went somewhere else". Each transfer is recorded per fault instead: a
 * stint closes as `transferred_out` and a fresh stint opens at the receiving garage in the same instant.
 * That closing stint is the move, and it carries everything the timeline needs to state it plainly —
 * which fault, from which garage, to which garage, why, and who decided.
 */
function buildTransferEvents(tasks = []) {
  const out = [];
  tasks.forEach((task) => {
    const stints = (task.assignments || [])
      .filter((a) => a.assigned_at)
      .slice()
      .sort((a, b) => new Date(a.assigned_at) - new Date(b.assigned_at));

    stints.forEach((s, i) => {
      if (s.outcome !== 'transferred_out' || !s.released_at) return;
      const next = stints[i + 1];
      out.push({
        key: `transfer-${s.id}`,
        at: s.released_at,
        symptom: task.symptom,
        kind_meta: task.kind_meta,
        from: s.garage,
        to: next?.garage || null,
        reason: s.reason || next?.reason || null,
        by: s.released_by_name || next?.assigned_by_name || null,
      });
    });
  });
  return out.sort((a, b) => new Date(a.at) - new Date(b.at));
}

function ClockTile({ label, value, hint, tone = 'slate' }) {
  const tones = { slate: 'bg-slate-50', amber: 'bg-amber-50', rose: 'bg-rose-50' };
  return (
    <div className={`min-w-[120px] rounded-2xl p-3 ${tones[tone]}`}>
      <p className="text-[11px] uppercase tracking-wide text-slate-400">{label}</p>
      <p className="font-display text-xl font-bold tabular-nums text-slate-900">{value}</p>
      {hint && <p className="mt-0.5 text-[11px] text-slate-400">{hint}</p>}
    </div>
  );
}

// "How long it took" — the ticket's own clock, its stage-by-stage walk, and every checkpoint the
// supervisor filed. Read together they answer the only question that matters when a bill lands late:
// where did the days go, and did anyone say why.
function TimePanel({ ticket, checkpoints, checkpointsLoading, canSeeCheckpoints }) {
  const { t, lang } = useI18n();
  const vocab = useCheckpointVocab();
  const [open, setOpen] = useState(false);

  const timing = ticket.stage_timing || {};
  const durations = timing.durations || {};

  // The stage walk: every milestone that actually has a stamp, PLUS every garage-to-garage move, in one
  // ordered story. The moves have to be merged in rather than listed apart — a timeline that jumps from
  // "arrived at garage" to "garage finished" while the car quietly changed workshops in between is not a
  // record of what happened.
  const steps = useMemo(() => {
    const handoffs = ticket.handoffs || {};
    const stamped = MILESTONES
      .map((m) => ({ ...m, stamp: handoffs[m.key], at: handoffs[m.key]?.at }))
      .filter((m) => m.at);

    const moves = buildTransferEvents(ticket.tasks || []).map((mv) => ({
      key: mv.key,
      isTransfer: true,
      at: mv.at,
      move: mv,
    }));

    const all = [...stamped, ...moves].sort((a, b) => new Date(a.at) - new Date(b.at));
    return all.map((s, i) => {
      const prev = all[i - 1];
      const gap = prev ? (new Date(s.at) - new Date(prev.at)) / 1000 : null;
      return { ...s, gapSeconds: gap != null && gap >= 0 ? gap : null };
    });
  }, [ticket]);

  // Where the longest single wait was — the step worth explaining first.
  const slowest = steps.reduce((worst, s) => (s.gapSeconds != null && (!worst || s.gapSeconds > worst.gapSeconds) ? s : worst), null);

  // Every garage the car actually sat in, and every move between them.
  const visits = useMemo(() => buildGarageVisits(ticket.tasks || [], ticket), [ticket]);
  const inGarages = visits.reduce((a, v) => a + v.seconds, 0);
  const inTransfer = visits.reduce((a, v) => a + (v.transferSeconds || 0), 0);
  // Only claim a move duration when every move on this ticket carries a stamped arrival.
  const movesMeasured = visits.length > 1 && !visits.some((v) => v.transferUnmeasured);
  const anyFromDispatch = visits.some((v) => v.fromDispatch);

  const rescheduled = (checkpoints || []).filter((c) => c.response === 'rescheduled');

  return (
    <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-card">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <ColumnHead icon={<Icon.Clock className="h-4 w-4" />} title={t('How long it took')} />
        <div className="flex flex-wrap gap-2.5">
          <ClockTile
            label={t('Total in maintenance')}
            value={fmtDuration(durations.total_downtime)}
            hint={t('test drive → back with us')}
          />
          <ClockTile label={t('Test drive')} value={fmtDuration(durations.test_drive)} hint={t('request → picked up')} />
          {/* With a transfer in the story the ticket's own "at garage" stamp only covers the LAST shop, so
              the tile reports the stint-derived total across every garage and says so. */}
          {visits.length > 1 ? (
            <ClockTile
              label={t('In garages')}
              value={fmtDuration(inGarages)}
              hint={anyFromDispatch
                ? t('across {n} garages · from dispatch', { n: visits.length })
                : t('across {n} garages', { n: visits.length })}
            />
          ) : (
            <ClockTile label={t('At the garage')} value={fmtDuration(durations.at_garage)} hint={t('arrival → returned')} />
          )}
          {/* Shown only when every move has a stamped arrival to measure against. Where the arrivals were
              never recorded the tile is absent rather than reporting a zero it invented. */}
          {movesMeasured && (
            <ClockTile
              label={t('Moving between garages')}
              tone={inTransfer > 0 ? 'amber' : 'slate'}
              value={fmtDuration(inTransfer)}
              hint={t('{n} transfers', { n: visits.length - 1 })}
            />
          )}
          {ticket.seconds_in_stage != null && (
            <ClockTile
              label={t('In this stage now')}
              tone={ticket.invoice_overdue ? 'rose' : 'slate'}
              value={fmtDuration(ticket.seconds_in_stage)}
              hint={ticket.status_label || ticket.workflow_status}
            />
          )}
        </div>
      </div>

      {/* Stage-by-stage. Folded by default — the tiles answer "how long"; this answers "where did it go". */}
      {steps.length > 0 && (
        <div className="mt-4">
          <button
            type="button"
            onClick={() => setOpen((v) => !v)}
            className="focus-ring-self inline-flex items-center gap-1.5 text-xs font-semibold text-indigo-600 hover:underline"
          >
            <Icon.ChevronDown className={`h-3.5 w-3.5 transition-transform ${open ? 'rotate-180' : ''}`} />
            {open ? t('Hide the stage-by-stage') : t('Show the stage-by-stage')}
            {slowest?.gapSeconds != null && !open && (
              <span className="ms-1 font-normal text-slate-400">
                {t('longest wait: {stage}, {time}', {
                  stage: slowest.isTransfer
                    ? t('Moved to {garage}', { garage: slowest.move.to || t('another garage') })
                    : t(slowest.label),
                  time: fmtDuration(slowest.gapSeconds),
                })}
              </span>
            )}
          </button>

          {open && (
            <ol className="mt-3 space-y-0">
              {steps.map((s, i) => (
                <li key={s.key} className="relative flex gap-3 ps-1">
                  {/* Spine */}
                  <div className="flex flex-col items-center">
                    <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${s.isTransfer
                      ? 'bg-amber-500'
                      : i === steps.length - 1 ? 'bg-emerald-500' : 'bg-indigo-400'}`}
                    />
                    {i < steps.length - 1 && <span className="w-px flex-1 bg-slate-200" />}
                  </div>
                  <div className="min-w-0 flex-1 pb-3">
                    <div className="flex flex-wrap items-baseline gap-x-2">
                      <span className={`text-sm font-medium ${s.isTransfer ? 'text-amber-700' : 'text-slate-700'}`}>
                        {s.isTransfer
                          ? t('Moved to {garage}', { garage: s.move.to || t('another garage') })
                          : t(s.label)}
                      </span>
                      {s.gapSeconds != null && (
                        <span className={`rounded-full px-1.5 py-0.5 text-[11px] font-semibold tabular-nums ${slowest?.key === s.key
                          ? 'bg-amber-50 text-amber-700'
                          : 'bg-slate-100 text-slate-500'}`}
                        >
                          +{fmtDuration(s.gapSeconds)}
                        </span>
                      )}
                    </div>

                    {s.isTransfer ? (
                      <>
                        {/* WHICH fault moved, and away from where — the two things a bare "transferred"
                            line leaves you guessing at. */}
                        <p className="text-[11px] text-amber-700">
                          <Icon.Truck className="me-1 inline h-3 w-3" />
                          {s.move.kind_meta?.emoji ? `${s.move.kind_meta.emoji} ` : ''}
                          {s.move.symptom}
                          {s.move.from ? ` · ${t('from {garage}', { garage: s.move.from })}` : ''}
                        </p>
                        <p className="text-[11px] text-slate-400">
                          {fmtDateTime(s.at)}
                          {s.move.by ? ` · ${s.move.by}` : ''}
                          {s.move.reason ? ` · ${s.move.reason}` : ''}
                        </p>
                      </>
                    ) : (
                      <p className="text-[11px] text-slate-400">
                        {fmtDateTime(s.at)}
                        {s.stamp.name ? ` · ${s.stamp.name}` : ''}
                        {s.stamp.garage ? ` · ${s.stamp.garage}` : ''}
                        {s.stamp.destination && !s.stamp.garage ? ` → ${s.stamp.destination}` : ''}
                      </p>
                    )}
                  </div>
                </li>
              ))}
            </ol>
          )}
        </div>
      )}

      {/* Garage by garage — where the car actually was, in order, with each move between shops shown as
          its own leg. A transfer is the one thing the ticket's own stamps cannot tell you. */}
      {visits.length > 0 && (
        <div className="mt-4 border-t border-slate-100 pt-3">
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('Garage by garage')}</p>
          <ol className="space-y-0">
            {visits.map((v, i) => (
              <li key={`${v.vendor_id}-${v.from}`}>
                {/* The move that got it here */}
                {(v.transferSeconds != null || v.transferUnmeasured) && (
                  <div className="flex items-center gap-2 py-1 ps-1 text-[11px] text-amber-700">
                    <Icon.Truck className="h-3.5 w-3.5" />
                    <span className="font-medium">{t('Transferred')}</span>
                    {v.transferSeconds != null ? (
                      <>
                        <span className="rounded-full bg-amber-50 px-1.5 py-0.5 font-semibold tabular-nums">
                          {fmtDuration(v.transferSeconds)}
                        </span>
                        {/* Which record timed the drive — the stint ledger, or the ticket's own
                            collected → checked-in stamps (which only cover the latest move). */}
                        <span className="text-slate-300">
                          {v.transferSource === 'ticket' ? t('collected → checked in') : t('left → arrived')}
                        </span>
                      </>
                    ) : (
                      <span className="text-slate-400">{t('recorded as one moment — the drive is not timed')}</span>
                    )}
                    {visits[i - 1]?.reason && <span className="text-slate-400">· {visits[i - 1].reason}</span>}
                  </div>
                )}
                <div className="flex gap-3">
                  <div className="flex flex-col items-center">
                    <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${v.isOpen ? 'bg-amber-500' : 'bg-indigo-400'}`} />
                    {i < visits.length - 1 && <span className="w-px flex-1 bg-slate-200" />}
                  </div>
                  <div className="min-w-0 flex-1 pb-3">
                    <div className="flex flex-wrap items-baseline gap-x-2">
                      <span className="text-sm font-medium text-slate-700">{v.name || t('No garage recorded')}</span>
                      <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-slate-600">
                        {fmtDuration(v.seconds)}
                      </span>
                      {v.isOpen && (
                        <span className="rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">{t('still there')}</span>
                      )}
                    </div>
                    <p className="text-[11px] text-slate-400">
                      {fmtDateTime(new Date(v.from).toISOString())} → {v.isOpen ? t('now') : fmtDateTime(new Date(v.to).toISOString())}
                      {/* Which clock this row used — arrival (time AT the garage) or dispatch (custody,
                          the drive included). Never left to the reader to assume. */}
                      <span className="ms-1 text-slate-300">
                        · {v.fromDispatch ? t('from dispatch') : t('from arrival')}
                      </span>
                    </p>
                    {/* Per FAULT, at THIS garage: its own time here and how it left. Two faults sent to the
                        same shop rarely leave together — one is fixed, the other is moved on — and a
                        single per-garage figure would hide exactly that. */}
                    <ul className="mt-1.5 space-y-1">
                      {v.items.map((it) => (
                        <li key={`${it.id}-${it.outcome}`} className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px]">
                          <span className="text-slate-600">
                            {it.kind_meta?.emoji && <span className="me-1 text-[9px]">{it.kind_meta.emoji}</span>}
                            {it.symptom}
                          </span>
                          <span className="rounded-full bg-slate-100 px-1.5 py-0.5 font-semibold tabular-nums text-slate-600">
                            {fmtDuration(it.seconds)}
                          </span>
                          <span className={`rounded-full px-1.5 py-0.5 font-medium ${OUTCOME_STYLE[it.outcome] || 'bg-slate-50 text-slate-500'}`}>
                            {it.isOpen ? t('still here') : t(OUTCOME_LABEL[it.outcome] || 'left this garage')}
                          </span>
                        </li>
                      ))}
                    </ul>
                  </div>
                </div>
              </li>
            ))}
          </ol>
        </div>
      )}

      {/* Checkpoints — the supervisor's daily answer to "is it still coming back when you said". This is
          where a late car explains itself; an empty list is itself the answer (nobody was asked, or
          nobody replied). */}
      {canSeeCheckpoints && (
        <div className="mt-4 border-t border-slate-100 pt-3">
          <div className="mb-2 flex flex-wrap items-center gap-2">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{t('Checkpoints')}</p>
            {rescheduled.length > 0 && (
              <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700">
                {t('date pushed back {n}×', { n: rescheduled.length })}
              </span>
            )}
          </div>

          {checkpointsLoading ? (
            <Skeleton className="h-16 rounded-xl" />
          ) : (checkpoints || []).length === 0 ? (
            <p className="text-[11px] text-slate-400">{t('No checkpoint was ever filed on this ticket — nothing on record explains the wait.')}</p>
          ) : (
            <div className="space-y-2">
              {checkpoints.map((c) => {
                const pushed = c.response === 'rescheduled';
                return (
                  <div key={c.id} className={`rounded-xl border p-2.5 ${pushed ? 'border-amber-200 bg-amber-50/50' : 'border-slate-200 bg-white'}`}>
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-[11px] font-semibold text-slate-600">{fmtDate(c.created_at, lang)}</span>
                      <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${pushed ? 'bg-amber-100 text-amber-700' : 'bg-emerald-50 text-emerald-700'}`}>
                        {vocab.responseMeta?.[c.response]?.label || c.response}
                      </span>
                      {c.delay_reason && (
                        <span className="text-[11px] text-slate-500">{vocab.delayReasonLabel(c.delay_reason)}</span>
                      )}
                      {c.submitted_by_name && <span className="text-[11px] text-slate-400">· {c.submitted_by_name}</span>}
                    </div>
                    {(c.summary || c.delay_reason_other) && (
                      <p className="mt-1 text-[11px] italic text-slate-600">“{c.summary || c.delay_reason_other}”</p>
                    )}
                    {pushed && c.previous_expected_date && c.next_expected_date && (
                      <p className="mt-1 text-[11px] tabular-nums text-amber-700">
                        {fmtDate(c.previous_expected_date, lang)} → {fmtDate(c.next_expected_date, lang)}
                      </p>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// A column heading with its icon in a tinted tile — the two halves of the desk read as a pair.
function ColumnHead({ icon, title, meta, tone = 'slate' }) {
  const tones = {
    slate: 'bg-slate-100 text-slate-500',
    indigo: 'bg-indigo-50 text-indigo-600',
  };
  return (
    <div className="flex flex-wrap items-center gap-2">
      <span className={`grid h-7 w-7 place-items-center rounded-lg ${tones[tone]}`}>{icon}</span>
      <h2 className="font-display text-sm font-bold uppercase tracking-wide text-slate-600">{title}</h2>
      {meta && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500">{meta}</span>}
    </div>
  );
}

/* ── page ─────────────────────────────────────────────────────────────────────────────────────── */

export default function InvoiceMatching() {
  const { t, lang } = useI18n();
  const { can } = usePermissions();
  const canManage = can('maintenance.manage');

  const [lane, setLane] = useState('open');       // open | no_invoice | partial | variance | matched | all
  const [q, setQ] = useState('');
  const [selectedId, setSelectedId] = useState(null);
  const [ticket, setTicket] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [activeInvoiceId, setActiveInvoiceId] = useState(null);
  const [activeGarageId, setActiveGarageId] = useState(null);
  const [addRequest, setAddRequest] = useState(null);
  const [checkpoints, setCheckpoints] = useState([]);   // the supervisor's daily "is it still on time" trail
  const [checkpointsLoading, setCheckpointsLoading] = useState(false);
  const [garageLinks, setGarageLinks] = useState([]);   // live per-garage portal links for this ticket
  const [linkBusy, setLinkBusy] = useState(null);
  const [copiedFor, setCopiedFor] = useState(null);
  const [garages, setGarages] = useState([]);
  const [findingsCatalog, setFindingsCatalog] = useState([]);

  const fetcher = useCallback(async () => (await api.get('/maintenance-tickets/invoice-matching')).data.data, []);
  const { data, loading, reload } = useFetch(fetcher, []);

  // Pickers for the invoice editor — the same two the ticket drawer feeds it.
  useEffect(() => {
    let alive = true;
    Promise.all([api.get('/Vendor'), api.get('/maintenance-tickets/findings-catalog')])
      .then(([g, f]) => {
        if (!alive) return;
        const glist = g.data?.data;
        const all = Array.isArray(glist) ? glist : glist?.items || [];
        const shops = all.filter((x) => x.type === 'garage');
        setGarages(shops.length ? shops : all);
        setFindingsCatalog(f.data?.data?.categories || []);
      })
      .catch(() => { /* pickers fall back to empty */ });
    return () => { alive = false; };
  }, []);

  const loadTicket = useCallback(async (id) => {
    if (!id) return;
    setDetailLoading(true);
    try {
      const res = await api.get(`/maintenance-tickets/${id}`);
      setTicket(res.data?.data || null);
    } catch {
      setTicket(null);
    } finally {
      setDetailLoading(false);
    }
  }, []);

  useEffect(() => { loadTicket(selectedId); }, [selectedId, loadTicket]);

  // The live per-garage portal links already issued on this ticket, so the desk offers "copy the link"
  // instead of minting a second one. Reading them is a manage-only endpoint; a read-only user simply
  // sees the slots without link actions.
  const loadLinks = useCallback(async (id) => {
    if (!id || !canManage) { setGarageLinks([]); return; }
    try {
      const res = await api.get(`/maintenance-tickets/${id}/garage-invoice-garages`);
      setGarageLinks(res.data?.data?.garages || []);
    } catch {
      setGarageLinks([]);
    }
  }, [canManage]);

  useEffect(() => { setCopiedFor(null); loadLinks(selectedId); }, [selectedId, loadLinks]);

  // Why a car was late is a question the checkpoint trail already answers — it just was not on this desk.
  // Read-only, its own endpoint, so a failure here never blocks the matching work.
  useEffect(() => {
    if (!selectedId) { setCheckpoints([]); return undefined; }
    let alive = true;
    setCheckpointsLoading(true);
    getTicketCheckpoints(selectedId)
      .then((d) => { if (alive) setCheckpoints(d?.checkpoints || []); })
      .catch(() => { if (alive) setCheckpoints([]); })
      .finally(() => { if (alive) setCheckpointsLoading(false); });
    return () => { alive = false; };
  }, [selectedId]);

  const summary = data?.summary || {};
  const rows = useMemo(() => {
    let r = data?.rows || [];
    if (lane === 'open') r = r.filter((x) => x.match_state !== 'matched');
    else if (lane !== 'all') r = r.filter((x) => x.match_state === lane);
    const term = q.trim().toLowerCase();
    if (term) r = r.filter((x) => `${x.plate || ''} ${x.car || ''} ${(x.garages || []).join(' ')}`.toLowerCase().includes(term));
    return r;
  }, [data, lane, q]);

  // Pick the top of the queue on first paint so the desk opens on work, not on an empty panel.
  useEffect(() => {
    if (!selectedId && rows.length > 0) setSelectedId(rows[0].ticket_id);
  }, [rows, selectedId]);

  const selectedRow = (data?.rows || []).find((r) => r.ticket_id === selectedId) || null;

  // work id → the invoice that covers it. Read straight off each bill's task_ids; a work item belongs to
  // exactly one bill, so there is no ambiguity to resolve here.
  const invoiceByFault = useMemo(() => {
    const map = {};
    (ticket?.invoices || []).forEach((inv) => (inv.task_ids || []).forEach((id) => { map[id] = inv; }));
    return map;
  }, [ticket]);

  const faults = useMemo(() => ticket?.tasks || [], [ticket]);
  // "2 faults · 1 service" — each kind counted in its own words. Lumping them under one noun is how a
  // planned oil change ends up reported as a breakdown.
  const kindCounts = KIND_ORDER
    .map((k) => ({ k, n: faults.filter((f) => kindOf(f) === k).length }))
    .filter((x) => x.n > 0)
    .map(({ k, n }) => (n === 1 ? `1 ${t(KIND_ONE[k])}` : t(KIND_COUNT[k], { n })))
    .join(' · ');

  const billable = faults.filter((f) => !NON_REPAIR.includes(f.status) && !f.is_incorrect);
  const unbilled = billable.filter((f) => !invoiceByFault[f.id]);
  const invoices = ticket?.invoices || [];
  const invoicedTotal = invoices.reduce((a, i) => a + Number(i.amount || 0), 0);
  const receiptTotal = invoices.reduce((a, i) => a + Number(i.receipt_total || 0), 0);
  const varianceTotal = invoices.reduce((a, i) => a + Number(i.variance || 0), 0);

  // ONE SLOT PER GARAGE. The work is grouped by the garage that did it (a work item's own current garage,
  // falling back to the ticket's), and each garage's bills are grouped beside it — so "this garage's
  // work" and "this garage's bill" are read together and can never be keyed against each other.
  const garageSlots = useMemo(() => {
    const map = new Map();
    const slotFor = (key, vendorId, name) => {
      if (!map.has(key)) map.set(key, { key, vendor_id: vendorId, name, items: [], unbilled: [], invoices: [] });
      const slot = map.get(key);
      if (!slot.name && name) slot.name = name;
      return slot;
    };

    faults.forEach((f) => {
      const vendorId = f.current_vendor_id || ticket?.vendor_id || null;
      const slot = slotFor(vendorId ? `v${vendorId}` : 'none', vendorId, f.current_garage || ticket?.garage || null);
      slot.items.push(f);
      const repairable = !NON_REPAIR.includes(f.status) && !f.is_incorrect;
      if (repairable && !invoiceByFault[f.id]) slot.unbilled.push(f);
    });

    // A bill from a garage with no work attached still has to be visible — otherwise money could sit on
    // the ticket with nothing on screen to explain it.
    (ticket?.invoices || []).forEach((inv) => {
      const key = inv.is_internal ? 'internal' : inv.vendor_id ? `v${inv.vendor_id}` : 'none';
      slotFor(key, inv.is_internal ? null : inv.vendor_id, inv.vendor_name || null).invoices.push(inv);
    });

    // Attach whichever tokenised link is already live for each garage.
    map.forEach((slot) => {
      slot.link = garageLinks.find((g) => String(g.vendor_id) === String(slot.vendor_id))?.link || null;
    });

    return [...map.values()].sort((a, b) => (a.name || '').localeCompare(b.name || ''));
  }, [faults, ticket, invoiceByFault, garageLinks]);

  const afterWrite = async () => {
    await Promise.all([loadTicket(selectedId), reload({ silent: true }), loadLinks(selectedId)]);
  };

  // Open the invoice form with ONLY this garage's unbilled work ticked — the form then names the garage
  // itself, so a bill can never be keyed against work another garage did.
  const createBillFor = (slot) => setAddRequest({ nonce: Date.now(), taskIds: slot.unbilled.map((f) => f.id) });

  const issueLink = async (slot) => {
    setLinkBusy(slot.vendor_id);
    try {
      await api.post(`/maintenance-tickets/${selectedId}/garage-invoice-link`, { vendor_id: slot.vendor_id });
      await loadLinks(selectedId);
    } catch { /* the slot simply keeps offering the button */ }
    setLinkBusy(null);
  };

  const copyLink = async (slot) => {
    if (!slot.link?.path) return;
    try {
      await navigator.clipboard.writeText(`${window.location.origin}${slot.link.path}`);
      setCopiedFor(slot.vendor_id);
    } catch { /* clipboard blocked — the link stays visible on the ticket */ }
  };

  // Hovering a garage slot lights up that garage's work in the left column — the same cross-link the
  // bills themselves have, one level up.
  const highlightedByGarage = (f) => (activeGarageId
    ? String(f.current_vendor_id || ticket?.vendor_id || '') === String(activeGarageId)
    : false);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1560px] space-y-5 px-4 sm:px-6 lg:px-8">

        {/* ── Hero: what the desk is, how much of it is done, and the lanes ── */}
        <div className="relative overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-card">
          {/* A soft brand wash, purely atmospheric — no data lives in it. */}
          <div className="pointer-events-none absolute -end-24 -top-24 h-64 w-64 rounded-full bg-indigo-500/10 blur-3xl" />
          <div className="pointer-events-none absolute -bottom-32 start-1/3 h-64 w-64 rounded-full bg-emerald-500/10 blur-3xl" />

          <div className="relative flex flex-wrap items-start justify-between gap-6">
            <div className="min-w-0 max-w-2xl">
              <p className="mb-1 inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-500">
                <Icon.Invoice className="h-3.5 w-3.5" />{t('Maintenance')}
              </p>
              <h1 className="font-display text-3xl font-bold tracking-tight text-slate-900">{t('Invoice Matching')}</h1>
              <p className="mt-1.5 text-sm leading-relaxed text-slate-500">
                {t('The car is back — key each garage’s bill and check it against the work that was actually done. A car worked in two garages comes back with two bills; each covers only the work its own garage did.')}
              </p>
            </div>

            <div className="flex items-center gap-6">
              <MatchRing matched={summary.matched ?? 0} total={summary.total ?? 0} />
              <div className="hidden border-s border-slate-200 ps-6 sm:block">
                <p className="text-[11px] uppercase tracking-wide text-slate-400">{t('Bills keyed')}</p>
                <p className="font-display text-2xl font-bold tabular-nums text-slate-900">{compactMoney(summary.invoiced_amount)}</p>
                {summary.overdue > 0 && (
                  <p className="mt-1 inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-600">
                    <Icon.Alert className="h-3 w-3" />{t('{n} past SLA', { n: summary.overdue })}
                  </p>
                )}
              </div>
            </div>
          </div>

          <div className="relative mt-5 flex flex-wrap gap-2">
            <LanePill label={t('Cars back')} value={summary.total ?? 0} active={lane === 'all'} onClick={() => setLane('all')} />
            <LanePill label={t('No bill yet')} tone="rose" value={summary.no_invoice ?? 0} active={lane === 'no_invoice'} onClick={() => setLane('no_invoice')} />
            <LanePill label={t('Work not billed')} tone="amber" value={summary.partial ?? 0} active={lane === 'partial'} onClick={() => setLane('partial')} />
            <LanePill label={t('Receipt off')} tone="cyan" value={summary.variance ?? 0} active={lane === 'variance'} onClick={() => setLane('variance')} />
            <LanePill label={t('Matched')} tone="emerald" value={summary.matched ?? 0} active={lane === 'matched'} onClick={() => setLane('matched')} />
          </div>
        </div>

        <div className="grid grid-cols-1 gap-5 xl:grid-cols-[340px_minmax(0,1fr)]">

          {/* ── Queue rail ── */}
          <aside className="space-y-3">
            <div className="flex items-center gap-2">
              <div className="relative flex-1">
                <Icon.Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                  value={q}
                  onChange={(e) => setQ(e.target.value)}
                  placeholder={t('Plate, car or garage')}
                  className="w-full rounded-xl border border-slate-200 bg-white py-2.5 ps-9 pe-3 text-sm shadow-soft outline-none transition focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100"
                />
              </div>
              <button
                type="button"
                onClick={() => setLane(lane === 'open' ? 'all' : 'open')}
                className={`focus-ring-self whitespace-nowrap rounded-xl px-3 py-2.5 text-xs font-semibold shadow-soft transition ${lane === 'open' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'}`}
              >
                {t('Needs work')}
              </button>
            </div>

            {loading ? (
              <div className="space-y-2">
                {Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-28 rounded-2xl" />)}
              </div>
            ) : rows.length === 0 ? (
              <div className="rounded-2xl border border-dashed border-slate-200 bg-white py-14 text-center">
                <span className="mx-auto grid h-12 w-12 place-items-center rounded-full bg-emerald-50">
                  <Icon.Check className="h-6 w-6 text-emerald-500" />
                </span>
                <p className="mt-3 text-sm font-medium text-slate-700">{t('Nothing waiting')}</p>
                <p className="text-xs text-slate-400">{t('Every car that came back has its bills matched.')}</p>
              </div>
            ) : (
              <div className="stagger max-h-[calc(100vh-260px)] space-y-2 overflow-y-auto pe-1">
                {rows.map((r) => (
                  <QueueRow key={r.ticket_id} row={r} active={r.ticket_id === selectedId} onSelect={setSelectedId} />
                ))}
              </div>
            )}
          </aside>

          {/* ── Workspace ── */}
          <section className="space-y-5">
            {!selectedId ? (
              <div className="flex min-h-[420px] flex-col items-center justify-center rounded-3xl border border-dashed border-slate-200 bg-white text-center">
                <span className="grid h-16 w-16 place-items-center rounded-2xl bg-slate-100">
                  <Icon.Invoice className="h-8 w-8 text-slate-400" />
                </span>
                <p className="mt-4 font-display text-base font-semibold text-slate-700">{t('Pick a car to match')}</p>
                <p className="mt-0.5 text-xs text-slate-400">{t('Its work and its bills open side by side.')}</p>
              </div>
            ) : detailLoading && !ticket ? (
              <Skeleton className="h-[480px] rounded-3xl" />
            ) : !ticket ? (
              <div className="rounded-3xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">{t('Could not load this ticket.')}</div>
            ) : (
              <div className="animate-fade-in-up space-y-5" key={ticket.id}>

                {/* Car + the match verdict, in one strip */}
                <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-card">
                  <div className="flex flex-wrap items-start justify-between gap-5">
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <Link to={`/maintenance-workflow/${ticket.id}`} className="focus-ring-self transition hover:opacity-80">
                          <PlateTag plate={ticket.plate || `#${ticket.id}`} size="lg" />
                        </Link>
                        {selectedRow && <StateChip state={selectedRow.match_state} />}
                        {selectedRow?.sla_overdue && <Badge tone="red">{t('Past SLA')}</Badge>}
                      </div>
                      <p className="mt-2 text-sm font-medium text-slate-600">{selectedRow?.car}</p>
                      <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                        {(selectedRow?.garages || []).map((g) => (
                          <span key={g} className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">
                            <Icon.Wrench className="h-3 w-3 text-slate-400" />{g}
                          </span>
                        ))}
                      </div>
                      <p className="mt-2 text-[11px] text-slate-400">
                        {t('Back {date}', { date: fmtDate(selectedRow?.back_at, lang) })}
                        {selectedRow?.days_back != null ? ` · ${t('{n} days ago', { n: selectedRow.days_back })}` : ''}
                      </p>
                      {/* The maintenance contract this shop visit sits under — every bill keyed here belongs
                          to that visit, so the paper trail runs invoice → ticket → contract → car. */}
                      {ticket.linked_contract_no && (
                        <p className="mt-1 text-[11px]">
                          <span className="text-slate-400">{t('Maintenance contract')}: </span>
                          <Link to={`/contracts/${ticket.linked_contract_id}`} className="font-mono font-semibold text-indigo-600 hover:underline">
                            {ticket.linked_contract_no}
                          </Link>
                        </p>
                      )}
                    </div>

                    {/* The three numbers that decide whether this ticket is done with. */}
                    <div className="flex flex-wrap items-stretch gap-3">
                      <div className="min-w-[132px] rounded-2xl bg-slate-50 p-3">
                        <p className="text-[11px] uppercase tracking-wide text-slate-400">{t('Work billed')}</p>
                        <p className="font-display text-2xl font-bold tabular-nums text-slate-900">
                          {billable.length - unbilled.length}<span className="text-slate-300">/{billable.length}</span>
                        </p>
                        <div className="mt-1.5"><CoverageBar billed={billable.length - unbilled.length} total={billable.length} /></div>
                      </div>
                      <div className="min-w-[132px] rounded-2xl bg-slate-50 p-3">
                        <p className="text-[11px] uppercase tracking-wide text-slate-400">{t('Bills keyed')}</p>
                        <p className="font-display text-2xl font-bold tabular-nums text-slate-900">{compactMoney(invoicedTotal)}</p>
                        <p className="mt-1 text-[11px] text-slate-400">{t('{n} invoices', { n: invoices.length })}</p>
                      </div>
                      <div className={`min-w-[132px] rounded-2xl p-3 ${Math.abs(varianceTotal) > 0.01 ? 'bg-cyan-50' : 'bg-slate-50'}`}>
                        <p className="text-[11px] uppercase tracking-wide text-slate-400">{t('Against receipts')}</p>
                        <p className={`font-display text-2xl font-bold tabular-nums ${Math.abs(varianceTotal) > 0.01 ? 'text-cyan-600' : 'text-slate-900'}`}>
                          {receiptTotal > 0 ? compactMoney(receiptTotal) : '—'}
                        </p>
                        <p className="mt-1 text-[11px] text-slate-400">
                          {Math.abs(varianceTotal) > 0.01
                            ? t('off by {amount}', { amount: money(Math.abs(varianceTotal)) })
                            : receiptTotal > 0 ? t('agrees') : t('no receipt keyed')}
                        </p>
                      </div>
                    </div>
                  </div>

                  {unbilled.length > 0 && (
                    <p className="mt-4 rounded-xl bg-amber-50 px-3.5 py-2.5 text-xs text-amber-800 ring-1 ring-inset ring-amber-600/15">
                      <Icon.Alert className="me-1 inline h-3.5 w-3.5" />
                      {t(unbilled.length === 1 ? '1 work item is on no bill: {list}' : '{n} work items are on no bill: {list}', {
                        n: unbilled.length,
                        list: unbilled.slice(0, 3).map((f) => f.symptom).join(', ') + (unbilled.length > 3 ? '…' : ''),
                      })}
                    </p>
                  )}
                </div>

                {/* Where the days went — the ticket's clock, its stage walk, and the checkpoint trail. */}
                <TimePanel
                  ticket={ticket}
                  checkpoints={checkpoints}
                  checkpointsLoading={checkpointsLoading}
                  canSeeCheckpoints
                />

                {/* ── One bill slot per garage ──
                    Two garages worked this car → two bills, and each garage bills only its own work.
                    Each slot keys that garage's bill (with only its work pre-ticked) or hands the garage
                    its own link to submit the invoice itself. */}
                {garageSlots.length > 0 && (
                  <div className="space-y-2.5">
                    <ColumnHead
                      icon={<Icon.Invoice className="h-4 w-4" />}
                      tone="indigo"
                      title={t('A bill per garage')}
                      meta={garageSlots.length === 1
                        ? t('1 garage worked this car')
                        : t('{n} garages worked this car', { n: garageSlots.length })}
                    />
                    <div className="stagger grid grid-cols-1 gap-2.5 md:grid-cols-2">
                      {garageSlots.map((slot) => (
                        <GarageSlot
                          key={slot.key}
                          slot={slot}
                          canManage={canManage}
                          onCreateBill={createBillFor}
                          onIssueLink={issueLink}
                          linkBusy={linkBusy}
                          copiedFor={copiedFor}
                          onCopy={copyLink}
                          onHover={(s) => setActiveGarageId(s?.vendor_id ?? null)}
                          active={!!activeGarageId && String(activeGarageId) === String(slot.vendor_id)}
                        />
                      ))}
                    </div>
                  </div>
                )}

                {/* Work ⟷ Paper, side by side */}
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">

                  {/* WHAT WE DID */}
                  <div className="space-y-3">
                    <ColumnHead
                      icon={<Icon.Wrench className="h-4 w-4" />}
                      title={t('What we did to the car')}
                      /* Counted by kind — "2 faults · 1 service", never "3 faults". */
                      meta={kindCounts}
                    />
                    {faults.length === 0 ? (
                      <div className="rounded-2xl border border-dashed border-slate-200 px-4 py-10 text-center text-sm text-slate-400">
                        {t('No work recorded on this ticket.')}
                      </div>
                    ) : (
                      <div className="space-y-4">
                        {KIND_ORDER.filter((k) => faults.some((f) => kindOf(f) === k)).map((kind) => (
                          <div key={kind} className="space-y-2.5">
                            <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t(KIND_LABEL[kind])}</p>
                            {faults.filter((f) => kindOf(f) === kind).map((f) => (
                              <WorkCard
                                key={f.id}
                                fault={f}
                                invoice={invoiceByFault[f.id]}
                                highlighted={(!!activeInvoiceId && invoiceByFault[f.id]?.id === activeInvoiceId) || highlightedByGarage(f)}
                                dimmed={(!!activeInvoiceId && invoiceByFault[f.id]?.id !== activeInvoiceId)
                                  || (!!activeGarageId && !highlightedByGarage(f))}
                                onHover={setActiveInvoiceId}
                              />
                            ))}
                          </div>
                        ))}
                      </div>
                    )}
                  </div>

                  {/* THE PAPER */}
                  <div className="space-y-3">
                    <ColumnHead
                      icon={<Icon.Invoice className="h-4 w-4" />}
                      tone="indigo"
                      title={t('What we were billed')}
                      meta={invoices.length > 0 ? money(invoicedTotal) : null}
                    />
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-soft">
                      <InvoicesPanel
                        ticket={ticket}
                        garages={garages}
                        findingsCatalog={findingsCatalog}
                        canManage={canManage}
                        onChanged={afterWrite}
                        activeInvoiceId={activeInvoiceId}
                        onHoverInvoice={(inv) => setActiveInvoiceId(inv?.id || null)}
                        addRequest={addRequest}
                      />
                    </div>
                    <ChargedLines invoices={invoices} activeInvoiceId={activeInvoiceId} onHover={setActiveInvoiceId} />
                  </div>
                </div>
              </div>
            )}
          </section>
        </div>
      </div>
    </div>
  );
}
