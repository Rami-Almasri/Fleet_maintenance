import { useRef, useState, useCallback, useEffect } from 'react';

// Before/After comparison slider — drag the handle to wipe between the pre-rental
// photo and the post-return photo of the same zone, so condition changes (a fresh
// scratch, a new dent) pop out instantly. Supports mouse, touch/pen (Pointer
// Events) and keyboard (focus the handle, use ←/→). The "after" image sits on top
// and is revealed from the left via clip-path, so both images stay pixel-aligned
// at full resolution with no layout reflow while dragging.

export default function BeforeAfterSlider({
  beforeSrc,
  afterSrc,
  beforeLabel = 'Pre-rental',
  afterLabel = 'Post-return',
  className = '',
}) {
  const containerRef = useRef(null);
  const [pos, setPos] = useState(50); // 0–100, the divider's horizontal position
  const [dragging, setDragging] = useState(false);

  const setFromClientX = useCallback((clientX) => {
    const el = containerRef.current;
    if (!el) return;
    const rect = el.getBoundingClientRect();
    const pct = ((clientX - rect.left) / rect.width) * 100;
    setPos(Math.max(0, Math.min(100, pct)));
  }, []);

  // Track the pointer anywhere on the page while dragging, and release on up.
  useEffect(() => {
    if (!dragging) return undefined;
    const move = (e) => setFromClientX(e.clientX);
    const up = () => setDragging(false);
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
    return () => {
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', up);
    };
  }, [dragging, setFromClientX]);

  const onPointerDown = (e) => {
    setDragging(true);
    setFromClientX(e.clientX); // jump to wherever you pressed
  };

  const onKeyDown = (e) => {
    if (e.key === 'ArrowLeft') setPos((p) => Math.max(0, p - 4));
    if (e.key === 'ArrowRight') setPos((p) => Math.min(100, p + 4));
  };

  return (
    <div
      ref={containerRef}
      onPointerDown={onPointerDown}
      className={`relative aspect-[4/3] w-full touch-none select-none overflow-hidden rounded-2xl bg-slate-900 ${
        dragging ? 'cursor-grabbing' : 'cursor-ew-resize'
      } ${className}`}
    >
      {/* base layer: the "after" image, fully shown */}
      <img
        src={afterSrc}
        alt={afterLabel}
        draggable={false}
        className="pointer-events-none absolute inset-0 h-full w-full object-cover"
      />
      {/* top layer: the "before" image, clipped to the left of the divider */}
      <img
        src={beforeSrc}
        alt={beforeLabel}
        draggable={false}
        style={{ clipPath: `inset(0 ${100 - pos}% 0 0)` }}
        className="pointer-events-none absolute inset-0 h-full w-full object-cover"
      />

      {/* corner labels — the active side is emphasised */}
      <span
        className={`pointer-events-none absolute left-3 top-3 rounded-full px-2.5 py-1 text-[11px] font-semibold backdrop-blur transition ${
          pos > 12 ? 'bg-black/55 text-white' : 'bg-black/20 text-white/50'
        }`}
      >
        {beforeLabel}
      </span>
      <span
        className={`pointer-events-none absolute right-3 top-3 rounded-full px-2.5 py-1 text-[11px] font-semibold backdrop-blur transition ${
          pos < 88 ? 'bg-black/55 text-white' : 'bg-black/20 text-white/50'
        }`}
      >
        {afterLabel}
      </span>

      {/* the divider + grab handle */}
      <div
        className="pointer-events-none absolute inset-y-0 w-0.5 bg-white/90 shadow-[0_0_0_1px_rgba(0,0,0,0.15)]"
        style={{ left: `${pos}%`, transform: 'translateX(-50%)' }}
      >
        <button
          type="button"
          role="slider"
          aria-label="Comparison position"
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={Math.round(pos)}
          onKeyDown={onKeyDown}
          onPointerDown={(e) => {
            e.stopPropagation();
            setDragging(true);
          }}
          className="pointer-events-auto absolute left-1/2 top-1/2 flex h-9 w-9 -translate-x-1/2 -translate-y-1/2 cursor-grab items-center justify-center rounded-full bg-white text-slate-700 shadow-lg ring-1 ring-black/10 outline-none transition focus:ring-2 focus:ring-indigo-500 active:cursor-grabbing"
        >
          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M9 7l-4 5 4 5M15 7l4 5-4 5" />
          </svg>
        </button>
      </div>
    </div>
  );
}
