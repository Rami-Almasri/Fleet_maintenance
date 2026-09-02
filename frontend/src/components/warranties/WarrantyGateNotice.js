import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useI18n } from '../../i18n/I18nContext';
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import Icon from '../ui/Icon';
import { Textarea } from '../ui/Field';
import { VERDICT_TONE, reasonText } from '../../lib/warranty';

/**
 * "Warranty Coverage Review Required" — what the user sees instead of a purchase request.
 *
 * ── WHY THIS IS A CARD AND NOT A TOAST ─────────────────────────────────────────────────────────
 *
 * A refusal the user cannot act on is a refusal they learn to route around. The three things
 * somebody needs at this exact moment are: WHY it stopped, WHO to ring, and WHAT they may do about
 * it — so all three are on screen. A red banner saying "blocked by warranty" would produce a phone
 * call to whoever built this, not a phone call to the dealer.
 *
 * ── THE OVERRIDE IS DELIBERATELY AVAILABLE AND DELIBERATELY EXPENSIVE ──────────────────────────
 *
 * There will be a Thursday afternoon when the car has to move and the dealer will not answer. A gate
 * with no escape hatch does not prevent that purchase — it moves it outside the system, where
 * nothing is recorded. So the hatch exists, costs a typed sentence, and puts the user's name on the
 * car's timeline. `can_override` comes from the SERVER: the button is never offered to somebody the
 * API will then refuse, because an offer that fails is worse than no offer.
 *
 * @param {object} gate     the 422 `data` payload from WarrantyProcurementGuard
 * @param {func}   onRetry  called with { warranty_override_reason } to re-submit the original request
 * @param {func}   onCancel dismiss
 */
export default function WarrantyGateNotice({ gate, onRetry, onCancel, busy = false }) {
  const { t } = useI18n();
  const [reason, setReason] = useState('');
  const [showOverride, setShowOverride] = useState(false);
  const [touched, setTouched] = useState(false);

  if (!gate) return null;

  const covered = gate.verdict === 'covered';
  // Mirrors the server's own bar (WarrantyProcurementGuard). Checked here only so the user is told
  // before they type, never as the enforcement — the API refuses regardless.
  const tooShort = reason.trim().length < 10;

  return (
    <div className="rounded-lg border border-amber-300 bg-amber-50/60 p-4">
      <div className="flex items-start gap-3">
        <Icon.Shield className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h4 className="text-sm font-semibold text-amber-900">
              {covered ? t('warrantyOps.gate.titleCovered') : t('warrantyOps.gate.titleUnknown')}
            </h4>
            <Badge tone={VERDICT_TONE[gate.verdict] || 'amber'} dot>
              {t(`warrantyOps.verdict.${gate.verdict}`)}
            </Badge>
          </div>

          <p className="mt-1 text-sm text-amber-900/90">
            {covered ? t('warrantyOps.gate.bodyCovered') : t('warrantyOps.gate.bodyUnknown')}
          </p>

          {/* WHY — composed from the reason code, so it reads in both languages. */}
          {reasonText(gate, t) && (
            <p className="mt-1 text-xs text-amber-800">{reasonText(gate, t)}</p>
          )}

          {/* WHO TO RING. The single most useful thing on this card, and the reason the contact
              details are columns on the warranty rather than a note somebody has to go and find. */}
          {gate.candidates?.length > 0 && (
            <div className="mt-3 rounded-md border border-amber-200 bg-white/70 p-3">
              <div className="text-xs uppercase tracking-wide text-amber-700">{t('warrantyOps.gate.coverLabel')}</div>
              <ul className="mt-1.5 space-y-1.5">
                {gate.candidates.map((w) => (
                  <li key={w.id} className="text-xs text-slate-700">
                    <span className="font-medium">{w.subject}</span>
                    {w.provider_name ? ` · ${w.provider_name}` : ''}
                    {w.expires_on ? ` · ${t('warrantyOps.gate.expires')} ${w.expires_on}` : ''}
                    {w.reference_no ? ` · ${t('warrantyOps.gate.reference')} ${w.reference_no}` : ''}
                    {w.contact_phone && (
                      <span className="ml-1 text-slate-500">
                        · {t('warrantyOps.gate.contact')} {w.contact_name ? `${w.contact_name} ` : ''}{w.contact_phone}
                      </span>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          )}

          <div className="mt-3 flex flex-wrap items-center gap-3">
            {gate.case_id && (
              <Link to={`/warranty/cases/${gate.case_id}`} className="text-xs font-medium text-indigo-600 hover:underline">
                {t('warrantyOps.gate.openCase')}
              </Link>
            )}

            {/* Server-decided. Never render a button the API will refuse. */}
            {gate.can_override && !showOverride && (
              <button type="button" onClick={() => setShowOverride(true)} className="text-xs font-medium text-amber-800 underline">
                {t('warrantyOps.gate.overrideToggle')}
              </button>
            )}

            {!gate.can_override && (
              <span className="text-xs text-slate-500">{t('warrantyOps.gate.overrideNoPermission')}</span>
            )}

            {onCancel && (
              <button type="button" onClick={onCancel} className="ml-auto text-xs text-slate-500 hover:underline">
                {t('warrantyOps.gate.cancel')}
              </button>
            )}
          </div>

          {showOverride && (
            <div className="mt-3 border-t border-amber-200 pt-3">
              <div className="text-sm font-semibold text-amber-900">{t('warrantyOps.gate.overrideTitle')}</div>
              {/* The hint is the deterrent. It is not a warning about the system, it is a statement
                  of what will be permanently true about this purchase. */}
              <p className="mt-0.5 text-xs text-amber-800">{t('warrantyOps.gate.overrideHint')}</p>

              <Textarea
                label={t('warrantyOps.gate.overrideReason')}
                value={reason}
                rows={3}
                placeholder={t('warrantyOps.gate.overridePlaceholder')}
                onChange={(e) => setReason(e.target.value)}
                onBlur={() => setTouched(true)}
                error={touched && tooShort ? t('warrantyOps.gate.overrideTooShort') : undefined}
              />

              <div className="mt-2">
                <Button
                  variant="danger"
                  disabled={tooShort || busy}
                  onClick={() => onRetry?.({ warranty_override_reason: reason.trim() })}
                >
                  {t('warrantyOps.gate.proceed')}
                </Button>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
