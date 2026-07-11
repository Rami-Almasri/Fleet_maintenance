// CountUp — animate a number from 0 → value on mount (and on later value changes),
// applying `format` each frame so KPI tiles "count up" into place on load.
//
//   <CountUp value={s.avg_utilization_pct} format={(n) => `${Math.round(n)}%`} />
//
// `value` may be null/undefined or non-numeric — then `format(value)` is rendered once
// with no animation (so '—' placeholders stay put). Pair with `tabular-nums` on the
// surrounding text so the width doesn't jitter while the digits roll.

import { useEffect, useRef, useState } from 'react';

export default function CountUp({ value, format = (n) => String(n), duration = 900 }) {
  const to = Number(value);
  const animatable = Number.isFinite(to);
  const [display, setDisplay] = useState(0);
  const fromRef = useRef(0);          // start each run from wherever we last settled
  const rafRef = useRef();

  useEffect(() => {
    if (!animatable) return undefined;
    const from = fromRef.current;
    if (to === from) { setDisplay(to); return undefined; }

    let start;
    const ease = (t) => 1 - Math.pow(1 - t, 3);   // easeOutCubic — fast then gently settles
    const step = (ts) => {
      if (start == null) start = ts;
      const p = Math.min(1, (ts - start) / duration);
      setDisplay(from + (to - from) * ease(p));
      if (p < 1) {
        rafRef.current = requestAnimationFrame(step);
      } else {
        fromRef.current = to;
        setDisplay(to);              // land exactly on the target (no rounding drift)
      }
    };
    rafRef.current = requestAnimationFrame(step);
    return () => cancelAnimationFrame(rafRef.current);
  }, [to, animatable, duration]);

  if (!animatable) return <>{format(value)}</>;
  return <>{format(display)}</>;
}
