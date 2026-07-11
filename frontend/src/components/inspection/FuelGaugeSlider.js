import { useMemo } from 'react';
import { snapFuel, fuelFraction } from '../../lib/inspections';

// ── Fuel Gauge Slider ─────────────────────────────────────────────────────────
//
// A visual 0–100% fuel reading with an E…F track that snaps to the eighths a real
// dashboard gauge shows. It feeds the Fuel Audit: the level captured at delivery
// (pre) vs return (post). `compareTo` (the other phase's level) draws a faint
// reference marker + a live delta so the inspector sees the gap as they drag.
//
// Presentational + controlled: the parent owns the value and persists it.

const MARKS = [
  { at: 0, label: 'E' },
  { at: 25, label: '¼' },
  { at: 50, label: '½' },
  { at: 75, label: '¾' },
  { at: 100, label: 'F' },
];

function toneFor(level) {
  if (level <= 12.5) return { bar: 'bg-rose-500', text: 'text-rose-600', ring: 'ring-rose-200' };
  if (level <= 37.5) return { bar: 'bg-amber-500', text: 'text-amber-600', ring: 'ring-amber-200' };
  return { bar: 'bg-emerald-500', text: 'text-emerald-600', ring: 'ring-emerald-200' };
}

export default function FuelGaugeSlider({ value, onChange, label, hint, compareTo = null, active = false }) {
  const isSet = value != null;
  const level = isSet ? snapFuel(value) : 0;
  const tone = useMemo(() => toneFor(level), [level]);

  const comparePct = compareTo != null ? snapFuel(compareTo) : null;
  const delta = comparePct != null && isSet ? Math.round(level - comparePct) : null;

  return (
    <div
      className={`rounded-2xl border p-4 transition ${
        active ? 'border-indigo-300 bg-indigo-50/40 ring-1 ring-indigo-200' : 'border-slate-200 bg-white'
      }`}
    >
      <div className="mb-3 flex items-start justify-between gap-2">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p>
          {hint && <p className="text-[11px] text-slate-400">{hint}</p>}
        </div>
        <div className="text-right">
          {isSet ? (
            <>
              <span className={`text-xl font-extrabold tabular-nums ${tone.text}`}>{Math.round(level)}%</span>
              <span className="ml-1 text-xs font-semibold text-slate-400">{fuelFraction(level)}</span>
            </>
          ) : (
            <span className="text-sm font-medium text-slate-400">Not set</span>
          )}
        </div>
      </div>

      {/* Track with the fill, the optional compare marker, and the eighth ticks. */}
      <div className="relative">
        <div className="relative h-3 overflow-hidden rounded-full bg-slate-100 ring-1 ring-inset ring-slate-200">
          <div
            className={`absolute inset-y-0 left-0 rounded-full transition-all ${isSet ? tone.bar : 'bg-transparent'}`}
            style={{ width: `${level}%` }}
          />
          {/* eighth ticks */}
          {Array.from({ length: 7 }, (_, i) => (
            <span
              key={i}
              className="absolute top-0 h-full w-px bg-white/70"
              style={{ left: `${((i + 1) / 8) * 100}%` }}
            />
          ))}
        </div>

        {/* faint reference marker for the other phase */}
        {comparePct != null && (
          <span
            className="pointer-events-none absolute -top-1 h-5 w-0.5 -translate-x-1/2 rounded bg-slate-400/70"
            style={{ left: `${comparePct}%` }}
            title={`Other reading: ${Math.round(comparePct)}%`}
          />
        )}

        <input
          type="range"
          min={0}
          max={100}
          step={12.5}
          value={isSet ? level : 50}
          onChange={(e) => onChange?.(snapFuel(Number(e.target.value)))}
          aria-label={label}
          className="absolute inset-x-0 -top-1.5 h-6 w-full cursor-pointer appearance-none bg-transparent
                     [&::-webkit-slider-thumb]:h-5 [&::-webkit-slider-thumb]:w-5 [&::-webkit-slider-thumb]:appearance-none
                     [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:border-2 [&::-webkit-slider-thumb]:border-white
                     [&::-webkit-slider-thumb]:bg-slate-700 [&::-webkit-slider-thumb]:shadow
                     [&::-moz-range-thumb]:h-5 [&::-moz-range-thumb]:w-5 [&::-moz-range-thumb]:rounded-full
                     [&::-moz-range-thumb]:border-2 [&::-moz-range-thumb]:border-white [&::-moz-range-thumb]:bg-slate-700"
        />
      </div>

      <div className="mt-2 flex justify-between text-[10px] font-medium text-slate-400">
        {MARKS.map((m) => (
          <span key={m.at}>{m.label}</span>
        ))}
      </div>

      {!isSet && (
        <button
          onClick={() => onChange?.(snapFuel(50))}
          className="mt-2 w-full rounded-lg border border-dashed border-slate-300 py-1.5 text-xs font-medium text-slate-500 transition hover:border-indigo-300 hover:text-indigo-600"
        >
          Tap to set the gauge
        </button>
      )}

      {delta != null && delta !== 0 && (
        <p className={`mt-2 text-xs font-semibold ${delta < 0 ? 'text-rose-600' : 'text-emerald-600'}`}>
          {delta < 0 ? '▼' : '▲'} {Math.abs(delta)}% vs the other reading
        </p>
      )}
    </div>
  );
}
