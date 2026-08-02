// DECISION CARDS — the intelligence platform's only user-facing surface.
//
// One card answers four questions in a fixed order: what happened (observation), what to do
// (recommendation), why we believe it (evidence), why that is right (reasoning). Anything that
// cannot fill all four is not a card, and the backend will not have sent it.
//
// THE DESIGN RULE THAT DRIVES THE LAYOUT: the supervisor is mid-decision, not reading a report. So
// the observation and the recommendation are always visible, and the reasoning — the paragraph of
// statistics that makes the case — sits behind "Why this?". Leading with the numbers would make the
// card feel like a dashboard interrupting the workflow, which is exactly what it must not be.
//
// The caveat is the exception: when the evidence is a proxy it is shown WITHOUT being opened,
// always, because a warning that overstates its own certainty is how a platform loses a user
// permanently on the first case where it turns out to be wrong.
//
// Every render is recorded server-side, and every answer (accept / override + reason / dismiss)
// closes the learning loop. The override REASON is the single most valuable field here: it is the
// only place the platform learns why it was wrong rather than merely that it was.
//
// Data: GET  /maintenance-tickets/{id}/decision-cards
//       POST /maintenance-tickets/{id}/decision-cards/{recommendation}/respond
// Renders nothing when there are no cards — which is most of the time, by design.

import { useCallback, useEffect, useState } from 'react';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Button from '../ui/Button';
import Icon from '../ui/Icon';

// Confidence → the visual weight the card is allowed to carry. It must never look more certain
// than the evidence behind it, so the tone is derived, never chosen per card.
const TONE = {
  strong:   { ring: 'ring-rose-600/20',   bg: 'bg-rose-50/70',   text: 'text-rose-800',   chip: 'bg-rose-100 text-rose-700' },
  moderate: { ring: 'ring-amber-600/20',  bg: 'bg-amber-50/70',  text: 'text-amber-900',  chip: 'bg-amber-100 text-amber-800' },
  limited:  { ring: 'ring-slate-400/20',  bg: 'bg-slate-50',     text: 'text-slate-700',  chip: 'bg-slate-200 text-slate-700' },
};

export default function DecisionCards({ ticketId, onAnswered }) {
  const { t, tf } = useI18n();
  const [cards, setCards] = useState([]);

  const load = useCallback(() => {
    if (!ticketId) { setCards([]); return undefined; }
    let alive = true;
    api
      .get(`/maintenance-tickets/${ticketId}/decision-cards`)
      .then((r) => { if (alive) setCards(Array.isArray(r.data?.data) ? r.data.data : []); })
      // Intelligence is advisory: if it cannot load, the workflow carries on without it.
      .catch(() => { if (alive) setCards([]); });
    return () => { alive = false; };
  }, [ticketId]);

  useEffect(() => load(), [load]);

  if (!cards.length) return null;

  return (
    <div className="space-y-2">
      {cards.map((card) => (
        <DecisionCard
          key={card.recommendation_id ?? card.id}
          card={card}
          ticketId={ticketId}
          t={t}
          tf={tf}
          onAnswered={onAnswered}
        />
      ))}
    </div>
  );
}

function DecisionCard({ card, ticketId, t, tf, onAnswered }) {
  const [showWhy, setShowWhy] = useState(false);
  const [overriding, setOverriding] = useState(false);
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);
  const [answered, setAnswered] = useState(card.answered || null);
  const [failed, setFailed] = useState(false);

  const tone = TONE[card.confidence] ?? TONE.limited;
  const ev = card.evidence ?? {};

  const respond = async (response, why = null) => {
    setSaving(true);
    setFailed(false);
    try {
      await api.post(`/maintenance-tickets/${ticketId}/decision-cards/${card.recommendation_id}/respond`, {
        response,
        reason: why,
      });
      setAnswered(response);
      setOverriding(false);
      onAnswered?.(response, card);
    } catch {
      // The answer is the product of the loop — if it did not save, say so rather than pretending.
      setFailed(true);
    } finally {
      setSaving(false);
    }
  };

  // Once answered the card collapses to a one-line receipt. It does not vanish: the supervisor
  // should be able to see what they decided and why, without the card competing for attention again.
  if (answered) {
    return (
      <div className="flex items-center gap-2 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-500 ring-1 ring-inset ring-slate-200">
        <Icon.Check className="h-3.5 w-3.5 shrink-0 text-slate-400" />
        <span>{tf(`workflow.decisionCard.answered.${answered}`, ANSWERED_FALLBACK[answered] ?? 'Recorded')}</span>
      </div>
    );
  }

  return (
    <div className={`rounded-xl p-3 ring-1 ring-inset ${tone.bg} ${tone.ring}`}>
      {/* Header — the strength verb comes from confidence, so the card cannot oversell itself. */}
      <div className="flex flex-wrap items-center gap-2">
        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ${tone.chip}`}>
          <Icon.Alert className="h-3 w-3" />
          {tf(`workflow.decisionCard.strength.${card.strength}`, STRENGTH_FALLBACK[card.strength] ?? card.strength)}
        </span>
        <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">
          {tf(`workflow.decisionCard.confidence.${card.confidence}`, CONFIDENCE_FALLBACK[card.confidence] ?? card.confidence)}
        </span>
      </div>

      {/* Observation — what happened. Plain sentence, no statistics. */}
      <p className={`mt-2 text-sm font-semibold ${tone.text}`}>{card.observation}</p>

      {/* Recommendation — what to do about it. */}
      <p className="mt-1 text-sm text-slate-700">{card.recommendation}</p>

      {/* THE CAVEAT IS NEVER HIDDEN. A proxy metric that presents as a measurement is how trust is
          lost on the first case it gets wrong. */}
      {ev.is_proxy && ev.proxy_note && (
        <p className="mt-2 flex items-start gap-1.5 text-[11px] italic text-slate-500">
          <Icon.Info className="mt-px h-3 w-3 shrink-0" />
          {tf('workflow.decisionCard.proxy', 'Measured as {note}.', { note: ev.proxy_note })}
        </p>
      )}

      {/* Reasoning behind a disclosure — available on demand, never in the way. */}
      <button
        type="button"
        onClick={() => setShowWhy((v) => !v)}
        className="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-slate-500 underline-offset-2 hover:underline"
      >
        {showWhy
          ? tf('workflow.decisionCard.hideWhy', 'Hide the evidence')
          : tf('workflow.decisionCard.why', 'Why this?')}
      </button>

      {showWhy && (
        <div className="mt-2 space-y-1.5 rounded-lg bg-white/70 p-2.5 text-xs text-slate-600 ring-1 ring-inset ring-slate-200/70">
          <p>{card.reasoning}</p>
          <p className="flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-slate-400">
            {ev.sample_size != null && (
              <span>
                {card.show_cases_not_statistics
                  // Below the statistics floor the honest thing is to show the cases, not a rate
                  // computed from four of them.
                  ? tf('workflow.decisionCard.tooThin', 'Only {n} comparable cases — treat as anecdote, not a rate.', { n: ev.sample_size })
                  : tf('workflow.decisionCard.basedOn', 'Based on {n} historical cases', { n: ev.sample_size })}
              </span>
            )}
            {Array.isArray(ev.source_ids) && ev.source_ids.length > 0 && (
              <span>{tf('workflow.decisionCard.priorCases', 'Prior tickets: {ids}', { ids: ev.source_ids.slice(0, 5).join(', ') })}</span>
            )}
          </p>
        </div>
      )}

      {/* Override reason — asked at the moment of overriding, when the reason is actually known. */}
      {overriding ? (
        <div className="mt-3 space-y-2">
          <label className="block text-xs font-semibold text-slate-600" htmlFor={`why-${card.recommendation_id}`}>
            {tf('workflow.decisionCard.reasonPrompt', 'What makes this case different?')}
          </label>
          <textarea
            id={`why-${card.recommendation_id}`}
            rows={2}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder={tf('workflow.decisionCard.reasonPh', 'e.g. the garage already re-diagnosed it on the last visit')}
            className="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs outline-none focus:border-slate-400"
          />
          <div className="flex flex-wrap gap-2">
            <Button size="sm" disabled={!reason.trim() || saving} onClick={() => respond('overridden', reason.trim())}>
              {tf('workflow.decisionCard.submitOverride', 'Record and continue')}
            </Button>
            <Button size="sm" variant="ghost" disabled={saving} onClick={() => { setOverriding(false); setReason(''); }}>
              {t('common.cancel')}
            </Button>
          </div>
        </div>
      ) : (
        <div className="mt-3 flex flex-wrap gap-2">
          {(card.actions ?? []).map((a) => (
            <Button
              key={a.effect}
              size="sm"
              variant={a.effect === 'override' ? 'ghost' : 'primary'}
              disabled={saving}
              onClick={() => (a.requires_reason ? setOverriding(true) : respond('accepted'))}
            >
              {tf(`workflow.decisionCard.action.${a.effect}`, a.label)}
            </Button>
          ))}
          <Button size="sm" variant="ghost" disabled={saving} onClick={() => respond('dismissed')}>
            {tf('workflow.decisionCard.dismiss', 'Not relevant')}
          </Button>
        </div>
      )}

      {failed && (
        <p className="mt-2 text-xs font-semibold text-rose-600">
          {tf('workflow.decisionCard.failed', 'Your response was not saved — please try again.')}
        </p>
      )}
    </div>
  );
}

// English fallbacks so the component is never worse than untranslated; the Arabic lands in labels.js.
const STRENGTH_FALLBACK   = { must: 'Must', should: 'Should', consider: 'Consider' };
const CONFIDENCE_FALLBACK = { strong: 'Strong evidence', moderate: 'Moderate evidence', limited: 'Limited evidence' };
const ANSWERED_FALLBACK   = {
  accepted:  'You accepted this recommendation.',
  overridden: 'You overrode this recommendation — your reason was recorded.',
  dismissed: 'Dismissed as not relevant.',
};
