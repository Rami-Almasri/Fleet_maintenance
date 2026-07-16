// Minimal canvas signature capture — no new npm dependency. Pointer Events (works for mouse + touch +
// pen) drive the drawing; a "Clear" button resets the canvas. The parent reads the signature via the
// imperative `ref.current.getBlob()` (returns a Promise<Blob|null>, PNG), mirroring the async-blob
// calling convention `compressImage()` already uses elsewhere in the workflow modal.

import { forwardRef, useImperativeHandle, useRef, useState } from 'react';
import { useI18n } from '../../i18n/I18nContext';

const SignaturePad = forwardRef(function SignaturePad({ height = 160, onChange }, ref) {
  const { t } = useI18n();
  const canvasRef = useRef(null);
  const drawing = useRef(false);
  const last = useRef(null);
  const [hasStroke, setHasStroke] = useState(false);

  const ctx = () => canvasRef.current?.getContext('2d');

  // Map a pointer event to canvas-local coordinates, accounting for the CSS-vs-backing-store scale
  // (the canvas backing store is sized to the element's actual pixel box on mount).
  const point = (e) => {
    const canvas = canvasRef.current;
    if (!canvas) return null;
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    return { x: (e.clientX - rect.left) * scaleX, y: (e.clientY - rect.top) * scaleY };
  };

  const onPointerDown = (e) => {
    e.preventDefault();
    canvasRef.current?.setPointerCapture?.(e.pointerId);
    drawing.current = true;
    last.current = point(e);
  };

  const onPointerMove = (e) => {
    if (!drawing.current) return;
    const p = point(e);
    const c = ctx();
    if (!c || !p || !last.current) return;
    c.strokeStyle = '#1e293b';
    c.lineWidth = 2.25;
    c.lineCap = 'round';
    c.lineJoin = 'round';
    c.beginPath();
    c.moveTo(last.current.x, last.current.y);
    c.lineTo(p.x, p.y);
    c.stroke();
    last.current = p;
    if (!hasStroke) { setHasStroke(true); onChange?.(true); }
  };

  const endStroke = () => {
    drawing.current = false;
    last.current = null;
  };

  const clear = () => {
    const canvas = canvasRef.current;
    const c = ctx();
    if (canvas && c) c.clearRect(0, 0, canvas.width, canvas.height);
    setHasStroke(false);
    onChange?.(false);
  };

  useImperativeHandle(ref, () => ({
    // Returns null when nothing was drawn — the caller treats that as "no signature captured".
    getBlob: () =>
      new Promise((resolve) => {
        const canvas = canvasRef.current;
        if (!canvas || !hasStroke) {
          resolve(null);
          return;
        }
        canvas.toBlob((blob) => resolve(blob), 'image/png');
      }),
    clear,
    isEmpty: () => !hasStroke,
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }), [hasStroke]);

  return (
    <div>
      <div className="overflow-hidden rounded-xl border border-slate-300 bg-white">
        <canvas
          ref={canvasRef}
          width={600}
          height={height * 2}
          style={{ width: '100%', height: `${height}px`, touchAction: 'none' }}
          className="block cursor-crosshair"
          onPointerDown={onPointerDown}
          onPointerMove={onPointerMove}
          onPointerUp={endStroke}
          onPointerLeave={endStroke}
          onPointerCancel={endStroke}
        />
      </div>
      <div className="mt-1.5 flex items-center justify-between">
        <p className="text-xs text-slate-400">{t('workflow.signature.hint')}</p>
        <button
          type="button"
          onClick={clear}
          className="rounded-lg px-2 py-1 text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-700"
        >
          {t('common.clear')}
        </button>
      </div>
    </div>
  );
});

export default SignaturePad;
