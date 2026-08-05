// THE PROOF BEHIND A NUMBER.
//
// This platform grades suppliers, and the first time a score goes against a garage somebody will
// dispute it. The only acceptable answer is the repairs themselves — two tickets, two dates, the gap
// between them — on screen, now, not in a follow-up email.
//
// CLAIM → METHOD → ROWS, in that order, because that is the order a sceptical reader needs them.
// The claim restates what was asserted so the drawer stands alone. The method comes BEFORE the table
// because fifty rows mean nothing until you know what was counted. The technical note is collapsed
// so the operator-facing explanation is not diluted by engine vocabulary.
//
// Everything here is supplied by the backend. The component renders an evidence payload; it never
// knows which metric produced it, which is what lets one drawer serve every figure on the platform.

import { useState } from 'react';
import Drawer from '../ui/Drawer';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import { useI18n } from '../../i18n/I18nContext';

// Four words, three colours. `came back later` is a real comeback — the fault returned — but outside
// the window, so it is not charged to this garage. Grey says exactly that: it happened, it is not
// being counted. Colouring it amber would imply a penalty the number never applied; leaving it green
// alongside "held" is what made a 253-day return read as a repair that lasted.
const OUTCOME_TONE = {
  held: 'green',
  'came back': 'amber',
  'back within a month': 'red',
  'came back later': 'gray',
};

function OutcomeChip({ outcome, tf }) {
  if (!outcome) return null;
  // Colour is never the only carrier — the word is always printed, so the table reads in greyscale
  // and to a colourblind reader.
  //
  // tf, NOT t. This read `t(key, outcome)` for a long time, which LOOKS like "key, fallback" and is
  // actually "key, interpolation vars" — t() renders the raw key when it misses. So the day the
  // backend learned a new outcome word, the table printed
  // `intelligence.evidence.outcome.came_back_later` at a user instead of the English the backend had
  // already sent. tf() is the one that takes a fallback, and the outcome string IS English, so an
  // unknown label now degrades to a readable word rather than to engine vocabulary.
  return (
    <Badge tone={OUTCOME_TONE[outcome] || 'gray'} dot>
      {tf(`intelligence.evidence.outcome.${outcome.replace(/ /g, '_')}`, outcome)}
    </Badge>
  );
}

export default function EvidenceDrawer({
  open, onClose, evidence, loading, error, page, onPage,
  tabs = [], activeId, onTab,
}) {
  const { t, tf } = useI18n();
  const e = (k, v) => t(`intelligence.evidence.${k}`, v);
  const [showTechnical, setShowTechnical] = useState(false);

  const meta = evidence?.meta;
  const lastPage = meta?.last_page ?? 1;

  // Which table shape to draw. Read off the PAYLOAD, not off the active tab: the drawer renders
  // whatever the evidence layer returned, so a claim gets the right columns whether it was reached
  // through a tab, a deep link, or a card that knows nothing about tabs.
  const isServices = Array.isArray(evidence?.columns) && evidence.columns.includes('due_every');

  return (
    <Drawer
      open={open}
      onClose={onClose}
      eyebrow={e('eyebrow')}
      title={e('title')}
      subtitle={meta ? e('subtitle', { total: meta.total }) : undefined}
      width="wide"
    >
      {/* 0 · THE TABS — outside the loading gate, so switching does not make the drawer look like it
          closed and reopened. The bar stays put and only the panel below it swaps. */}
      {tabs.length > 1 && (
        <div className="mb-4 flex gap-1 rounded-xl bg-slate-100 p-1" role="tablist">
          {tabs.map((tab) => {
            const selected = tab.id === activeId;
            return (
              <button
                key={tab.id}
                type="button"
                role="tab"
                aria-selected={selected}
                onClick={() => onTab?.(tab.id)}
                className={`flex-1 rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                  selected ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                }`}
              >
                {e(`tab.${tab.key}`, tab.key)}
              </button>
            );
          })}
        </div>
      )}

      {loading && (
        <div className="space-y-3">
          <div className="h-5 w-3/4 animate-pulse rounded bg-slate-100" />
          <div className="h-16 animate-pulse rounded-xl bg-slate-100" />
          <div className="h-40 animate-pulse rounded-xl bg-slate-100" />
        </div>
      )}

      {!loading && error && (
        <div className="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800 ring-1 ring-inset ring-rose-200">
          <p className="font-semibold">{e('error.title')}</p>
          <p className="mt-1 text-rose-700">{error}</p>
        </div>
      )}

      {!loading && !error && evidence && (
        <div className="space-y-5">
          {/* 1 · THE CLAIM — restated, so a forwarded link stands on its own. */}
          <p className="rounded-xl bg-slate-900 px-4 py-3 text-sm font-medium leading-relaxed text-white">
            {evidence.claim}
          </p>

          {/* 2 · THE METHOD — before the table, in language an operator can check against memory. */}
          <div className="rounded-xl bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-600 ring-1 ring-inset ring-slate-200">
            <p className="mb-1 font-semibold text-slate-700">{e('method')}</p>
            <p>{evidence.method}</p>

            {evidence.technical_note && (
              <>
                <button
                  type="button"
                  onClick={() => setShowTechnical((v) => !v)}
                  className="mt-2 inline-flex items-center gap-1 text-[11px] font-semibold text-slate-500 hover:text-slate-700"
                  aria-expanded={showTechnical}
                >
                  <Icon.ChevronDown className={`h-3 w-3 transition-transform ${showTechnical ? 'rotate-180' : ''}`} />
                  {e('technical')}
                </button>
                {showTechnical && (
                  <p className="mt-2 border-t border-slate-200 pt-2 font-mono text-[11px] leading-relaxed text-slate-500">
                    {evidence.technical_note}
                  </p>
                )}
              </>
            )}
          </div>

          {/* 3 · THE ROWS — the repairs themselves, or the scheduled work. */}
          {evidence.rows?.length && isServices ? (
            <div className="overflow-x-auto rounded-xl ring-1 ring-inset ring-slate-200">
              <table className="w-full min-w-[44rem] text-xs">
                <thead className="bg-slate-50">
                  <tr className="text-[11px] uppercase tracking-wide text-slate-400">
                    <th className="px-3 py-2 text-start font-semibold">{e('col.vehicle')}</th>
                    <th className="px-3 py-2 text-start font-semibold">{e('col.service')}</th>
                    <th className="px-3 py-2 text-start font-semibold">{e('col.doneOn')}</th>
                    <th className="px-3 py-2 text-start font-semibold">{e('col.doneAgain')}</th>
                    <th className="px-3 py-2 text-end font-semibold">{e('col.days')}</th>
                    <th className="px-3 py-2 text-start font-semibold">{e('col.dueEvery')}</th>
                    <th className="px-3 py-2 text-start font-semibold">{e('col.garage')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {evidence.rows.map((r, i) => (
                    <tr key={`${r.ticket_id}-${i}`} className="hover:bg-slate-50/60">
                      <td className="px-3 py-2 font-medium text-slate-800">{r.vehicle}</td>
                      <td className="px-3 py-2 text-slate-600">{r.service}</td>
                      <td className="px-3 py-2 tabular-nums text-slate-600">{r.done_on}</td>
                      <td className="px-3 py-2 tabular-nums text-slate-600">{r.done_again_on || '—'}</td>
                      <td className="px-3 py-2 text-end tabular-nums text-slate-700">{r.days_between ?? '—'}</td>
                      {/* The cadence that governs THIS service, in whatever units govern it — km,
                          months, or both. What the car actually ran between services is NOT here:
                          the odometer is absent from this corpus, and a column of blanks would read
                          as a broken page rather than a gap worth closing. */}
                      <td className="px-3 py-2 text-slate-500">{r.due_every || '—'}</td>
                      <td className="px-3 py-2 text-slate-500">{r.garage || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : null}

          {evidence.rows?.length && !isServices ? (
            <div className="overflow-x-auto rounded-xl ring-1 ring-inset ring-slate-200">
              <table className="w-full min-w-[44rem] text-xs">
                <thead className="bg-slate-50">
                  <tr className="text-[11px] uppercase tracking-wide text-slate-400">
                    <th className="px-3 py-2 text-start font-semibold">{e('col.vehicle')}</th>
                    <th className="px-3 py-2 text-start font-semibold">{e('col.fault')}</th>
                    <th className="px-3 py-2 text-start font-semibold">{e('col.repaired')}</th>
                    <th className="px-3 py-2 text-start font-semibold">{e('col.cameBack')}</th>
                    <th className="px-3 py-2 text-end font-semibold">{e('col.days')}</th>
                    <th className="px-3 py-2 text-start font-semibold">{e('col.outcome')}</th>
                    <th className="px-3 py-2 text-start font-semibold">{e('col.wentTo')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {evidence.rows.map((r, i) => (
                    <tr key={`${r.ticket_id}-${i}`} className="hover:bg-slate-50/60">
                      <td className="px-3 py-2 font-medium text-slate-800">{r.vehicle}</td>
                      <td className="px-3 py-2 text-slate-600">{r.fault}</td>
                      <td className="px-3 py-2 tabular-nums text-slate-600">{r.repaired_on}</td>
                      <td className="px-3 py-2 tabular-nums text-slate-600">{r.came_back_on || '—'}</td>
                      <td className="px-3 py-2 text-end tabular-nums text-slate-700">{r.days_between ?? '—'}</td>
                      <td className="px-3 py-2"><OutcomeChip outcome={r.outcome} tf={tf} /></td>
                      <td className="px-3 py-2 text-slate-500">{r.went_back_to || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : null}

          {!evidence.rows?.length && (
            <p className="rounded-xl bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">{e('empty')}</p>
          )}

          {lastPage > 1 && (
            <div className="flex items-center justify-between text-xs text-slate-500">
              <button
                type="button"
                disabled={page <= 1}
                onClick={() => onPage(page - 1)}
                className="rounded-lg px-3 py-1.5 font-semibold ring-1 ring-inset ring-slate-200 disabled:opacity-40"
              >
                {e('prev')}
              </button>
              <span className="tabular-nums">{e('pageOf', { page, last: lastPage })}</span>
              <button
                type="button"
                disabled={page >= lastPage}
                onClick={() => onPage(page + 1)}
                className="rounded-lg px-3 py-1.5 font-semibold ring-1 ring-inset ring-slate-200 disabled:opacity-40"
              >
                {e('next')}
              </button>
            </div>
          )}
        </div>
      )}
    </Drawer>
  );
}
