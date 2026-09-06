import { useCallback, useMemo, useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { PageHeader, SearchInput, EmptyState, ErrorState } from '../../components/ui/Misc';
import MetricCard, { MetricGrid } from '../../components/ui/MetricCard';
import { SectionCard } from '../../components/ui/Table';
import Badge from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import Modal from '../../components/ui/Modal';
import FilterChips from '../../components/ui/FilterChips';
import { Input, Textarea } from '../../components/ui/Field';
import { useToast } from '../../components/ui/Toast';
import { usePermissions } from '../../hooks/usePermissions';
import { useI18n } from '../../i18n/I18nContext';
import NoteLines from '../../components/workflow/NoteLines';
import { num, fmtDate } from '../../lib/format';

/**
 * Oil Mileage Follow-up — the queue Leen and Marwa work.
 *
 * A car on a long rental burns through its oil interval days after it leaves, and the odometer we
 * hold stops being true the moment it drives off. Each day the backend projects where the car has
 * probably reached (a flat 200 km/day business assumption) and, when that projection crosses the
 * service point, it asks for a REAL number from the customer.
 *
 * The board is one clean card per car, read top to bottom in the order a controller thinks:
 *   who is out → how long is left → what real number do we hold → where is it heading → today's action.
 *
 * READABILITY IS THE DESIGN. Plain white cards, large numbers, plain-English labels, one action
 * sentence. Everything shown is computed by OilChangeProjectionService; the page derives no
 * thresholds of its own, and it renders the API's own `basis` string so the arithmetic is never a
 * black box.
 */

// English lives beside the key in these tables; the visible text is resolved with t(…) at render.
const DECISION_LABEL = {
  recall: 'Recall now',
  defer:  'Do it on return',
};

/**
 * The sentence builders below are plain module functions — they are called from tests and from other
 * modules where no React context exists — so the translator is a PARAMETER, defaulting to an
 * English passthrough that still fills {slots}.
 */
const asIs = (s, vars) => (vars ? String(s).replace(/\{(\w+)\}/g, (m, k) => (vars[k] != null ? vars[k] : m)) : s);

/** How the rental's remaining run is phrased. Null days = the contract carries no duration. */
const dueBackIn = (p, t = asIs) => {
  if (!p?.return_date_known) return t('with no return date on the contract');
  const d = p.remaining_days;
  if (d === 0) return t('due back today');
  // Arabic has six plural categories — branch the ENGLISH and emit two separate phrases.
  return d === 1 ? t('due back in 1 day') : t('due back in {n} days', { n: d });
};

/**
 * The verdict in one plain sentence — kept for the DIALOGS, where whoever is mid-call wants the whole
 * situation restated before they commit a number or a decision. The board itself renders no
 * paragraphs; the cards carry the same facts as big numbers and one action line.
 */
export const reasonFor = (p, t = asIs) => {
  if (!p) return '—';
  if (p.status === 'no_data' || p.oil_status === 'no_data') {
    return t('No mileage was recorded when this car went out, so we can’t work out where it is now.');
  }

  const ret = num(p.expected_return);
  const max = num(p.allowed_max);
  const now = num(p.expected);
  const over = num(Math.max(p.over_tolerance_km ?? 0, 0));

  switch (p.oil_status) {
    case 'decision_required':
      return `${t('Likely around {now} km now and {due} — it would come back on about {ret} km, which is {over} km past the {max} km allowance.', { now, due: dueBackIn(p, t), ret, over, max })} `
        + (p.decision_ready
          ? t('Recall it now, or accept that and change the oil the day it returns.')
          : t('That is an estimate, not a reading — get the real number from the customer before deciding anything.'));

    case 'recall_required':
      return p.decision?.decided_by
        ? t('Recall agreed by {who} — projected to reach {ret} km against a {max} km allowance. Arrange the return with the customer; the oil change is raised automatically once the car is back.', { who: p.decision.decided_by, ret, max })
        : t('Recall agreed — projected to reach {ret} km against a {max} km allowance. Arrange the return with the customer; the oil change is raised automatically once the car is back.', { ret, max });

    case 'service_required_on_return':
      return t('{lead} and would come back on about {ret} km, inside the {max} km allowance. Let the rental finish — the oil change is booked for the return.', {
        lead: p?.return_date_known ? dueBackIn(p, t) : t('Out with no return date on the contract'),
        ret,
        max,
      });

    case 'within_tolerance':
      return t('Likely around {now} km — it comes back on about {ret} km, still short of the {limit} km oil point. Nothing to do.', { now, ret, limit: num(p.oil_limit) });

    default:
      return t('Likely around {now} km. Next check around {date}.', { now, date: fmtDate(p.breach_on) });
  }
};

/**
 * The operational LANE — ONE primary action per car. The backend computes this (`projection.lane`)
 * and is authoritative; the fallback derivation below implements the identical priority for any
 * payload that predates the field:
 *
 *   1. a returned car never reaches this board (the API only serves open rentals);
 *   2. due back TODAY (or overdue) and still out  ⇒ service_on_return — never a customer call;
 *   3. an agreed recall or an answerable decision ⇒ action_required;
 *   4. a stale number with days still to run      ⇒ call_customer;
 *   5. everything else                            ⇒ safe.
 */
export const laneFor = (p) => {
  if (p?.lane) return p.lane;
  if (!p || p.status === 'no_data' || p.oil_status === 'no_data') return 'no_data';
  // No oil question ⇒ nothing to do, whatever the return date. A car that finishes before it even
  // reaches its oil point must never be put in front of a person just because it comes back today.
  const concern = ['decision_required', 'recall_required', 'service_required_on_return'].includes(p.oil_status)
    || p.status === 'chase_due';
  if (!concern) return 'safe';
  // A decision about a car nowhere near its oil point is not today's business (see the backend's
  // DECISION_WINDOW_DAYS) — the fact stands, the card stays quiet until the car is close.
  if (p.oil_status === 'decision_required' && p.decision_due_soon === false && p.status !== 'chase_due') return 'safe';
  if (p.return_date_known && p.remaining_days === 0) return 'service_on_return';
  if (p.oil_status === 'recall_required' || p.decision_ready) return 'action_required';
  if (p.oil_status === 'decision_required' || p.status === 'chase_due') return 'call_customer';
  return 'safe';
};

/**
 * HOW MUCH RUN IS LEFT before the car passes the point it must not pass.
 *
 * It is the same pair of numbers the card already puts side by side — "Max allowed" against
 * "Today (est.)" — so the order of the list, the column in the call list and the sentence on the
 * card can never tell three different stories. Nothing new is computed here: `allowed_max` is the
 * oil limit plus the grace, `expected` is today's projection, both straight off the API.
 *
 *   km  — negative once the estimate is already past the allowance (the dangerous end)
 *   days— how long that headroom lasts at the projection's own km/day pace
 *
 * A car we cannot project has no margin at all — `km: null` — and sorts to the BOTTOM, never to the
 * top: "we don't know" is not the same as "it's on fire".
 */
export const oilMarginFor = (p) => {
  if (!p || p.allowed_max == null || p.expected == null) return { km: null, days: null };
  const km = p.allowed_max - p.expected;
  return { km, days: p.rate ? Math.floor(km / p.rate) : null };
};

/** Most dangerous first: least headroom (and the already-over cars) at the top, unknowns last. */
export const byUrgency = (a, b) => {
  const x = oilMarginFor(a?.projection).km;
  const y = oilMarginFor(b?.projection).km;
  if (x == null && y == null) return 0;
  if (x == null) return 1;
  if (y == null) return -1;
  return x - y;
};

export const LANES = [
  { key: 'action_required',   label: 'Action required',   tone: 'red' },
  { key: 'service_on_return', label: 'Service on return', tone: 'amber' },
  { key: 'call_customer',     label: 'Call customer',     tone: 'amber' },
  { key: 'safe',              label: 'Safe / scheduled',  tone: 'green' },
  { key: 'no_data',           label: 'Can’t project',     tone: 'slate' },
];

/**
 * The card's verdict: the lane label plus the short line (and, for an arrival, the steps) behind
 * it. Derived from the lane so a card can never contradict the tab it sits in.
 */
export const actionFor = (p, t = asIs) => {
  const lane = laneFor(p);

  if (lane === 'no_data') {
    return {
      key: 'no_data', lane, tone: 'slate', label: t('Can’t project'),
      headline: t('Capture a handover reading'),
      support: t('No mileage recorded at handover — the projection cannot start.'),
    };
  }

  const age = p.days_elapsed;
  const ageText = age == null ? null : (age === 1 ? t('1 day old') : t('{n} days old', { n: age }));
  const fromCustomer = p.anchor_source === 'reading';

  if (lane === 'service_on_return') {
    return {
      key: 'return_today', lane, tone: 'amber', label: t('Service on return'),
      headline: t('🔧 Service on return — the car is due back today'),
      support: t('Don’t call the customer — they are already returning the car. Catch it on arrival:'),
      steps: [
        t('Read the actual odometer as soon as it arrives'),
        t('Compare it with the oil limit and the allowed maximum'),
        t('Do the oil service if it is required'),
        t('Enter the actual reading here to close the loop'),
      ],
      chase: true,
    };
  }

  if (lane === 'action_required') {
    if (p.oil_status === 'recall_required') {
      // The recall's own stage is the headline — "recall agreed" stopped being useful the moment
      // the relay existed, because the person reading this needs to know whose move it is now.
      const stage = p.decision?.recall?.stage;
      const headlineEn = {
        waiting_sales:     'Waiting for Sales — ask them to arrange the return',
        ready_for_driver:  'Sales confirmed — a driver must be arranged',
        driver_assigned:   'A driver is on the way to collect the car',
        vehicle_collected: 'The car is with our driver',
        at_workshop:       'The car is at the workshop — review the follow-up request',
        inspection:        'Being inspected',
        oil_service:       'Oil change ticket is open',
        return_to_customer: 'Oil changed — give the car back to the customer',
        completed:         'Done — the oil change this recall owed is finished',
        cancelled:         'Recall stood down',
      }[stage] || 'Recall agreed — arrange the return with the customer';

      // Once the car is OURS the story changes completely: there is no customer to phone and no
      // reading to ask for, because the driver read the dashboard when he took the keys. From here
      // the only outstanding thing is the oil change itself.
      const ours = p.decision?.recall?.in_our_custody;

      return {
        key: 'recall', lane, tone: 'red', label: t('Action required'),
        headline: t(headlineEn),
        support: ours
          ? t('The customer no longer has this car — record the oil change once it is done.')
          : (p.decision?.decided_by
            ? t('Recall agreed by {who}. The oil change is required whatever else happens to this car.', { who: p.decision.decided_by })
            : t('Recall agreed. The oil change is required whatever else happens to this car.')),
        // Suppresses "Enter reading": asking for a customer reading on a car standing in our own
        // workshop invites a number nobody observed, over the one the driver actually captured.
        inOurHands: !!ours,
      };
    }
    return {
      key: 'decide', lane, tone: 'red', label: t('Action required'),
      headline: t('Recall now, or oil change on return?'),
      support: t('Fresh customer reading — {km} km. Both answers are safe on this number.', { km: num(p.anchor_odometer) }),
      decide: true,
    };
  }

  if (lane === 'call_customer') {
    const stale = fromCustomer
      ? t('Last reading is {age}', { age: ageText })
      : (ageText
        ? t('No customer reading yet — estimate runs from handover ({age})', { age: ageText })
        : t('No customer reading yet — estimate runs from handover'));
    return {
      key: 'call', lane, tone: 'amber', label: t('Call customer'),
      headline: t('📞 Call customer for an odometer reading'),
      support: p.oil_status === 'decision_required'
        ? t('{lead}. No decision until a fresh number is in.', { lead: stale })
        : t('Estimated past the oil point — confirm with a real number.'),
      chase: true,
    };
  }

  if (p.oil_status === 'service_required_on_return') {
    return {
      key: 'on_return', lane, tone: 'green', label: t('Safe / scheduled'),
      headline: t('Nothing to do — oil change booked for the return'),
      support: t('Passes the oil point but finishes inside grace.'),
    };
  }

  // A decision that is real but not yet TODAY's — typically a car whose oil was just changed and
  // which cannot finish a long rental inside the new interval either. Say so plainly rather than
  // showing a green card with no explanation, and say when it will be asked.
  if (p.oil_status === 'decision_required' && p.decision_due_soon === false) {
    const away = p.oil_limit != null && p.expected != null ? p.oil_limit - p.expected : null;
    return {
      key: 'not_yet', lane, tone: 'green', label: t('Safe / scheduled'),
      headline: t('Nothing to do yet — it will need another oil change before this rental ends'),
      support: away != null
        ? t("Still {km} km from the {limit} km oil point. We'll ask what to do when it gets close.", { km: num(away), limit: num(p.oil_limit) })
        : t('We’ll ask what to do when it gets close to the oil point.'),
    };
  }

  return {
    key: 'safe', lane, tone: 'green', label: t('Safe / scheduled'),
    headline: t('Nothing to do'),
    support: t('Comes back before the oil point.'),
  };
};

/** The verdict's colour, carried on a rail down the card's leading edge instead of a full border. */
const RAIL_TONE = {
  red:   'bg-rose-500',
  amber: 'bg-amber-400',
  green: 'bg-emerald-400',
  slate: 'bg-slate-300',
};

/** The card's own edge — a red card should feel warmer than a green one without shouting. */
const CARD_EDGE = {
  red:   'border-rose-200 hover:ring-rose-100',
  amber: 'border-amber-200 hover:ring-amber-100',
  green: 'border-slate-200 hover:ring-emerald-100',
  slate: 'border-slate-200 hover:ring-slate-100',
};

const ACTION_BOX_TONE = {
  red:   'border-rose-200 bg-rose-50 text-rose-900',
  amber: 'border-amber-200 bg-amber-50 text-amber-900',
  green: 'border-emerald-200 bg-emerald-50 text-emerald-900',
  slate: 'border-slate-200 bg-slate-50 text-slate-700',
};

/** One big number with a plain-English label. Large and calm — no boxes, no abbreviations. */
function Stat({ label, value, sub, pill, pillTone, highlight }) {
  return (
    <div className="min-w-0">
      <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{label}</div>
      <div className="mt-0.5 flex flex-wrap items-baseline gap-2">
        <span className={`text-xl font-bold tabular-nums tracking-tight ${highlight ? 'text-indigo-700' : 'text-slate-900'}`}>{value}</span>
        {pill && <Badge tone={pillTone}>{pill}</Badge>}
      </div>
      {sub && <div className="mt-0.5 text-xs leading-snug text-slate-500">{sub}</div>}
    </div>
  );
}

/**
 * THE TRACK — the entire oil question as one line you can read without arithmetic.
 *
 * Every number on this card answers the same question: has this car got room left? Spelled out as
 * figures it takes a moment's subtraction to see. Drawn to scale it takes none — the run from the
 * last oil change to the end of the allowance, coloured green while the car is inside its interval,
 * amber across the grace, red past the point it must not pass, with a pin where the car is today
 * and a hollow one where it is projected to come back.
 *
 * It invents nothing. Every position is a figure already printed underneath it, so the picture and
 * the numbers cannot disagree; if a figure is missing the track simply doesn't draw.
 */
function MileageTrack({ p, lastService }) {
  const { t } = useI18n();
  const start = lastService ?? p.handover_odometer;
  if (start == null || p.oil_limit == null || p.allowed_max == null || p.expected == null) return null;

  // The line runs to whichever comes last — the allowance, or where the car is actually heading —
  // so a car far past its limit still shows how far past, instead of pinning to the end.
  const end = Math.max(p.allowed_max, p.expected, p.expected_return ?? 0);
  const span = end - start;
  if (span <= 0) return null;

  const at = (km) => Math.min(100, Math.max(0, ((km - start) / span) * 100));
  const limitAt = at(p.oil_limit);
  const maxAt = at(p.allowed_max);
  const nowAt = at(p.expected);
  const retAt = p.expected_return != null ? at(p.expected_return) : null;
  // Two pins on top of each other is a smudge, not information. A car due back today genuinely IS
  // at both points, so it gets one pin and the label says so.
  const retApart = retAt != null && Math.abs(retAt - nowAt) > 6;

  return (
    <div className="pt-1">
      <div
        className="relative h-2.5 w-full overflow-hidden rounded-full bg-slate-200"
        role="img"
        aria-label={t('Estimated at {now} km, against a {limit} km oil limit and a {max} km maximum.', {
          now: num(p.expected), limit: num(p.oil_limit), max: num(p.allowed_max),
        })}
      >
        {/* Inside the interval → across the grace → past the allowance. */}
        <div className="absolute inset-y-0 start-0 bg-emerald-400" style={{ width: `${limitAt}%` }} />
        <div className="absolute inset-y-0 bg-amber-400" style={{ insetInlineStart: `${limitAt}%`, width: `${Math.max(maxAt - limitAt, 0)}%` }} />
        <div className="absolute inset-y-0 bg-rose-500" style={{ insetInlineStart: `${maxAt}%`, width: `${Math.max(100 - maxAt, 0)}%` }} />
      </div>

      {/* The pins sit in their own strip beneath the bar, so nothing ever covers the colours. */}
      <div className="relative mt-1 h-4">
        {retApart && (
          <span
            className="absolute -translate-x-1/2 text-[10px] font-semibold text-slate-500 rtl:translate-x-1/2"
            style={{ insetInlineStart: `${retAt}%` }}
            title={t('Return (est.) {km} km', { km: num(p.expected_return) })}
          >
            ▲ {t('return')}
          </span>
        )}
        <span
          className="absolute -translate-x-1/2 whitespace-nowrap text-[10px] font-bold text-indigo-700 rtl:translate-x-1/2"
          style={{ insetInlineStart: `${nowAt}%` }}
          title={t('Today (est.) {km} km', { km: num(p.expected) })}
        >
          ▲ {retApart ? t('now') : (retAt != null ? t('now / return') : t('now'))}
        </span>
      </div>

      {/* The scale's own anchors, stated rather than positioned — no label can drift off a number. */}
      <div className="flex justify-between text-[10px] tabular-nums text-slate-400">
        <span>{num(start)} {t('km')}{lastService != null ? ` · ${t('last change')}` : ` · ${t('handover')}`}</span>
        <span className="text-amber-600">{num(p.oil_limit)} · {t('oil limit')}</span>
        <span className="text-rose-600">{num(p.allowed_max)} · {t('max')}</span>
      </div>
    </div>
  );
}

/** A titled group of related numbers — the card is read group by group, never as a number soup. */
function Group({ title, children }) {
  return (
    <div className="rounded-xl border border-slate-100 bg-gradient-to-b from-slate-50 to-white p-3.5">
      <div className="mb-2.5 text-[11px] font-bold uppercase tracking-wider text-slate-400">{title}</div>
      <div className="flex flex-wrap items-start gap-x-6 gap-y-3">{children}</div>
    </div>
  );
}

/** The quiet arrow between chronological mileage figures. */
function Arrow() {
  return <span className="hidden self-center text-lg text-slate-300 sm:inline" aria-hidden="true">→</span>;
}

/**
 * The RECALL RELAY — the stages a recalled car actually passes through, in order.
 *
 * A recall is not one act, it is a handover chain: Sales agrees the return with the customer, the
 * Supervisors find a driver, the driver collects the car, it arrives, it is inspected, the oil is
 * changed. None of these are invented states — each is read from the record that owns it (the Sales
 * stamp, the logistics task's phase, the follow-up request, the service ticket). They are listed
 * here so the card can always answer the only question that matters: what happens next, and who
 * does it?
 */
const RECALL_STAGES = [
  { key: 'waiting_sales',     label: 'Waiting for Sales', who: 'Sales must agree the return with the customer' },
  // NB: `return_to_customer` is inserted after `oil_service` below — a recall does not end when the
  // oil is changed, it ends when the customer has their rental back.
  { key: 'ready_for_driver',  label: 'Ready for driver',  who: 'Waleed / Abdullah arrange a driver' },
  { key: 'driver_assigned',   label: 'Driver assigned',   who: 'The driver is on the way to the customer' },
  { key: 'vehicle_collected', label: 'Vehicle collected', who: 'The car is with our driver' },
  { key: 'at_workshop',       label: 'At the workshop',   who: 'Review the follow-up request and send it in' },
  { key: 'inspection',        label: 'Inspection / test', who: 'Abu Maroof is testing the car' },
  { key: 'oil_service',       label: 'Oil change',        who: 'The oil service ticket is open' },
  // NB: two of these lines change with the route the test answer picked — see `stageWho()` below. A
  // car that arrives with no test is not waiting for anyone to review anything, and an oil ticket
  // with no garage on it yet is waiting for a Supervisor, not for a workshop.
  { key: 'return_to_customer', label: 'Give it back',     who: 'Hand the car back — the customer is still paying for it' },
  { key: 'completed',         label: 'Completed',         who: 'Everything this recall owed is done' },
];

const STAGE_INDEX = Object.fromEntries(RECALL_STAGES.map((s, i) => [s.key, i]));

/**
 * "Who does it" for the step the car is actually on — which is not always the same sentence.
 *
 * The test answer on a recall is a ROUTE. With a test the car is announced to the review queue and
 * handed to the Inspector; without one it goes to the Supervisors, who read the dial and pick the
 * garage. Two steps therefore have two owners, and printing the wrong one sends somebody to a queue
 * that has no card in it for this car.
 */
function stageWho(stage, recall) {
  if (stage?.key === 'at_workshop' && recall?.awaiting_supervisor) {
    return 'Waleed / Abdullah take it: read the odometer and pick the garage';
  }
  if (stage?.key === 'oil_service' && recall?.service_ticket?.awaiting_garage) {
    return 'Waleed / Abdullah pick the garage and enter the odometer';
  }
  return stage?.who;
}

/**
 * THE SUPERVISOR'S STEP — the car is standing here with no test asked for, so it is his.
 *
 * Two answers and nothing else, because two answers are the whole job: what the dial says now, and
 * which garage it goes to. Both are on this one panel deliberately. The alternative — open the
 * ticket and let him find it on the dispatch board — is how a car ends up sitting in a yard while
 * everyone assumes somebody else picked a shop.
 *
 * The garage may be left empty: the ticket then waits in his own dispatch lane, which is the same
 * choice one step later. What he can never do is have the choice taken away from him.
 */
function SupervisorHandOver({ r, recall, onChanged }) {
  const { t, tf } = useI18n();
  const toast = useToast();
  const [garages, setGarages] = useState([]);
  const [odometer, setOdometer] = useState('');
  const [vendorId, setVendorId] = useState('');
  const [busy, setBusy] = useState(false);
  // The ticket may already exist (the driver's arrival opened it) — in which case this panel is
  // finishing the job rather than starting it, and only the garage is still missing.
  const opened = !!recall?.service_ticket?.awaiting_garage;

  // The shops, same source and same filter the dispatch board uses — one list of garages fleet-wide.
  useEffect(() => {
    let alive = true;
    api.get('/Vendor')
      .then((res) => {
        if (!alive) return;
        const raw = res.data?.data;
        const all = Array.isArray(raw) ? raw : raw?.items || [];
        const shops = all.filter((x) => x.type === 'garage');
        setGarages(shops.length ? shops : all);
      })
      .catch(() => setGarages([]));
    return () => { alive = false; };
  }, []);

  const submit = async () => {
    if (odometer === '' || Number.isNaN(Number(odometer))) {
      return toast.error(t('Enter the odometer reading off the dashboard'));
    }
    setBusy(true);
    try {
      const { data } = await api.post(`/Contract/${r.contract_id}/oil-recall/hand-over`, {
        odometer: Number(odometer),
        vendor_id: vendorId ? Number(vendorId) : null,
      });
      toast.success(vendorId
        ? t('Sent to the garage — oil change ticket #{id}', { id: data?.data?.ticket_id })
        : t('Opened as ticket #{id} — pick the garage in your dispatch queue', { id: data?.data?.ticket_id }));
      setVendorId('');
      setOdometer('');
      onChanged?.();
    } catch (e) {
      toast.error(e.response?.data?.message || t('Could not hand it over.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mt-2 rounded-lg border-2 border-amber-400 bg-amber-50 p-3">
      <div className="text-sm font-bold text-amber-900">
        🔧 {tf('oil.recall.handover.title', 'The car is here — send it for its oil change')}
      </div>
      <p className="mt-0.5 text-xs leading-snug text-amber-800">
        {opened
          ? tf('oil.recall.handover.bodyOpen', 'The oil change is open as ticket #{id} and no garage has been chosen yet. Read the odometer off the dashboard and pick the garage — it goes straight there.', { id: recall.service_ticket.id })
          : tf('oil.recall.handover.body', 'No test was asked for, so nobody is holding this car. Read the odometer off the dashboard and choose the garage — that opens the oil change ticket and sends it there.')}
      </p>

      <div className="mt-2 flex flex-wrap items-end gap-2">
        <label className="flex flex-col gap-1">
          <span className="text-[10px] font-bold uppercase tracking-wider text-amber-900">
            {tf('oil.recall.handover.odometer', 'Odometer now')}
          </span>
          <input
            type="number"
            inputMode="numeric"
            value={odometer}
            onChange={(e) => setOdometer(e.target.value)}
            placeholder={t('e.g. {n}', { n: '137969' })}
            className="w-32 rounded-lg border border-amber-300 bg-white px-2.5 py-1.5 text-sm tabular-nums outline-none focus:border-amber-500"
          />
        </label>
        <label className="flex flex-col gap-1">
          <span className="text-[10px] font-bold uppercase tracking-wider text-amber-900">
            {tf('oil.recall.handover.garage', 'Garage')}
          </span>
          <select
            value={vendorId}
            onChange={(e) => setVendorId(e.target.value)}
            className="min-w-[11rem] rounded-lg border border-amber-300 bg-white px-2.5 py-1.5 text-sm outline-none focus:border-amber-500"
          >
            <option value="">{t('Decide later — keep it in my queue')}</option>
            {garages.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
          </select>
        </label>
        <Button size="sm" variant="primary" loading={busy} onClick={submit}>
          {vendorId
            ? tf('oil.recall.handover.send', 'Send to the garage')
            : opened
              ? tf('oil.recall.handover.reading', 'Save the reading')
              : tf('oil.recall.handover.open', 'Open the oil change')}
        </Button>
      </div>
    </div>
  );
}

/**
 * The recall's own panel: where it has got to, the single action available now, and the work the
 * car owes when it lands.
 *
 * The oil change is rendered as a LOCKED requirement, not a checkbox with a tick in it. This recall
 * exists because the oil lifecycle asked for it, so nobody — here or through the API — gets to turn
 * it into "just test the car" and lose the reason the customer was interrupted in the first place.
 */
function RecallRelay({ r, recall, canRecord, onChanged }) {
  const { t, tf } = useI18n();
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  const stageIdx = STAGE_INDEX[recall.stage] ?? 0;
  const cancelled = recall.stage === 'cancelled';
  const current = RECALL_STAGES[stageIdx];
  const test = recall.required_actions?.test;

  const post = async (url, body, okMessage) => {
    setBusy(true);
    try {
      await api.post(url, body);
      toast.success(okMessage);
      onChanged?.();
    } catch (e) {
      toast.error(e.response?.data?.message || t('Could not save that.'));
    } finally {
      setBusy(false);
    }
  };

  const total = RECALL_STAGES.length;
  const parking = recall.service_location === 'parking';

  return (
    <div className="overflow-hidden rounded-xl border border-rose-200 bg-white">
      {/* ── Header: what this is, and the one-word answer to "where has it got to" ────────── */}
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-rose-100 bg-rose-50/60 px-3.5 py-2">
        <div className="flex items-center gap-2">
          <span className="text-base leading-none" aria-hidden>🚗</span>
          <span className="text-[11px] font-bold uppercase tracking-wider text-rose-700">
            {tf('oil.recall.title', 'Recall · bringing the car back')}
          </span>
        </div>
        <Badge tone={cancelled ? 'slate' : recall.stage === 'completed' ? 'green' : 'red'}>
          {cancelled ? tf('oil.recall.stoodDown', 'Stood down') : (current ? t(current.label) : null)}
        </Badge>
      </div>

      <div className="space-y-3 p-3.5">
        {/* ── The chain, as a RAIL rather than eight competing chips ──────────────────────
            Eight equal pills all shouting at once is the thing that made this unreadable. A rail
            shows the same sequence at a glance: filled behind, bright at the current step, faint
            ahead — and only the step you are ON gets words. */}
        {!cancelled && (
          <div>
            <div className="flex items-center gap-1" role="list" aria-label={tf('oil.recall.progress', 'Recall progress')}>
              {RECALL_STAGES.map((s, i) => (
                <span
                  key={s.key}
                  role="listitem"
                  title={`${i + 1}. ${t(s.label)} — ${t(s.who)}`}
                  className={`h-1.5 flex-1 rounded-full transition-colors ${
                    i < stageIdx ? 'bg-emerald-400'
                      : i === stageIdx ? 'bg-rose-500'
                      : 'bg-slate-200'}`}
                />
              ))}
            </div>
            <div className="mt-1.5 flex items-baseline justify-between gap-2">
              <span className="text-sm font-bold text-slate-900">{current ? t(current.label) : null}</span>
              <span className="text-[11px] tabular-nums text-slate-400">
                {tf('oil.recall.stepOf', 'step {n} of {total}', { n: stageIdx + 1, total })}
              </span>
            </div>
          </div>
        )}

        {/* ── WHO ACTS NOW. The one line somebody reads if they read nothing else. ────────── */}
        {current && !cancelled && (
          <div className="flex items-start gap-2 rounded-lg bg-slate-900 px-3 py-2 text-white">
            <span className="mt-0.5 text-xs" aria-hidden>➜</span>
            <div className="min-w-0">
              <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                {tf('oil.recall.nextUp', 'Next')}
              </div>
              <div className="text-sm font-semibold leading-snug">{t(stageWho(current, recall))}</div>
            </div>
          </div>
        )}

        {/* ── The facts, as a two-column list instead of a paragraph pile ─────────────────── */}
        {!cancelled && (
          <dl className="grid grid-cols-[auto,1fr] gap-x-3 gap-y-1.5 text-sm">
            <dt className="text-xs font-semibold uppercase tracking-wide text-slate-400">
              {tf('oil.recall.where', 'Oil change at')}
            </dt>
            <dd className="text-slate-800">
              {parking
                ? tf('oil.recall.whereParking', 'our parking — Abu Maroof does it')
                : tf('oil.recall.whereGarage', 'a garage — Waleed / Abdullah arrange it')}
              {recall.request_adopted && (
                <span className="mt-0.5 block text-xs text-slate-500">
                  {tf('oil.recall.adopted', 'added to the test request the system already raised')}
                </span>
              )}
            </dd>

            <dt className="text-xs font-semibold uppercase tracking-wide text-slate-400">
              {tf('oil.recall.salesRow', 'Sales')}
            </dt>
            <dd className={recall.sales?.confirmed ? 'text-emerald-700' : 'text-amber-700'}>
              {recall.sales?.confirmed ? (
                <>
                  <span className="font-semibold">✓ {tf('oil.recall.sales.confirmed', 'Confirmed')}</span>
                  {recall.sales.confirmed_by ? ` · ${recall.sales.confirmed_by}` : ''}
                  {recall.sales.confirmed_at ? ` · ${fmtDate(recall.sales.confirmed_at)}` : ''}
                  {recall.sales.note ? <span className="block text-xs italic text-slate-500">“{recall.sales.note}”</span> : null}
                </>
              ) : (
                <span className="font-semibold">{tf('oil.recall.sales.waitingRow', 'Not agreed with the customer yet')}</span>
              )}
            </dd>

            <dt className="text-xs font-semibold uppercase tracking-wide text-slate-400">
              {tf('oil.recall.driverRow', 'Driver')}
            </dt>
            <dd className="text-slate-800">
              {recall.collection
                ? (
                  <>
                    {recall.collection.driver || tf('oil.recall.noDriverYet', 'nobody has claimed it yet')}
                    <span className="text-slate-500"> · {recall.collection.phase} · #{recall.collection.task_id}</span>
                  </>
                )
                : <span className="text-slate-500">{tf('oil.recall.notArranged', 'not arranged yet')}</span>}
            </dd>
          </dl>
        )}

        {/* ── The LAST step: the oil is done and we are still holding a paid-for car. ────────
            Loud on purpose. Everything else in the system has gone quiet by now — the ticket is
            closed, the workshop has moved on — while the customer is paying for a car in our yard.
            The chase re-rings every few minutes until this is pressed. */}
        {recall.owes_return && (
          <div className="rounded-lg border-2 border-emerald-400 bg-emerald-50 p-3">
            <div className="text-sm font-bold text-emerald-900">
              ✅ {tf('oil.recall.return.title', 'Oil changed — now give the car back')}
            </div>
            <p className="mt-0.5 text-sm leading-snug text-emerald-800">
              {tf('oil.recall.return.body', 'The work is finished and the customer is still paying for this car. Everyone gets a reminder every 5 minutes until it is back with them.')}
            </p>
            {canRecord && (
              <Button
                size="sm"
                variant="primary"
                className="mt-2"
                loading={busy}
                onClick={() => post(
                  `/Contract/${r.contract_id}/oil-returned`,
                  {},
                  t('Handed back — the reminder stops now'),
                )}
              >
                {tf('oil.recall.return.button', 'Returned to the customer')}
              </Button>
            )}
          </div>
        )}

        {/* ── The gate. Nothing has reached a driver until this is pressed. ───────────────── */}
        {recall.awaiting_sales && (
          <div className="rounded-lg border border-amber-300 bg-amber-50 p-3">
            <div className="text-sm font-bold text-amber-900">{tf('oil.recall.sales.title', 'Waiting for Sales OK')}</div>
            <p className="mt-0.5 text-sm leading-snug text-amber-800">
              {tf('oil.recall.sales.body', 'Ask Sales to agree the return with the customer. No driver has been told anything — nobody is dispatched until this is pressed.')}
            </p>
            {canRecord && (
              <Button
                size="sm"
                variant="primary"
                className="mt-2"
                loading={busy}
                onClick={() => post(
                  `/Contract/${r.contract_id}/oil-recall/sales-confirm`,
                  {},
                  t('Sales confirmed — Waleed and Abdullah have been asked to arrange a driver'),
                )}
              >
                {tf('oil.recall.sales.button', 'Sales OK — customer confirmed')}
              </Button>
            )}
          </div>
        )}

        {/* ── What the car owes when it lands: one optional choice, one locked requirement ── */}
        {!cancelled && recall.stage !== 'completed' && (
          <div className="rounded-lg bg-slate-50 p-3 ring-1 ring-inset ring-slate-200">
            <div className="mb-2 text-[10px] font-bold uppercase tracking-wider text-slate-400">
              {tf('oil.recall.required.title', 'When the car arrives')}
            </div>
            <div className="flex flex-wrap items-center gap-2">
              {/* The oil change first — it is the reason the customer is being interrupted. */}
              <span className="inline-flex items-center gap-1.5 rounded-full bg-rose-600 px-3 py-1 text-xs font-bold text-white">
                <span aria-hidden>🔒</span> {tf('oil.recall.required.oil', 'Oil change')}
              </span>
              <label className={`inline-flex cursor-pointer items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ring-1 transition ${
                test?.required ? 'bg-indigo-600 text-white ring-indigo-600' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-100'}`}
              >
                <input
                  type="checkbox"
                  className="h-3 w-3"
                  aria-label={t('Test / Inspection')}
                  checked={!!test?.required}
                  disabled={!canRecord || busy}
                  onChange={(e) => post(
                    `/Contract/${r.contract_id}/oil-recall/instructions`,
                    { test_required: e.target.checked },
                    e.target.checked ? t('Test added to the driver’s instructions') : t('Test removed — the oil change still stands'),
                  )}
                />
                {tf('oil.recall.required.test', 'Test / inspection')}
              </label>
            </div>
            <p className="mt-2 text-xs leading-snug text-slate-500">
              {tf('oil.recall.required.note', 'The oil change is why this car is coming back, so it cannot be removed. The test is optional and can be changed until the car arrives.')}
            </p>

            {/* ── WHAT THE ANSWER ACTUALLY DOES ────────────────────────────────────────────────
                The tick is not a preference, it is the hand-off: it decides whose queue this car
                lands in when it rolls through the gate. Said here, in the same box, because the
                person ticking it is deciding somebody else's morning. */}
            <div className="mt-2 flex items-start gap-2 rounded-md bg-white/70 px-2.5 py-2 text-xs leading-snug text-slate-600 ring-1 ring-inset ring-slate-200">
              <span aria-hidden>➜</span>
              <span>
                {test?.required
                  ? tf('oil.recall.route.inspector', 'On arrival it goes to Abu Maroof: the request is waiting in the review queue, and approving it starts the inspection workflow. The oil change rides on that same visit.')
                  : parking
                    ? tf('oil.recall.route.parking', 'On arrival Abu Maroof changes the oil here in our parking — no garage, no test.')
                    : tf('oil.recall.route.supervisor', 'No test — on arrival it goes straight to Waleed / Abdullah: the oil change opens in their queue and they enter the odometer and pick the garage.')}
              </span>
            </div>

            {/* The car is standing here and nobody has been handed it — normally the driver's
                "Arrived" tap does this, so this button is the same step by hand. */}
            {/* Shown while the Supervisor's decision is still outstanding — whether the ticket has
                been opened yet or not. The driver's arrival tap opens it automatically WITHOUT a
                garage (a driver parking a car at night has no business choosing the shop), so the
                panel has to survive that: the ticket existing is not the same as somebody having
                decided where the car goes. */}
            {canRecord && (recall.awaiting_supervisor || recall.service_ticket?.awaiting_garage) && (
              <SupervisorHandOver r={r} recall={recall} onChanged={onChanged} />
            )}

            {/* Once it IS theirs, say where it got to — "Oil change" on its own hides whether
                anybody has actually picked a garage yet. */}
            {recall.service_ticket && (
              <p className="mt-2 text-xs text-slate-500">
                {recall.service_ticket.awaiting_garage
                  ? tf('oil.recall.ticket.awaitingGarage', 'Oil change ticket #{id} is open — waiting for a garage to be picked.', { id: recall.service_ticket.id })
                  : tf('oil.recall.ticket.open', 'Oil change ticket #{id}{garage}.', {
                    id: recall.service_ticket.id,
                    garage: recall.service_ticket.garage ? ` · ${recall.service_ticket.garage}` : '',
                  })}
              </p>
            )}
          </div>
        )}
      </div>
    </div>
  );
}

/**
 * One car, one card, one action. Reads top to bottom in the order a controller thinks:
 * who is out → how long is left → what number do we hold → where is it heading → what do I do today.
 */
export function VehicleCard({ r, canRecord, onReading, onDecide, onOilChange, onChanged }) {
  const { t, tf } = useI18n();
  const p = r.projection || {};
  const a = actionFor(p, t);
  // The recall relay, when this car is on one. Only an OPEN recall has a chain left to run.
  const recall = p.decision?.recall && !p.decision?.settled_at ? p.decision.recall : null;

  const age = p.days_elapsed;
  // How stale the number is drives the whole page: a fresh reading is a decision, an old one is a call.
  const ageTone = age == null ? 'slate' : age <= 1 ? 'green' : age <= 7 ? 'amber' : 'red';
  const d = p.return_date_known ? p.remaining_days : null;
  const fromCustomer = p.anchor_source === 'reading';
  const over = p.over_tolerance_km;

  // "We think it needs the oil change in ~N days": how long the 200 km/day pace takes to reach the
  // oil limit from today's estimate. Negative ⇒ the estimate says it is already past the oil point.
  const kmToOil = p.oil_limit != null && p.expected != null ? p.oil_limit - p.expected : null;
  const daysToOil = kmToOil == null || !p.rate ? null : Math.ceil(kmToOil / p.rate);

  return (
    <div
      data-card
      className={`group relative overflow-hidden rounded-2xl border bg-white shadow-sm ring-1 ring-transparent transition duration-200 hover:-translate-y-0.5 hover:shadow-lg ${
        CARD_EDGE[a.tone] || CARD_EDGE.slate}`}
    >
      {/* The verdict as a colour you see before you read anything — one rail down the leading edge
          rather than a coloured border boxing the whole card in. */}
      <span className={`absolute inset-y-0 start-0 w-1.5 ${RAIL_TONE[a.tone] || RAIL_TONE.slate}`} aria-hidden="true" />

      <div className="space-y-4 p-5 ps-6">
      {/* Header: the car, the people, and the verdict — nothing else. */}
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <div className="truncate text-lg font-bold tracking-tight text-slate-900">{r.car || r.plate || t('Contract {no}', { no: r.contract_id })}</div>
          <div className="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-sm text-slate-600">
            {r.vehicle_id
              ? <Link to={`/vehicles/${r.vehicle_id}`} className="font-semibold text-slate-800 underline-offset-2 transition hover:text-indigo-600 hover:underline">{r.plate || `#${r.vehicle_id}`}</Link>
              : <span className="font-semibold text-slate-800">{r.plate || '—'}</span>}
            <span>· {t('Contract {no}', { no: r.contract_no || r.contract_id })}</span>
            {r.customer && <span>· {r.customer}</span>}
          </div>
        </div>
        <div className="flex shrink-0 flex-col items-end gap-1">
          <Badge tone={a.tone} dot>{a.label}</Badge>
          {/* The rental clock lives in the header — it frames every other number on the card. */}
          <Badge tone={d === 0 ? 'red' : d != null && d <= 3 ? 'amber' : 'slate'}>
            {d == null ? t('No return date') : d === 0 ? t('Due back today') : d === 1 ? t('1 day left') : t('{n} days left', { n: d })}
          </Badge>
          {/* An oil service recorded AHEAD of the anchor: informational when plausible, loud when
              the sheet row physically contradicts the mileage evidence. */}
          {p.oil_service_state === 'mid_rental_service' && <Badge tone="slate">{t('Mid-rental oil service')}</Badge>}
          {p.oil_service_state === 'suspicious' && <Badge tone="red">{t('Odometer conflict')}</Badge>}
        </div>
      </div>

      {/* Group 1 — the mileage story, left to right in the order it happened. */}
      <Group title={t('Mileage — where is the car?')}>
        {/* The handover reading under its OWN name — never dressed up as the latest reading. */}
        <Stat
          label={t('Handover reading')}
          value={p.handover_odometer != null ? `${num(p.handover_odometer)} km` : '—'}
          sub={p.handover_on ? t('{date} · contract', { date: fmtDate(p.handover_on) }) : t('not recorded')}
        />
        <Arrow />
        <Stat
          label={t('Latest known odometer')}
          value={p.anchor_odometer != null ? `${num(p.anchor_odometer)} km` : '—'}
          pill={age != null ? t('{n}d old', { n: age }) : undefined}
          pillTone={ageTone}
          highlight
          sub={p.anchor_on
            ? (fromCustomer
              ? t('{date} · customer reading', { date: fmtDate(p.anchor_on) })
              : p.anchor_source === 'sheet'
                ? t('{date} · Oil Change sheet', { date: fmtDate(p.anchor_on) })
                : t('{date} · contract handover', { date: fmtDate(p.anchor_on) }))
            : t('no reading held')}
        />
        <Arrow />
        <Stat
          label={t('Today (est.)')}
          value={p.expected != null ? `${num(p.expected)} km` : '—'}
          pill={p.expected != null ? t('projection') : undefined}
          pillTone="slate"
          sub={p.expected != null && p.anchor_odometer != null && age != null
            ? t('= {anchor} + {n}d × {rate} km', { anchor: num(p.anchor_odometer), n: age, rate: num(p.rate) })
            : undefined}
        />
        <Arrow />
        <Stat
          label={t('Return (est.)')}
          value={p.expected_return != null ? `${num(p.expected_return)} km` : '—'}
          sub={!p.return_due_on
            ? t('no return date on the contract')
            : d === 0
              ? t('due back today — same as today’s estimate')
              : t('back {date}', { date: fmtDate(p.return_due_on) })}
        />
        {/* The same four numbers, drawn to scale — the one glance that needs no arithmetic. */}
        <div className="w-full">
          <MileageTrack p={p} lastService={r.last_service_odometer} />
        </div>
      </Group>

      {/* Group 2 — the oil schedule, from the last change to the hard maximum. */}
      <Group title={t('Oil service — when is it due?')}>
        <Stat
          label={t('Last oil change')}
          value={r.last_service_odometer != null ? `${num(r.last_service_odometer)} km` : '—'}
          sub={t('Oil Change sheet')}
        />
        <Stat
          label={t('Interval')}
          value={r.service_interval_km != null ? `${num(r.service_interval_km)} km` : '—'}
        />
        <Stat
          label={t('Oil limit')}
          value={p.oil_limit != null ? `${num(p.oil_limit)} km` : '—'}
          sub={t('last change + interval')}
        />
        <Stat
          label={t('Max allowed')}
          value={p.allowed_max != null ? `${num(p.allowed_max)} km` : '—'}
          sub={p.tolerance != null ? t('limit + {n} km grace', { n: num(p.tolerance) }) : undefined}
        />
        <Stat
          label={t('Change due')}
          value={daysToOil == null ? '—' : daysToOil <= 0 ? t('now') : daysToOil === 1 ? t('~1 day') : t('~{n} days', { n: daysToOil })}
          sub={daysToOil == null ? undefined : daysToOil <= 0 ? t('estimate is past the oil point') : t('at the current pace')}
        />
      </Group>

      {/* The oil-service reading explained in one line — surfaced, never silently trusted. */}
      {p.oil_service_state === 'mid_rental_service' && (
        <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
          {t('Oil service recorded at {km} km, after the last known reading ({anchor} km) — a mid-rental service, not an error. The sheet carries no service date, so the estimate still runs from the last dated reading.', { km: num(p.oil_service_odometer), anchor: num(p.anchor_odometer) })}
        </div>
      )}
      {p.oil_service_state === 'suspicious' && (
        <div className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
          {t('Oil service recorded at {km} km — {ahead} km ahead of the last known reading, further than this car could have driven. Verify the sheet row before relying on the oil limit.', { km: num(p.oil_service_odometer), ahead: num(p.oil_service_ahead_km) })}
        </div>
      )}

      {/* The deadline sentence: the latest the oil can be changed, against where we think the car
          is TODAY. One plain string so it reads like speech, not like a table. */}
      {p.allowed_max != null && p.expected != null && (
        <div className={`rounded-lg border-s-4 px-3 py-2 text-sm font-semibold tabular-nums ${
          p.expected > p.allowed_max
            ? 'border-rose-400 bg-rose-50 text-rose-800'
            : 'border-slate-300 bg-slate-50 text-slate-700'}`}
        >
          {p.expected > p.allowed_max
            ? t('Must be changed by {max} km at the latest — estimated now {now} km, already {over} km past.', { max: num(p.allowed_max), now: num(p.expected), over: num(p.expected - p.allowed_max) })
            : t('Must be changed by {max} km at the latest — estimated now {now} km, {left} km to go.', { max: num(p.allowed_max), now: num(p.expected), left: num(p.allowed_max - p.expected) })}
        </div>
      )}

      {/* The recall relay — only on a car actually being brought back. Sits directly above today's
          action, because on those cars the relay IS today's action. */}
      {recall && <RecallRelay r={r} recall={recall} canRecord={canRecord} onChanged={onChanged} />}

      {/* Today's action — one bold line, one quiet line, and the buttons. Nothing to read twice. */}
      <div className={`rounded-xl border p-3.5 shadow-inner ${ACTION_BOX_TONE[a.tone]}`}>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="min-w-0">
            {/* On a recalled car the relay panel directly above has ALREADY said the stage, who acts
                next and what the car owes. Repeating it here in bigger type is what turned the card
                into a wall — so this box keeps only what is its own: the badges and the buttons. */}
            {!recall && <div className="text-base font-bold leading-snug">{a.headline}</div>}
            {!recall && <div className="mt-0.5 text-sm opacity-80">{a.support}</div>}
            {/* An arrival is a checklist, not a sentence — what to do the moment the car rolls in. */}
            {a.steps && (
              <ol className="mt-1.5 list-decimal space-y-0.5 ps-5 text-sm">
                {a.steps.map((s) => <li key={s}>{s}</li>)}
              </ol>
            )}
          </div>
          <div className="flex shrink-0 flex-wrap items-center gap-1.5">
            {over != null && (over > 0
              ? <Badge tone="red">{t('{n} km over grace', { n: num(over) })}</Badge>
              : <Badge tone="green">{t('{n} km inside grace', { n: num(Math.abs(over)) })}</Badge>)}
            {canRecord && a.key !== 'no_data' && (
              <>
                {/* The customer's reading is only askable while the customer HAS the car. */}
                {!a.inOurHands && (
                  <Button size="sm" variant={a.chase ? 'primary' : 'ghost'} onClick={() => onReading(r)}>
                    {t('Enter reading')}
                  </Button>
                )}
                {/* The far end — and ONLY once the car is physically ours. While it is still with
                    the customer (waiting for Sales, waiting for a driver) there is no oil change to
                    record and no dash to read; offering it there invites a number that was never
                    observed. Custody comes from the collection itself, not the contract. */}
                {p.decision && !p.decision.settled_at && p.decision.recall?.in_our_custody && (
                  <Button size="sm" variant="primary" onClick={() => onOilChange(r)}>
                    {tf('oil.done.button', 'Oil changed')}
                  </Button>
                )}
                {/* Only offered once the projection rests on a fresh reading — nobody should be asked
                    to recall a customer's car on the strength of a 200 km/day assumption. */}
                {a.decide && (
                  <>
                    <Button size="sm" variant="danger" onClick={() => onDecide(r, 'recall')}>{t('Recall now')}</Button>
                    <Button size="sm" variant="secondary" onClick={() => onDecide(r, 'defer')}>{t('Do it on return')}</Button>
                  </>
                )}
              </>
            )}
          </div>
        </div>
      </div>
      </div>
    </div>
  );
}

/**
 * Enter the number the customer read off the dash.
 *
 * Loads the contract's own projection + every previous reading, so whoever is on the call can see what
 * was reported last time before typing a new figure. On save it shows the RECALCULATED verdict — the
 * whole point of entering the number is finding out what it changes, and the caller needs to know that
 * before they put the phone down.
 */
export function ReadingDialog({ row, onClose, onSaved }) {
  const { t } = useI18n();
  const toast = useToast();
  const [detail, setDetail] = useState(null);
  const [odometer, setOdometer] = useState('');
  const [reportedBy, setReportedBy] = useState('');
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState(null);

  useEffect(() => {
    let alive = true;
    api.get(`/Contract/${row.contract_id}/oil-projection`)
      .then((res) => { if (alive) setDetail(res.data?.data || null); })
      .catch(() => { if (alive) setDetail(null); });
    return () => { alive = false; };
  }, [row.contract_id]);

  const projection = detail?.projection || row.projection;
  const readings = detail?.readings || [];

  const submit = async () => {
    if (odometer === '' || Number.isNaN(Number(odometer))) {
      return toast.error(t('Enter the odometer reading the customer gave you'));
    }
    setBusy(true);
    try {
      const { data } = await api.post(`/Contract/${row.contract_id}/mileage-reading`, {
        odometer: Number(odometer),
        reported_by: reportedBy || null,
        note: note || null,
      });
      const saved = data?.data || {};
      setResult(saved.projection || null);
      // The new reading re-anchors EVERYTHING — so every number in this dialog moves with it:
      // the verdict sentence up top, the Expected-today reference, and the history list all
      // re-render from the recalculated projection instead of the one loaded on open.
      setDetail((d) => ({
        ...(d || {}),
        projection: saved.projection || d?.projection,
        readings: saved.reading ? [saved.reading, ...(d?.readings || [])] : (d?.readings || []),
      }));
      setOdometer('');
      toast.success(t('Reading saved — recalculated'));
      onSaved();
    } catch (e) {
      // The API rejects a reading that runs backwards; surface its sentence, not a generic failure.
      toast.error(e.response?.data?.message || t('Could not save the reading'));
    } finally {
      setBusy(false);
    }
  };

  // The recalculated answer, tone-matched: a car that is now a decision must not be reported in the
  // same reassuring green as one that just cleared itself.
  const resultTone = result?.oil_status === 'decision_required'
    ? 'border-rose-200 bg-rose-50 text-rose-800'
    : 'border-emerald-200 bg-emerald-50 text-emerald-800';

  return (
    <Modal
      open
      onClose={() => !busy && onClose()}
      size="lg"
      title={t('Mileage reading — {car}', { car: row.plate || row.car || t('contract {no}', { no: row.contract_id }) })}
      subtitle={row.customer ? t('{customer} · contract {no}', { customer: row.customer, no: row.contract_no || row.contract_id }) : undefined}
      footer={(
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose} disabled={busy}>{t('Close')}</Button>
          <Button onClick={submit} loading={busy}>{t('Save reading')}</Button>
        </div>
      )}
    >
      <div className="space-y-4">
        <div className="rounded-lg bg-slate-50 p-3 text-sm text-slate-700">{reasonFor(projection, t)}</div>

        {/* The reference number for the call: a customer figure close to this = tracking normally. */}
        {projection?.expected != null && (
          <div className="rounded-lg border border-indigo-100 bg-indigo-50 p-3 text-sm text-indigo-900">
            {/* Label and figure split deliberately: the value trails the colon in English AND
                Arabic, so the bold number survives without stranding a clause in markup. */}
            {t('Expected today:')} <strong>{num(projection.expected)} km</strong>
            <span className="ms-1 text-xs text-indigo-700">
              {t('— if the customer reports a value close to this, the rental is tracking normally.')}
            </span>
          </div>
        )}

        {result && (
          <div className={`rounded-lg border p-3 text-sm ${resultTone}`}>
            <div className="font-semibold">{t('Recalculated')}</div>
            <div>{reasonFor(result, t)}</div>
            {result.oil_status === 'decision_required' && (
              <div className="mt-1 text-xs">
                {t('Close this and choose “Recall now” or “Do it on return”.')}
              </div>
            )}
          </div>
        )}

        <Input
          label={t('Odometer reported by the customer (km)')}
          type="number"
          required
          value={odometer}
          onChange={(e) => setOdometer(e.target.value)}
          placeholder={t('e.g. 41200')}
        />
        <Input
          label={t('Who gave the reading (optional)')}
          value={reportedBy}
          onChange={(e) => setReportedBy(e.target.value)}
          placeholder={t('Customer, over the phone')}
        />
        <Textarea
          label={t('Note (optional)')}
          value={note}
          onChange={(e) => setNote(e.target.value)}
          rows={2}
        />

        <div>
          <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
            {t('Odometer history')}
          </div>
          {readings.length === 0 && projection?.handover_odometer == null ? (
            <div className="text-sm text-slate-500">
              {t('No readings held for this rental yet.')}
            </div>
          ) : (
            <ul className="divide-y divide-slate-100 text-sm">
              {readings.map((r) => (
                <li key={r.id} className="flex items-center justify-between py-1.5">
                  <span className="font-medium text-slate-800">{num(r.odometer)} km</span>
                  <span className="text-slate-500">{r.reported_by ? `${fmtDate(r.reported_on)} · ${r.reported_by}` : t('{date} · customer reading', { date: fmtDate(r.reported_on) })}</span>
                </li>
              ))}
              {/* The evidence trail bottoms out at the handover — every row says its source. */}
              {projection?.handover_odometer != null && (
                <li className="flex items-center justify-between py-1.5">
                  <span className="font-medium text-slate-800">{num(projection.handover_odometer)} km</span>
                  <span className="text-slate-500">{t('{date} · contract handover', { date: fmtDate(projection.handover_on) })}</span>
                </li>
              )}
              {projection?.oil_service_odometer != null && projection?.oil_service_state && (
                <li className="flex items-center justify-between py-1.5">
                  <span className="font-medium text-slate-800">{num(projection.oil_service_odometer)} km</span>
                  <span className="text-slate-500">{t('date unknown · oil service (sheet)')}</span>
                </li>
              )}
            </ul>
          )}
        </div>
      </div>
    </Modal>
  );
}

/**
 * THE OIL IS CHANGED — one number, and this car's follow-up is over.
 *
 * Everything else on this page is arrangement: a projection, a decision, a phone call, a driver.
 * None of it changes the car's oil life. This does — the workshop reads the dash after the change,
 * types that number, and the car's next service runs from it (reading + interval), the projection
 * re-anchors on a fact instead of a 200 km/day guess, and the recall stands down on its own.
 *
 * The dialog states what the number will DO before it is saved, and states what it DID after — the
 * person entering it is changing the car's schedule, and must see that, not just a green toast.
 */
export function OilChangeDialog({ row, onClose, onSaved }) {
  const { tf } = useI18n();
  const toast = useToast();
  const [odometer, setOdometer] = useState('');
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(null);
  const p = row.projection || {};

  const interval = row.service_interval_km ?? null;
  const entered = odometer === '' ? null : Number(odometer);
  // The preview: what the car's profile will say the moment this is saved.
  const nextDue = entered != null && !Number.isNaN(entered) && interval != null ? entered + interval : null;

  const submit = async () => {
    if (odometer === '' || Number.isNaN(Number(odometer))) {
      return toast.error(tf('oil.done.needOdometer', 'Enter the odometer the oil was changed at'));
    }
    setBusy(true);
    try {
      const { data } = await api.post(`/Contract/${row.contract_id}/oil-change-done`, {
        odometer: Number(odometer),
        note: note || null,
      });
      setDone(data?.data?.vehicle || null);
      toast.success(tf('oil.done.saved', 'Oil change recorded — the car’s next service has moved'));
      onSaved();
    } catch (e) {
      toast.error(e.response?.data?.message || tf('oil.done.failed', 'Could not record the oil change'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      onClose={() => !busy && onClose()}
      title={`${tf('oil.done.title', 'Oil changed')} — ${row.plate || row.car || row.contract_id}`}
      subtitle={row.customer ? `${row.customer} · ${row.contract_no || row.contract_id}` : undefined}
      footer={(
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose} disabled={busy}>
            {done ? tf('common.close', 'Close') : tf('common.cancel', 'Cancel')}
          </Button>
          {!done && (
            <Button onClick={submit} loading={busy}>
              {tf('oil.done.save', 'Record the oil change')}
            </Button>
          )}
        </div>
      )}
    >
      <div className="space-y-3 text-sm">
        {done ? (
          // What it actually did — the car's own schedule, in its own numbers.
          <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-emerald-900">
            <div className="font-semibold">{tf('oil.done.result', 'Done. This car’s schedule now reads:')}</div>
            <div className="mt-1">
              {tf('oil.done.lastChange', 'Last oil change')}: <strong>{num(done.last_service_odometer)} km</strong>
            </div>
            <div>
              {tf('oil.done.nextChange', 'Next oil change')}: <strong>{num(done.next_service_odometer)} km</strong>
              {' '}({num(done.last_service_odometer)} + {num(done.service_interval_km)})
            </div>
            <div>
              {tf('oil.done.allowedMax', 'Follow-up allowance')}: <strong>{num(done.allowed_max)} km</strong>
              {' '}— {tf('oil.done.graceNote', 'the board keeps its 500 km grace on top of the new limit')}
            </div>
          </div>
        ) : (
          <>
            <div className="rounded-lg bg-slate-50 p-3 text-slate-700">
              {tf('oil.done.intro', 'Enter the odometer the oil was actually changed at. That number becomes this car’s new service point — everything after it is measured from there.')}
            </div>
            <Input
              label={tf('oil.done.odoLabel', 'Odometer at the oil change (km)')}
              type="number"
              required
              value={odometer}
              onChange={(e) => setOdometer(e.target.value)}
              placeholder={p.expected != null ? String(p.expected) : '20000'}
            />
            {nextDue != null && (
              <div className="rounded-lg border border-indigo-100 bg-indigo-50 p-3 text-indigo-900">
                {tf('oil.done.preview', 'Next oil change will be set to')}{' '}
                <strong>{num(nextDue)} km</strong> ({num(entered)} + {num(interval)}).
              </div>
            )}
            <Textarea
              label={tf('oil.done.noteLabel', 'Note (optional)')}
              value={note}
              onChange={(e) => setNote(e.target.value)}
              rows={2}
            />
            <ul className="list-disc space-y-1 ps-5 text-xs text-slate-600">
              <li>{tf('oil.done.effect1', 'The car’s profile is updated: last change and next change.')}</li>
              <li>{tf('oil.done.effect2', 'This follow-up closes — the recall call and any driver collection stand down.')}</li>
              <li>{tf('oil.done.effect3', 'The inspection request raised for this car is resolved.')}</li>
            </ul>
          </>
        )}
      </div>
    </Modal>
  );
}

/**
 * The call itself, on a car that cannot finish the rental inside its allowance.
 *
 * Confirmed rather than fired from a bare card button, because one of the two answers means phoning a
 * paying customer to ask for their car back — and the person doing that should see the figures they are
 * doing it on, spelled out, one more time.
 */
export function DecisionDialog({ row, decision, onClose, onDecided }) {
  const { t, tf } = useI18n();
  const toast = useToast();
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const p = row.projection || {};
  const recall = decision === 'recall';

  // Does the fleet ALREADY want this car tested? Usually the system's own "routine check overdue"
  // card sitting in /inspection-review. If it does, the honest default is yes — silently dropping a
  // safety check the system asked for is not a decision anyone took.
  const already = row.pending_test_request || null;
  const [testRequired, setTestRequired] = useState(Boolean(already));
  // WHERE the change happens decides WHO is told, so it is asked here, not left to a later screen.
  const [location, setLocation] = useState('garage');

  const submit = async () => {
    setBusy(true);
    try {
      await api.post(`/Contract/${row.contract_id}/oil-decision`, {
        decision,
        note: note || null,
        ...(recall ? { test_required: testRequired, service_location: location } : {}),
      });
      toast.success(recall ? t('Recall recorded') : t('Oil change booked for the return'));
      onDecided();
      onClose();
    } catch (e) {
      toast.error(e.response?.data?.message || t('Could not record the decision'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      onClose={() => !busy && onClose()}
      title={`${t(DECISION_LABEL[decision])} — ${row.plate || row.car || t('contract {no}', { no: row.contract_id })}`}
      subtitle={row.customer ? t('{customer} · contract {no}', { customer: row.customer, no: row.contract_no || row.contract_id }) : undefined}
      footer={(
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose} disabled={busy}>{t('Cancel')}</Button>
          <Button variant={recall ? 'danger' : 'primary'} onClick={submit} loading={busy}>
            {t(DECISION_LABEL[decision])}
          </Button>
        </div>
      )}
    >
      <div className="space-y-3 text-sm">
        <div className="rounded-lg bg-slate-50 p-3 text-slate-700">
          <div>{t('Projected on return: {km} km', { km: num(p.expected_return) })}</div>
          <div>{t('Allowed maximum: {max} km ({limit} km oil limit + {tol} km tolerance)', { max: num(p.allowed_max), limit: num(p.oil_limit), tol: num(p.tolerance) })}</div>
          <div>
            {p.return_date_known
              ? (p.remaining_days === 1
                ? t('Rental still to run: 1 day')
                : t('Rental still to run: {n} days', { n: p.remaining_days }))
              : t('Rental still to run: not stated on the contract')}
          </div>
          <div className="mt-1 font-semibold text-rose-700">
            {t('{n} km past what this car is allowed to run.', { n: num(Math.max(p.over_tolerance_km ?? 0, 0)) })}
          </div>
        </div>

        {/* ── The two operational choices, asked once, at the moment the recall is agreed ────── */}
        {recall && (
          <div className="space-y-3 rounded-lg border border-indigo-200 bg-indigo-50/60 p-3">
            {/* 1. Is this car also being tested? */}
            {already ? (
              <div>
                <div className="text-sm font-bold text-indigo-900">
                  {tf('oil.decide.alreadyAsked', 'The system has already asked for a test on this car')}
                </div>
                <p className="mt-0.5 text-xs text-indigo-800">
                  {tf('oil.decide.alreadyAskedWhy', 'Request #{id}, waiting in the review queue:', { id: already.id })}
                </p>
                {/* The reason is a list of separate facts, not a sentence — one line each. */}
                <NoteLines value={already.reason} quote className="mt-0.5 text-xs italic text-indigo-800" />
                <label className="mt-1.5 flex items-start gap-2 text-sm text-slate-800">
                  <input
                    type="checkbox"
                    className="mt-1"
                    checked={testRequired}
                    onChange={(e) => setTestRequired(e.target.checked)}
                  />
                  <span>
                    {tf('oil.decide.useExisting', 'Do the test too — add the oil change to that request')}
                    <span className="block text-xs text-slate-500">
                      {tf('oil.decide.useExistingWhy', 'No second card is created. Unticked, that request is left exactly as it is and the oil change goes straight to a driver.')}
                    </span>
                  </span>
                </label>
              </div>
            ) : (
              <label className="flex items-start gap-2 text-sm text-slate-800">
                <input
                  type="checkbox"
                  className="mt-1"
                  checked={testRequired}
                  onChange={(e) => setTestRequired(e.target.checked)}
                />
                <span>
                  {tf('oil.decide.alsoTest', 'Test the car as well')}
                  <span className="block text-xs text-slate-500">
                    {tf('oil.decide.alsoTestWhy', 'Files an inspection request for the review queue. Leave it off and this is an oil change only.')}
                  </span>
                </span>
              </label>
            )}

            {/* 2. Where — which is the same question as who. */}
            <div>
              <div className="text-sm font-semibold text-slate-800">
                {tf('oil.decide.whereTitle', 'Where will the oil be changed?')}
              </div>
              <div className="mt-1 flex flex-wrap gap-4">
                <label className="flex items-center gap-2 text-sm text-slate-700">
                  <input
                    type="radio"
                    name="oil-service-location"
                    checked={location === 'garage'}
                    onChange={() => setLocation('garage')}
                  />
                  {tf('oil.decide.atGarage', 'At a garage')}
                </label>
                <label className="flex items-center gap-2 text-sm text-slate-700">
                  <input
                    type="radio"
                    name="oil-service-location"
                    checked={location === 'parking'}
                    onChange={() => setLocation('parking')}
                  />
                  {tf('oil.decide.inParking', 'In our parking')}
                </label>
              </div>
              <p className="mt-1 text-xs text-slate-600">
                {location === 'parking'
                  ? tf('oil.decide.parkingWho', 'Abu Maroof is told to do the change when the car lands. The driver is told to bring it to the parking.')
                  : tf('oil.decide.garageWho', 'Waleed and Abdullah are told to arrange the garage. The driver is told to bring it to the workshop.')}
              </p>
            </div>
          </div>
        )}

        {/* The exact consequence, spelled out BEFORE the confirm — the two answers run two
            different operational workflows and the person clicking must know which. */}
        <div className="rounded-lg border border-slate-200 bg-white p-3">
          <div className="text-sm font-bold text-slate-800">{recall ? t('🚨 Recall now — this will:') : t('🔧 Service on return — this will:')}</div>
          <ul className="mt-1.5 list-disc space-y-1 ps-5 text-sm text-slate-700">
            {recall ? (
              <>
                <li>{t('Raise a call for you: ask Sales to agree the return with the customer.')}</li>
                <li>{t('Notify no driver yet — nobody is dispatched until you click “Sales OK — Customer confirmed” on this card.')}</li>
                <li>
                  {location === 'parking'
                    ? tf('oil.decide.thenParking', 'Then, on Sales OK: send a driver to collect it, and tell Abu Maroof to change the oil in the parking.')
                    : tf('oil.decide.thenGarage', 'Then, on Sales OK: ask Waleed and Abdullah to arrange a driver and a garage.')}
                </li>
                <li>
                  {!testRequired
                    ? tf('oil.decide.noRequest', 'File no inspection request — this is an oil change, not a test.')
                    : already
                      ? tf('oil.decide.addToRequest', 'Add the oil change to the test request already waiting (#{id}) — no second card.', { id: already.id })
                      : tf('oil.decide.newRequest', 'File the inspection follow-up with these figures for the Inspector.')}
                </li>
                <li>{t('Require the actual odometer when the car is received — and an oil change, whatever else is done to the car.')}</li>
              </>
            ) : (
              <>
                <li>{t('The customer keeps the car until the agreed return — nobody is called.')}</li>
                <li>{t('File the inspection follow-up so the return is expected, with these figures.')}</li>
                <li>{t('On return: actual odometer read, oil checked, service ticket raised automatically.')}</li>
                <li>{t('Cancel any recall call or collection that was previously open.')}</li>
              </>
            )}
          </ul>
          <p className="mt-1.5 text-xs text-slate-500">
            {t('The figures stay live: a newer odometer reading updates the follow-up automatically.')}
          </p>
        </div>

        <Textarea
          label={t('Why (optional)')}
          value={note}
          onChange={(e) => setNote(e.target.value)}
          rows={2}
          placeholder={recall ? t('Customer agreed to bring it in Thursday') : t('Customer is mid-trip; overrun accepted')}
        />
      </div>
    </Modal>
  );
}

/**
 * The call list — who to phone, on which number, about which car.
 *
 * The board is one big card per car because each card carries a decision. Making twenty-six calls
 * is a different job: it wants a flat list with the phone number on it. Same rows, same lane, no
 * new fetch — everything below comes from the payload the board already holds, and the CSV is that
 * same list written out so it can be worked from a phone or handed to someone else.
 */
export function CallListDialog({ rows, laneLabel, onClose }) {
  const { t } = useI18n();

  // Same order as the board behind it — worst first, so the list is worked from the top down.
  const ordered = useMemo(() => [...rows].sort(byUrgency), [rows]);

  const csvRows = useMemo(() => ordered.map((r) => {
    const p = r.projection || {};
    const m = oilMarginFor(p);
    return {
      car:      r.car || '',
      plate:    r.plate || '',
      customer: r.customer || '',
      phone:    r.customer_phone || '',
      cx:       r.customer_no || '',
      contract: r.contract_no || '',
      // The branch's own note on the contract — which team, booked which way. Carried out with the
      // list so a sheet handed to someone else still says whose customer each row belongs to.
      remarks:  r.contract_remarks || '',
      // The figures the margin is made of travel WITH it — a spreadsheet handed to someone else has
      // to be able to show its own arithmetic, exactly like the page does.
      left:     m.km == null ? '' : m.km,
      days:     m.days == null ? '' : m.days,
      now:      p.expected ?? '',
      max:      p.allowed_max ?? '',
    };
  }), [ordered]);

  const download = () => {
    const head = [
      t('Car'), t('Plate'), t('Customer'), t('Phone'), t('CX number'), t('Contract'), t('Contract note'),
      t('Km left before the allowance'), t('Days left (est.)'), t('Today (est.) km'), t('Max allowed km'),
    ];
    const cell = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const lines = csvRows.map((r) => [r.car, r.plate, r.customer, r.phone, r.cx, r.contract, r.remarks, r.left, r.days, r.now, r.max].map(cell).join(','));
    // A leading BOM so Excel opens Arabic customer names as Arabic, not as mojibake.
    const blob = new Blob(['﻿' + [head.map(cell).join(','), ...lines].join('\r\n')], {
      type: 'text/csv;charset=utf-8',
    });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `oil-call-list-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  };

  return (
    <Modal
      open
      onClose={onClose}
      size="xl"
      title={t('Call list')}
      subtitle={t('{n} car(s) in “{lane}”, worst first — the least room left against the allowance is at the top.', { n: rows.length, lane: laneLabel })}
      footer={(
        <div className="flex items-center justify-between gap-2">
          <Button variant="secondary" onClick={download} disabled={!rows.length}>{t('Download CSV')}</Button>
          <Button variant="ghost" onClick={onClose}>{t('Close')}</Button>
        </div>
      )}
    >
      {rows.length === 0 ? (
        <div className="py-8 text-center text-sm text-slate-400">{t('No cars in this list.')}</div>
      ) : (
        <div className="max-h-[60vh] overflow-auto">
          <table className="w-full text-sm">
            <thead className="sticky top-0 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-2 py-2 text-start">{t('Car')}</th>
                <th className="px-2 py-2 text-start">{t('Plate')}</th>
                <th className="px-2 py-2 text-start">{t('Customer')}</th>
                <th className="px-2 py-2 text-start">{t('Phone')}</th>
                <th className="px-2 py-2 text-start">{t('CX number')}</th>
                <th className="px-2 py-2 text-start">{t('Contract note')}</th>
                <th className="px-2 py-2 text-end">{t('Km left for oil')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {ordered.map((r) => {
                const p = r.projection || {};
                const m = oilMarginFor(p);
                return (
                <tr key={r.contract_id} className="align-top transition-colors hover:bg-slate-50">
                  <td className="px-2 py-2 text-slate-700">
                    {r.car || '—'}
                    {r.contract_no && <div className="text-xs text-slate-400">{r.contract_no}</div>}
                  </td>
                  <td className="px-2 py-2 font-medium text-slate-900">
                    {r.vehicle_id
                      ? <Link to={`/vehicles/${r.vehicle_id}`} className="text-indigo-600 hover:text-indigo-700">{r.plate || `#${r.vehicle_id}`}</Link>
                      : (r.plate || '—')}
                  </td>
                  <td className="px-2 py-2 text-slate-700">{r.customer || '—'}</td>
                  <td className="px-2 py-2">
                    {r.customer_phone
                      ? <a href={`tel:${r.customer_phone}`} className="font-medium text-indigo-600 hover:text-indigo-700" dir="ltr">{r.customer_phone}</a>
                      : <span className="text-slate-400">{t('No number on file')}</span>}
                    {r.customer_whatsapp && r.customer_whatsapp !== r.customer_phone && (
                      <div className="text-xs text-slate-500" dir="ltr">{t('WhatsApp')}: {r.customer_whatsapp}</div>
                    )}
                    {r.customer_mobile2 && r.customer_mobile2 !== r.customer_phone && (
                      <div className="text-xs text-slate-500" dir="ltr">{r.customer_mobile2}</div>
                    )}
                  </td>
                  <td className="px-2 py-2 text-slate-700" dir="ltr">{r.customer_no || '—'}</td>
                  {/* What the branch wrote on the contract in OM — which team owns this rental and
                      how it was booked. Printed word for word: it is somebody else's shorthand, and
                      a call list that re-words it would be telling the caller something we invented. */}
                  <td className="max-w-[14rem] px-2 py-2 text-xs text-slate-600" dir="ltr">
                    {r.contract_remarks || <span className="text-slate-400">—</span>}
                  </td>
                  {/* How much run is left before this car passes what it is allowed. The order of
                      the list is this column, so the person dialling can stop wherever they run out
                      of time and know the cars they skipped were the least urgent ones. */}
                  <td className="px-2 py-2 text-end tabular-nums">
                    {m.km == null ? (
                      <span className="text-slate-400">{t('Can’t project')}</span>
                    ) : m.km < 0 ? (
                      <>
                        <div className="font-bold text-rose-700">{t('{n} km over', { n: num(Math.abs(m.km)) })}</div>
                        <div className="text-xs text-rose-500">{t('past the allowance already')}</div>
                      </>
                    ) : (
                      <>
                        <div className={`font-bold ${m.days != null && m.days <= 3 ? 'text-amber-700' : 'text-slate-900'}`}>
                          {t('{n} km', { n: num(m.km) })}
                        </div>
                        <div className="text-xs text-slate-500">
                          {m.days == null
                            ? t('to the {max} km allowance', { max: num(p.allowed_max) })
                            : m.days === 0
                              ? t('today, at the current pace')
                              : m.days === 1
                                ? t('~1 day at the current pace')
                                : t('~{n} days at the current pace', { n: m.days })}
                        </div>
                      </>
                    )}
                  </td>
                </tr>
                );
              })}
            </tbody>
          </table>
          {/* The column states its own arithmetic — nobody should have to guess what "km left" is. */}
          <div className="mt-3 text-xs text-slate-500">
            <span className="font-semibold">{t('Data origin:')}</span>{' '}
            {t('“Km left for oil” is the max allowed (oil limit + grace) minus today’s estimated odometer — the same two figures shown on each car’s card. The days are that gap at the projection’s own km/day pace. Nothing here is a new calculation.')}{' '}
            {t('“Contract note” is the remark typed on the contract in OfficeManager, shown word for word.')}
          </div>
        </div>
      )}
    </Modal>
  );
}

export default function OilProjection() {
  const { t } = useI18n();
  const { can } = usePermissions();
  const canRecord = can('reminders.manage');
  const [filter, setFilter] = useState('call_customer');
  const [due, setDue] = useState('any');       // secondary: due window
  const [freshness, setFreshness] = useState('any'); // secondary: reading age
  const [q, setQ] = useState('');
  const [active, setActive] = useState(null);
  const [deciding, setDeciding] = useState(null);   // { row, decision }
  const [changing, setChanging] = useState(null);   // the car whose completed oil change is being recorded
  const [callList, setCallList] = useState(false);  // the flat "who do I phone" view of the current lane

  // One call, one board. The separate "recalls to arrange" list used to load alongside it and
  // restate, in a second place, what each recalled car's own panel already says — the stage, the
  // figures, the person whose move it is. Two renderings of one recall can only ever agree by
  // accident, so the card is now the single place a recall is read.
  const fetcher = useCallback(async () => (await api.get('/OilProjection')).data.data || {}, []);
  // Modal open ⇒ pause polling, so the board can't reshuffle under someone mid-call.
  const { data, loading, error, reload } = useFetch(fetcher, [], {
    refreshInterval: 60000,
    paused: () => Boolean(active || deciding || changing || callList),
  });

  const contracts = useMemo(() => data?.contracts || [], [data]);
  const summary = data?.summary || {
    chase_due: 0, ok: 0, no_data: 0, total: 0,
    decision_required: 0, awaiting_reading: 0, recall_required: 0,
    service_required_on_return: 0, within_tolerance: 0,
  };

  // One classification, used for the tabs, the counts and the cards alike — a card can never sit
  // under a tab whose count didn't include it.
  const laneCounts = useMemo(() => {
    const counts = { action_required: 0, service_on_return: 0, call_customer: 0, safe: 0, no_data: 0 };
    contracts.forEach((r) => { counts[laneFor(r.projection)] += 1; });
    return counts;
  }, [contracts]);

  const rows = useMemo(() => {
    const needle = q.trim().toLowerCase();
    let list = contracts;
    if (filter !== 'all') list = list.filter((r) => laneFor(r.projection) === filter);
    // Secondary filters narrow WITHIN the lane view — they never reclassify a car.
    if (due !== 'any') {
      list = list.filter((r) => {
        const d = r.projection?.return_date_known ? r.projection?.remaining_days : null;
        if (d == null) return false;
        if (due === 'today') return d === 0;
        if (due === 'tomorrow') return d === 1;
        if (due === '1-3') return d >= 1 && d <= 3;
        if (due === '4-7') return d >= 4 && d <= 7;
        return d >= 8;
      });
    }
    if (freshness !== 'any') {
      list = list.filter((r) => {
        const age = r.projection?.days_elapsed;
        if (age == null) return false;
        return freshness === 'fresh' ? age <= 1 : age > 1;
      });
    }
    if (needle) {
      list = list.filter((r) => `${r.plate || ''} ${r.car || ''} ${r.customer || ''} ${r.contract_no || ''}`
        .toLowerCase().includes(needle));
    }
    // Worst first. A queue of twenty-six calls is worked from the top, so the top must be the car
    // with the least room left against its allowance — not whichever contract the API listed first.
    return [...list].sort(byUrgency);
  }, [contracts, filter, due, freshness, q]);

  if (error) return <ErrorState title={t('Could not load the follow-up queue')} message={error} onRetry={reload} />;

  return (
    <div className="space-y-5">
      <PageHeader
        title={t('Oil Mileage Follow-up')}
        subtitle={t('Cars out on rental whose oil limit is coming up. Get the real odometer from the customer — the question is whether the car can finish the rental inside its allowance, not whether it has passed the limit.')}
      />

      <MetricGrid cols={4}>
        <MetricCard label={t('Action required')} value={laneCounts.action_required} tone="red" loading={loading}
          tooltip={t('An agreed recall to arrange, or a fresh reading proving the car cannot finish inside its allowance. Someone must act before the return.')} />
        <MetricCard label={t('Service on return')} value={laneCounts.service_on_return} tone="amber" loading={loading}
          tooltip={t('Due back today (or overdue). Don’t call — read the actual odometer when the car arrives, check the oil and service if required.')} />
        <MetricCard label={t('Call customer')} value={laneCounts.call_customer} tone="amber" loading={loading}
          tooltip={t('Still days to run on a stale number. Phone the customer for the real odometer before anything is decided.')} />
        <MetricCard label={t('Safe / scheduled')} value={laneCounts.safe} tone="green" loading={loading}
          tooltip={t('No intervention needed — finishes inside its allowance; any oil change is raised automatically at the return.')} />
      </MetricGrid>

      <SectionCard
        title={t('Follow-up queue')}
        subtitle={data?.model
          ? t("Projected at {rate} km/day, with a {grace} km grace above each car's oil limit.", { rate: num(data.model.rate_km_per_day), grace: num(data.model.tolerance_km ?? data.model.grace_km) })
          : undefined}
        actions={(
          <div className="flex flex-wrap items-center gap-2">
            <SearchInput value={q} onChange={setQ} placeholder={t('Plate, customer, contract…')} />
            <FilterChips
              value={filter}
              onChange={setFilter}
              options={[
                { key: 'call_customer', label: `${t('Call customer')} (${laneCounts.call_customer})` },
                { key: 'service_on_return', label: `${t('Service on return')} (${laneCounts.service_on_return})` },
                { key: 'action_required', label: `${t('Action required')} (${laneCounts.action_required})` },
                { key: 'safe', label: `${t('Safe / scheduled')} (${laneCounts.safe})` },
                { key: 'no_data', label: `${t('Can’t project')} (${laneCounts.no_data})` },
                { key: 'all', label: `${t('All')} (${summary.total})` },
              ]}
            />
            {/* Narrow WITHIN the lane — these never move a car between lanes. */}
            <select
              value={due}
              onChange={(e) => setDue(e.target.value)}
              className="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-700"
              aria-label={t('Due window')}
            >
              <option value="any">{t('Due: any')}</option>
              <option value="today">{t('Due today')}</option>
              <option value="tomorrow">{t('Due tomorrow')}</option>
              <option value="1-3">{t('Due in 1–3 days')}</option>
              <option value="4-7">{t('Due in 4–7 days')}</option>
              <option value="8+">{t('Due in 8+ days')}</option>
            </select>
            <select
              value={freshness}
              onChange={(e) => setFreshness(e.target.value)}
              className="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-700"
              aria-label={t('Reading age')}
            >
              <option value="any">{t('Reading: any')}</option>
              <option value="fresh">{t('Reading fresh (≤1d)')}</option>
              <option value="stale">{t('Reading stale (>1d)')}</option>
            </select>
            {/* The cards are for deciding; this is for dialling. Same rows, phone numbers on them. */}
            <Button size="sm" variant="secondary" onClick={() => setCallList(true)} disabled={!rows.length}>
              {t('Call list')} ({rows.length})
            </Button>
          </div>
        )}
      >
        {loading && rows.length === 0 ? (
          // Skeletons in the shape of the cards that are coming, so the page doesn't jump when
          // they land — and so an empty board is never mistaken for a slow one.
          <div className="grid gap-4 xl:grid-cols-2" aria-busy="true" aria-label={t('Loading the fleet position…')}>
            {[0, 1, 2, 3].map((i) => (
              <div key={i} className="animate-pulse space-y-4 rounded-2xl border border-slate-200 bg-white p-5">
                <div className="flex justify-between gap-4">
                  <div className="w-1/2 space-y-2">
                    <div className="h-4 w-3/4 rounded bg-slate-200" />
                    <div className="h-3 w-full rounded bg-slate-100" />
                  </div>
                  <div className="h-5 w-24 rounded-full bg-slate-100" />
                </div>
                <div className="h-20 rounded-xl bg-slate-50" />
                <div className="h-2.5 rounded-full bg-slate-100" />
                <div className="h-14 rounded-lg bg-slate-50" />
              </div>
            ))}
          </div>
        ) : rows.length === 0 ? (
          <EmptyState
            title={t('Nothing to decide')}
            message={t('No car currently out on rental is projected to finish past its oil allowance.')}
          />
        ) : (
          <div className="grid gap-4 xl:grid-cols-2">
            {rows.map((r) => (
              <VehicleCard
                key={r.contract_id}
                r={r}
                canRecord={canRecord}
                onReading={setActive}
                onDecide={(row, decision) => setDeciding({ row, decision })}
                onOilChange={setChanging}
                onChanged={() => reload({ silent: true })}
              />
            ))}
          </div>
        )}
      </SectionCard>

      {/* The four lanes, stated once, so nobody has to infer them from badge colours. */}
      <div className="grid gap-2 text-xs text-slate-600 sm:grid-cols-2 xl:grid-cols-4">
        <div className="rounded-lg border border-slate-200 p-2.5">
          <Badge tone="amber">{t('Call customer')}</Badge>
          <div className="mt-1.5">
            {t('Still days to run on a stale number. Phone for the real odometer before anything is decided.')}
          </div>
        </div>
        <div className="rounded-lg border border-slate-200 p-2.5">
          <Badge tone="amber">{t('Service on return')}</Badge>
          <div className="mt-1.5">
            {t('Due back today. Don’t call — read the actual odometer when it arrives, check the oil and service if required.')}
          </div>
        </div>
        <div className="rounded-lg border border-slate-200 p-2.5">
          <Badge tone="red">{t('Action required')}</Badge>
          <div className="mt-1.5">
            {t('Cannot finish inside the allowance on a fresh reading, or a recall is already agreed. Someone must choose: recall now or do it on return.')}
          </div>
        </div>
        <div className="rounded-lg border border-slate-200 p-2.5">
          <Badge tone="green">{t('Safe / scheduled')}</Badge>
          <div className="mt-1.5">
            {t('Finishes inside its allowance. Any oil change is raised automatically as soon as the car is back.')}
          </div>
        </div>
      </div>

      {/* Traceability: the page states the arithmetic behind every number it shows. */}
      {data?.model?.basis && (
        <div className="text-xs text-slate-500">
          <span className="font-semibold">{t('Data origin:')}</span>{' '}
          {t('{basis}. Oil limit comes from the Oil Change sheet anchors on each car; remaining days come from the contract’s own duration; customer-reported readings are stored against the contract and never change the car’s odometer. “Stored” is the odometer the system holds for the car — it was last refreshed at handover, so during a rental it lags the projection by design.', { basis: data.model.basis })}
        </div>
      )}

      {callList && (
        <CallListDialog
          rows={rows}
          laneLabel={t(LANES.find((l) => l.key === filter)?.label || 'All')}
          onClose={() => setCallList(false)}
        />
      )}

      {active && (
        <ReadingDialog
          row={active}
          onClose={() => setActive(null)}
          onSaved={() => reload({ silent: true })}
        />
      )}

      {changing && (
        <OilChangeDialog
          row={changing}
          onClose={() => setChanging(null)}
          onSaved={() => reload({ silent: true })}
        />
      )}

      {deciding && (
        <DecisionDialog
          row={deciding.row}
          decision={deciding.decision}
          onClose={() => setDeciding(null)}
          onDecided={() => reload({ silent: true })}
        />
      )}
    </div>
  );
}
