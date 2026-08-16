// SEND A CAR IN — the one front door for "this car needs to go somewhere".
//
// It replaces the old single-purpose Request Inspection form, which could only ever ask one question.
// There are really TWO, and conflating them cost a day of workshop time every time somebody picked the
// wrong one:
//
//   ASK FOR A TEST   — something is wrong and nobody knows what. The inspector test-drives it and
//                      decides. Lands in the review queue (a driver) or straight with the inspector (a
//                      controller, who IS the review authority).
//   STRAIGHT TO THE  — nothing to diagnose. The parts arrived, the garage asked for it back, it's a
//   GARAGE             booked service, the fault is already named. Skips the review gate AND the test
//                      drive: born at NEEDS DISPATCH, waiting for a supervisor to pick the garage.
//                      Offered only to people with diagnostic/dispatch authority — committing a car to
//                      a workshop with nobody having diagnosed it is not a driver's call.
//
// Both doors then ask the SAME question — "why?" — and accept exactly one of three answers, never two:
//
//   NAME THE FAULT   — from the live fault vocabulary, or (the good bit) from what THIS car was already
//                      in the shop for. The single likeliest reason a car goes back in is the last
//                      repair not holding, so its own history is offered first, one tap, and picking it
//                      stamps repeat_of_ticket_id — the requester's CLAIM, recorded as theirs. Nobody
//                      here is saying the fault recurred; only the workshop's confirmation says that.
//   PICK A REASON    — a code from the door's own list, for when you honestly cannot name a fault.
//   WRITE A NOTE     — your own words.
//
// WHO FILED IT IS NOT A FIELD. It comes from the session and is shown, not chosen; the server stamps
// requested_by from the token no matter what this form sends.
//
// Every string resolves through the i18n catalog (workflow.sendIn.*) so it mirrors cleanly in Arabic/RTL.

import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../api/client';
import { useAuth } from '../../auth/AuthContext';
import { useI18n } from '../../i18n/I18nContext';
import { usePermissions } from '../../hooks/usePermissions';
import Modal from '../ui/Modal';
import Button from '../ui/Button';
import Icon from '../ui/Icon';
import { Textarea } from '../ui/Field';
import VehicleStatusSelect from './VehicleStatusSelect';
// One normaliser for the Symptom → Root-Cause key, shared with the Diagnosis step — two copies of this
// rule is how the intake panel and the Inspector's picker quietly start quoting different lists.
import { normalizeSymptom } from './RootCausePicker';

// The two doors. `dispatch` is filtered out for anyone without dispatch/diagnostic authority — the
// route refuses it anyway; hiding it stops people filling in a form they cannot submit.
const DOOR_INSPECTION = 'inspection';
const DOOR_DISPATCH   = 'dispatch';

// The driver-voice choices on the inspection door, unchanged from the form this replaces.
// `observation` is a UI-only path: it writes a Driver Observation (a note on the car), never a ticket.
// `office_call` is the Controller's own judgement and is offered only to maintenance.manage.
const VOICE_DROVE       = 'test_drive';
const VOICE_OBSERVATION = 'observation';
const VOICE_OFFICE      = 'office_call';

// The three ways of answering "why?", mutually exclusive by contract (Maintenance::REPORT_MODES).
const MODE_FAULT  = 'fault';
const MODE_REASON = 'reason';
const MODE_NOTE   = 'note';

// A day count → the short phrase the history chips wear. Deliberately coarse: "3 weeks ago" is the
// resolution a person actually reasons at, and "23 days ago" pretends to a precision nobody uses.
function agoPhrase(days, t) {
  if (days == null) return null;
  if (days <= 0)  return t('workflow.sendIn.ago.today');
  if (days === 1) return t('workflow.sendIn.ago.yesterday');
  if (days < 14)  return t('workflow.sendIn.ago.days', { n: days });
  if (days < 60)  return t('workflow.sendIn.ago.weeks', { n: Math.round(days / 7) });
  return t('workflow.sendIn.ago.months', { n: Math.round(days / 30) });
}

/**
 * One entry in the "is it this again?" strip — a fault THIS car has already been in for.
 *
 * The chip states facts and nothing else: what it was, when, whether it was fixed, at which garage,
 * how many times it has appeared. `within_recurrence_window` earns an amber tone because a fault fixed
 * inside the recurrence window is worth the requester's attention — it is NOT the system asserting the
 * fault came back, and the copy says so in those words.
 */
function HistoryChip({ fault, picked, onToggle, t }) {
  const ago  = agoPhrase(fault.days_since, t);
  const warn = fault.within_recurrence_window && !picked;

  return (
    <button
      type="button"
      onClick={() => onToggle(fault)}
      aria-pressed={picked}
      className={`group relative flex w-full items-start gap-2.5 rounded-xl border px-3 py-2.5 text-start transition
        ${picked
          ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500'
          : warn
            ? 'border-amber-300 bg-amber-50/60 hover:border-amber-400'
            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'}`}
    >
      <span
        aria-hidden
        className={`mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-md text-[11px] font-bold
          ${picked ? 'bg-indigo-600 text-white' : warn ? 'bg-amber-200 text-amber-800' : 'bg-slate-100 text-slate-500'}`}
      >
        {picked ? '✓' : fault.occurrences}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-semibold text-slate-800">{fault.text}</span>
        <span className="mt-0.5 block text-[11px] leading-relaxed text-slate-500">
          {fault.fixed
            ? t('workflow.sendIn.history.fixed', { when: ago || '—' })
            : t('workflow.sendIn.history.notFixed', { when: ago || '—' })}
          {fault.garage ? ` · ${fault.garage}` : ''}
          {fault.occurrences > 1 ? ` · ${t('workflow.sendIn.history.times', { n: fault.occurrences })}` : ''}
        </span>
        {warn && (
          <span className="mt-1 block text-[11px] font-medium text-amber-700">
            {t('workflow.sendIn.history.mightNotHaveHeld')}
          </span>
        )}
      </span>
    </button>
  );
}

/** The fault vocabulary, searchable, grouped by category. Selection is a toggle; six is the ceiling. */
function FaultPicker({ groups, picked, onToggle, t, lang }) {
  const [q, setQ] = useState('');
  const [open, setOpen] = useState(null);   // expanded category key; null = none

  const needle = q.trim().toLowerCase();
  const shown = useMemo(() => {
    if (!needle) return groups;
    return groups
      .map((g) => ({
        ...g,
        faults: g.faults.filter((f) =>
          `${f.name} ${f.name_ar || ''}`.toLowerCase().includes(needle)),
      }))
      .filter((g) => g.faults.length);
  }, [groups, needle]);

  // Searching is its own answer to "which category?" — collapsing the results behind an accordion
  // would hide what the person just asked for.
  const expanded = (key) => !!needle || open === key;

  return (
    <div className="rounded-xl border border-slate-200 bg-white">
      <div className="relative border-b border-slate-100 p-2">
        <Icon.Search className="pointer-events-none absolute inset-y-0 start-4 my-auto h-4 w-4 text-slate-400" />
        <input
          type="search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder={t('workflow.sendIn.fault.search')}
          className="w-full rounded-lg border-0 bg-slate-50 py-2 ps-9 pe-3 text-sm text-slate-800 placeholder:text-slate-400 focus:bg-white focus:ring-2 focus:ring-indigo-500"
        />
      </div>

      <div className="max-h-64 overflow-y-auto p-2">
        {shown.length === 0 && (
          <p className="px-2 py-6 text-center text-sm text-slate-400">{t('workflow.sendIn.fault.noMatch')}</p>
        )}

        {shown.map((g) => (
          <div key={g.key} className="mb-1 last:mb-0">
            <button
              type="button"
              onClick={() => setOpen(open === g.key ? null : g.key)}
              className="flex w-full items-center justify-between rounded-lg px-2 py-1.5 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 hover:bg-slate-50"
            >
              <span>{(lang === 'ar' && g.label_ar) || g.label}</span>
              <span className="flex items-center gap-1.5">
                <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-500">{g.faults.length}</span>
                <Icon.ChevronDown className={`h-3.5 w-3.5 transition-transform ${expanded(g.key) ? 'rotate-180' : ''}`} />
              </span>
            </button>

            {expanded(g.key) && (
              <div className="flex flex-wrap gap-1.5 px-2 py-2">
                {g.faults.map((f) => {
                  const on = picked.some((p) => p.fault_catalog_id === f.id);
                  return (
                    <button
                      key={f.id}
                      type="button"
                      onClick={() => onToggle(f)}
                      aria-pressed={on}
                      className={`rounded-full px-2.5 py-1 text-xs font-medium transition ${on
                        ? 'bg-indigo-600 text-white shadow-sm'
                        : 'bg-slate-100 text-slate-700 hover:bg-slate-200'}`}
                    >
                      {(lang === 'ar' && f.name_ar) || f.name}
                    </button>
                  );
                })}
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}

export default function SendCarInModal({ vehicles = [], onClose, onDone }) {
  const { t, tf, lang } = useI18n();
  const { user } = useAuth();
  const { can } = usePermissions();

  // Controller authority (Lin & Marwa); super-admin passes through can() unconditionally.
  const canManage  = can('maintenance.manage');
  // Who may use the second door. Re-checked against the server's own answer once options load, so the
  // tab can never survive a client-side permission cache that disagrees with the route.
  const canInitiate = can('maintenance.initiate');

  const [door, setDoor]   = useState(DOOR_INSPECTION);
  const [voice, setVoice] = useState(VOICE_DROVE);
  const [mode, setMode]   = useState(MODE_FAULT);

  const [vehicleId, setVehicleId] = useState('');
  const [faults, setFaults]       = useState([]);   // [{ text, slug, fault_catalog_id, category_key, repeat_of_ticket_id }]
  const [reasonCode, setReason]   = useState('');
  const [note, setNote]           = useState('');
  const [observationRaise, setObservationRaise] = useState(false);

  const navigate = useNavigate();
  const [options, setOptions] = useState(null);     // { fault_groups, fault_causes, reasons, filed_by, … }
  const [recent, setRecent]   = useState([]);       // this car's own fault history
  const [recentLoading, setRecentLoading] = useState(false);
  const [inFlight, setInFlight] = useState(null);

  const [saving, setSaving] = useState(false);
  const [error, setError]   = useState(null);

  const isObservation = door === DOOR_INSPECTION && voice === VOICE_OBSERVATION;
  const isOffice      = door === DOOR_INSPECTION && voice === VOICE_OFFICE && canManage;
  // The server is the authority on which doors are usable; until its answer lands, fall back to the
  // client's own permission cache so the form is never briefly blank.
  const canDispatch = options ? !!options.can_dispatch : (canInitiate || canManage);
  const canRequest  = options ? !!options.can_request  : (can('maintenance.logistics') || canManage);

  // ── loads ──────────────────────────────────────────────────────────────────────────────────────
  // The vocabulary, the reason lists and the filer's name: one call, once, car-independent.
  useEffect(() => {
    let alive = true;
    api.get('/maintenance-tickets/request-options')
      .then((r) => { if (alive) setOptions(r?.data?.data || null); })
      .catch(() => { if (alive) setOptions(null); });
    return () => { alive = false; };
  }, []);

  // This car's own history + whether it already has a request in flight. Both are best-effort: a failed
  // fetch just leaves the panel off. The server guard is the real fence, not these notes.
  useEffect(() => {
    // A refusal belongs to the car it was about. Leaving it on screen while a DIFFERENT car is picked
    // is how "89529 — Available" ends up sitting above "this car is already in the pipeline".
    setError(null);
    if (!vehicleId) { setRecent([]); setInFlight(null); return undefined; }
    let alive = true;
    setRecentLoading(true);
    api.get(`/maintenance-tickets/vehicle/${Number(vehicleId)}/recent-faults`)
      .then((r) => { if (alive) setRecent(r?.data?.data?.faults || []); })
      .catch(() => { if (alive) setRecent([]); })
      .finally(() => { if (alive) setRecentLoading(false); });
    api.get(`/maintenance-tickets/vehicle/${Number(vehicleId)}/inspection-request`)
      .then((r) => { if (alive) setInFlight(r?.data?.data || null); })
      .catch(() => { if (alive) setInFlight(null); });
    return () => { alive = false; };
  }, [vehicleId]);

  // An observation IS a note — there is no fault to name and no reason code to pick, because nothing is
  // being requested. Forcing the mode here keeps the form honest rather than showing dead controls.
  useEffect(() => { if (isObservation) setMode(MODE_NOTE); }, [isObservation]);

  // Switching doors changes which reason list is legal, so a reason picked on the other door would be
  // refused on submit. Clear it at the moment of the switch instead of at the moment of the refusal.
  useEffect(() => { setReason(''); setError(null); }, [door]);

  // Land on a door this person can actually submit through. An inspector holds `initiate` but not
  // `logistics`: opening on "Ask for a test" would let him fill the whole form in and be refused by the
  // route at the very end. The server's answer arrives a beat after mount, so this corrects for it.
  useEffect(() => {
    if (options && !options.can_request && options.can_dispatch) setDoor(DOOR_DISPATCH);
  }, [options]);

  // ── selection ──────────────────────────────────────────────────────────────────────────────────
  const toggleCatalogFault = useCallback((f) => {
    setFaults((prev) => {
      const on = prev.some((p) => p.fault_catalog_id === f.id);
      if (on) return prev.filter((p) => p.fault_catalog_id !== f.id);
      if (prev.length >= 6) return prev;
      return [...prev, {
        text: f.name, slug: f.slug, fault_catalog_id: f.id,
        category_key: f.category_key, repeat_of_ticket_id: null,
        // Filled in only if they go on to say what they think it is (see pickCause).
        root_cause: null, root_cause_id: null,
      }];
    });
  }, []);

  // Picking from this car's history carries the ticket it came from — that link IS the claim, and it is
  // what turns "brake noise again" from a guess the workshop re-derives into a statement it can check.
  const toggleHistoryFault = useCallback((h) => {
    setFaults((prev) => {
      const key = (p) => `${p.fault_catalog_id || ''}|${p.text.toLowerCase()}`;
      const mine = key({ fault_catalog_id: h.fault_catalog_id, text: h.text });
      if (prev.some((p) => key(p) === mine)) return prev.filter((p) => key(p) !== mine);
      if (prev.length >= 6) return prev;
      return [...prev, {
        text: h.text, slug: null, fault_catalog_id: h.fault_catalog_id,
        category_key: h.category_key, repeat_of_ticket_id: h.ticket_id,
        root_cause: null, root_cause_id: null,
      }];
    });
  }, []);

  const historyPicked = (h) => faults.some(
    (p) => p.text.toLowerCase() === h.text.toLowerCase() && p.repeat_of_ticket_id === h.ticket_id
  );

  // The probable causes behind each fault currently picked, in pick order. Faults the knowledge base has
  // nothing for are dropped rather than shown empty — "no causes listed" tells the requester nothing and
  // reads as a gap in the car's story rather than a gap in the library. `index` addresses the fault row,
  // not its text: the same fault can be picked from the catalog and from history, and a text key would
  // make one tap light up both.
  const causesForPicked = useMemo(() => {
    const catalog = options?.fault_causes || {};
    return faults
      .map((f, index) => ({
        index,
        text:   f.text,
        picked: f.root_cause_id || null,
        causes: catalog[normalizeSymptom(f.text)] || [],
      }))
      .filter((row) => row.causes.length > 0);
  }, [faults, options]);

  // Pick (or unpick) the suspected cause on ONE fault. Never more than one per fault — "it's this or
  // that" is not a suspicion, it is the absence of one, and the inspector is the one being asked.
  const pickCause = useCallback((index, cause) => {
    setFaults((prev) => prev.map((f, i) => (i === index
      ? { ...f, root_cause: cause?.root_cause || null, root_cause_id: cause?.id || null }
      : f)));
  }, []);

  // ── validity ───────────────────────────────────────────────────────────────────────────────────
  const reasonList = options?.reasons?.[door] || {};
  const statementReady =
    (mode === MODE_FAULT  && faults.length > 0)
    || (mode === MODE_REASON && !!reasonCode && (reasonCode !== 'other' || !!note.trim()))
    || (mode === MODE_NOTE   && !!note.trim());

  // A car with a request already in flight can't be flagged again, and it can't be sent straight to a
  // garage either — the server refuses BOTH doors on the same fact, so both must say so before the form
  // is filled in rather than after it is submitted. An observation is a note, not a request, so that
  // path stays open.
  //
  // ONE exception, and the server computes it (`can_supersede`) rather than the form guessing: the system
  // SUGGESTED a test for a car that is out on hire. That suggestion was made from mileage and dates by
  // nobody, and it is the only thing standing in the way. The person filling this form has been in the
  // car, so BOTH doors stay open and the suggestion is stood down on submit — a guess does not outrank a
  // statement, whichever door the statement comes through. (A person's request still blocks both doors:
  // that is the duplicate this guard exists to stop.)
  const canSupersede = !!inFlight?.can_supersede;
  // The request door ADDS to an open request rather than opening a second one, so an open request is not
  // a refusal there — it only changes what the button does and what happens next. The one genuine dead
  // end left is the garage door while the Inspector already holds the car: that is assigned work.
  const isAdding = !isObservation && !!inFlight && door === DOOR_INSPECTION;
  const blocked = !isObservation && !!inFlight && !isAdding && !canSupersede;
  const disabled = saving || !vehicleId || blocked || !statementReady;

  // ── submit ─────────────────────────────────────────────────────────────────────────────────────
  const statementBody = () => ({
    reported_faults:     mode === MODE_FAULT  ? faults : undefined,
    request_reason_code: mode === MODE_REASON ? reasonCode : undefined,
  });

  async function submit() {
    if (disabled) return;
    setSaving(true);
    setError(null);
    try {
      // A) Driver Observation — a different entity entirely, so it leaves the ticket API. It only ever
      //    raises an inspection when the driver explicitly asks, and never when one is already in flight.
      if (isObservation) {
        const resp = await api.post('/driver-observations', {
          vehicle_id: Number(vehicleId), note: note.trim(),
        });
        const created = resp?.data?.data;
        const raise = observationRaise && !inFlight;
        if (raise && created?.id) {
          await api.post(`/driver-observations/${created.id}/request-inspection`);
        }
        onDone?.(t(raise ? 'workflow.success.observationInspection' : 'workflow.success.observation'));
        return;
      }

      // B) Straight to the garage — born at Needs Dispatch, waiting on a supervisor's garage choice.
      if (door === DOOR_DISPATCH) {
        await api.post('/maintenance-tickets/direct-dispatch', {
          vehicle_id: Number(vehicleId),
          customer_complaint: note.trim() || null,
          ...statementBody(),
        });
        onDone?.(t('workflow.sendIn.success.dispatch'));
        return;
      }

      // C) The Controller's own call: past the review gate (she IS the review authority), straight to
      //    the inspector, who is notified now.
      if (isOffice) {
        const officeResp = await api.post('/maintenance-tickets/request-inspection', {
          vehicle_id: Number(vehicleId),
          trigger_reason: 'test_drive',
          notes: note.trim() || null,
          ...statementBody(),
        });
        // Same rule on this door: if it was added to an open request, the card is the next step.
        if (isAdding) {
          const id = officeResp?.data?.data?.id || inFlight?.ticket_id;
          onDone?.(t('workflow.sendIn.success.added'));
          if (id) navigate(`/inspection-review?ticket=${id}`);
          return;
        }
        onDone?.(t('workflow.success.requestOffice', { who: '' }).trim());
        return;
      }

      // D) The driver's request — into the Controllers' review queue.
      const resp = await api.post('/maintenance-tickets/request', {
        vehicle_id: Number(vehicleId),
        trigger_reason: VOICE_DROVE,
        customer_complaint: note.trim() || null,
        ...statementBody(),
      });
      // Added to a request that was already open: the useful next step is the card itself, so hand them
      // straight to it (deep-link highlights it) instead of leaving them to find it in the queue. This is
      // where a rented car is marked and where it gets approved.
      if (isAdding) {
        const id = resp?.data?.data?.id || inFlight?.ticket_id;
        onDone?.(t('workflow.sendIn.success.added'));
        if (id) navigate(`/inspection-review?ticket=${id}`);
        return;
      }
      onDone?.(t('workflow.sendIn.success.request'));
    } catch (e) {
      setError(e?.response?.data?.message || t('workflow.error.generic'));
    } finally {
      setSaving(false);
    }
  }

  // ── pieces ─────────────────────────────────────────────────────────────────────────────────────
  const doors = [
    ...(canRequest  ? [{ key: DOOR_INSPECTION, icon: '🔍', tone: 'indigo' }] : []),
    ...(canDispatch ? [{ key: DOOR_DISPATCH,   icon: '🔧', tone: 'amber'  }] : []),
  ];

  const modes = [
    { key: MODE_FAULT,  icon: '🔧' },
    { key: MODE_REASON, icon: '📋' },
    { key: MODE_NOTE,   icon: '✍️' },
  ];

  const filedBy = options?.filed_by?.name || user?.name;

  const footer = (
    <>
      <Button variant="ghost" onClick={onClose} disabled={saving}>{t('common.cancel')}</Button>
      <Button
        variant={door === DOOR_DISPATCH ? 'warning' : 'primary'}
        onClick={submit}
        disabled={disabled}
        loading={saving}
      >
        {isObservation
          ? t('workflow.sendIn.submit.observation')
          : isAdding
            ? t('workflow.sendIn.submit.add')
            : door === DOOR_DISPATCH
              ? t('workflow.sendIn.submit.dispatch')
              : t('workflow.sendIn.submit.request')}
      </Button>
    </>
  );

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      title={t('workflow.sendIn.title')}
      subtitle={t('workflow.sendIn.subtitle')}
      footer={footer}
    >
      {/* ── THE TWO DOORS ─────────────────────────────────────────────────────────────────────── */}
      <div className="mb-4 grid gap-2 sm:grid-cols-2">
        {doors.map((d) => {
          const active = door === d.key;
          const accent = d.tone === 'amber'
            ? 'border-amber-500 bg-amber-50 ring-amber-500'
            : 'border-indigo-500 bg-indigo-50 ring-indigo-500';
          return (
            <button
              key={d.key}
              type="button"
              onClick={() => setDoor(d.key)}
              aria-pressed={active}
              className={`flex items-start gap-3 rounded-xl border px-3.5 py-3 text-start transition
                ${active ? `${accent} ring-1` : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'}`}
            >
              <span aria-hidden className="text-lg leading-none">{d.icon}</span>
              <span className="min-w-0">
                <span className="block text-sm font-semibold text-slate-900">{t(`workflow.sendIn.door.${d.key}.label`)}</span>
                <span className="mt-0.5 block text-xs leading-relaxed text-slate-500">{t(`workflow.sendIn.door.${d.key}.sub`)}</span>
                <span className={`mt-1.5 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide
                  ${d.key === DOOR_DISPATCH ? 'bg-amber-100 text-amber-800' : 'bg-indigo-100 text-indigo-700'}`}>
                  {t(`workflow.sendIn.door.${d.key}.lands`)}
                </span>
              </span>
            </button>
          );
        })}
      </div>

      <div className="space-y-4">
        {/* ── WHICH CAR ───────────────────────────────────────────────────────────────────────── */}
        <div>
          <div className="mb-1 flex items-center justify-between gap-2">
            <span className="text-sm font-medium text-slate-700">
              {t('workflow.field.vehicle')}<span className="ms-0.5 text-red-500">*</span>
            </span>
            {/* WHO IS FILING — read from the session, shown, never chosen. */}
            {filedBy && (
              <span className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">
                <Icon.Users className="h-3 w-3 text-slate-400" />
                {t('workflow.sendIn.filedBy', { who: filedBy })}
              </span>
            )}
          </div>

          {/* A car already in maintenance is being handled — hide it so nobody opens a duplicate for it.
              Rented cars stay selectable. An observation opens nothing, so every car stays notable. */}
          <VehicleStatusSelect
            value={vehicleId}
            onChange={setVehicleId}
            vehicles={isObservation
              ? vehicles
              : vehicles.filter((v) => !(v.under_maintenance || v.operational_status === 'maintenance'))}
            placeholder={t('workflow.ph.searchVehicle')}
          />
          {!isObservation && <p className="mt-1 text-xs text-slate-400">{t('workflow.hint.requestHideMaintenance')}</p>}

          {/* Already in flight — say what stage it's at, who raised it and what they reported, so the
              point that's already been made is visible before this one is written out. */}
          {inFlight && (
            <div className="mt-2 rounded-xl bg-amber-50 px-3 py-2.5 text-xs leading-relaxed text-amber-900 ring-1 ring-inset ring-amber-500/30">
              <p className="font-semibold">{t('workflow.hint.inFlightTitle')}</p>
              <p className="mt-0.5">
                {t(inFlight.state === 'inspection_diagnostic'
                  ? 'workflow.hint.inFlightDriving'
                  : inFlight.state === 'inspection_requested'
                    ? 'workflow.hint.inFlightApproved'
                    : 'workflow.hint.inFlightPending')}
              </p>
              {inFlight.note && <p className="mt-1 text-amber-800/80">{t('workflow.hint.inFlightNote', { note: inFlight.note })}</p>}
              <p className="mt-1.5">
                {t(isObservation
                  ? 'workflow.hint.inFlightObservation'
                  : isAdding
                    ? 'workflow.hint.inFlightAdd'
                    : canSupersede
                      ? 'workflow.hint.inFlightSupersede'
                      : 'workflow.hint.inFlightDispatchBlocked')}
              </p>
              {inFlight.url && (
                <a href={inFlight.url} className="mt-1 inline-block font-semibold underline hover:no-underline">
                  {t('workflow.hint.inFlightLink')}
                </a>
              )}
            </div>
          )}
        </div>

        {/* ── WHAT HAPPENED (inspection door only) ────────────────────────────────────────────── */}
        {door === DOOR_INSPECTION && (
          <div>
            <span className="mb-1.5 block text-sm font-medium text-slate-700">{t('workflow.reason.driverLabel')}</span>
            <div className="grid gap-2">
              {[VOICE_DROVE, VOICE_OBSERVATION, ...(canManage ? [VOICE_OFFICE] : [])].map((v) => {
                const active = voice === v;
                return (
                  <button
                    key={v}
                    type="button"
                    onClick={() => { setVoice(v); setError(null); }}
                    aria-pressed={active}
                    className={`flex items-start gap-3 rounded-xl border px-3 py-2.5 text-start transition
                      ${active ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500' : 'border-slate-200 bg-white hover:border-slate-300'}`}
                  >
                    <span className={`mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border ${active ? 'border-indigo-600' : 'border-slate-300'}`}>
                      {active && <span className="h-2 w-2 rounded-full bg-indigo-600" />}
                    </span>
                    <span className="min-w-0">
                      <span className="block text-sm font-semibold text-slate-800">
                        {t(v === VOICE_OFFICE ? 'workflow.reason.office.label' : `workflow.reason.driver.${v}.label`)}
                      </span>
                      <span className="block text-xs text-slate-500">
                        {t(v === VOICE_OFFICE ? 'workflow.reason.office.sub' : `workflow.reason.driver.${v}.sub`)}
                      </span>
                    </span>
                  </button>
                );
              })}
            </div>

            {/* Who may file this at all — advisory, not a hard gate. It speaks to the driver-voice
                choices only: the office choice is explicitly for a car nobody here has been in. */}
            {!isOffice && (
              <p className="mt-2 rounded-lg bg-amber-50/70 px-3 py-2 text-[11px] leading-relaxed text-amber-800 ring-1 ring-inset ring-amber-500/20">
                {t('workflow.hint.requestEligibility')}
              </p>
            )}
            {isOffice && (
              <p className="mt-2 rounded-lg bg-indigo-50/70 px-3 py-2 text-[11px] leading-relaxed text-indigo-800 ring-1 ring-inset ring-indigo-600/15">
                {t('workflow.hint.officeRequest')}
              </p>
            )}
            {isObservation && (
              <p className="mt-2 rounded-lg bg-sky-50/70 px-3 py-2 text-xs leading-relaxed text-sky-800 ring-1 ring-inset ring-sky-600/10">
                {t('workflow.hint.observationBanner')}
              </p>
            )}
          </div>
        )}

        {/* ── WHY — one of three, never two ───────────────────────────────────────────────────── */}
        {!isObservation && (
          <div>
            <div className="mb-1.5 flex items-baseline justify-between gap-2">
              <span className="text-sm font-medium text-slate-700">
                {t('workflow.sendIn.why.label')}<span className="ms-0.5 text-red-500">*</span>
              </span>
              <span className="text-[11px] text-slate-400">{t('workflow.sendIn.why.exclusive')}</span>
            </div>

            <div className="mb-3 flex gap-1 rounded-xl bg-slate-100 p-1">
              {modes.map((m) => {
                const active = mode === m.key;
                return (
                  <button
                    key={m.key}
                    type="button"
                    onClick={() => { setMode(m.key); setError(null); }}
                    aria-pressed={active}
                    className={`flex flex-1 items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold transition
                      ${active ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-700'}`}
                  >
                    <span aria-hidden>{m.icon}</span>
                    {t(`workflow.sendIn.mode.${m.key}`)}
                  </button>
                );
              })}
            </div>

            {/* ── NAME THE FAULT ──────────────────────────────────────────────────────────────── */}
            {mode === MODE_FAULT && (
              <div className="space-y-3">
                {/* The picked set, always visible so six-is-the-ceiling is felt, not discovered. */}
                {faults.length > 0 && (
                  <div className="flex flex-wrap items-center gap-1.5 rounded-xl border border-indigo-100 bg-indigo-50/50 p-2">
                    {faults.map((f, i) => (
                      <span
                        key={`${f.fault_catalog_id || f.text}-${i}`}
                        className="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-800 shadow-sm ring-1 ring-indigo-200"
                      >
                        {f.repeat_of_ticket_id && (
                          <span className="text-[10px] font-bold text-amber-600" title={t('workflow.sendIn.fault.repeatTitle', { id: f.repeat_of_ticket_id })}>
                            ↻
                          </span>
                        )}
                        {f.text}
                        <button
                          type="button"
                          onClick={() => setFaults((p) => p.filter((_, j) => j !== i))}
                          className="text-slate-400 hover:text-red-600"
                          aria-label={t('common.remove')}
                        >
                          <Icon.X className="h-3 w-3" />
                        </button>
                      </span>
                    ))}
                    <span className="ms-auto pe-1 text-[11px] font-medium text-indigo-600">
                      {t('workflow.sendIn.fault.count', { n: faults.length })}
                    </span>
                  </div>
                )}

                {/* ── WHAT DO YOU THINK IT IS? ─────────────────────────────────────────────────────
                    The curated short-list a mechanic works through for the fault just named — the SAME
                    list the Inspector is offered at the Diagnosis step, so the two can never quote
                    different vocabularies.

                    OPTIONAL, and one per fault. What is recorded is a SUSPICION (`suspected_cause`)
                    sitting beside the fault name, which is itself a claim — picking one does not
                    diagnose the car, skip the inspector or change where the request goes. Tap again to
                    unpick. (On the straight-to-garage door there is no inspector following, so the
                    server writes the pick onto the finding instead — same tap, more authority behind
                    it.) */}
                {causesForPicked.length > 0 && (
                  <div className="rounded-xl border border-slate-200 bg-white p-3">
                    <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
                      <Icon.Wrench className="h-3.5 w-3.5 text-slate-400" />
                      {t('workflow.sendIn.causes.title')}
                    </p>
                    <div className="space-y-2.5">
                      {causesForPicked.map(({ index, text, causes, picked }) => (
                        <div key={`${text}-${index}`}>
                          <p className="text-[11px] font-semibold text-slate-600">{text}</p>
                          <div className="mt-1 flex flex-wrap gap-1">
                            {causes.map((c) => {
                              const on = picked === c.id;
                              return (
                                <button
                                  key={c.id}
                                  type="button"
                                  onClick={() => pickCause(index, on ? null : c)}
                                  aria-pressed={on}
                                  title={c.description || undefined}
                                  className={`rounded-full px-2.5 py-0.5 text-[11px] ring-1 transition ${
                                    on
                                      ? 'bg-indigo-600 text-white ring-indigo-600'
                                      : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'
                                  }`}
                                >
                                  {c.root_cause}
                                </button>
                              );
                            })}
                          </div>
                        </div>
                      ))}
                    </div>
                    <p className="mt-2 border-t border-slate-100 pt-2 text-[11px] leading-relaxed text-slate-400">
                      {t('workflow.sendIn.causes.note')}
                    </p>
                  </div>
                )}

                {/* ── IS IT THIS AGAIN? — this car's own history, offered before the catalog. ──── */}
                {vehicleId && (recentLoading || recent.length > 0) && (
                  <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
                    <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
                      <Icon.Refresh className="h-3.5 w-3.5 text-slate-400" />
                      {t('workflow.sendIn.history.title')}
                    </p>
                    {recentLoading ? (
                      <p className="py-2 text-sm text-slate-400">{t('common.loading')}</p>
                    ) : (
                      <>
                        <p className="mb-2 text-[11px] leading-relaxed text-slate-500">
                          {t('workflow.sendIn.history.blurb')}
                        </p>
                        <div className="grid gap-1.5 sm:grid-cols-2">
                          {recent.map((h) => (
                            <HistoryChip
                              key={`${h.ticket_id}-${h.text}`}
                              fault={h}
                              picked={historyPicked(h)}
                              onToggle={toggleHistoryFault}
                              t={t}
                            />
                          ))}
                        </div>
                      </>
                    )}
                  </div>
                )}
                {vehicleId && !recentLoading && recent.length === 0 && (
                  <p className="rounded-lg bg-slate-50 px-3 py-2 text-[11px] text-slate-500 ring-1 ring-inset ring-slate-100">
                    {t('workflow.sendIn.history.none')}
                  </p>
                )}

                {/* ── THE VOCABULARY ────────────────────────────────────────────────────────── */}
                {options?.fault_groups?.length
                  ? <FaultPicker groups={options.fault_groups} picked={faults} onToggle={toggleCatalogFault} t={t} lang={lang} />
                  : <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-400">{t('common.loading')}</p>}

                {/* A note beside named faults is DETAIL about them, not a second answer — which is why
                    it is allowed here and a second reason code is not. */}
                <Textarea
                  label={t('workflow.sendIn.fault.detailLabel')}
                  rows={2}
                  maxLength={2000}
                  value={note}
                  onChange={(e) => setNote(e.target.value)}
                  placeholder={t('workflow.sendIn.fault.detailPlaceholder')}
                />
              </div>
            )}

            {/* ── PICK A REASON ───────────────────────────────────────────────────────────────── */}
            {mode === MODE_REASON && (
              <div className="space-y-2">
                {Object.entries(reasonList).map(([code, label]) => {
                  const active = reasonCode === code;
                  return (
                    <button
                      key={code}
                      type="button"
                      onClick={() => setReason(code)}
                      aria-pressed={active}
                      className={`flex w-full items-center gap-3 rounded-xl border px-3 py-2.5 text-start transition
                        ${active ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500' : 'border-slate-200 bg-white hover:border-slate-300'}`}
                    >
                      <span className={`flex h-4 w-4 shrink-0 items-center justify-center rounded-full border ${active ? 'border-indigo-600' : 'border-slate-300'}`}>
                        {active && <span className="h-2 w-2 rounded-full bg-indigo-600" />}
                      </span>
                      {/* The catalog answer wins when it has one; the server's English is the fallback,
                          so a reason code added on the server shows up here without a frontend deploy. */}
                      <span className="text-sm font-medium text-slate-800">
                        {tf(`workflow.sendIn.reason.${door}.${code}`, label)}
                      </span>
                    </button>
                  );
                })}
                {reasonCode === 'other' && (
                  <Textarea
                    label={t('workflow.sendIn.reason.otherLabel')}
                    required
                    rows={2}
                    maxLength={2000}
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    placeholder={t('workflow.sendIn.reason.otherPlaceholder')}
                  />
                )}
              </div>
            )}

            {/* ── WRITE A NOTE ────────────────────────────────────────────────────────────────── */}
            {mode === MODE_NOTE && (
              <Textarea
                label={t('workflow.sendIn.note.label')}
                required
                rows={4}
                maxLength={2000}
                value={note}
                onChange={(e) => setNote(e.target.value)}
                placeholder={t('workflow.sendIn.note.placeholder')}
              />
            )}
          </div>
        )}

        {/* ── OBSERVATION — a note on the car, and only a request if asked for ─────────────────── */}
        {isObservation && (
          <>
            <Textarea
              label={t('workflow.field.observationNote')}
              required
              rows={4}
              maxLength={2000}
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder={t('workflow.ph.observationNote')}
            />
            <label className="flex items-start gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-600 ring-1 ring-inset ring-slate-100">
              <input
                type="checkbox"
                checked={observationRaise}
                onChange={(e) => setObservationRaise(e.target.checked)}
                disabled={!!inFlight}
                className="mt-0.5"
              />
              <span>{t(inFlight ? 'workflow.hint.inFlightObservationRaise' : 'workflow.hint.observationRaise')}</span>
            </label>
          </>
        )}

        {/* WHAT THIS BUTTON ACTUALLY DOES — stated before it is pressed, not discovered after. */}
        <p className={`rounded-lg px-3 py-2 text-[11px] leading-relaxed ring-1 ring-inset ${door === DOOR_DISPATCH
          ? 'bg-amber-50/70 text-amber-800 ring-amber-500/20'
          : 'bg-slate-50 text-slate-500 ring-slate-100'}`}>
          {t(door === DOOR_DISPATCH
            ? 'workflow.sendIn.outcome.dispatch'
            : isObservation
              ? 'workflow.sendIn.outcome.observation'
              : isOffice
                ? 'workflow.sendIn.outcome.office'
                : 'workflow.sendIn.outcome.request')}
        </p>

        {/* A customer issue is NOT an inspection request — route it to the right entity. */}
        <p className="rounded-lg bg-slate-50 px-3 py-2 text-[11px] leading-relaxed text-slate-500 ring-1 ring-inset ring-slate-100">
          {t('A customer reported a problem? Log it in the Complaints Center. Just noticed something on return? Use Driver Observations.')}{' '}
          <a href="/complaints" className="font-semibold text-indigo-600 hover:underline">{t('Open the Complaints Center')}</a>
        </p>

        {error && <p className="text-sm font-medium text-red-600">{error}</p>}
      </div>
    </Modal>
  );
}
