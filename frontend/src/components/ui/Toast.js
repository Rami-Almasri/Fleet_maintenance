import { createContext, useCallback, useContext, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

const ToastContext = createContext(null);

const TONE = {
  success: { ring: 'ring-emerald-600/20', bar: 'bg-emerald-500', icon: 'M5 13l4 4L19 7' },
  error: { ring: 'ring-red-600/20', bar: 'bg-red-500', icon: 'M6 18L18 6M6 6l12 12' },
  info: { ring: 'ring-blue-600/20', bar: 'bg-blue-500', icon: 'M12 8h.01M11 12h1v4h1' },
};

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([]);
  const idRef = useRef(0);

  const remove = useCallback((id) => setToasts((t) => t.filter((x) => x.id !== id)), []);

  const push = useCallback(
    (message, type = 'success') => {
      const id = ++idRef.current;
      setToasts((t) => [...t, { id, message, type }]);
      setTimeout(() => remove(id), 4000);
    },
    [remove]
  );

  const api = {
    success: (m) => push(m, 'success'),
    error: (m) => push(m, 'error'),
    info: (m) => push(m, 'info'),
  };

  return (
    <ToastContext.Provider value={api}>
      {children}
      {createPortal(
        <div className="fixed bottom-4 right-4 z-[60] flex w-full max-w-sm flex-col gap-2">
          {toasts.map((t) => {
            const tone = TONE[t.type] || TONE.info;
            return (
              <div
                key={t.id}
                className={`flex items-start gap-3 overflow-hidden rounded-xl bg-white p-4 shadow-lg ring-1 ${tone.ring}`}
              >
                <span className={`mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full ${tone.bar} text-white`}>
                  <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                    <path d={tone.icon} />
                  </svg>
                </span>
                <p className="flex-1 text-sm text-gray-700">{t.message}</p>
                <button onClick={() => remove(t.id)} className="text-gray-400 transition hover:text-gray-600">
                  <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
            );
          })}
        </div>,
        document.body
      )}
    </ToastContext.Provider>
  );
}

export function useToast() {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error('useToast must be used within <ToastProvider>');
  return ctx;
}
