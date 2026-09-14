import { useCallback, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useToast } from '../components/ui/Toast';
import { usePermissions } from '../hooks/usePermissions';
import { useI18n } from '../i18n/I18nContext';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Icon from '../components/ui/Icon';
import Modal from '../components/ui/Modal';
import { Card, PageHeader, TableSkeleton, EmptyState, ErrorState, SearchInput } from '../components/ui/Misc';
import { Input, Select, Textarea } from '../components/ui/Field';
import { num, fmtDate } from '../lib/format';
import AccidentContextBanner from '../components/accidents/AccidentContextBanner';
import AccidentProgress from '../components/accidents/AccidentProgress';
import AccidentDamagePicker from '../components/accidents/AccidentDamagePicker';

/**
 * ACCIDENT CASES — the board of what is waiting on somebody, and the file behind each crash.
 *
 * ── THE TILE ORDER IS AN ARGUMENT ──────────────────────────────────────────────────────────────
 *
 * WORK first, MONEY last. The four tiles at the top are things a person must do today: a police
 * report nobody has chased, a fault nobody has ruled on, an insurer who has gone quiet, a car
 * sitting out of the rental pool. The money below is the CONSEQUENCE of having done or not done
 * them. A board that opens with total accident spend tells a manager how bad last quarter was; one
 * that opens with four unchased police reports tells them what to do about this one.
 *
 * ── THE TILE MOST DASHBOARDS WOULD OMIT ────────────────────────────────────────────────────────
 *
 * "Police reports waived". It measures this feature's own guardrail being consciously set aside, and
 * it is exactly the number a system like this quietly leaves out. If it climbs, either the reports
 * are genuinely unobtainable — in which case the intake rule is wrong — or the waiver has become the
 * fast path. Both are things somebody needs to SEE rather than infer from a claim they lost later.
 *
 * ── THE DETAIL PAGE LEADS WITH WHO HAD THE CAR ─────────────────────────────────────────────────
 *
 * Not in a tab. @see AccidentContextBanner for why that placement is load-bearing rather than
 * decorative.
 *
 * Every gate on the page (`gates`) is computed SERVER-SIDE and rendered here verbatim. This page
 * never re-derives "the police report is missing" from raw fields — one authority decides what is
 * outstanding, and it is the same one that enforces the ladder.
 */

// TableSkeleton returns a bare <tbody>, so it is only valid inside a <table>. Every loading state on
// this page is standalone (there is no table for it to sit in yet), so it gets its own wrapper — a
// <tbody> dropped straight into a <div> is invalid HTML and React logs a hydration error for it.
const Skeleton = ({ cols, rows }) => (
  <table className="w-full"><TableSkeleton cols={cols} rows={rows} /></table>
);

const STAGE_TONE = {
  reported: 'red', awaiting_police: 'amber', assessment: 'orange', liability: 'violet',
  insurance: 'blue', repair: 'indigo', settlement: 'cyan', closed: 'gray',
};

const POLICE_TONE = { missing: 'red', recorded: 'amber', verified: 'green', bypassed: 'orange' };

const LIABILITY_TONE = {
  pending: 'amber', customer: 'red', other_party: 'green', company: 'orange',
  employee: 'orange', shared: 'violet', unknown: 'gray',
};

const CLAIM_TONE = {
  not_submitted: 'gray', preparing: 'slate', submitted: 'blue', under_review: 'blue',
  info_required: 'amber', approved: 'green', partially_approved: 'yellow',
  rejected: 'red', closed: 'gray',
};

const words = (s) => String(s || '').replace(/_/g, ' ');

// ─────────────────────────────────────────────────────────────────────────────────────────────
// THE BOARD
// ─────────────────────────────────────────────────────────────────────────────────────────────

function Tile({ label, value, tone = 'slate', hint, onClick, active }) {
  const Wrapper = onClick ? 'button' : 'div';
  return (
    <Wrapper
      {...(onClick ? { type: 'button', onClick, 'aria-pressed': !!active } : {})}
      className={`rounded-xl border p-3 text-start transition ${active
        ? 'border-rose-500 bg-rose-50 ring-1 ring-rose-500'
        : 'border-slate-200 bg-white'} ${onClick ? 'hover:border-slate-300 hover:bg-slate-50' : ''}`}
    >
      <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{label}</p>
      <p className={`mt-1 text-2xl font-bold ${tone === 'red' ? 'text-red-600' : tone === 'amber' ? 'text-amber-600' : 'text-slate-800'}`}>{value}</p>
      {hint && <p className="mt-0.5 text-[11px] leading-snug text-slate-400">{hint}</p>}
    </Wrapper>
  );
}

function Board() {
  const { t } = useI18n();
  const [params, setParams] = useSearchParams();
  const queue = params.get('queue') || '';
  const stage = params.get('stage') || '';
  const [q, setQ] = useState(params.get('q') || '');

  const dash = useFetch(() => api.get('/accidents/dashboard').then((r) => r.data.data), []);

  const list = useFetch(() => {
    const p = new URLSearchParams();
    if (queue) p.set('queue', queue);
    if (stage) p.set('stage', stage);
    if (q.trim()) p.set('q', q.trim());
    return api.get(`/accidents?${p.toString()}`).then((r) => r.data.data);
  }, [queue, stage, q]);

  const setQueue = (next) => {
    const p = new URLSearchParams(params);
    if (next && next !== queue) p.set('queue', next); else p.delete('queue');
    p.delete('stage');
    setParams(p);
  };

  const d = dash.data;
  const money = d?.money;

  return (
    <div className="space-y-5">
      <PageHeader
        title={t('Accidents')}
        subtitle={t('Every crash the fleet has had, and the four questions each one opens: who had the car, what the police wrote down, whose fault it was, and who pays.')}
      />

      {/* ── WORK ────────────────────────────────────────────────────────────────────────────── */}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Tile
          label={t('Awaiting police report')} value={num(d?.awaiting_police ?? 0)} tone="red"
          hint={t('The step that silently stalls a case for weeks')}
          onClick={() => setQueue('police')} active={queue === 'police'}
        />
        <Tile
          label={t('Liability undecided')} value={num(d?.awaiting_liability ?? 0)} tone="amber"
          hint={t('Nobody has ruled on who was at fault')}
          onClick={() => setQueue('liability')} active={queue === 'liability'}
        />
        <Tile
          label={t('With the insurer')} value={num(d?.awaiting_insurer ?? 0)}
          hint={d?.insurer_overdue ? t('{n} past the expected answer date', { n: d.insurer_overdue }) : t('Waiting on their answer')}
          onClick={() => setQueue('insurer')} active={queue === 'insurer'}
        />
        <Tile
          label={t('Cars held off hire')} value={num(d?.vehicles_restricted ?? 0)} tone="amber"
          hint={t('Blocked from rental by an unresolved accident')}
        />
      </div>

      {/* ── the guardrail's own failure measure, and the shape of the year ──────────────────── */}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Tile label={t('Open cases')} value={num(d?.open_cases ?? 0)} onClick={() => setQueue('')} active={!queue} />
        <Tile label={t('Happened on hire')} value={num(d?.on_rental ?? 0)} hint={t('A customer was driving')} onClick={() => setQueue('rental')} active={queue === 'rental'} />
        <Tile label={t('Under accident repair')} value={num(d?.under_repair ?? 0)} onClick={() => setQueue('repair')} active={queue === 'repair'} />
        <Tile
          label={t('Police reports waived')} value={num(d?.police_waived ?? 0)}
          hint={t('Requirement consciously set aside — each with a name and a reason')}
        />
      </div>

      {/* ── MONEY: phases kept apart, never added together ──────────────────────────────────── */}
      {money && (
        <Card>
          <div className="grid gap-4 p-4 sm:grid-cols-3 lg:grid-cols-5">
            {[
              ['Estimated', money.estimated, t('What it was quoted at')],
              ['Approved', money.approved, t('What the insurer agreed to')],
              ['Actual', money.actual, t('What the repairs cost')],
              ['Paid', money.paid, t('What has changed hands')],
              ['Unresolved', money.unresolved, t('Nobody has taken responsibility yet')],
            ].map(([label, value, hint]) => (
              <div key={label}>
                <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{t(label)}</p>
                <p className={`mt-1 text-xl font-bold ${label === 'Unresolved' && value > 0 ? 'text-amber-600' : 'text-slate-800'}`}>
                  {money.currency} {num(value)}
                </p>
                <p className="text-[11px] text-slate-400">{hint}</p>
              </div>
            ))}
          </div>
          <p className="border-t border-slate-100 px-4 py-2 text-[11px] leading-relaxed text-slate-400">
            {t('These are five different certainties, not five views of one number. An estimate is not an approval and an approval is not a payment — they are never added together.')}
          </p>
        </Card>
      )}

      {/* ── the list ────────────────────────────────────────────────────────────────────────── */}
      <Card>
        <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 p-3">
          <SearchInput
            value={q}
            onChange={setQ}
            placeholder={t('Reference, police report, claim, customer, contract or place')}
            className="max-w-md flex-1"
          />
          <Select
            value={stage}
            onChange={(e) => { const p = new URLSearchParams(params); if (e.target.value) p.set('stage', e.target.value); else p.delete('stage'); p.delete('queue'); setParams(p); }}
            className="w-48"
          >
            <option value="">{t('Open cases')}</option>
            <option value="all">{t('Every case')}</option>
            {(list.data?.stages || []).map((s) => <option key={s} value={s}>{t(words(s))}</option>)}
          </Select>
        </div>

        {list.loading && <Skeleton cols={6} />}
        {list.error && <ErrorState message={list.error} onRetry={list.reload} />}
        {!list.loading && !list.error && (list.data?.cases || []).length === 0 && (
          <EmptyState
            title={t('Nothing here')}
            message={t('No accident case matches this view. Report one from “Send a car in”.')}
          />
        )}

        {!list.loading && (list.data?.cases || []).length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                <tr>
                  {['Reference', 'Vehicle', 'When', 'Who had it', 'Stage', 'Police', 'Liability', 'Insurer'].map((h) => (
                    <th key={h} className="px-3 py-2 text-start font-semibold">{t(h)}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {list.data.cases.map((c) => (
                  <tr key={c.id} className="hover:bg-slate-50">
                    <td className="px-3 py-2">
                      <Link to={`/accidents/${c.id}`} className="font-semibold text-rose-700 hover:underline">{c.reference}</Link>
                    </td>
                    <td className="px-3 py-2">
                      {c.vehicle
                        ? <Link to={`/vehicles/${c.vehicle.id}`} className="text-slate-700 hover:underline">{c.vehicle.plate_no}</Link>
                        : '—'}
                    </td>
                    <td className="px-3 py-2 text-slate-600">{fmtDate(c.occurred_at)}</td>
                    <td className="px-3 py-2">
                      {c.context?.was_with_customer
                        ? <span className="font-medium text-amber-700">{c.context.customer?.name || t('A customer')}</span>
                        : <span className="capitalize text-slate-500">{t(words(c.context?.responsible_party_type))}</span>}
                    </td>
                    <td className="px-3 py-2"><Badge tone={STAGE_TONE[c.stage] || 'gray'}>{t(words(c.stage))}</Badge></td>
                    <td className="px-3 py-2"><Badge tone={POLICE_TONE[c.police?.status] || 'gray'}>{t(words(c.police?.status))}</Badge></td>
                    <td className="px-3 py-2"><Badge tone={LIABILITY_TONE[c.liability?.status] || 'gray'}>{t(words(c.liability?.status))}</Badge></td>
                    <td className="px-3 py-2">
                      <Badge tone={CLAIM_TONE[c.insurance?.claim_status] || 'gray'}>{t(words(c.insurance?.claim_status))}</Badge>
                      {c.insurance?.overdue && <span className="ms-1 text-[11px] font-semibold text-red-600">{t('overdue')}</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────────────────────
// THE CASE
// ─────────────────────────────────────────────────────────────────────────────────────────────

const TABS = ['Overview', 'Accident details', 'Police & documents', 'Liability', 'Insurance', 'Repairs', 'Financials', 'Timeline'];

function Detail({ caseId }) {
  const { t } = useI18n();
  const toast = useToast();
  const { can } = usePermissions();
  const navigate = useNavigate();

  const [tab, setTab] = useState('Overview');
  const [action, setAction] = useState(null);   // the open modal's key
  const [form, setForm] = useState({});
  const [busy, setBusy] = useState(false);

  const c = useFetch(() => api.get(`/accidents/${caseId}`).then((r) => r.data.data), [caseId]);
  const timeline = useFetch(() => api.get(`/accidents/${caseId}/timeline`).then((r) => r.data.data), [caseId]);
  const docs = useFetch(() => api.get(`/accidents/${caseId}/documents`).then((r) => r.data.data), [caseId]);
  // The customer-charge position. Its own call because it reaches into the accounting layer for a
  // live balance — and its own AUTHORITY: the page renders `status` and `blockers` verbatim rather
  // than working out from liability + amounts whether billing is allowed, so the button and the
  // endpoint can never disagree about it.
  const charge = useFetch(() => api.get(`/accidents/${caseId}/charge`).then((r) => r.data.data), [caseId]);
  // The damage + location vocabularies. Fetched once at the Detail level rather than inside the
  // modal so opening "Add damage" is instant — a picker that spins on open is a picker people learn
  // to avoid, and the whole point of it is that it must be easier than typing.
  const vocab = useFetch(() => api.get('/accidents/options').then((r) => r.data.data), []);

  const data = c.data;

  const open = useCallback((key, initial = {}) => { setForm(initial); setAction(key); }, []);
  const close = useCallback(() => { if (!busy) { setAction(null); setForm({}); } }, [busy]);

  const post = useCallback(async (path, body, okMessage) => {
    setBusy(true);
    try {
      await api.post(`/accidents/${caseId}${path}`, body);
      toast.success(okMessage);
      setAction(null); setForm({});
      c.reload({ silent: true });
      timeline.reload({ silent: true });
      docs.reload({ silent: true });
      charge.reload({ silent: true });
    } catch (e) {
      // The service's refusals are sentences ("the police report is still outstanding"). Show them —
      // flattening them into a generic failure is what teaches people to click past a gate.
      const errs = e?.response?.data?.errors;
      toast.error(errs ? Object.values(errs).flat().join(' ') : (e?.response?.data?.message || t('Something went wrong')));
    } finally {
      setBusy(false);
    }
  }, [caseId, toast, c, timeline, docs, charge, t]);

  // Damage items and documents are removed, not edited — so DELETE, not the post() helper above.
  // Sharing one helper for both verbs would have quietly sent a POST to a DELETE-only route.
  const remove = useCallback(async (path, okMessage) => {
    setBusy(true);
    try {
      await api.delete(`/accidents/${caseId}${path}`);
      toast.success(okMessage);
      c.reload({ silent: true });
      timeline.reload({ silent: true });
      docs.reload({ silent: true });
      charge.reload({ silent: true });
    } catch (e) {
      toast.error(e?.response?.data?.message || t('Something went wrong'));
    } finally {
      setBusy(false);
    }
  }, [caseId, toast, c, timeline, docs, charge, t]);

  const upload = useCallback(async (file, kind, note) => {
    setBusy(true);
    try {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('kind', kind);
      if (note) fd.append('note', note);
      await api.post(`/accidents/${caseId}/documents`, fd, { headers: { 'Content-Type': 'multipart/form-data' } });
      toast.success(t('Document uploaded'));
      setAction(null); setForm({});
      docs.reload({ silent: true });
      charge.reload({ silent: true });
      c.reload({ silent: true });
      timeline.reload({ silent: true });
    } catch (e) {
      toast.error(e?.response?.data?.message || t('Upload failed'));
    } finally {
      setBusy(false);
    }
  }, [caseId, toast, docs, c, timeline, charge, t]);

  const gateTone = { critical: 'red', warning: 'amber', info: 'blue' };

  if (c.loading) return <Skeleton cols={3} rows={8} />;
  if (c.error) return <ErrorState message={c.error} onRetry={c.reload} />;
  if (!data) return null;

  const money = data.financials || {};
  const closed = data.is_closed;

  return (
    <div className="space-y-4">
      {/* ── HEADER ──────────────────────────────────────────────────────────────────────────── */}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <Link to="/accidents" className="text-xs font-medium text-slate-500 hover:text-slate-700">
            <span aria-hidden className="inline-block rtl:-scale-x-100">←</span> {t('All accidents')}
          </Link>
          <h1 className="mt-1 flex items-center gap-2 text-2xl font-bold text-slate-900">
            <span aria-hidden>🚨</span>{data.reference}
            <Badge tone={STAGE_TONE[data.stage] || 'gray'}>{t(words(data.stage))}</Badge>
          </h1>
          <p className="mt-0.5 text-sm text-slate-500">
            {data.vehicle
              ? <Link to={`/vehicles/${data.vehicle.id}`} className="font-medium text-slate-700 hover:underline">
                  {data.vehicle.plate_no} · {data.vehicle.make} {data.vehicle.model}
                </Link>
              : t('Vehicle')}
            {' · '}{fmtDate(data.occurred_at)}{data.location ? ` · ${data.location}` : ''}
          </p>
        </div>

        <div className="flex flex-wrap gap-2">
          {!closed && can('accidents.manage') && (
            <Button size="sm" variant="secondary" onClick={() => open('repair', {})}>
              <Icon.Wrench className="h-4 w-4" /> {t('Send for repair')}
            </Button>
          )}
          {!closed && can('accidents.close') && (
            <Button size="sm" variant="secondary" onClick={() => open('close', {})}>{t('Close case')}</Button>
          )}
          {closed && can('accidents.override') && (
            <Button size="sm" variant="danger" onClick={() => open('reopen', {})}>{t('Reopen case')}</Button>
          )}
        </div>
      </div>

      {/* ── WHO HAD THE CAR. Never in a tab. ───────────────────────────────────────────────── */}
      <AccidentContextBanner context={data.context} />

      {/* ── WHAT IS STILL OUTSTANDING — the server's own list, rendered verbatim ────────────── */}
      {data.gates?.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {data.gates.map((g) => (
            <Badge key={g.key} tone={gateTone[g.level] || 'gray'} dot>{t(g.label)}</Badge>
          ))}
        </div>
      )}

      <Card className="p-3">
        <AccidentProgress stages={data.stages} current={data.stage} index={data.stage_index} />
      </Card>

      {/* ── TABS ────────────────────────────────────────────────────────────────────────────── */}
      <div className="flex gap-1 overflow-x-auto rounded-xl bg-slate-100 p-1">
        {TABS.map((label) => (
          <button
            key={label}
            type="button"
            onClick={() => setTab(label)}
            aria-pressed={tab === label}
            className={`whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold transition ${tab === label
              ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200'
              : 'text-slate-500 hover:text-slate-700'}`}
          >
            {t(label)}
          </button>
        ))}
      </div>

      {/* ── OVERVIEW ────────────────────────────────────────────────────────────────────────── */}
      {tab === 'Overview' && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card className="p-4">
            <h2 className="mb-2 text-sm font-bold text-slate-700">{t('What happened')}</h2>
            <p className="whitespace-pre-line text-sm text-slate-600">{data.description || t('No description recorded.')}</p>
            <dl className="mt-3 grid grid-cols-2 gap-2 text-xs">
              <Fact label={t('Type')} value={data.accident_type ? t(words(data.accident_type)) : '—'} />
              <Fact label={t('Reported by')} value={data.reported_by_name || '—'} />
              <Fact label={t('Drivable')} value={data.drivable === null ? t('Not assessed') : data.drivable ? t('Yes') : t('No')} />
              <Fact label={t('Recovery truck')} value={data.towing_required === null ? t('Not assessed') : data.towing_required ? t('Yes') : t('No')} />
              <Fact label={t('Odometer')} value={data.odometer ? num(data.odometer) : '—'} />
              <Fact label={t('Damage areas')} value={num((data.damage_items || []).length)} />
            </dl>
          </Card>

          <Card className="p-4">
            <h2 className="mb-2 text-sm font-bold text-slate-700">{t('Where the case stands')}</h2>
            <dl className="grid grid-cols-2 gap-2 text-xs">
              <Fact label={t('Police report')} value={<Badge tone={POLICE_TONE[data.police.status]}>{t(words(data.police.status))}</Badge>} />
              <Fact label={t('Liability')} value={<Badge tone={LIABILITY_TONE[data.liability.status]}>{t(words(data.liability.status))}</Badge>} />
              <Fact label={t('Insurance claim')} value={<Badge tone={CLAIM_TONE[data.insurance.claim_status]}>{t(words(data.insurance.claim_status))}</Badge>} />
              <Fact label={t('Repairs raised')} value={num((data.repairs || []).length)} />
            </dl>
            <div className="mt-3 grid grid-cols-2 gap-2 border-t border-slate-100 pt-3 text-xs sm:grid-cols-4">
              {[['Estimated', money.estimated], ['Approved', money.approved], ['Actual', money.actual], ['Paid', money.paid]].map(([l, v]) => (
                <div key={l}>
                  <dt className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{t(l)}</dt>
                  <dd className="font-bold text-slate-700">{money.currency} {num(v)}</dd>
                </div>
              ))}
            </div>
          </Card>
        </div>
      )}

      {/* ── ACCIDENT DETAILS ────────────────────────────────────────────────────────────────── */}
      {tab === 'Accident details' && (
        <div className="space-y-4">
          <Card className="p-4">
            <div className="mb-3 flex items-center justify-between">
              <h2 className="text-sm font-bold text-slate-700">{t('Damage')}</h2>
              {!closed && can('accidents.manage') && (
                <div className="flex gap-2">
                  <Button size="sm" variant="secondary" onClick={() => open('damage', { severity: 'unknown' })}>
                    <Icon.Plus className="h-4 w-4" /> {t('Add damage')}
                  </Button>
                  {!data.assessed_at && (
                    <Button size="sm" onClick={() => open('assess', { drivable: data.drivable, towing_required: data.towing_required })}>
                      {t('Complete assessment')}
                    </Button>
                  )}
                </div>
              )}
            </div>

            {(data.damage_items || []).length === 0 ? (
              <p className="text-sm text-slate-400">{t('Nothing recorded yet. Even “no visible damage” is a finding worth writing down.')}</p>
            ) : (
              <ul className="divide-y divide-slate-100">
                {data.damage_items.map((d) => (
                  <li key={d.id} className="flex items-start justify-between gap-3 py-2">
                    <div className="min-w-0">
                      <p className="text-sm font-semibold text-slate-800">{d.area_label}</p>
                      {d.description && <p className="text-xs text-slate-500">{d.description}</p>}
                      <p className="mt-0.5 flex flex-wrap items-center gap-2 text-[11px] text-slate-400">
                        <Badge tone={d.severity === 'severe' ? 'red' : d.severity === 'moderate' ? 'amber' : d.severity === 'minor' ? 'yellow' : 'gray'}>
                          {t(d.severity)}
                        </Badge>
                        {d.estimated_cost && <span>{money.currency} {num(d.estimated_cost)}</span>}
                        {/* Null forever on items nobody repaired — an unrepaired dent is a fact. */}
                        {d.repaired && <span className="font-medium text-emerald-600">{t('repaired')}</span>}
                      </p>
                    </div>
                    {!closed && can('accidents.manage') && (
                      <button
                        type="button"
                        onClick={() => remove(`/damage/${d.id}`, t('Damage item removed'))}
                        className="shrink-0 rounded-lg p-1.5 text-slate-300 hover:bg-red-50 hover:text-red-600"
                        aria-label={t('Remove')}
                      >
                        <Icon.X className="h-3.5 w-3.5" />
                      </button>
                    )}
                  </li>
                ))}
              </ul>
            )}
            {data.assessed_at && (
              <p className="mt-3 border-t border-slate-100 pt-2 text-[11px] text-slate-400">
                {t('Assessed by {who} on {when}', { who: data.assessed_by_name || '—', when: fmtDate(data.assessed_at) })}
              </p>
            )}
          </Card>

          {data.other_party?.involved && (
            <Card className="p-4">
              <h2 className="mb-2 text-sm font-bold text-slate-700">{t('Other party')}</h2>
              <dl className="grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-3">
                <Fact label={t('Name')} value={data.other_party.name || '—'} />
                <Fact label={t('Phone')} value={data.other_party.phone || '—'} />
                <Fact label={t('Plate')} value={data.other_party.plate || '—'} />
                <Fact label={t('Insurer')} value={data.other_party.insurer || '—'} />
                <Fact label={t('Policy')} value={data.other_party.policy_no || '—'} />
              </dl>
              {data.other_party.note && <p className="mt-2 text-xs text-slate-500">{data.other_party.note}</p>}
            </Card>
          )}

          {data.safety_concerns && (
            <Card className="border-red-200 bg-red-50 p-4">
              <h2 className="text-sm font-bold text-red-800">{t('Safety concerns')}</h2>
              <p className="mt-1 text-sm text-red-700">{data.safety_concerns}</p>
            </Card>
          )}
        </div>
      )}

      {/* ── POLICE & DOCUMENTS ──────────────────────────────────────────────────────────────── */}
      {tab === 'Police & documents' && (
        <div className="space-y-4">
          <Card className={`p-4 ${data.police.status === 'missing' ? 'border-red-300 bg-red-50' : ''}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h2 className="flex items-center gap-2 text-sm font-bold text-slate-700">
                  {data.police.status === 'missing' && <span aria-hidden>⚠️</span>}
                  {t('Police report')}
                  <Badge tone={POLICE_TONE[data.police.status]}>{t(words(data.police.status))}</Badge>
                </h2>
                {data.police.status === 'missing' && (
                  <p className="mt-1 text-xs text-red-700">
                    {t('This case cannot move past the documentation stage until the report is verified — or waived, with a reason.')}
                  </p>
                )}
              </div>
              {!closed && (
                <div className="flex flex-wrap gap-2">
                  {can('accidents.manage') && (
                    <Button size="sm" variant="secondary" onClick={() => open('police', {
                      police_report_no: data.police.report_no || '',
                      police_report_date: data.police.report_date || '',
                      police_authority: data.police.authority || '',
                    })}>{t('Record report')}</Button>
                  )}
                  {/* Verification is a DIFFERENT permission from recording — an uploader verifying
                      their own upload is a formality, not a check. */}
                  {can('accidents.police.verify') && data.police.status === 'recorded' && (
                    <Button size="sm" onClick={() => open('verify', {})}>{t('Verify')}</Button>
                  )}
                  {can('accidents.override') && !data.police.satisfied && (
                    <Button size="sm" variant="ghost" onClick={() => open('bypass', {})}>{t('Waive requirement')}</Button>
                  )}
                </div>
              )}
            </div>

            <dl className="mt-3 grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4">
              <Fact label={t('Report number')} value={data.police.report_no || '—'} />
              <Fact label={t('Report date')} value={data.police.report_date ? fmtDate(data.police.report_date) : '—'} />
              <Fact label={t('Authority')} value={data.police.authority || '—'} />
              <Fact label={t('Verified by')} value={data.police.verified_by_name || '—'} />
            </dl>

            {/* THE EXCEPTION, SHOWN AS ONE. A waiver is never a silent state. */}
            {data.police.status === 'bypassed' && (
              <div className="mt-3 rounded-lg bg-orange-100 p-3 text-xs text-orange-900 ring-1 ring-inset ring-orange-500/30">
                <p className="font-semibold">{t('Requirement waived by {who} on {when}', {
                  who: data.police.bypassed_by_name || '—', when: fmtDate(data.police.bypassed_at),
                })}</p>
                <p className="mt-1">{data.police.bypass_reason}</p>
              </div>
            )}
          </Card>

          <Card className="p-4">
            <div className="mb-3 flex items-center justify-between">
              <h2 className="text-sm font-bold text-slate-700">{t('Documents')}</h2>
              {!closed && (can('accidents.manage') || can('accidents.report')) && (
                <Button size="sm" variant="secondary" onClick={() => open('upload', { kind: 'police_report' })}>
                  <Icon.Plus className="h-4 w-4" /> {t('Upload')}
                </Button>
              )}
            </div>
            {(docs.data?.documents || []).length === 0 ? (
              <p className="text-sm text-slate-400">{t('Nothing filed yet.')}</p>
            ) : (
              <ul className="divide-y divide-slate-100">
                {docs.data.documents.map((d) => (
                  <li key={d.id} className="flex items-center justify-between gap-3 py-2">
                    <div className="min-w-0">
                      <p className="text-sm font-medium text-slate-800">{t(d.kind_label)}</p>
                      <p className="truncate text-[11px] text-slate-400">
                        {d.original_name} · {d.uploaded_by || t('Unknown')} · {fmtDate(d.uploaded_at)}
                      </p>
                      {d.note && <p className="text-[11px] text-slate-500">{d.note}</p>}
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                      {d.url && (
                        <a href={d.url} target="_blank" rel="noreferrer" className="text-xs font-semibold text-rose-700 hover:underline">
                          {t('Open')}
                        </a>
                      )}
                      {!closed && can('accidents.manage') && (
                        <button
                          type="button"
                          onClick={() => remove(`/documents/${d.id}`, t('Document deleted'))}
                          className="rounded-lg p-1.5 text-slate-300 hover:bg-red-50 hover:text-red-600"
                          aria-label={t('Remove')}
                        >
                          <Icon.X className="h-3.5 w-3.5" />
                        </button>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      )}

      {/* ── LIABILITY ───────────────────────────────────────────────────────────────────────── */}
      {tab === 'Liability' && (
        <Card className="p-4">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <h2 className="flex items-center gap-2 text-sm font-bold text-slate-700">
              {t('Who was at fault')}
              <Badge tone={LIABILITY_TONE[data.liability.status]}>{t(words(data.liability.status))}</Badge>
            </h2>
            {!closed && can('accidents.liability') && (
              <Button size="sm" onClick={() => open('liability', {
                liability_status: data.liability.status === 'pending' ? '' : data.liability.status,
                liability_source: data.liability.source || '',
                liability_note: data.liability.note || '',
              })}>
                {data.liability.decided ? t('Revise the verdict') : t('Decide liability')}
              </Button>
            )}
          </div>

          {!data.liability.decided ? (
            <p className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-800 ring-1 ring-inset ring-amber-500/20">
              {t('Nobody has ruled on this yet. The customer having been at the wheel is not an answer — the system will not assume it, and neither should the case.')}
            </p>
          ) : (
            <dl className="mt-3 grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4">
              <Fact label={t('Basis')} value={t(words(data.liability.source))} />
              <Fact label={t('Share')} value={data.liability.share_pct === null ? '—' : `${data.liability.share_pct}%`} />
              <Fact label={t('Decided by')} value={data.liability.decided_by_name || '—'} />
              <Fact label={t('Decided on')} value={fmtDate(data.liability.decided_at)} />
            </dl>
          )}
          {data.liability.note && <p className="mt-3 whitespace-pre-line text-sm text-slate-600">{data.liability.note}</p>}
        </Card>
      )}

      {/* ── INSURANCE ───────────────────────────────────────────────────────────────────────── */}
      {tab === 'Insurance' && (
        <Card className="p-4">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <h2 className="flex items-center gap-2 text-sm font-bold text-slate-700">
              {t('Insurance claim')}
              <Badge tone={CLAIM_TONE[data.insurance.claim_status]}>{t(words(data.insurance.claim_status))}</Badge>
              {data.insurance.overdue && <Badge tone="red" dot>{t('No answer past the due date')}</Badge>}
            </h2>
            {!closed && can('accidents.insurance') && (
              <Button size="sm" onClick={() => open('insurance', {
                insurer_name: data.insurance.insurer_name || '',
                policy_no: data.insurance.policy_no || '',
                claim_no: data.insurance.claim_no || '',
                claim_status: data.insurance.claim_status || '',
                claim_response_due_on: data.insurance.response_due_on || '',
                insurance_contact_name: data.insurance.contact_name || '',
                insurance_contact_phone: data.insurance.contact_phone || '',
                insurance_contact_email: data.insurance.contact_email || '',
              })}>{t('Update claim')}</Button>
            )}
          </div>
          <dl className="mt-3 grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4">
            <Fact label={t('Insurer')} value={data.insurance.insurer_name || '—'} />
            <Fact label={t('Policy')} value={data.insurance.policy_no || '—'} />
            <Fact label={t('Claim number')} value={data.insurance.claim_no || '—'} />
            <Fact label={t('Submitted')} value={data.insurance.submitted_at ? fmtDate(data.insurance.submitted_at) : '—'} />
            <Fact label={t('Answer expected by')} value={data.insurance.response_due_on ? fmtDate(data.insurance.response_due_on) : '—'} />
            <Fact label={t('Contact')} value={data.insurance.contact_name || '—'} />
            <Fact label={t('Phone')} value={data.insurance.contact_phone || '—'} />
            <Fact label={t('Email')} value={data.insurance.contact_email || '—'} />
          </dl>
          {data.insurance.note && (
            <p className="mt-3 whitespace-pre-line rounded-lg bg-slate-50 p-3 text-xs text-slate-600">{data.insurance.note}</p>
          )}
        </Card>
      )}

      {/* ── REPAIRS ─────────────────────────────────────────────────────────────────────────── */}
      {tab === 'Repairs' && (
        <Card className="p-4">
          <div className="mb-3 flex items-center justify-between">
            <h2 className="text-sm font-bold text-slate-700">{t('Repairs raised for this accident')}</h2>
            {!closed && can('accidents.manage') && (
              <Button size="sm" variant="secondary" onClick={() => open('repair', {})}>
                <Icon.Wrench className="h-4 w-4" /> {t('Send for repair')}
              </Button>
            )}
          </div>
          <p className="mb-3 text-[11px] leading-relaxed text-slate-400">
            {t('A repair raised here is an ordinary maintenance ticket — it goes to the supervisors’ dispatch board and runs the normal workflow. The accident case is its parent, so the work stays traceable back to what caused it.')}
          </p>
          {(data.repairs || []).length === 0 ? (
            <p className="text-sm text-slate-400">{t('No repair has been raised yet.')}</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {data.repairs.map((r) => (
                <li key={r.id} className="flex items-center justify-between gap-3 py-2">
                  <div>
                    <Link to={r.url} className="text-sm font-semibold text-indigo-600 hover:underline">{t('Ticket')} #{r.id}</Link>
                    <p className="text-[11px] text-slate-400">
                      {t(words(r.workflow_status))}{r.vendor_name ? ` · ${r.vendor_name}` : ''} · {fmtDate(r.created_at)}
                    </p>
                  </div>
                  <span className="text-sm font-semibold text-slate-700">{r.cost ? `${money.currency} ${num(r.cost)}` : '—'}</span>
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}

      {/* ── FINANCIALS ──────────────────────────────────────────────────────────────────────── */}
      {tab === 'Financials' && (
        <div className="space-y-4">
          {/* THE CUSTOMER CHARGE, ABOVE THE LEDGER. The ledger below says what the accident COST and
              who bears it; this says whether the person who bears it has actually been billed. That
              is the question somebody opens this tab to answer, so it leads. */}
          <CustomerCharge
            state={charge.data}
            loading={charge.loading}
            can={can}
            onCharge={() => open('charge', {})}
            onWithdraw={() => open('withdraw_charge', {})}
          />

          <Card className="p-4">
            <div className="mb-3 flex items-center justify-between">
              <h2 className="text-sm font-bold text-slate-700">{t('The money')}</h2>
              {!closed && can('accidents.financials') && (
                <Button size="sm" onClick={() => open('financial', { phase: 'estimate', party: 'insurance', amount: '' })}>
                  <Icon.Plus className="h-4 w-4" /> {t('Record an amount')}
                </Button>
              )}
            </div>

            <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
              {[
                ['Estimated', money.estimated, t('What it was quoted at')],
                ['Approved', money.approved, t('What the insurer agreed to')],
                ['Actual', money.actual, t('What the repairs cost')],
                ['Paid', money.paid, t('What has changed hands')],
                ['Unresolved', money.unresolved, t('Nobody has taken responsibility yet')],
              ].map(([label, value, hint]) => (
                <div key={label} className="rounded-xl border border-slate-200 p-3">
                  <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">{t(label)}</p>
                  <p className={`mt-1 text-lg font-bold ${label === 'Unresolved' && value > 0 ? 'text-amber-600' : 'text-slate-800'}`}>
                    {money.currency} {num(value)}
                  </p>
                  <p className="text-[10px] leading-snug text-slate-400">{hint}</p>
                </div>
              ))}
            </div>

            <p className="mt-3 text-[11px] leading-relaxed text-slate-400">
              {t('Estimate, approval and payment are three different certainties and are never added together. A revised figure supersedes its predecessor — it never erases it, and the older number stays on the ledger below.')}
            </p>
          </Card>

          <Card className="p-4">
            <h2 className="mb-3 text-sm font-bold text-slate-700">{t('The ledger')}</h2>
            {(data.financial_entries || []).length === 0 ? (
              <p className="text-sm text-slate-400">{t('Nothing recorded yet.')}</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                    <tr>{['Phase', 'Borne by', 'Amount', 'Recorded by', 'When', ''].map((h, i) => (
                      <th key={i} className="px-3 py-2 text-start font-semibold">{h && t(h)}</th>
                    ))}</tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {data.financial_entries.map((e) => (
                      <tr key={e.id} className={e.is_live ? '' : 'bg-slate-50 text-slate-400 line-through'}>
                        <td className="px-3 py-2">{t(words(e.phase))}</td>
                        <td className="px-3 py-2">{t(words(e.party))}</td>
                        <td className="px-3 py-2 font-semibold">{e.currency} {num(e.amount)}</td>
                        <td className="px-3 py-2 text-xs">{e.recorded_by_name || '—'}</td>
                        <td className="px-3 py-2 text-xs">{fmtDate(e.recorded_at)}</td>
                        <td className="px-3 py-2 text-xs no-underline">
                          {!e.is_live && <span className="text-[11px] italic">{t('superseded')}</span>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
        </div>
      )}

      {/* ── TIMELINE ────────────────────────────────────────────────────────────────────────── */}
      {tab === 'Timeline' && (
        <Card className="p-4">
          <h2 className="mb-1 text-sm font-bold text-slate-700">{t('Everything that has happened on this case')}</h2>
          <p className="mb-3 text-[11px] text-slate-400">
            {t('Oldest first — a case reads forwards. These are the same rows that appear on the vehicle’s own history.')}
          </p>
          {timeline.loading && <Skeleton cols={2} rows={5} />}
          <ol className="space-y-2">
            {(timeline.data?.events || []).map((e) => (
              <li key={e.id} className="flex gap-3 rounded-xl border border-slate-200 p-3">
                <span aria-hidden className="mt-0.5 h-2 w-2 shrink-0 rounded-full bg-rose-500" />
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-semibold text-slate-800">{e.description}</p>
                  <p className="mt-0.5 text-[11px] text-slate-400">
                    {e.actor_name} · {fmtDate(e.occurred_at)} {new Date(e.occurred_at).toLocaleTimeString()}
                  </p>
                  {/* THE DOOR. The server decides what an event opens onto — the police report scan,
                      the insurer's letter, the repair ticket — so a timeline line is never a dead
                      reference to a document the reader then has to go and find. */}
                  {e.link && (
                    <a
                      href={e.link}
                      {...(/^https?:|^\/storage\//.test(e.link) ? { target: '_blank', rel: 'noreferrer' } : {})}
                      className="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-rose-700 hover:underline"
                    >
                      {t(e.link_label || 'Open')} <span aria-hidden className="inline-block rtl:-scale-x-100">→</span>
                    </a>
                  )}
                  {(e.meta?.reason || e.meta?.note) && (
                    <p className="mt-1 text-xs text-slate-500">{e.meta.reason || e.meta.note}</p>
                  )}
                </div>
              </li>
            ))}
          </ol>
        </Card>
      )}

      {/* ── the action modals ───────────────────────────────────────────────────────────────── */}
      <ActionModals
        action={action} form={form} setForm={setForm} close={close} busy={busy}
        post={post} upload={upload} data={data} navigate={navigate} chargeState={charge.data} vocab={vocab.data}
      />
    </div>
  );
}

/**
 * HAS THE CUSTOMER BEEN BILLED FOR THIS, AND WHAT IS LEFT TO COLLECT?
 *
 * Four resting states, and the card looks different in each because they mean genuinely different
 * things to the person reading it:
 *
 *   nothing_to_charge  the verdict cleared the customer. FINISHED, not unfinished — so the card is
 *                      quiet and grey, and offers no button. Rendering this as a warning would put a
 *                      permanent amber nag on every accident somebody else caused.
 *   not_chargeable     the case is not ready yet, and the server says exactly why. The blockers are
 *                      shown as sentences, not as a disabled button with no explanation.
 *   pending            billable now — the one state with a primary action.
 *   charged            done. The card then stops being about the decision and starts being about
 *                      the money: what their credit absorbed and what is still owed.
 *
 * NOTHING HERE IS DERIVED LOCALLY. `status`, `blockers`, `covered_by_wallet` and `outstanding` all
 * come from the server, which reads them from the audit line written when the charge happened. The
 * page cannot reach a different conclusion from the endpoint that enforces it.
 */
function CustomerCharge({ state, loading, can, onCharge, onWithdraw }) {
  const { t } = useI18n();

  if (loading) return <Card className="p-4"><Skeleton cols={3} rows={2} /></Card>;
  if (!state) return null;

  const cur = state.currency || 'AED';
  const money = (v) => `${cur} ${num(v)}`;

  // A cleared customer is a finished state. Quiet, grey, no action.
  if (state.status === 'nothing_to_charge') {
    return (
      <Card className="p-4">
        <h2 className="text-sm font-bold text-slate-700">{t('Customer charge')}</h2>
        <p className="mt-1 text-sm text-slate-500">
          {state.blockers?.[0]?.message || t('Nothing is billable to the customer on this accident.')}
        </p>
      </Card>
    );
  }

  if (state.status === 'charged') {
    const settled = state.outstanding <= 0;
    return (
      <Card className={`p-4 ${settled ? 'border-emerald-200 bg-emerald-50/40' : 'border-amber-200 bg-amber-50/40'}`}>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="flex items-center gap-2 text-sm font-bold text-slate-700">
              {t('Customer charge')}
              <Badge tone={settled ? 'green' : 'amber'}>
                {settled ? t('charged · fully covered') : t('charged · partly outstanding')}
              </Badge>
            </h2>
            <p className="mt-1 text-xs text-slate-600">
              {t('{amount} billed to {who} on invoice {ref}', {
                amount: money(state.charged_amount),
                who: state.customer?.name || t('the customer'),
                ref: state.invoice?.invoice_ref || '—',
              })}
            </p>
          </div>
          {can('accidents.override') && (
            <Button size="sm" variant="ghost" onClick={onWithdraw}>{t('Withdraw the charge')}</Button>
          )}
        </div>

        {/* The split, stated as what HAPPENED at the moment of billing — not re-derived from
            today's balance, which a later payment would quietly change. */}
        <div className="mt-3 grid gap-3 border-t border-slate-200/70 pt-3 sm:grid-cols-4">
          <Fact label={t('Billed')} value={<span className="font-bold">{money(state.charged_amount)}</span>} />
          <Fact
            label={t('Covered by their credit')}
            value={<span className="font-bold text-emerald-700">{money(state.covered_by_wallet)}</span>}
          />
          <Fact
            label={t('Still outstanding')}
            value={<span className={`font-bold ${settled ? 'text-slate-500' : 'text-amber-700'}`}>{money(state.outstanding)}</span>}
          />
          <Fact
            label={t('Their balance now')}
            value={(
              <span className="font-bold">
                {money(Math.abs(state.customer_balance))}
                <span className="ms-1 text-[11px] font-normal text-slate-500">
                  {state.customer_balance > 0 ? t('owed') : state.customer_balance < 0 ? t('in credit') : t('settled')}
                </span>
              </span>
            )}
          />
        </div>
        {/* THREE cases, not two. A charge nothing was covered on must not claim "their credit
            absorbed part of it" — the commonest outcome is a customer with no credit at all, and
            telling them otherwise is the card asserting a transfer that never happened. */}
        <p className="mt-2 text-[11px] leading-relaxed text-slate-500">
          {settled
            ? t('Their available credit absorbed the whole charge, so there is nothing to collect. The balance above is what remains on their account.')
            : state.covered_by_wallet > 0
              ? t('Their available credit absorbed part of it; the rest is on their account to collect through the ordinary rental billing.')
              : t('They held no available credit, so the whole amount is on their account to collect through the ordinary rental billing.')}
        </p>
      </Card>
    );
  }

  // pending | not_chargeable
  const ready = state.status === 'pending';
  return (
    <Card className={`p-4 ${ready ? 'border-indigo-200 bg-indigo-50/40' : ''}`}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="flex items-center gap-2 text-sm font-bold text-slate-700">
            {t('Customer charge')}
            <Badge tone={ready ? 'blue' : 'gray'}>{ready ? t('pending') : t('not billable yet')}</Badge>
          </h2>
          {ready && (
            <p className="mt-1 text-xs text-slate-600">
              {t('{amount} is confirmed against {who} and has not been billed.', {
                amount: money(state.amount), who: state.customer?.name || t('the customer'),
              })}
            </p>
          )}
        </div>
        {ready && can('accidents.financials') && (
          <Button size="sm" onClick={onCharge}>{t('Charge the customer')}</Button>
        )}
      </div>

      {/* WHY NOT, in the server's own words. A disabled button with no reason is what teaches
          people that the system is broken rather than that the case is unfinished. */}
      {state.blockers?.length > 0 && (
        <ul className="mt-2 space-y-1">
          {state.blockers.map((b) => (
            <li key={b.key} className="flex items-start gap-2 text-xs text-slate-600">
              <span aria-hidden className="mt-0.5 text-slate-400">•</span>{t(b.message)}
            </li>
          ))}
        </ul>
      )}

      {ready && (
        <div className="mt-3 grid gap-3 border-t border-slate-200/70 pt-3 sm:grid-cols-3">
          <Fact label={t('Confirmed amount')} value={<span className="font-bold">{money(state.amount)}</span>} />
          <Fact label={t('Their available credit')} value={money(state.wallet_before)} />
          <Fact
            label={t('Would be left to collect')}
            value={<span className="font-bold text-amber-700">{money(state.outstanding)}</span>}
          />
        </div>
      )}
    </Card>
  );
}

function Fact({ label, value }) {
  return (
    <div>
      <dt className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className="text-slate-700">{value}</dd>
    </div>
  );
}

/**
 * One component for every write on the case. Each modal states, in a sentence, what pressing the
 * button will actually do — the same discipline the Send-a-car-in form uses, and for the same
 * reason: a confirmation the user reads AFTER the fact is not a confirmation.
 */
function ActionModals({ action, form, setForm, close, busy, post, upload, data, navigate, chargeState, vocab }) {
  const { t } = useI18n();
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target?.type === 'checkbox' ? e.target.checked : e.target.value }));

  const footer = (label, onOk, disabled) => (
    <>
      <Button variant="ghost" onClick={close} disabled={busy}>{t('Cancel')}</Button>
      <Button onClick={onOk} loading={busy} disabled={busy || disabled}>{label}</Button>
    </>
  );

  if (!action) return null;

  if (action === 'police') {
    return (
      <Modal open onClose={close} title={t('Record the police report')}
        subtitle={t('Recording is not verifying — somebody else confirms it has been read against the file.')}
        footer={footer(t('Save'), () => post('/police', form, t('Police report recorded')), !form.police_report_no)}>
        <div className="space-y-3">
          <Input label={t('Report number')} required value={form.police_report_no || ''} onChange={set('police_report_no')} />
          <Input type="date" label={t('Report date')} value={form.police_report_date || ''} onChange={set('police_report_date')} />
          <Input label={t('Issuing authority')} value={form.police_authority || ''} onChange={set('police_authority')} />
          <Textarea label={t('Note')} rows={2} value={form.police_note || ''} onChange={set('police_note')} />
        </div>
      </Modal>
    );
  }

  if (action === 'verify') {
    return (
      <Modal open onClose={close} title={t('Verify the police report')}
        subtitle={t('You are stating that you have read report {n} against this case.', { n: data.police.report_no || '—' })}
        footer={footer(t('Verify'), () => post('/police/verify', form, t('Police report verified')))}>
        <Textarea label={t('Note (optional)')} rows={3} value={form.note || ''} onChange={set('note')} />
      </Modal>
    );
  }

  if (action === 'bypass') {
    return (
      <Modal open onClose={close} title={t('Waive the police report requirement')}
        subtitle={t('This is recorded permanently, with your name on it.')}
        footer={footer(t('Waive it'), () => post('/police/bypass', form, t('Requirement waived')), !(form.reason || '').trim())}>
        <div className="space-y-3">
          <p className="rounded-lg bg-orange-50 px-3 py-2 text-xs leading-relaxed text-orange-800 ring-1 ring-inset ring-orange-500/20">
            {t('Waiving is legitimate — a car scraped in our own yard has no police report and never will. What it is not is invisible: the case will show “waived”, by you, with this reason, for the rest of its life.')}
          </p>
          <Textarea label={t('Why is it being waived?')} required rows={3} value={form.reason || ''} onChange={set('reason')} />
        </div>
      </Modal>
    );
  }

  if (action === 'liability') {
    return (
      <Modal open onClose={close} title={t('Who was at fault?')}
        subtitle={t('A verdict is recorded with its author and its basis. “Unknown” is a legitimate answer.')}
        footer={footer(t('Record the verdict'), () => post('/liability', form, t('Liability recorded')),
          !form.liability_status || !form.liability_source)}>
        <div className="space-y-3">
          <Select label={t('Responsibility')} required value={form.liability_status || ''} onChange={set('liability_status')}>
            <option value="">{t('Choose…')}</option>
            {['customer', 'other_party', 'company', 'employee', 'shared', 'unknown'].map((k) => (
              <option key={k} value={k}>{t(words(k))}</option>
            ))}
          </Select>
          {form.liability_status === 'shared' && (
            <Input type="number" min="0" max="100" label={t('Our share (%)')} required
              value={form.liability_share_pct || ''} onChange={set('liability_share_pct')} />
          )}
          <Select label={t('On what basis?')} required value={form.liability_source || ''} onChange={set('liability_source')}>
            <option value="">{t('Choose…')}</option>
            {['police_report', 'insurance', 'internal', 'legal', 'other'].map((k) => (
              <option key={k} value={k}>{t(words(k))}</option>
            ))}
          </Select>
          <Textarea label={t('Notes')} rows={3} value={form.liability_note || ''} onChange={set('liability_note')} />
        </div>
      </Modal>
    );
  }

  if (action === 'insurance') {
    return (
      <Modal open onClose={close} title={t('Insurance claim')} size="lg"
        subtitle={t('The claim status is the insurer’s, not ours — record what they said, whatever order they said it in.')}
        footer={footer(t('Save'), () => post('/insurance', form, t('Insurance updated')))}>
        <div className="grid gap-3 sm:grid-cols-2">
          <Input label={t('Insurer')} value={form.insurer_name || ''} onChange={set('insurer_name')} />
          <Input label={t('Policy number')} value={form.policy_no || ''} onChange={set('policy_no')} />
          <Input label={t('Claim number')} value={form.claim_no || ''} onChange={set('claim_no')} />
          <Select label={t('Claim status')} value={form.claim_status || ''} onChange={set('claim_status')}>
            <option value="">{t('Unchanged')}</option>
            {['not_submitted', 'preparing', 'submitted', 'under_review', 'info_required',
              'approved', 'partially_approved', 'rejected', 'closed'].map((k) => (
              <option key={k} value={k}>{t(words(k))}</option>
            ))}
          </Select>
          <Input type="date" label={t('Answer expected by')} value={form.claim_response_due_on || ''} onChange={set('claim_response_due_on')} />
          <Input label={t('Contact name')} value={form.insurance_contact_name || ''} onChange={set('insurance_contact_name')} />
          <Input label={t('Contact phone')} value={form.insurance_contact_phone || ''} onChange={set('insurance_contact_phone')} />
          <Input type="email" label={t('Contact email')} value={form.insurance_contact_email || ''} onChange={set('insurance_contact_email')} />
          <div className="sm:col-span-2">
            <Textarea label={t('Add a note')} rows={2} value={form.insurance_note || ''} onChange={set('insurance_note')} />
          </div>
        </div>
      </Modal>
    );
  }

  if (action === 'financial') {
    return (
      <Modal open onClose={close} title={t('Record an amount')}
        subtitle={t('A figure for a phase and party that already has one will supersede it — the old number stays on the ledger.')}
        footer={footer(t('Record it'), () => post('/financials', form, t('Amount recorded')), !form.amount)}>
        <div className="space-y-3">
          <Select label={t('How certain is this figure?')} required value={form.phase || ''} onChange={set('phase')}>
            {['estimate', 'revised_estimate', 'approved', 'actual', 'paid'].map((k) => (
              <option key={k} value={k}>{t(words(k))}</option>
            ))}
          </Select>
          <Select label={t('Who bears it?')} required value={form.party || ''} onChange={set('party')}>
            {['insurance', 'customer', 'company', 'other_party', 'deductible', 'unresolved'].map((k) => (
              <option key={k} value={k}>{t(words(k))}</option>
            ))}
          </Select>
          <Input type="number" step="0.01" min="0" label={t('Amount')} required value={form.amount || ''} onChange={set('amount')} />
          <Textarea label={t('Note')} rows={2} value={form.note || ''} onChange={set('note')} />
        </div>
      </Modal>
    );
  }

  if (action === 'charge') {
    const st = chargeState || {};
    const cur = st.currency || 'AED';
    return (
      <Modal open onClose={close} title={t('Charge the customer')}
        subtitle={t('This puts the amount on their account through the ordinary rental ledger — the same place their rent and their credit already live.')}
        footer={footer(t('Charge it'), () => post('/charge', form, t('Customer charged')))}>
        <div className="space-y-3">
          <div className="rounded-xl border border-slate-200 p-3 text-xs">
            <dl className="grid gap-2 sm:grid-cols-3">
              <Fact label={t('Amount')} value={<span className="font-bold">{cur} {num(st.amount)}</span>} />
              <Fact label={t('Customer')} value={st.customer?.name || '—'} />
              <Fact label={t('Contract')} value={st.contract?.contract_no || '—'} />
            </dl>
          </div>
          <p className="rounded-lg bg-slate-50 px-3 py-2 text-[11px] leading-relaxed text-slate-600">
            {st.covered_by_wallet > 0
              ? t('{covered} of this will be absorbed by credit they already hold; {rest} will be left on their account to collect.', {
                  covered: `${cur} ${num(st.covered_by_wallet)}`, rest: `${cur} ${num(st.outstanding)}`,
                })
              : t('They hold no available credit, so the full amount will sit on their account to collect.')}
          </p>
          {/* VAT is zero unless somebody says otherwise: the amount billed must equal the amount the
              case decided, or the ledger and the case disagree by 5% forever. */}
          <Input
            type="number" step="0.01" min="0" max="100"
            label={t('VAT %')}
            hint={t('Left at 0 so the charge matches the amount agreed on this case. Set it only if this recharge is VAT-able.')}
            value={form.vat_percentage ?? ''}
            onChange={set('vat_percentage')}
            placeholder="0"
          />
          <Textarea label={t('Add to the note on their invoice')} rows={2} value={form.note || ''} onChange={set('note')} />
        </div>
      </Modal>
    );
  }

  if (action === 'withdraw_charge') {
    return (
      <Modal open onClose={close} title={t('Withdraw the customer charge')}
        subtitle={t('The invoice is removed from their account and the balance goes back exactly as it was. The accident’s history keeps both the charge and this withdrawal.')}
        footer={footer(t('Withdraw it'), () => post('/charge/reverse', form, t('Charge withdrawn')), !(form.reason || '').trim())}>
        <Textarea label={t('Why is it being withdrawn?')} required rows={3} value={form.reason || ''} onChange={set('reason')} />
      </Modal>
    );
  }

  if (action === 'damage') {
    return (
      <Modal open onClose={close} size="lg" title={t('Record damage')}
        subtitle={t('Named from the damage catalog so it can be counted and costed later — not typed.')}
        footer={footer(t('Add'), () => post('/damage', form, t('Damage recorded')), !form.damage_catalog_id)}>
        <div className="space-y-4">
          <AccidentDamagePicker
            damageGroups={vocab?.damage_groups || []}
            locationGroups={vocab?.location_groups || []}
            value={form}
            onChange={(next) => setForm((f) => ({ ...f, ...next }))}
          />
          <div className="grid gap-3 sm:grid-cols-2">
            <Select label={t('Severity')} value={form.severity || 'unknown'} onChange={set('severity')}>
              {['minor', 'moderate', 'severe', 'unknown'].map((k) => <option key={k} value={k}>{t(k)}</option>)}
            </Select>
            <Input type="number" step="0.01" min="0" label={t('Estimated cost')} value={form.estimated_cost || ''} onChange={set('estimated_cost')} />
          </div>
          {/* The specifics live here, beside the category — detail, never instead of it. */}
          <Textarea
            label={t('Anything else about it?')}
            hint={t('Free text is welcome here — it is detail about the damage above, not a replacement for naming it.')}
            rows={2} value={form.description || ''} onChange={set('description')}
          />
        </div>
      </Modal>
    );
  }

  if (action === 'assess') {
    return (
      <Modal open onClose={close} title={t('Complete the damage assessment')}
        subtitle={t('Confirms somebody has looked at the car and written down what is broken.')}
        footer={footer(t('Complete'), () => post('/assess', form, t('Assessment completed')))}>
        <div className="space-y-3">
          <Select label={t('Can the car be driven?')} value={String(form.drivable)} onChange={(e) => setForm((f) => ({ ...f, drivable: e.target.value === 'null' ? null : e.target.value === 'true' }))}>
            <option value="null">{t('Not assessed')}</option>
            <option value="true">{t('Yes')}</option>
            <option value="false">{t('No')}</option>
          </Select>
          <Select label={t('Does it need a recovery truck?')} value={String(form.towing_required)} onChange={(e) => setForm((f) => ({ ...f, towing_required: e.target.value === 'null' ? null : e.target.value === 'true' }))}>
            <option value="null">{t('Not assessed')}</option>
            <option value="true">{t('Yes')}</option>
            <option value="false">{t('No')}</option>
          </Select>
          <Textarea label={t('Safety concerns')} rows={2} value={form.safety_concerns || ''} onChange={set('safety_concerns')} />
        </div>
      </Modal>
    );
  }

  if (action === 'upload') {
    return (
      <Modal open onClose={close} title={t('Upload a document')}
        subtitle={t('Say what it is — filing a police report as “other” is what makes the missing-paperwork queue lie.')}
        footer={footer(t('Upload'), () => form.file && upload(form.file, form.kind, form.note), !form.file)}>
        <div className="space-y-3">
          <Select label={t('What is this?')} required value={form.kind || ''} onChange={set('kind')}>
            {[
              ['police_report', 'Police report'],
              ['insurance_claim', 'Insurance claim document'],
              ['insurance_decision', 'Insurance decision / settlement letter'],
              ['damage_photo', 'Damage photo'],
              ['damage_video', 'Damage video'],
              ['repair_estimate', 'Repair estimate / quotation'],
              ['repair_invoice', 'Repair invoice'],
              ['other_party_document', 'Other party document'],
              ['accident_other', 'Other accident document'],
            ].map(([k, l]) => <option key={k} value={k}>{t(l)}</option>)}
          </Select>
          <input
            type="file"
            aria-label={t('File')}
            onChange={(e) => setForm((f) => ({ ...f, file: e.target.files?.[0] || null }))}
            className="w-full rounded-lg border border-slate-200 p-2 text-sm"
          />
          <Textarea label={t('Note')} rows={2} value={form.note || ''} onChange={set('note')} />
        </div>
      </Modal>
    );
  }

  if (action === 'repair') {
    return (
      <Modal open onClose={close} title={t('Send the car for repair')}
        subtitle={t('This raises an ordinary maintenance ticket, parented to this accident.')}
        footer={footer(t('Raise the ticket'), async () => {
          await post('/repair', form, t('Repair raised'));
        })}>
        <div className="space-y-3">
          <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs leading-relaxed text-slate-500">
            {t('The ticket is born at “Needs Dispatch” and waits for a supervisor to pick the garage — exactly like any other car sent straight to the workshop. The damage recorded on this case travels with it.')}
          </p>
          <Input label={t('Link an existing ticket instead (optional)')} type="number"
            value={form.maintenance_id || ''} onChange={set('maintenance_id')}
            hint={t('Use this when the repair was opened before the accident case')} />
          <Textarea label={t('Note for the workshop')} rows={2} value={form.note || ''} onChange={set('note')} />
        </div>
      </Modal>
    );
  }

  if (action === 'close') {
    return (
      <Modal open onClose={close} title={t('Close the accident case')}
        subtitle={t('Liability must have been decided and the police-report question answered. Outstanding money does not block closing — it is recorded as the final position.')}
        footer={footer(t('Close it'), () => post('/close', form, t('Case closed')))}>
        <Textarea label={t('Closing note')} rows={3} value={form.note || ''} onChange={set('note')} />
      </Modal>
    );
  }

  if (action === 'reopen') {
    return (
      <Modal open onClose={close} title={t('Reopen the accident case')}
        subtitle={t('It returns to the settlement stage — what is usually reopened is the money, not the crash.')}
        footer={footer(t('Reopen'), () => post('/reopen', form, t('Case reopened')), !(form.reason || '').trim())}>
        <Textarea label={t('Why is it being reopened?')} required rows={3} value={form.reason || ''} onChange={set('reason')} />
      </Modal>
    );
  }

  return null;
}

export default function AccidentCases() {
  const { caseId } = useParams();

  return caseId ? <Detail caseId={caseId} /> : <Board />;
}
