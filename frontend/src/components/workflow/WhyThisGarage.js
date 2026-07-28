// "Why this garage?" — the durable explanation of a garage-choice decision, shown in the Maintenance
// Ticket History (TicketDetailDrawer) and on the Vehicle Timeline. Answers, months later, why a car was
// sent where it was: what the data-driven engine recommended, whether it was accepted, the reasons, and
// how confident the engine was.
//
// Works from either data source (both carry the same fields): the ticket resource's `garage_recommendation`
// or a garage_assigned event's `meta.recommendation`. Pass `t` for i18n (ticket drawer); omit it to fall
// back to English (the vehicle timeline is English-only).

import Icon from '../ui/Icon';

const CONF = {
  high: 'bg-emerald-100 text-emerald-800 ring-emerald-600/20',
  medium: 'bg-amber-100 text-amber-800 ring-amber-600/20',
  low: 'bg-slate-100 text-slate-600 ring-slate-500/20',
};
const EN = {
  title: 'Why this garage?', recommended: 'Recommended', assigned: 'Assigned', accepted: 'Accepted',
  yes: 'Yes', no: 'No', overridden: 'Manual override', reason: 'Reason', confidence: 'Confidence',
  conf: { high: 'High', medium: 'Medium', low: 'Low' },
};

export default function WhyThisGarage({ rec, t, className = '' }) {
  if (!rec) return null;
  const L = (key) => (t ? t(`workflow.garageRec.why.${key}`) : EN[key]);
  const confLabel = rec.confidence ? (t ? t(`workflow.garageRec.confidence.${rec.confidence}`) : (EN.conf[rec.confidence] || rec.confidence)) : null;

  const reasons = (rec.reasons && rec.reasons.length)
    ? rec.reasons
    : (rec.reason ? String(rec.reason).split(';').map((s) => ({ t: s.trim() })).filter((r) => r.t) : []);
  const recommended = rec.recommended_garage;
  const chosen = rec.chosen_garage;
  const overrode = rec.followed === false || (rec.accepted === false && recommended);
  const showAssigned = chosen && recommended && chosen !== recommended;

  return (
    <div className={`rounded-xl bg-slate-50 p-3.5 ring-1 ring-inset ring-slate-200/70 ${className}`}>
      <p className="mb-2 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-600">
        <Icon.Wrench className="h-3.5 w-3.5 text-indigo-600" /> {L('title')}
      </p>

      <div className="space-y-1.5 text-sm">
        {recommended && (
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-slate-500">{L('recommended')}:</span>
            <span className="font-semibold text-slate-800">{recommended}</span>
            {overrode ? (
              <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 ring-1 ring-inset ring-amber-600/20">
                {L('overridden')}
              </span>
            ) : (
              <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-800 ring-1 ring-inset ring-emerald-600/20">
                {L('accepted')}: {L('yes')}
              </span>
            )}
          </div>
        )}
        {showAssigned && (
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-slate-500">{L('assigned')}:</span>
            <span className="font-semibold text-slate-800">{chosen}</span>
          </div>
        )}
      </div>

      {reasons.length > 0 && (
        <div className="mt-2.5">
          <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{L('reason')}</p>
          <ul className="space-y-1">
            {reasons.map((r, i) => (
              <li key={i} className="flex items-start gap-1.5 text-[13px] text-slate-700">
                <Icon.Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" />
                <span>{r.t}{r.s ? <span className="text-slate-400"> · {r.s}</span> : null}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      {confLabel && (
        <div className="mt-2.5 flex items-center gap-2">
          <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{L('confidence')}:</span>
          <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${CONF[rec.confidence] || CONF.low}`}>
            {confLabel}
          </span>
        </div>
      )}
    </div>
  );
}
