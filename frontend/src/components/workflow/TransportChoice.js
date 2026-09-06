// HOW THE CAR GETS THERE — a company driver, or a recovery truck for one nobody can drive.
//
// Asked at the moment somebody commits a car to a workshop, because that person has just looked at it
// and the supervisor picking the garage tomorrow has not. Until it was asked here, "can this car
// actually be driven?" was discovered at the pickup — by a driver standing next to a car that would not
// start, whose trip was already wasted.
//
// TWO BUTTONS AND NO DEFAULT, deliberately. Leaving it unsaid is a real answer ("I don't know yet"),
// and pre-selecting "driver" would put words in the mouth of the one person who could have told us the
// car has to be towed. The server stores null the same way, and every screen that reads it shows the
// gap as a question rather than filling it in.
//
// One component, two callers (the review card's "straight to the garage" decision and the Send a Car In
// garage door), because they are the same question about the same car — two copies would be two
// vocabularies for one fact. Values are Maintenance::TRANSPORT_DRIVER / TRANSPORT_RECOVERY.

import { Input } from '../ui/Field';

// The SAME keys the garage-transfer dialog asks with (workflow.task.transport*) — one vocabulary for
// one question about one car. A second set of words for "driver or tow" is how a fleet ends up with two
// answers to whether a car can be driven.
const OPTIONS = [
  { key: 'driver',   icon: '🚗', labelKey: 'workflow.task.transportDriver',   labelEn: 'Company Driver',  hintKey: 'workflow.task.transportDriverHint',   hintEn: 'Vehicle is drivable — a driver collects it.' },
  { key: 'recovery', icon: '🛻', labelKey: 'workflow.task.transportRecovery', labelEn: 'Recovery Truck', hintKey: 'workflow.task.transportRecoveryHint', hintEn: 'Vehicle is not drivable — a towing unit collects it.' },
];

export default function TransportChoice({ value, onChange, disabled, tf, label, unit, onUnit }) {
  const isRecovery = value === 'recovery';

  return (
    <div>
      <p className="mb-1.5 text-xs font-semibold text-slate-600">
        {label || tf('workflow.task.transportMethodQuestion', 'How will the vehicle be transferred?')}
      </p>
      <div className="grid grid-cols-2 gap-2">
        {OPTIONS.map((o) => {
          const active = value === o.key;
          return (
            <button
              key={o.key}
              type="button"
              disabled={disabled}
              aria-pressed={active}
              // Tapping the chosen one again clears it — "I said driver, but actually I'm not sure" has
              // to be expressible, or the first tap becomes irreversible. Choosing DRIVER also clears the
              // towing unit: a car that is being driven has no truck, and leaving one behind would have
              // the ticket claiming both.
              onClick={() => {
                const next = active ? null : o.key;
                onChange(next);
                if (next !== 'recovery' && onUnit) onUnit({ name: '', phone: '' });
              }}
              className={`flex flex-col items-start gap-0.5 rounded-xl border px-3 py-2.5 text-start transition disabled:opacity-50
                ${active
                  ? 'border-amber-500 bg-amber-50 ring-1 ring-amber-500'
                  : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'}`}
            >
              <span className="text-sm font-semibold text-slate-800">{o.icon} {tf(o.labelKey, o.labelEn)}</span>
              <span className="text-[11px] leading-relaxed text-slate-500">{tf(o.hintKey, o.hintEn)}</span>
            </button>
          );
        })}
      </div>

      {/* WHICH TRUCK — the same two fields the Recovery dispatch step asks for, asked here because this
          is where a tow is first known about. Answering now fills that form in rather than asking the
          question twice; leaving it blank is allowed, because "this car has to be towed" is true whether
          or not a truck has been called yet — and the dispatch form still demands the unit at the moment
          the tow actually happens. What must NOT happen is the choice being lost in between. */}
      {isRecovery && onUnit && (
        <div className="mt-2 rounded-xl bg-amber-50/70 p-3 ring-1 ring-inset ring-amber-500/20">
          <p className="text-[11px] leading-relaxed text-amber-900">
            🛻 {tf(
              'workflow.transport.recoveryIntakeHint',
              'This car will be towed, not driven. Log the unit now if it is already arranged — the supervisor’s dispatch screen opens with it filled in. If not, they will be asked for it before the car leaves.',
            )}
          </p>
          <div className="mt-2 grid gap-2 sm:grid-cols-2">
            <Input
              label={tf('workflow.transport.unitName', 'Recovery unit name / ID')}
              value={unit?.name || ''}
              onChange={(e) => onUnit({ name: e.target.value, phone: unit?.phone || '' })}
              placeholder={tf('workflow.transport.unitNamePh', 'e.g. Recovery Truck #05 or Al-Salem Towing Co.')}
              maxLength={191}
              disabled={disabled}
            />
            <Input
              label={tf('workflow.transport.unitPhone', 'Operator mobile (optional)')}
              value={unit?.phone || ''}
              onChange={(e) => onUnit({ name: unit?.name || '', phone: e.target.value })}
              placeholder={tf('workflow.transport.unitPhonePh', 'e.g. 05x xxx xxxx')}
              maxLength={40}
              disabled={disabled}
            />
          </div>
        </div>
      )}
    </div>
  );
}
