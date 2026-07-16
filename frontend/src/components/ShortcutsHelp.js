// A polished keyboard-shortcuts cheat-sheet. Opens on "?" (handled in AppLayout)
// and lists the app's global shortcuts. Closes on Escape or backdrop click.

const GROUPS = [
  {
    title: 'Navigation',
    items: [
      { keys: ['⌘', 'K'], label: 'Open command palette' },
      { keys: ['G', 'D'], label: 'Go to Dashboard' },
      { keys: ['G', 'V'], label: 'Go to Vehicles' },
      { keys: ['G', 'C'], label: 'Go to Contracts' },
      { keys: ['G', 'M'], label: 'Go to Maintenance' },
    ],
  },
  {
    title: 'General',
    items: [
      { keys: ['?'], label: 'Show this help' },
      { keys: ['/'], label: 'Focus search (where available)' },
      { keys: ['Esc'], label: 'Close any overlay' },
      { keys: ['T'], label: 'Back to top of page' },
    ],
  },
];

function Key({ children }) {
  return (
    <kbd className="inline-flex min-w-[1.6rem] items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-1.5 py-1 text-[11px] font-semibold text-slate-600 shadow-sm">
      {children}
    </kbd>
  );
}

export default function ShortcutsHelp({ open, onClose }) {
  if (!open) return null;
  return (
    <div
      className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-900/40 px-4 backdrop-blur-sm"
      onClick={onClose}
    >
      <div
        className="w-full max-w-xl animate-fade-in-up overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-900/10"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between border-b border-slate-100 px-6 py-4">
          <div className="flex items-center gap-3">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-sm">
              <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8"><path strokeLinecap="round" strokeLinejoin="round" d="M7 8h10M7 12h6m-6 4h10M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z" /></svg>
            </span>
            <div>
              <h2 className="text-base font-bold text-slate-900">Keyboard Shortcuts</h2>
              <p className="text-xs text-slate-500">Move faster across the app</p>
            </div>
          </div>
          <button onClick={onClose} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600" aria-label="Close">
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
          </button>
        </div>

        <div className="grid gap-x-10 gap-y-6 px-6 py-6 sm:grid-cols-2">
          {GROUPS.map((g) => (
            <div key={g.title}>
              <p className="mb-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-400">{g.title}</p>
              <ul className="space-y-2.5">
                {g.items.map((it) => (
                  <li key={it.label} className="flex items-center justify-between gap-4">
                    <span className="text-sm text-slate-600">{it.label}</span>
                    <span className="flex shrink-0 items-center gap-1">
                      {it.keys.map((k, i) => (
                        <Key key={i}>{k}</Key>
                      ))}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>

        <div className="border-t border-slate-100 bg-slate-50/60 px-6 py-3 text-center text-xs text-slate-400">
          Press <Key>?</Key> any time to reopen this panel
        </div>
      </div>
    </div>
  );
}
