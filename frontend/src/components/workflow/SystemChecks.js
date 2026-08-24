import React from 'react';
import Icon from '../ui/Icon';
import Badge from '../ui/Badge';

/**
 * SYSTEM CHECKS — the obligations the platform raised on this car, answered in taps.
 *
 * The system said "check the battery". This is where somebody says what they found, and it is
 * deliberately the least typing in the whole report: a result radio, and — only when that result
 * means work — a decision radio. No free text, no description to compose, nothing to remember.
 *
 * ── WHY THIS IS NOT A CHECKLIST ────────────────────────────────────────────────────────────────
 * A checklist item is ticked and forgotten. Each row here is an OBLIGATION with a persistent
 * identity: it was raised on a date for a stated reason, it must be resolved, and its answer is kept
 * forever with the inspector's name on it. The difference matters because of the one thing this
 * screen makes possible for the first time:
 *
 *   "Checked → OK" is a real, recorded answer that creates NOTHING.
 *
 * Before this existed the only way to close a system recommendation was to log a fault, so a clean
 * check either invented a problem or left no trace at all — indistinguishable from nobody looking.
 * The green "no action" note under an OK result is not decoration; it is the screen telling the
 * inspector he is allowed to say nothing is wrong.
 *
 * ── VOCABULARY ─────────────────────────────────────────────────────────────────────────────────
 * Every option comes from the server's catalog (config/vehicle_checks.php), including the Arabic.
 * Nothing here hardcodes a check type, so a Coolant check added tomorrow renders with no change to
 * this file. The one exception is a check whose catalog has no finding keyword of its own: it asks
 * the inspector to name the fault, from the findings catalog, and that is the only picker on screen.
 */

/** Result/decision options carry their own Arabic — catalog DATA, not UI strings. */
const label = (option, lang) => (lang === 'ar' && option.label_ar) || option.label;

/**
 * The question, in the reader's language. Reason CODES with params, composed here rather than sent
 * as English prose, which is what makes the panel work in Arabic ([[reason-code-contract]]).
 * Falls back to the engine's legacy `detail_en` for any code not yet given a sentence.
 */
function reasonSentence(check, t) {
  const key = `checks.reason.${check.reason_code}`;
  const composed = t(key, check.reason_params || {});
  // t() returns the key itself when it has no phrase — that is the signal to fall back rather than
  // print "checks.reason.check.battery_past_life" at an inspector.
  return composed === key ? (check.detail_en || null) : composed;
}

function Radio({ name, checked, onChange, children, tone = 'slate' }) {
  const ring = checked
    ? { emerald: 'border-emerald-400 bg-emerald-50', amber: 'border-amber-400 bg-amber-50', slate: 'border-slate-400 bg-slate-50' }[tone]
    : 'border-slate-200 bg-white hover:border-slate-300';

  return (
    <label className={`inline-flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-medium transition ${ring}`}>
      <input type="radio" name={name} checked={checked} onChange={onChange} className="h-3.5 w-3.5 accent-slate-700" />
      {children}
    </label>
  );
}

/**
 * @param {Array}    checks    the ticket's `required_checks` (server-shaped, with option lists)
 * @param {Object}   value     { [checkId]: { result_code, decision_code, finding_keyword } }
 * @param {Function} onChange  receives the next value object
 * @param {Array}    findingKeywords  flat catalog keywords, for the one case a check cannot pre-fill
 */
export default function SystemChecks({ checks = [], value = {}, onChange, findingKeywords = [], t, lang = 'en' }) {
  const open = checks.filter((c) => c.is_open);

  if (open.length === 0) return null;

  const answered = open.filter((c) => {
    const a = value[c.id];
    if (!a?.result_code) return false;
    const result = c.result_options.find((r) => r.code === a.result_code);
    if (!result) return false;
    // A result that means work is only fully answered once a decision is made — and, where the
    // catalog cannot name the fault, once the inspector has.
    if (!result.creates_action) return true;
    if (!a.decision_code) return false;
    const decision = result.decisions.find((d) => d.code === a.decision_code);
    if (decision?.action === 'open_task' && !result.finding_keyword && !a.finding_keyword) return false;
    return true;
  }).length;

  const set = (id, patch) => onChange({ ...value, [id]: { ...(value[id] || {}), ...patch } });

  return (
    <div className="rounded-xl border border-teal-200 bg-teal-50/60 p-3">
      <div className="mb-2 flex items-center justify-between gap-2">
        <p className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-teal-800">
          <Icon.Shield className="h-3.5 w-3.5" /> {t('checks.title')}
        </p>
        <Badge tone={answered === open.length ? 'emerald' : 'amber'}>
          {t('checks.answeredCount', { done: answered, total: open.length })}
        </Badge>
      </div>

      {/* Says out loud that a clean answer is a complete answer. Inspectors trained on the old screen
          reach for a fault because that used to be the only way to close one of these. */}
      <p className="mb-3 text-xs text-teal-900/75">{t('checks.hint')}</p>

      <ul className="space-y-2">
        {open.map((check) => {
          const answer = value[check.id] || {};
          const result = check.result_options.find((r) => r.code === answer.result_code);
          const decision = result?.decisions?.find((d) => d.code === answer.decision_code);
          const why = reasonSentence(check, t);
          // Only when the catalog cannot name the fault itself — the one case the engine cannot
          // pre-fill the vocabulary and a human has to.
          const needsKeyword = decision?.action === 'open_task' && !result?.finding_keyword;

          return (
            <li key={check.id} className="rounded-lg border border-teal-200/80 bg-white p-3">
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm font-semibold text-slate-800">
                  {(lang === 'ar' && check.label_ar) || check.label}
                </span>
                {check.severity === 'critical' && <Badge tone="red">{t('checks.severity.critical')}</Badge>}
                {check.severity === 'moderate' && <Badge tone="amber">{t('checks.severity.moderate')}</Badge>}
                {answer.result_code && <Badge tone="emerald" dot>{t('checks.checked')}</Badge>}
              </div>

              {/* WHY we are asking. Never a black box — the reason and its measured numbers, plus
                  where the obligation came from, are on the row itself. */}
              {why && <p className="mt-0.5 text-xs text-slate-500">{why}</p>}
              {check.origin && <p className="mt-0.5 text-[11px] italic text-slate-400">{check.origin}</p>}

              {/* RESULT — the only mandatory answer. */}
              <div className="mt-2">
                <span className="mb-1 block text-[11px] font-medium uppercase tracking-wide text-slate-500">
                  {t('checks.resultLabel')}
                </span>
                <div className="flex flex-wrap gap-1.5">
                  {check.result_options.map((option) => (
                    <Radio
                      key={option.code}
                      name={`check-${check.id}-result`}
                      checked={answer.result_code === option.code}
                      tone={option.creates_action ? 'amber' : 'emerald'}
                      // Changing the result invalidates any decision made under the previous one.
                      onChange={() => set(check.id, { result_code: option.code, decision_code: null, finding_keyword: null })}
                    >
                      {label(option, lang)}
                    </Radio>
                  ))}
                </div>
              </div>

              {/* NO ACTION — stated plainly, because it is the outcome the old screen could not express. */}
              {result && !result.creates_action && (
                <p className="mt-2 flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1.5 text-[11px] text-emerald-800 ring-1 ring-inset ring-emerald-200">
                  <Icon.Check className="h-3.5 w-3.5 shrink-0" /> {t('checks.noActionNote')}
                </p>
              )}

              {/* DECISION — revealed only when the result means work. */}
              {result?.creates_action && (
                <div className="mt-2 border-t border-slate-100 pt-2">
                  <span className="mb-1 block text-[11px] font-medium uppercase tracking-wide text-slate-500">
                    {t('checks.decisionLabel')}
                  </span>
                  <div className="flex flex-wrap gap-1.5">
                    {result.decisions.map((option) => (
                      <Radio
                        key={option.code}
                        name={`check-${check.id}-decision`}
                        checked={answer.decision_code === option.code}
                        tone={option.action === 'open_task' ? 'amber' : 'slate'}
                        onChange={() => set(check.id, { decision_code: option.code })}
                      >
                        {label(option, lang)}
                      </Radio>
                    ))}
                  </div>

                  {/* What approving actually does, before he commits to it: this exact item appears
                      in the findings list below and goes to the workshop like any other — AND which
                      KIND of work it becomes. Brake pads due is a SERVICE; brake noise is a FAULT.
                      They route, cost and read differently, so the answer is on screen at the moment
                      of the decision rather than only visible once the task exists. */}
                  {decision?.action === 'open_task' && result.finding_keyword && (
                    <p className="mt-2 flex flex-wrap items-center gap-1.5 text-[11px] text-amber-800">
                      <Icon.Wrench className="h-3.5 w-3.5 shrink-0" />
                      {t('checks.willCreate', { finding: result.finding_keyword })}
                      {result.kind && (
                        <span className={`inline-flex shrink-0 items-center rounded px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide ring-1 ring-inset ${
                          result.kind === 'service'
                            ? 'bg-blue-100 text-blue-700 ring-blue-600/20'
                            : 'bg-red-100 text-red-700 ring-red-600/20'
                        }`}>
                          {t(`checks.kind.${result.kind}`)}
                        </span>
                      )}
                    </p>
                  )}

                  {needsKeyword && (
                    <div className="mt-2">
                      <span className="mb-1 block text-[11px] font-medium text-slate-600">
                        {t('checks.pickFinding')}
                      </span>
                      <select
                        className="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-xs"
                        value={answer.finding_keyword || ''}
                        onChange={(e) => set(check.id, { finding_keyword: e.target.value || null })}
                      >
                        <option value="">{t('checks.pickFindingPlaceholder')}</option>
                        {findingKeywords.map((keyword) => (
                          <option key={keyword} value={keyword}>{keyword}</option>
                        ))}
                      </select>
                    </div>
                  )}
                </div>
              )}
            </li>
          );
        })}
      </ul>
    </div>
  );
}

/**
 * The request payload, and the client half of the server's gate.
 *
 * Returns { rows, unanswered } — `rows` is what POST /report carries, `unanswered` is the labels of
 * the checks still owing an answer, so the inspector is stopped on the screen where he can fix it
 * rather than by a rejected submit. The server enforces the same rule independently; this only makes
 * it readable.
 */
export function buildCheckResults(checks = [], value = {}) {
  const open = checks.filter((c) => c.is_open);
  const rows = [];
  const unanswered = [];

  open.forEach((check) => {
    const answer = value[check.id] || {};
    const result = check.result_options.find((r) => r.code === answer.result_code);

    if (!result) {
      unanswered.push(check.label);
      return;
    }

    if (result.creates_action) {
      const decision = result.decisions.find((d) => d.code === answer.decision_code);
      if (!decision) {
        unanswered.push(check.label);
        return;
      }
      if (decision.action === 'open_task' && !result.finding_keyword && !answer.finding_keyword) {
        unanswered.push(check.label);
        return;
      }
    }

    rows.push({
      id: check.id,
      result_code: result.code,
      decision_code: result.creates_action ? answer.decision_code : null,
      finding_keyword: answer.finding_keyword || null,
    });
  });

  return { rows, unanswered };
}

/**
 * The findings an approved check will add to the report, so the findings step can show them as
 * already accounted for instead of the inspector wondering why a fault he did not tap appeared.
 */
export function checkFindings(checks = [], value = {}) {
  return checks.filter((c) => c.is_open).reduce((out, check) => {
    const answer = value[check.id] || {};
    const result = check.result_options.find((r) => r.code === answer.result_code);
    if (!result?.creates_action) return out;
    const decision = result.decisions.find((d) => d.code === answer.decision_code);
    if (decision?.action !== 'open_task') return out;
    const keyword = result.finding_keyword || answer.finding_keyword;
    return keyword && !out.includes(keyword) ? [...out, keyword] : out;
  }, []);
}
