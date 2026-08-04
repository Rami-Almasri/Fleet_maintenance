// Checkpoint Compliance (/oversight/checkpoint-compliance) — did the daily chase actually get answered?
//
// Every day a car is near or past the date it was promised back, the system reminds the responsible
// supervisor to confirm the date or give a new one with a reason. This board lists the cars where that
// reminder went out and NOTHING came back: who was notified, on which days, how long the silence has run,
// and — for context — every reason that car's date has already moved for. A row past the tolerated silence
// (one day by default) is flagged red.
//
// Read-only. The fix is filing the answer, so every row deep-links to the car's checkpoint form.
// Backed by GET /Oversight/checkpoint-compliance.

import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import Icon from '../../components/ui/Icon';
import { Skeleton } from '../../components/ui/Skeleton';
import { Card, PageHeader, SearchInput, EmptyState, ErrorState } from '../../components/ui/Misc';
import { getCheckpointCompliance, delayReasonLabel } from '../../lib/maintenanceCheckpoints';
import { fmtDate } from '../../lib/format';

// How loud the last ask was — the same ladder the scan fires on.
const LEVEL_META = {
  request:   { label: 'First ask',  chip: 'bg-sky-50 text-sky-700 ring-sky-200' },
  reminder:  { label: 'Reminder',   chip: 'bg-amber-50 text-amber-700 ring-amber-200' },
  due_today: { label: 'Due today',  chip: 'bg-orange-50 text-orange-700 ring-orange-200' },
  overdue:   { label: 'Overdue',    chip: 'bg-red-50 text-red-700 ring-red-200' },
};

const reasonText = (r) => (r.delay_reason === 'other'
  ? (r.reason_other || 'Other')
  : (delayReasonLabel(r.delay_reason) || '—'));

// Has this car's promised date actually shifted? Drives the struck-through "originally" line.
const moved = (r) => !!r.original_promised_on && r.original_promised_on !== r.current_promised_on;

function Stat({ value, label, tone = 'slate' }) {
  const colour = tone === 'red' ? 'text-red-600' : tone === 'emerald' ? 'text-emerald-600' : 'text-slate-900';
  return (
    <div className="rounded-2xl border border-slate-200/60 bg-white px-4 py-3 text-center shadow-soft">
      <p className={`text-2xl font-bold tabular-nums ${colour}`}>{value}</p>
      <p className="text-[11px] uppercase tracking-wide text-slate-400">{label}</p>
    </div>
  );
}

export default function CheckpointCompliance() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [q, setQ] = useState('');
  const [breachedOnly, setBreachedOnly] = useState(false);
  const [expanded, setExpanded] = useState(null);

  useEffect(() => {
    let alive = true;
    getCheckpointCompliance()
      .then((d) => { if (alive) setData(d); })
      .catch(() => { if (alive) setError(true); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  const rows = useMemo(() => {
    let r = data?.rows || [];
    if (breachedOnly) r = r.filter((x) => x.breached);
    const term = q.trim().toLowerCase();
    if (term) {
      r = r.filter((x) => `${x.plate_no || ''} ${x.car || ''} ${x.garage || ''} ${(x.notified || []).join(' ')}`
        .toLowerCase().includes(term));
    }
    return r;
  }, [data, breachedOnly, q]);

  const summary = data?.summary || {};
  const alertDays = data?.alert_days ?? 1;
  const unassigned = data?.unassigned || [];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1200px] space-y-6 px-4 sm:px-6 lg:px-8">
        <div>
          <Link to="/apps/reports" className="mb-2 inline-flex items-center gap-1 text-xs font-medium text-slate-400 transition-colors hover:text-slate-600">
            <Icon.ArrowRight className="h-3 w-3 rotate-180" /> Reports
          </Link>
          <PageHeader
            title="Checkpoint Compliance"
            subtitle="Cars whose supervisor was reminded the car is due back and never answered — no confirmation, no new date, no reason."
          >
            <div className="flex flex-wrap gap-3">
              <Stat value={summary.open ?? 0} label="Awaiting answer" />
              <Stat value={summary.breached ?? 0} label={`Silent ${alertDays}+ day`} tone="red" />
              <Stat value={summary.unassigned ?? 0} label="No owner" tone={summary.unassigned ? 'red' : 'slate'} />
              <Stat
                value={summary.response_rate == null ? '—' : `${summary.response_rate}%`}
                label="Reminders answered" tone="emerald"
              />
            </div>
          </PageHeader>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" checked={breachedOnly} onChange={(e) => setBreachedOnly(e.target.checked)}
                   className="h-4 w-4 rounded border-slate-300 text-red-600 focus:ring-red-500" />
            Only silent {alertDays}+ day
          </label>
          <SearchInput value={q} onChange={setQ} placeholder="Plate, garage or supervisor…" className="ms-auto w-72" />
        </div>

        {/* Cars needing a chase that NOBODY owns. The reminder is deliberately withheld rather than
            broadcast to every permission holder, so this list is the only place the gap shows up —
            it has to be loud, and it sits above the normal rows. */}
        {!loading && !error && unassigned.length > 0 && (
          <div className="rounded-2xl border border-red-200 bg-red-50/50 p-5">
            <h2 className="text-sm font-bold text-red-800">
              {unassigned.length} car(s) need a checkpoint but have no responsible owner
            </h2>
            <p className="mt-0.5 text-xs text-red-700">
              No reminder was sent for these — nobody is assigned to chase them. Open each car and set a
              responsible user, or configure the supervisor fallback.
            </p>
            <div className="mt-3 flex flex-wrap gap-2">
              {unassigned.map((u) => (
                <Link key={u.ticket_id} to={`/maintenance-progress?ticket=${u.ticket_id}`}
                      className="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-slate-700 ring-1 ring-red-200 hover:bg-red-50">
                  <span className="font-mono font-bold">{u.plate_no || `#${u.ticket_id}`}</span>
                  <span className="text-slate-400">
                    {u.expected_on ? fmtDate(u.expected_on) : 'no ETA'}
                    {u.days_over > 0 ? ` · ${u.days_over}d over` : ''}
                  </span>
                </Link>
              ))}
            </div>
          </div>
        )}

        {loading ? (
          <div className="space-y-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-32 rounded-2xl" />)}</div>
        ) : error ? (
          <Card><ErrorState /></Card>
        ) : rows.length === 0 ? (
          <Card>
            <EmptyState
              icon={<Icon.Check className="h-6 w-6 text-emerald-500" />}
              title="Every reminder has been answered"
              message="No supervisor is currently sitting on an unanswered checkpoint reminder."
            />
          </Card>
        ) : (
          <div className="stagger space-y-3">
            {rows.map((r) => {
              const level = LEVEL_META[r.last_level] || { label: r.last_level || '—', chip: 'bg-slate-50 text-slate-600 ring-slate-200' };
              const open = expanded === r.ticket_id;
              return (
                <div key={r.ticket_id}
                     className={`rounded-2xl border bg-white p-5 shadow-soft ${r.breached ? 'border-red-200' : 'border-slate-200/60'}`}>
                  <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
                    {/* Vehicle */}
                    <div className="sm:w-44 sm:flex-shrink-0">
                      <Link to={`/maintenance-progress?ticket=${r.ticket_id}`}
                            className="font-mono text-base font-bold text-slate-900 hover:text-indigo-600">
                        {r.plate_no || `#${r.ticket_id}`}
                      </Link>
                      {r.car && <p className="text-xs text-slate-400">{r.car}</p>}
                      {r.garage && <p className="mt-1 text-[11px] text-slate-400">{r.garage}</p>}
                    </div>

                    {/* The three dates, named. Collapsing them into one "promised back" reads as a
                        contradiction the moment a car has been rescheduled. */}
                    <div className="sm:w-56 sm:flex-shrink-0">
                      <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Promised date</p>
                      <dl className="space-y-0.5 text-[11px]">
                        <div className="flex justify-between gap-2">
                          <dt className="text-slate-400">Originally</dt>
                          <dd className={`font-medium ${moved(r) ? 'text-slate-400 line-through' : 'text-slate-700'}`}>
                            {r.original_promised_on ? fmtDate(r.original_promised_on) : '—'}
                          </dd>
                        </div>
                        <div className="flex justify-between gap-2">
                          <dt className="text-slate-500">Now</dt>
                          <dd className="font-semibold text-slate-900">
                            {r.current_promised_on ? fmtDate(r.current_promised_on) : '—'}
                          </dd>
                        </div>
                        {r.reminded_about_on && r.reminded_about_on !== r.current_promised_on && (
                          <div className="flex justify-between gap-2">
                            <dt className="text-slate-400">Chased about</dt>
                            <dd className="font-medium text-slate-600">{fmtDate(r.reminded_about_on)}</dd>
                          </div>
                        )}
                      </dl>
                      {r.last_rescheduled_at && (
                        <p className="mt-1 text-[11px] text-amber-700">
                          Last moved {fmtDate(r.last_rescheduled_at)}
                        </p>
                      )}
                    </div>

                    {/* The silence — the finding itself */}
                    <div className="sm:w-72 sm:flex-shrink-0">
                      <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Unanswered</p>
                      <p className={`text-lg font-bold ${r.breached ? 'text-red-600' : 'text-slate-900'}`}>
                        {r.days_unanswered === 0 ? 'Since today' : `${r.days_unanswered} day(s)`}
                      </p>
                      <div className="mt-1.5 flex flex-wrap items-center gap-2">
                        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ${level.chip}`}>
                          {level.label}
                        </span>
                        <span className="text-[11px] text-slate-400">
                          Reminded on {r.days_reminded} day(s) — {fmtDate(r.first_reminder_on)} → {fmtDate(r.last_reminder_on)}
                        </span>
                      </div>
                    </div>

                    {/* Who was told, and what this car has answered before */}
                    <div className="min-w-0 flex-1">
                      <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Notified</p>
                      <div className="flex flex-wrap gap-1.5">
                        {(r.notified || []).length === 0
                          ? <span className="text-xs text-slate-400">—</span>
                          : r.notified.map((n) => (
                              <span key={n} className="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-indigo-100">{n}</span>
                            ))}
                      </div>
                      <div className="mt-2 flex flex-wrap items-center gap-3 text-[11px] text-slate-500">
                        <span>{r.checkpoint_count} answer(s) on record</span>
                        <span className={r.reschedule_count > 0 ? 'font-semibold text-amber-700' : ''}>
                          Date moved {r.reschedule_count}×
                        </span>
                        {r.reasons?.length > 0 && (
                          <button type="button" onClick={() => setExpanded(open ? null : r.ticket_id)}
                                  className="font-medium text-indigo-600 hover:text-indigo-700">
                            {open ? 'Hide reason history' : 'Reason history'}
                          </button>
                        )}
                      </div>
                    </div>
                  </div>

                  {/* Every reason this car's date has moved for — newest first. */}
                  {open && r.reasons?.length > 0 && (
                    <ol className="mt-4 space-y-2 border-t border-slate-100 pt-4">
                      {r.reasons.map((x, i) => (
                        <li key={i} className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg bg-amber-50/60 px-3 py-2 text-xs ring-1 ring-amber-100">
                          <span className="font-semibold text-amber-800">{reasonText(x)}</span>
                          <span className="text-slate-500">
                            {x.previous_date ? `${fmtDate(x.previous_date)} → ` : ''}{fmtDate(x.next_date)}
                          </span>
                          <span className="ms-auto text-slate-400">{x.by || 'Unknown'} · {fmtDate(x.at)}</span>
                        </li>
                      ))}
                    </ol>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}
