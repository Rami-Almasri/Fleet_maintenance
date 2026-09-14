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
// Both doors then ask the SAME question — "why?" — and accept exactly one KIND of answer, never two.
// BOTH of them are countable, which is the point: every request that has ever been filed can be grouped
// by what it was filed for. Free text was the third answer and was withdrawn for failing exactly that.
//
//   NAME THE WORK    — from the live vocabularies, in one searchable list:
//                        · FAULTS, or (the good bit) what THIS car was already in the shop for. The single
//                          likeliest reason a car goes back in is the last repair not holding, so its own
//                          history is offered first, one tap, and picking it stamps repeat_of_ticket_id —
//                          the requester's CLAIM, recorded as theirs. Nobody here is saying the fault
//                          recurred; only the workshop's confirmation says that.
//                        · SERVICES — oil change, tyre rotation, A/C service. Nothing is wrong with the
//                          car; work is simply due. Until these were offered, the only way to say "it's
//                          due an oil change" was the reason code "Booked service work", which never said
//                          WHICH, so the supervisor got a ticket with no job on it.
//                      The two live in one picker with the service categories BADGED, exactly as the
//                      Inspector's own findings picker shows them. The badge is what keeps them apart, not
//                      the menu: the row's catalog identity decides how it is stored and counted, so a tap
//                      cannot file planned work as a failure. Both may be named together — "it pulls left
//                      and it's due an oil change" is one answer about two jobs.
//   PICK A REASON    — a code from the door's own list, for when you honestly cannot name the work. The
//                      list is data (request_reasons) and the office edits it in place, so a reason that
//                      is genuinely missing gets ADDED rather than typed once into a free-text box.
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
import { Input, Select, Textarea } from '../ui/Field';
import VehicleStatusSelect from './VehicleStatusSelect';
// Driver or recovery truck — the same two buttons the review card asks with, so the one question about
// a car's condition is never asked two different ways.
import TransportChoice from './TransportChoice';
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
// AN ACCIDENT IS NOT A REPAIR REQUEST, and this option exists to stop it being filed as one.
//
// It sits in this list because this is where a person goes when something has happened to a car —
// asking them to know, in that moment, that a crash lives in a different part of the app is how a
// crash gets typed into a fault note and loses its police report, its liability question and its
// insurer. But picking it leaves the ticket API entirely: it POSTs to /accidents, opens an accident
// CASE, and the repair (if there is one) is raised later from that case as an ordinary ticket
// parented to it. Nothing about the "why?" question below applies — an accident is its own answer.
const VOICE_ACCIDENT    = 'accident';

// The ways of answering "why?" (Maintenance::REPORT_MODES). `fault` and `service` are both NAMED WORK and
// may be sent together; a reason code excludes them, and they exclude it. MODE_SERVICE is not a tab of
// its own — it labels the service half of the one picker and the payload field it fills.
//
// There is no MODE_NOTE here any more. The server still knows `note` as a stored mode — breakdown intake
// and complaint triage write one, and the tickets that carry it must keep reading — but this form no
// longer OFFERS it: see the note where the tab used to be rendered.
const MODE_FAULT   = 'fault';
const MODE_SERVICE = 'service';
const MODE_REASON  = 'reason';

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

/**
 * Fold a search string down to what a person MEANT to type, so Arabic search behaves the way Latin
 * search already did by accident.
 *
 * Typing "اطار" and never finding "إطار مستهلك" reads as a missing fault, not a missing hamza — and
 * nobody hunting a fault mid-shift is going to guess that the alef was the problem. So the alef forms
 * collapse to one, ta marbuta to ha, alef maqsura to ya, and the diacritics and tatweel that a keyboard
 * may or may not produce are dropped. Applied to BOTH sides of the comparison, so it is a widening of
 * what matches and never a narrowing.
 */
function foldSearch(s) {
  return (s || '')
    .toLowerCase()
    .replace(/[ً-ْٰـ]/g, '')   // harakat + superscript alef + tatweel
    .replace(/[آأإاٱ]/g, 'ا')   // آ أ إ ٱ → ا
    .replace(/ة/g, 'ه')                  // ة → ه
    .replace(/ى/g, 'ي')                  // ى → ي
    .replace(/ؤ/g, 'و')                  // ؤ → و
    .replace(/ئ/g, 'ي');                 // ئ → ي
}

/**
 * THE WORK VOCABULARY — faults and services in ONE searchable, category-grouped list.
 *
 * They were briefly two tabs, and that was wrong: a person who knows the car is due an oil change should
 * type "oil" and find it, not first work out that an oil change is filed under a different question from
 * a brake noise. The Inspector's own findings picker (FindingsPicker) has always shown the two together
 * with the service categories badged, and this is the same list saying the same thing.
 *
 * The BADGE is what keeps it honest, not the separation. A service group is marked PLANNED SERVICE at the
 * point of selection, and the row's own catalog identity — not which list it sat in — decides how it is
 * stored (`requested_services`, kind=service) and counted. So the tap cannot mislabel the work: only the
 * catalog can, and it doesn't.
 *
 * Each group declares its `kind`; selection is a toggle and six per kind is the ceiling.
 */
function WorkPicker({ groups, isPicked, onToggle, t, tp, lang }) {
  const [q, setQ] = useState('');
  const [open, setOpen] = useState(null);   // expanded category key; null = none

  // Both names are always searched, whichever language the screen is in: the workshop is bilingual and a
  // person who knows a part as "kalatch" should not have to switch the UI to find الكلتش.
  const needle = foldSearch(q.trim());
  const shown = useMemo(() => {
    if (!needle) return groups;
    return groups
      .map((g) => ({
        ...g,
        items: g.items.filter((i) =>
          foldSearch(`${i.name} ${i.name_ar || ''}`).includes(needle)),
      }))
      .filter((g) => g.items.length);
  }, [groups, needle]);

  // Searching is its own answer to "which category?" — collapsing the results behind an accordion
  // would hide what the person just asked for.
  const expanded = (key) => !!needle || open === key;

  // "every 10,000 km · 6 months" — the cadence a service runs on, straight off the catalog row. It says
  // nothing about how far THIS car has gone since its last one.
  //
  // "every" is a shared prefix carried once, so a service with both halves does not read
  // "every … · every …". The month count goes through tp() rather than one interpolated string because
  // Arabic needs three different words here — 6 is «6 أشهر», 24 is «24 شهرًا» — and a single form gets
  // one of them wrong on every row. km is invariant («كم») and needs no plural.
  const cadence = (i) => {
    const every  = t('workflow.sendIn.service.every');
    const km     = i.interval_km ? t('workflow.sendIn.service.km', { n: i.interval_km.toLocaleString() }) : null;
    const months = i.interval_months ? tp('workflow.sendIn.service.months', i.interval_months) : null;
    const parts  = [km, months].filter(Boolean);

    return parts.length ? `${every} ${parts.join(' · ')}` : '';
  };

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

        {shown.map((g) => {
          const isService = g.kind === MODE_SERVICE;
          return (
            <div key={`${g.kind}:${g.key}`} className="mb-1 last:mb-0">
              <button
                type="button"
                onClick={() => setOpen(open === `${g.kind}:${g.key}` ? null : `${g.kind}:${g.key}`)}
                className="flex w-full items-center gap-1.5 rounded-lg px-2 py-1.5 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 hover:bg-slate-50"
              >
                <span className="min-w-0 truncate">{(lang === 'ar' && g.label_ar) || g.label}</span>
                {/* PLANNED WORK, NOT A DEFECT — the same marker the Inspector's picker carries, so that
                    "Oil Change" is never read as something found wrong with the car. */}
                {isService && (
                  <span className="shrink-0 rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold normal-case tracking-normal text-sky-700 ring-1 ring-inset ring-sky-200">
                    {t('workflow.sendIn.service.badge')}
                  </span>
                )}
                <span className="min-w-0 flex-1" />
                <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-500">{g.items.length}</span>
                <Icon.ChevronDown className={`h-3.5 w-3.5 shrink-0 transition-transform ${expanded(`${g.kind}:${g.key}`) ? 'rotate-180' : ''}`} />
              </button>

              {expanded(`${g.kind}:${g.key}`) && (
                <div className={isService ? 'grid gap-1.5 px-2 py-2 sm:grid-cols-2' : 'flex flex-wrap gap-1.5 px-2 py-2'}>
                  {g.items.map((i) => {
                    const on    = isPicked(g.kind, i);
                    const every = isService ? cadence(i) : '';
                    // A service wears its cadence, so it gets a card; a fault is a bare chip as before.
                    if (isService) {
                      return (
                        <button
                          key={i.id}
                          type="button"
                          onClick={() => onToggle(g.kind, i)}
                          aria-pressed={on}
                          className={`rounded-lg border px-2.5 py-1.5 text-start transition ${on
                            ? 'border-sky-500 bg-sky-50 ring-1 ring-sky-500'
                            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'}`}
                        >
                          <span className="block text-xs font-semibold text-slate-800">
                            {(lang === 'ar' && i.name_ar) || i.name}
                          </span>
                          {every && <span className="mt-0.5 block text-[10px] text-slate-500">{every}</span>}
                        </button>
                      );
                    }
                    return (
                      <button
                        key={i.id}
                        type="button"
                        onClick={() => onToggle(g.kind, i)}
                        aria-pressed={on}
                        className={`rounded-full px-2.5 py-1 text-xs font-medium transition ${on
                          ? 'bg-indigo-600 text-white shadow-sm'
                          : 'bg-slate-100 text-slate-700 hover:bg-slate-200'}`}
                      >
                        {(lang === 'ar' && i.name_ar) || i.name}
                      </button>
                    );
                  })}
                </div>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}


// "This car is already in the shop" — the one test, so the pickable list and the greyed-out list can
// never disagree about which side a car falls on.
const inShop = (v) => !!(v.under_maintenance || v.operational_status === 'maintenance');

export default function SendCarInModal({ vehicles = [], onClose, onDone }) {
  const { t, tf, tp, lang } = useI18n();
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
  const [services, setServices]   = useState([]);   // [{ text, slug, service_catalog_id }] — planned work, never faults
  const [reasonCode, setReason]   = useState('');
  const [note, setNote]           = useState('');
  const [observationRaise, setObservationRaise] = useState(false);
  // ── the accident path's own fields ───────────────────────────────────────────────────────────
  // Deliberately few. What is asked at the roadside is what somebody standing at the roadside can
  // actually answer; the police report, the liability verdict and the insurer are worked through the
  // case's own stages afterwards. A form that demanded them here would produce no report at all.
  const [acc, setAcc] = useState({
    occurred_at: '', location: '', accident_type: '',
    drivable: null, towing_required: null,
    other_party_involved: false, other_party_name: '', other_party_plate: '',
    // The damage types somebody can already SEE, picked from the catalog rather than typed. It used
    // to be a comma-separated box, which meant "front bumper" / "Front Bumper" / "f. bumper" were
    // three different areas the moment three people filed a report — countable in the schema and
    // uncountable in practice. WHERE on the car is deliberately not asked here: at the roadside the
    // damage type is answerable and the precise panel often is not, and the assessment stage exists
    // to add it. @see AccidentDamagePicker
    damage_ids: [],
  });
  // The damage vocabulary, off the same /accidents/options call that produces the context preview.
  const [accVocab, setAccVocab] = useState(null);
  // Who the SERVER says has the car — read-only, and shown while they are still typing rather than
  // sprung on them after they submit. What is actually frozen onto the case is resolved again
  // server-side at report time; this is a courtesy, not the record.
  const [accContext, setAccContext] = useState(null);
  // Garage door only: how the car physically gets there. Null until somebody says — see TransportChoice.
  // `unit` is the towing unit on the recovery answer, cleared by the picker the moment it stops being one.
  const [transport, setTransport] = useState(null);
  const [unit, setUnit] = useState({ name: '', phone: '' });

  const navigate = useNavigate();
  const [options, setOptions] = useState(null);     // { fault_groups, fault_causes, reasons, filed_by, … }
  const [recent, setRecent]   = useState([]);       // this car's own fault history
  const [recentLoading, setRecentLoading] = useState(false);
  const [inFlight, setInFlight] = useState(null);

  const [saving, setSaving] = useState(false);
  const [error, setError]   = useState(null);

  // Editing the reason list itself, from inside the form that uses it. The office maintains this list;
  // making them leave the form to do it is how a missing reason becomes a note nobody can count.
  const [adding, setAdding]         = useState(false);
  const [newReason, setNewReason]   = useState('');
  const [reasonBusy, setReasonBusy] = useState(false);
  const [reasonNotice, setReasonNotice] = useState(null);

  const isObservation = door === DOOR_INSPECTION && voice === VOICE_OBSERVATION;
  const isOffice      = door === DOOR_INSPECTION && voice === VOICE_OFFICE && canManage;
  // Offered only to people the accident route will actually accept — the bar is deliberately low
  // (every field role holds `accidents.report`), but showing it to somebody who would be refused
  // means letting them fill in a whole crash report and then losing it.
  const canReportAccident = can('accidents.report');
  const isAccident    = door === DOOR_INSPECTION && voice === VOICE_ACCIDENT && canReportAccident;
  // The server is the authority on which doors are usable; until its answer lands, fall back to the
  // client's own permission cache so the form is never briefly blank.
  const canDispatch = options ? !!options.can_dispatch : (canInitiate || canManage);
  const canRequest  = options ? !!options.can_request  : (can('maintenance.logistics') || canManage);

  // The fleet, split once at the door: cars that can be sent in, and cars that are already in the shop.
  // The second half is not thrown away — the picker lists it greyed out so a plate search gets an
  // answer ("it's already in") instead of silence ("No matches").
  const pickableVehicles = useMemo(() => vehicles.filter((v) => !inShop(v)), [vehicles]);
  const inShopVehicles   = useMemo(() => vehicles.filter(inShop), [vehicles]);

  // ── loads ──────────────────────────────────────────────────────────────────────────────────────
  // The vocabulary, the reason lists and the filer's name: one call, once, car-independent. Pulled out
  // of the effect because adding or removing a reason has to re-read it — the picker must show the
  // server's answer, not a locally patched guess about what the server did.
  const loadOptions = useCallback(async () => {
    try {
      const r = await api.get('/maintenance-tickets/request-options');
      return r?.data?.data || null;
    } catch {
      return null;
    }
  }, []);

  useEffect(() => {
    let alive = true;
    loadOptions().then((data) => { if (alive) setOptions(data); });
    return () => { alive = false; };
  }, [loadOptions]);

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

  // WHO HAS THIS CAR — asked of the server the moment there is a car and a time to ask about, and
  // re-asked when either changes. Anchored on the accident's OWN timestamp rather than on now: a
  // crash reported on Monday about Saturday belongs to Saturday's renter, and "who has it now" is
  // exactly the wrong answer. Best-effort — a failed fetch just leaves the banner off.
  useEffect(() => {
    if (!isAccident || !vehicleId) { setAccContext(null); return undefined; }
    let alive = true;
    const params = new URLSearchParams({ vehicle_id: String(Number(vehicleId)) });
    if (acc.occurred_at) params.set('occurred_at', acc.occurred_at);
    api.get(`/accidents/options?${params.toString()}`)
      .then((r) => {
        if (!alive) return;
        setAccContext(r?.data?.data?.context_preview || null);
        setAccVocab(r?.data?.data || null);
      })
      .catch(() => { if (alive) setAccContext(null); });
    return () => { alive = false; };
  }, [isAccident, vehicleId, acc.occurred_at]);

  // An observation asks no "why?" at all — nothing is being requested, so the whole tab strip is hidden
  // and `mode` means nothing while it is selected. Coming BACK out of it, land on the naming tab: the
  // mode left behind must be one that still exists, and since WRITE A NOTE was withdrawn, anything that
  // assumed it would strand the form on a tab with nothing under it.
  useEffect(() => { if (!isObservation) setMode(MODE_FAULT); }, [isObservation]);

  // Switching doors changes which reason list is legal, so a reason picked on the other door would be
  // refused on submit. Clear it at the moment of the switch instead of at the moment of the refusal.
  // Named work is NOT cleared: both doors accept it, and re-picking an oil change because you changed
  // your mind about which queue it belongs in would be the form punishing a correction.
  useEffect(() => {
    setReason('');
    setError(null);
    // The list-editing panel belongs to the door it was opened on, and so does anything it just said.
    setAdding(false);
    setNewReason('');
    setReasonNotice(null);
  }, [door]);

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

  // Planned work is a flat pick — no history strip, no suspected cause, no repeat claim. There is nothing
  // to suspect about an oil change and a service repeating is the schedule doing its job, not a recurrence.
  const toggleService = useCallback((s) => {
    setServices((prev) => {
      const on = prev.some((p) => p.service_catalog_id === s.id);
      if (on) return prev.filter((p) => p.service_catalog_id !== s.id);
      if (prev.length >= 6) return prev;
      return [...prev, { text: s.name, slug: s.slug, service_catalog_id: s.id }];
    });
  }, []);

  // ONE LIST for the picker: the fault categories, then the service categories carrying their kind and
  // their cadence. Built here rather than in the picker so the two server payloads stay exactly as the
  // server sent them and only the presentation is joined.
  const workGroups = useMemo(() => ([
    ...(options?.fault_groups || []).map((g) => ({ ...g, kind: MODE_FAULT,   items: g.faults })),
    ...(options?.service_groups || []).map((g) => ({ ...g, kind: MODE_SERVICE, items: g.services })),
  ]), [options]);

  const isPicked = useCallback((kind, item) => (kind === MODE_SERVICE
    ? services.some((p) => p.service_catalog_id === item.id)
    : faults.some((p) => p.fault_catalog_id === item.id)), [faults, services]);

  const toggleWork = useCallback((kind, item) => {
    if (kind === MODE_SERVICE) toggleService(item);
    else toggleCatalogFault(item);
  }, [toggleService, toggleCatalogFault]);

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

  // ── the reason list, and editing it ────────────────────────────────────────────────────────────
  // Straight off the server (`request_reasons`, live rows only, this door's). Order is the office's own.
  const reasonEntries = useMemo(() => Object.entries(options?.reasons?.[door] || {}), [options, door]);
  // Who may edit it. The server's answer wins; the client's permission cache only covers the gap before
  // the options land, and /request-reasons refuses anyone it disagrees with either way.
  const canEditReasons = options ? !!options.can_edit_reasons : canManage;

  // ADD — the person types the sentence, the server derives the stable code from it. Codes are never
  // typed here: two people inventing keys for the same reason is how one list becomes two.
  const addReason = useCallback(async () => {
    const label = newReason.trim();
    if (!label || reasonBusy) return;
    setReasonBusy(true);
    setError(null);
    setReasonNotice(null);
    try {
      const r = await api.post('/request-reasons', { door, label });
      setOptions(await loadOptions());
      setAdding(false);
      setNewReason('');
      // Select what they just added — they typed it because it is the reason they mean.
      const code = r?.data?.data?.code;
      if (code) setReason(code);
    } catch (e) {
      setError(e?.response?.data?.message || t('workflow.error.generic'));
    } finally {
      setReasonBusy(false);
    }
  }, [newReason, reasonBusy, door, loadOptions, t]);

  // REMOVE — retires it server-side. Nothing is deleted: the reason stops being offered, and every
  // ticket already filed under it still reads as what it was filed under. The server says how many
  // those are, and that sentence is shown rather than a bare "removed".
  const retireReason = useCallback(async (code, label) => {
    const row = (options?.reason_rows?.[door] || []).find((x) => x.code === code);
    if (!row?.id || reasonBusy) return;
    setReasonBusy(true);
    setError(null);
    setReasonNotice(null);
    try {
      const r = await api.delete(`/request-reasons/${row.id}`);
      setOptions(await loadOptions());
      // A reason that was picked and has just been withdrawn is no longer an answer.
      setReason((prev) => (prev === code ? '' : prev));
      const used = r?.data?.data?.tickets_using_it || 0;
      // Said inline rather than as a toast, because it is reassurance about what did NOT happen: the
      // tickets filed under this reason are untouched and still read.
      setReasonNotice(used > 0
        ? t('workflow.sendIn.reason.removedKept', { label, n: used })
        : t('workflow.sendIn.reason.removed', { label }));
    } catch (e) {
      setError(e?.response?.data?.message || t('workflow.error.generic'));
    } finally {
      setReasonBusy(false);
    }
  }, [options, door, reasonBusy, loadOptions, t]);

  // ── validity ───────────────────────────────────────────────────────────────────────────────────
  // An OBSERVATION is judged on its own terms and always was: it is a note on the car, it opens nothing,
  // and it answers no question — so it is ready when there are words in it. Stated separately here
  // because it used to lean on the WRITE A NOTE tab's rule, and that tab is gone; leaving it to fall
  // through would have left Save permanently dead on the observation path.
  //
  // Otherwise the naming tab is satisfied by EITHER kind of named work — a car going in for an oil change
  // alone is a complete answer to "why is it going in?" — and a reason is complete on its own, now that
  // the free-text "Something else" that needed a sentence beside it is gone.
  //
  // AN ACCIDENT answers the question by being one. What is required is a car and a sentence saying
  // what happened — nothing else, on purpose: the police report, the damage assessment, the
  // liability verdict and the insurer are stages of the case, and demanding any of them from
  // somebody standing beside a damaged car is how a crash goes unrecorded.
  const statementReady = isAccident
    ? !!note.trim()
    : isObservation
      ? !!note.trim()
      : (mode === MODE_FAULT  && (faults.length > 0 || services.length > 0))
        || (mode === MODE_REASON && !!reasonCode);

  // A car with a request already in flight cannot be sent straight to a garage — the server refuses that
  // door on the same fact, so the form must say so before it is filled in rather than after it is
  // submitted. An observation is a note, not a request, so that path stays open.
  //
  // ONE exception, and the server computes it (`can_supersede`) rather than the form guessing: nothing has
  // been decided on the open request yet, so committing the car to a workshop answers it outright and it
  // is stood down on submit. Not once the Inspector holds it — that is assigned work.
  const canSupersede = !!inFlight?.can_supersede;
  // THE OTHER WAY THROUGH, and the one that closes the trap this form used to set. When the open request
  // is already the Inspector's, a new ticket must not be opened behind his back — but the DECISION
  // ("no test needed, it needs a garage") is still a legitimate one, so it is applied to that very
  // request instead: it is converted into a Needs Dispatch ticket and no test drive happens. The server
  // computes the flag (`can_send_to_garage`) from the same rule the endpoint enforces; it is false only
  // while he is actually driving the car, where the test is already under way and his report lands the
  // ticket in that same dispatch queue anyway.
  const canConvert = !!inFlight?.can_send_to_garage && !canSupersede && door === DOOR_DISPATCH;
  // The request door ADDS to an open request rather than opening a second one, so an open request is not
  // a refusal there — it only changes what the button does and what happens next. That holds for the
  // scanner's own suggestion too, and identically whether the car is on hire or in the yard: its list of
  // checks is kept and this report is written underneath it. The garage door has no dead end left either
  // (see canConvert) except the one that is not a refusal at all: the Inspector is driving the car right
  // now, so the test this door exists to skip is already happening.
  // An accident is never "added to" an open request and is never refused because of one: it opens a
  // different entity entirely, and a car with a test drive pending can still be crashed.
  const isAdding = !isObservation && !isAccident && !!inFlight && door === DOOR_INSPECTION;
  // What is left after all three ways through — adding to it, standing it down, or converting it — is
  // the ONE state where the garage door genuinely has nothing to do: the Inspector is driving the car
  // this second. Nothing is refused there either; the answer to "does it need a test?" is being produced
  // as we speak, and his report puts the car in the dispatch queue when it is.
  const blocked = !isObservation && !isAccident && !!inFlight && !isAdding && !canSupersede && !canConvert;
  const disabled = saving || !vehicleId || blocked || !statementReady;

  // ── submit ─────────────────────────────────────────────────────────────────────────────────────
  // Named work (either kind, or both) OR a reason code — never both sides, which the server also refuses.
  // The towing unit rides along ONLY on the recovery answer — sending a unit name beside "company
  // driver" would leave the ticket claiming both a driver and a truck.
  const recoveryBody = () => (transport === 'recovery' ? {
    recovery_unit_name:  unit.name.trim()  || null,
    recovery_unit_phone: unit.phone.trim() || null,
  } : {});

  const statementBody = () => ({
    reported_faults:     mode === MODE_FAULT && faults.length   ? faults   : undefined,
    requested_services:  mode === MODE_FAULT && services.length ? services : undefined,
    request_reason_code: mode === MODE_REASON ? reasonCode : undefined,
  });

  // WHERE THEY LAND after asking for a test. The useful next step is the request ITSELF — the one this
  // just wrote or added to — so deep-link the exact record rather than dropping them at the top of a
  // queue to go and find their own car: /inspection-review highlights `?ticket=<id>` and scrolls it in.
  //
  // Only while it is actually awaiting a decision, though. A Controller's own request goes straight past
  // the gate to the Inspector, and sending her to a queue that by definition does not carry it would be a
  // dead end with a "no longer awaiting review" toast at the end of it.
  const landOnRecord = (record) => {
    const id    = record?.id || inFlight?.ticket_id;
    const stage = record?.workflow_status || inFlight?.state;
    if (id && stage === 'pending_review') navigate(`/inspection-review?ticket=${id}`);
  };

  async function submit() {
    if (disabled) return;
    setSaving(true);
    setError(null);
    try {
      // A0) AN ACCIDENT — a different entity again, and the one that must never be filed as a repair
      //     request. It opens an accident CASE: the police report, the liability question and the
      //     insurer all hang off that, and the repair (when there is one) is raised from the case as
      //     an ordinary ticket parented to it. They land on the case, because the next thing anybody
      //     needs to do is answer the questions it has just opened.
      if (isAccident) {
        const resp = await api.post('/accidents', {
          vehicle_id:  Number(vehicleId),
          description: note.trim(),
          occurred_at: acc.occurred_at || null,
          location:    acc.location.trim() || null,
          accident_type: acc.accident_type || null,
          drivable:        acc.drivable,
          towing_required: acc.towing_required,
          other_party_involved: acc.other_party_involved,
          other_party_name:  acc.other_party_involved ? (acc.other_party_name.trim()  || null) : null,
          other_party_plate: acc.other_party_involved ? (acc.other_party_plate.trim() || null) : null,
          // The areas somebody could already see, one item each — countable from the first minute,
          // rather than a paragraph nobody can group by. Anything they cannot name yet is added at
          // the assessment stage by whoever actually looks at the car.
          damage_items: acc.damage_ids.slice(0, 30).map((id) => ({ damage_catalog_id: id })),
        });
        const created = resp?.data?.data;
        onDone?.(t('Accident reported — the case is open'));
        if (created?.id) navigate(`/accidents/${created.id}`);
        return;
      }

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
        // B1) …except when the car already has a request the Inspector holds. A SECOND ticket must not
        //     be opened behind him — but this decision is not a second ticket, it is a better answer to
        //     the one already open, so it is applied to that request: it converts to Needs Dispatch and
        //     the test drive never happens. Anything named here rides along and becomes the work the
        //     supervisor dispatches. This is the path that used to be a refusal ending at an Approve
        //     button — the one that started the very test drive this door exists to skip.
        if (canConvert && inFlight?.ticket_id) {
          await api.post(`/maintenance-tickets/${inFlight.ticket_id}/review/dispatch`, {
            customer_complaint: note.trim() || null,
            // On THIS door a reason is the decision's reason ("the parts are in"), which is exactly what
            // the conversion endpoint stores — so it travels as itself rather than inside the statement.
            request_reason_code: mode === MODE_REASON ? reasonCode : undefined,
            transport: transport || null,
            ...recoveryBody(),
            ...statementBody(),
          });
          onDone?.(t('workflow.sendIn.success.dispatchConverted'));
          return;
        }

        await api.post('/maintenance-tickets/direct-dispatch', {
          vehicle_id: Number(vehicleId),
          customer_complaint: note.trim() || null,
          // HOW it travels — recorded with the decision, so the supervisor picking the garage inherits
          // the answer from the person who has actually seen the car.
          transport: transport || null,
          ...recoveryBody(),
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
        // Same rule on this door: if it was added to an open request, that card is the next step.
        const officeRecord = officeResp?.data?.data;
        onDone?.(isAdding
          ? t('workflow.sendIn.success.added')
          : t('workflow.success.requestOffice', { who: '' }).trim());
        landOnRecord(officeRecord);
        return;
      }

      // D) The driver's request — into the Controllers' review queue.
      const resp = await api.post('/maintenance-tickets/request', {
        vehicle_id: Number(vehicleId),
        trigger_reason: VOICE_DROVE,
        customer_complaint: note.trim() || null,
        ...statementBody(),
      });
      // Whether this opened a request or was added to one already open, the card it produced is the
      // useful next step — hand them straight to that exact record instead of leaving them to find it.
      const record = resp?.data?.data;
      onDone?.(t(isAdding ? 'workflow.sendIn.success.added' : 'workflow.sendIn.success.request'));
      landOnRecord(record);
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

  // TWO tabs. Naming a service is not a different QUESTION from naming a fault, it is a different answer
  // to the same one, so it lives in the same picker rather than a tab of its own — and "write a note" was
  // withdrawn, because a free sentence is not an answer that can be counted.
  const modes = [
    { key: MODE_FAULT,  icon: '🔧' },
    { key: MODE_REASON, icon: '📋' },
  ];

  const filedBy = options?.filed_by?.name || user?.name;

  const footer = (
    <>
      <Button variant="ghost" onClick={onClose} disabled={saving}>{t('common.cancel')}</Button>
      <Button
        variant={isAccident ? 'danger' : door === DOOR_DISPATCH ? 'warning' : 'primary'}
        onClick={submit}
        disabled={disabled}
        loading={saving}
      >
        {isAccident
          ? t('Report the accident')
          : isObservation
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

          {/* A car already in maintenance is being handled — it can't be picked, so nobody opens a
              duplicate for it. It is NOT dropped from the list, though: searching its plate used to
              answer "No matches", which reads as "this car isn't in the fleet" rather than "it's
              already in the shop". It is listed greyed out, saying so. Rented cars stay selectable.
              An observation opens nothing, so for that path every car stays pickable. */}
          {/* An accident, like an observation, opens no ticket — so every car stays pickable. A car
              can be crashed while a driver is taking it TO the garage, and refusing to record that
              because it is already in the shop would lose the only report of it. */}
          <VehicleStatusSelect
            value={vehicleId}
            onChange={setVehicleId}
            vehicles={(isObservation || isAccident) ? vehicles : pickableVehicles}
            blocked={(isObservation || isAccident) ? [] : inShopVehicles}
            placeholder={t('workflow.ph.searchVehicle')}
            // The picker's own warnings are about opening a TICKET — "will trigger a new diagnostic
            // entry", "make sure it has been returned before starting a test drive". Neither is true
            // of an accident report, and the second actively tells somebody to delay filing a crash
            // on a car that is still out with a customer, which is the commonest accident there is.
            warnings={!isAccident}
          />
          {!isObservation && !isAccident && <p className="mt-1 text-xs text-slate-400">{t('workflow.hint.requestHideMaintenance')}</p>}

          {/* Already in flight — say what stage it's at, who raised it and what they reported, so the
              point that's already been made is visible before this one is written out. */}
          {/* Hidden on the accident path: an open request says nothing about whether a car can be
              crashed, and the three sentences below all describe what would happen to a TICKET. */}
          {inFlight && !isAccident && (
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
                      // The open request is the Inspector's, but nothing about it makes a car whose
                      // fault is already known undiagnosable. Say what THIS button will do to it —
                      // convert it, no test drive — rather than refusing the decision outright.
                      : canConvert
                        ? 'workflow.hint.inFlightDispatchConvert'
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
              {[
                VOICE_DROVE,
                VOICE_OBSERVATION,
                ...(canManage ? [VOICE_OFFICE] : []),
                // LAST in the list and visually apart, because it is the one answer that leaves this
                // form's whole model behind. Last rather than first on purpose: it is the rarest of
                // the four, and putting the loudest option at the top is how ordinary reports start
                // getting filed as accidents.
                ...(canReportAccident ? [VOICE_ACCIDENT] : []),
              ].map((v) => {
                const active = voice === v;
                const crash  = v === VOICE_ACCIDENT;
                return (
                  <button
                    key={v}
                    type="button"
                    onClick={() => { setVoice(v); setError(null); }}
                    aria-pressed={active}
                    className={`flex items-start gap-3 rounded-xl border px-3 py-2.5 text-start transition
                      ${active
                        ? (crash ? 'border-rose-500 bg-rose-50 ring-1 ring-rose-500' : 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500')
                        : (crash ? 'border-rose-200 bg-white hover:border-rose-300' : 'border-slate-200 bg-white hover:border-slate-300')}`}
                  >
                    <span className={`mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border ${active ? (crash ? 'border-rose-600' : 'border-indigo-600') : 'border-slate-300'}`}>
                      {active && <span className={`h-2 w-2 rounded-full ${crash ? 'bg-rose-600' : 'bg-indigo-600'}`} />}
                    </span>
                    <span className="min-w-0">
                      <span className="block text-sm font-semibold text-slate-800">
                        {crash
                          ? `🚨 ${t('An accident happened')}`
                          : t(v === VOICE_OFFICE ? 'workflow.reason.office.label' : `workflow.reason.driver.${v}.label`)}
                      </span>
                      <span className="block text-xs text-slate-500">
                        {crash
                          ? t('A collision or impact — this opens an accident case, not a repair request')
                          : t(v === VOICE_OFFICE ? 'workflow.reason.office.sub' : `workflow.reason.driver.${v}.sub`)}
                      </span>
                    </span>
                  </button>
                );
              })}
            </div>

            {/* Who may file this at all — advisory, not a hard gate. It speaks to the driver-voice
                choices only: the office choice is explicitly for a car nobody here has been in. */}
            {!isOffice && !isAccident && (
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

        {/* ── WHY — one of three, never two ─────────────────────────────────────────────────────
            Not asked on the accident path: "there was a crash" IS the answer, and offering a fault
            picker beside it would invite somebody to name the damage as a fault — which is exactly
            how an accident loses its police report and its insurer and becomes a body-shop ticket. */}
        {!isObservation && !isAccident && (
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

                {/* The picked SERVICES, in their own tray. Same picker, but shown apart once chosen: the
                    ticket treats them differently, so the person is told that before they submit rather
                    than discovering it on the board. */}
                {services.length > 0 && (
                  <div className="flex flex-wrap items-center gap-1.5 rounded-xl border border-sky-100 bg-sky-50/50 p-2">
                    <span className="pe-1 text-[10px] font-semibold uppercase tracking-wide text-sky-700">
                      {t('workflow.sendIn.service.badge')}
                    </span>
                    {services.map((s, i) => (
                      <span
                        key={s.service_catalog_id}
                        className="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-800 shadow-sm ring-1 ring-sky-200"
                      >
                        {s.text}
                        <button
                          type="button"
                          onClick={() => setServices((p) => p.filter((_, j) => j !== i))}
                          className="text-slate-400 hover:text-red-600"
                          aria-label={t('common.remove')}
                        >
                          <Icon.X className="h-3 w-3" />
                        </button>
                      </span>
                    ))}
                    <span className="ms-auto pe-1 text-[11px] font-medium text-sky-700">
                      {t('workflow.sendIn.service.count', { n: services.length })}
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

                {/* ── THE VOCABULARY — faults and services, one list, services badged ────────── */}
                {workGroups.length
                  ? <WorkPicker groups={workGroups} isPicked={isPicked} onToggle={toggleWork} t={t} tp={tp} lang={lang} />
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

            {/* ── PICK A REASON ─────────────────────────────────────────────────────────────────
                The list is DATA now (request_reasons), not a constant, so it is offered exactly as the
                server sent it and the office edits it in place. Rewording a reason rewrites nothing:
                the CODE is what tickets store, and removing a reason retires it rather than deleting
                it, so everything already filed under it keeps reading. */}
            {mode === MODE_REASON && (
              <div className="space-y-2">
                {reasonEntries.length === 0 && (
                  <p className="rounded-lg bg-slate-50 px-3 py-3 text-center text-xs text-slate-400">
                    {t('workflow.sendIn.reason.empty')}
                  </p>
                )}

                {/* What removing a reason DID and did not do — the reassurance belongs beside the list
                    that just changed, not in a toast that has already gone. */}
                {reasonNotice && (
                  <p className="rounded-lg bg-emerald-50 px-3 py-2 text-[11px] leading-relaxed text-emerald-800 ring-1 ring-inset ring-emerald-600/15">
                    {reasonNotice}
                  </p>
                )}

                {reasonEntries.map(([code, label]) => {
                  const active = reasonCode === code;
                  return (
                    <div
                      key={code}
                      className={`flex w-full items-center gap-1 rounded-xl border transition
                        ${active ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500' : 'border-slate-200 bg-white hover:border-slate-300'}`}
                    >
                      <button
                        type="button"
                        onClick={() => setReason(code)}
                        aria-pressed={active}
                        className="flex min-w-0 flex-1 items-center gap-3 px-3 py-2.5 text-start"
                      >
                        <span className={`flex h-4 w-4 shrink-0 items-center justify-center rounded-full border ${active ? 'border-indigo-600' : 'border-slate-300'}`}>
                          {active && <span className="h-2 w-2 rounded-full bg-indigo-600" />}
                        </span>
                        {/* The catalog answer wins when it has one; the server's own wording is the
                            fallback, which is what lets a reason the office just added show up here
                            with no frontend deploy behind it. */}
                        <span className="text-sm font-medium text-slate-800">
                          {tf(`workflow.sendIn.reason.${door}.${code}`, label)}
                        </span>
                      </button>

                      {/* TAKE IT OFF THE LIST — retires it. The tickets already filed under it keep
                          their reason; it simply stops being offered from now on. */}
                      {canEditReasons && (
                        <button
                          type="button"
                          onClick={() => retireReason(code, label)}
                          disabled={reasonBusy}
                          title={t('workflow.sendIn.reason.removeTitle')}
                          aria-label={t('workflow.sendIn.reason.remove')}
                          className="me-1.5 shrink-0 rounded-lg p-1.5 text-slate-300 transition hover:bg-red-50 hover:text-red-600 disabled:opacity-40"
                        >
                          <Icon.X className="h-3.5 w-3.5" />
                        </button>
                      )}
                    </div>
                  );
                })}

                {/* ── ADD ONE ──────────────────────────────────────────────────────────────────
                    Type the sentence a person would actually say. The machine key is derived from it
                    on the server and never typed here — two people inventing codes for the same
                    reason is how one list quietly becomes two. */}
                {canEditReasons && (adding ? (
                  <div className="rounded-xl border border-dashed border-indigo-300 bg-indigo-50/40 p-3">
                    <label className="block text-[11px] font-semibold uppercase tracking-wide text-indigo-700">
                      {t('workflow.sendIn.reason.addLabel')}
                    </label>
                    <input
                      autoFocus
                      type="text"
                      value={newReason}
                      maxLength={191}
                      onChange={(e) => setNewReason(e.target.value)}
                      onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addReason(); } }}
                      placeholder={t('workflow.sendIn.reason.addPlaceholder')}
                      className="mt-1.5 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder:text-slate-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                    />
                    <p className="mt-1.5 text-[11px] leading-relaxed text-slate-500">
                      {t('workflow.sendIn.reason.addHint')}
                    </p>
                    <div className="mt-2 flex items-center gap-2">
                      <Button size="sm" onClick={addReason} disabled={!newReason.trim() || reasonBusy} loading={reasonBusy}>
                        {t('workflow.sendIn.reason.addSave')}
                      </Button>
                      <Button size="sm" variant="ghost" onClick={() => { setAdding(false); setNewReason(''); }} disabled={reasonBusy}>
                        {t('common.cancel')}
                      </Button>
                    </div>
                  </div>
                ) : (
                  <button
                    type="button"
                    onClick={() => { setAdding(true); setError(null); }}
                    className="flex w-full items-center justify-center gap-1.5 rounded-xl border border-dashed border-slate-300 px-3 py-2.5 text-sm font-medium text-slate-500 transition hover:border-indigo-400 hover:bg-indigo-50/50 hover:text-indigo-700"
                  >
                    <Icon.Plus className="h-4 w-4" />
                    {t('workflow.sendIn.reason.add')}
                  </button>
                ))}
              </div>
            )}

            {/* WRITE A NOTE was the third answer here and is GONE. A free sentence is not an answer
                anything can count: "why did this car go in?" asked of a thousand tickets returned a
                thousand different sentences, which is the same nothing the withdrawn "Something else"
                recorded. The two answers left are both countable, and the reason list is editable now —
                so a reason that genuinely is not on the list gets ADDED to it rather than typed once
                into a box nobody can group by. A note still rides along as DETAIL beside named work,
                and a Driver Observation is still free text, because neither is answering this question. */}
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

        {/* ── AN ACCIDENT HAPPENED ────────────────────────────────────────────────────────────────
            The roadside form. Everything here is answerable by somebody standing next to the car;
            the police report, the liability verdict, the insurer and the money are stages of the
            case that opens when this is submitted.

            THE CUSTOMER BANNER IS THE POINT OF THIS PANEL. If the car is on hire, the person filing
            this needs to know before they type another word — a crash on a live rental is
            simultaneously an operational problem and a commercial one, and finding out three days
            later is how a contract quietly keeps billing while nobody decides what to do about it. */}
        {isAccident && (
          <div className="space-y-3">
            {accContext?.contract_no && (
              <div className="rounded-xl border border-amber-300 bg-amber-50 p-3 text-amber-900">
                <p className="flex items-center gap-1.5 text-sm font-bold">
                  <span aria-hidden>⚠️</span>
                  {t('This vehicle is with a customer right now')}
                </p>
                <dl className="mt-2 grid gap-x-4 gap-y-1 text-xs sm:grid-cols-2">
                  <div><dt className="inline font-semibold">{t('Customer')}: </dt><dd className="inline">{accContext.customer_name || '—'}</dd></div>
                  <div><dt className="inline font-semibold">{t('Contract')}: </dt><dd className="inline">{accContext.contract_no}</dd></div>
                  <div><dt className="inline font-semibold">{t('Out')}: </dt><dd className="inline">{accContext.out_date || '—'}</dd></div>
                  <div><dt className="inline font-semibold">{t('Expected back')}: </dt><dd className="inline">{accContext.in_date || t('open-ended')}</dd></div>
                </dl>
                <p className="mt-2 text-[11px] leading-relaxed">
                  {t('The rental contract is not affected by reporting this — it stays open and keeps running. What happens to the hire is a separate decision.')}
                </p>
              </div>
            )}
            {accContext && !accContext.contract_no && (
              <p className="rounded-lg bg-slate-50 px-3 py-2 text-[11px] leading-relaxed text-slate-500 ring-1 ring-inset ring-slate-100">
                {t('No open rental was found on this car for that moment. Say who had it below if you know.')}
              </p>
            )}

            <Textarea
              label={t('What happened?')}
              required
              rows={3}
              maxLength={5000}
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder={t('Describe the accident in your own words.')}
            />

            <div className="grid gap-3 sm:grid-cols-2">
              {/* WHEN, not "now". A crash reported on Monday about Saturday belongs to Saturday's
                  renter, and this field is what decides which customer the case is frozen against. */}
              <Input
                type="datetime-local"
                label={t('When did it happen?')}
                hint={t('Leave empty if it just happened')}
                value={acc.occurred_at}
                max={new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16)}
                onChange={(e) => setAcc((a) => ({ ...a, occurred_at: e.target.value }))}
              />
              <Input
                label={t('Where?')}
                value={acc.location}
                maxLength={255}
                onChange={(e) => setAcc((a) => ({ ...a, location: e.target.value }))}
                placeholder={t('Road, area or landmark')}
              />
              <Select
                label={t('Type of accident')}
                value={acc.accident_type}
                onChange={(e) => setAcc((a) => ({ ...a, accident_type: e.target.value }))}
              >
                <option value="">{t('Not sure yet')}</option>
                {(options?.accident_types || [
                  'collision', 'rear_end', 'side_impact', 'head_on', 'single_vehicle', 'rollover',
                  'parked_hit', 'pedestrian', 'animal', 'flood', 'fire', 'vandalism', 'other',
                ]).map((k) => (
                  <option key={k} value={k}>{t(k.replace(/_/g, ' '))}</option>
                ))}
              </Select>
            </div>

            {/* ── VISIBLE DAMAGE — tapped, not typed ────────────────────────────────────────────
                Optional: a person beside a crashed car may not be able to itemise it, and the
                assessment stage exists for exactly that. But anything they DO record is named from
                the catalog, so an accident's damage is countable from the first minute. */}
            {(accVocab?.damage_groups || []).length > 0 && (
              <div>
                <div className="mb-1.5 flex items-baseline justify-between gap-2">
                  <span className="text-sm font-medium text-slate-700">{t('Visible damage')}</span>
                  <span className="text-[11px] text-slate-400">
                    {acc.damage_ids.length > 0
                      ? t('{n} selected', { n: acc.damage_ids.length })
                      : t('Optional — tap what you can see')}
                  </span>
                </div>
                <div className="max-h-40 overflow-y-auto rounded-xl border border-slate-200 bg-white p-2">
                  {accVocab.damage_groups.map((g) => (
                    <div key={g.key} className="mb-2 last:mb-0">
                      <p className="px-1 pb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                        {(lang === 'ar' && g.label_ar) || g.label}
                      </p>
                      <div className="flex flex-wrap gap-1.5">
                        {g.items.map((i) => {
                          const on = acc.damage_ids.includes(i.id);
                          return (
                            <button
                              key={i.id}
                              type="button"
                              aria-pressed={on}
                              onClick={() => setAcc((a) => ({
                                ...a,
                                damage_ids: on
                                  ? a.damage_ids.filter((x) => x !== i.id)
                                  : [...a.damage_ids, i.id],
                              }))}
                              className={`rounded-full px-3 py-1 text-xs font-medium ring-1 transition ${on
                                ? 'bg-rose-600 text-white ring-rose-600'
                                : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'}`}
                            >
                              {(lang === 'ar' && i.name_ar) || i.name}
                            </button>
                          );
                        })}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* CAN IT BE DRIVEN — three answers, not two. "Not assessed" is a different fact from
                "no", and a checkbox would collapse them into the same thing. */}
            <div className="grid gap-2 sm:grid-cols-2">
              {[
                { key: 'drivable', label: t('Can the car still be driven?') },
                { key: 'towing_required', label: t('Does it need a recovery truck?') },
              ].map(({ key, label }) => (
                <div key={key} className="rounded-xl border border-slate-200 bg-white p-2.5">
                  <p className="mb-1.5 text-xs font-medium text-slate-700">{label}</p>
                  <div className="flex gap-1">
                    {[
                      { v: true,  l: t('Yes') },
                      { v: false, l: t('No') },
                      { v: null,  l: t('Not sure') },
                    ].map(({ v, l }) => (
                      <button
                        key={String(v)}
                        type="button"
                        aria-pressed={acc[key] === v}
                        onClick={() => setAcc((a) => ({ ...a, [key]: v }))}
                        className={`flex-1 rounded-lg px-2 py-1.5 text-xs font-semibold transition ${acc[key] === v
                          ? 'bg-slate-800 text-white'
                          : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
                      >
                        {l}
                      </button>
                    ))}
                  </div>
                </div>
              ))}
            </div>

            <div className="rounded-xl border border-slate-200 bg-white p-3">
              <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                <input
                  type="checkbox"
                  checked={acc.other_party_involved}
                  onChange={(e) => setAcc((a) => ({ ...a, other_party_involved: e.target.checked }))}
                />
                {t('Another vehicle or party was involved')}
              </label>
              {acc.other_party_involved && (
                <div className="mt-2 grid gap-2 sm:grid-cols-2">
                  <Input
                    label={t('Other party name')}
                    value={acc.other_party_name}
                    maxLength={255}
                    onChange={(e) => setAcc((a) => ({ ...a, other_party_name: e.target.value }))}
                  />
                  <Input
                    label={t('Other party plate')}
                    value={acc.other_party_plate}
                    maxLength={64}
                    onChange={(e) => setAcc((a) => ({ ...a, other_party_plate: e.target.value }))}
                  />
                </div>
              )}
            </div>

            <p className="rounded-lg bg-rose-50 px-3 py-2 text-[11px] leading-relaxed text-rose-800 ring-1 ring-inset ring-rose-500/20">
              {t('This opens an accident case, not a repair request. The police report, who was at fault, the insurance claim and the repair are all worked through the case — and the car will not be offered for rent until it is resolved.')}
            </p>
          </div>
        )}

        {/* HOW THE CAR GETS THERE. Only on the garage door, because it is the only door that commits the
            car to a trip — asking for a test drive books nobody a journey. Asked HERE, of the person who
            has just seen the car, rather than discovered at the pickup by a driver standing next to
            something that will not start. */}
        {door === DOOR_DISPATCH && !isObservation && (
          <TransportChoice
            value={transport}
            onChange={setTransport}
            unit={unit}
            onUnit={setUnit}
            disabled={saving}
            tf={tf}
          />
        )}

        {/* WHAT THIS BUTTON ACTUALLY DOES — stated before it is pressed, not discovered after.
            The accident path says its own version inside its panel, so it is skipped here. */}
        {!isAccident && (
        <p className={`rounded-lg px-3 py-2 text-[11px] leading-relaxed ring-1 ring-inset ${door === DOOR_DISPATCH
          ? 'bg-amber-50/70 text-amber-800 ring-amber-500/20'
          : 'bg-slate-50 text-slate-500 ring-slate-100'}`}>
          {t(door === DOOR_DISPATCH
            // Converting an open request lands the car in exactly the same queue, but what happens to
            // the request itself is different enough to be worth one extra sentence: it is not left
            // standing, and nobody is sent out to drive the car.
            ? (canConvert ? 'workflow.sendIn.outcome.dispatchConvert' : 'workflow.sendIn.outcome.dispatch')
            : isObservation
              ? 'workflow.sendIn.outcome.observation'
              : isOffice
                ? 'workflow.sendIn.outcome.office'
                : 'workflow.sendIn.outcome.request')}
        </p>
        )}

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
