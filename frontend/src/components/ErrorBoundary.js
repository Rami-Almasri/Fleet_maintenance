import { Component } from 'react';

// Catches render errors in a page so a bug shows a friendly card instead of a blank screen.
// Keyed by route in AppLayout, so navigating elsewhere automatically clears the error.
export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { error: null };
  }

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error, info) {
    // eslint-disable-next-line no-console
    console.error('Page error:', error, info);
  }

  render() {
    if (!this.state.error) return this.props.children;
    return (
      <div className="flex min-h-[60vh] items-center justify-center px-4">
        <div className="w-full max-w-md rounded-2xl border border-slate-200/60 bg-white p-8 text-center shadow-soft">
          <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-red-50 text-red-500">
            <svg className="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h16.9a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" /></svg>
          </div>
          <h2 className="text-lg font-bold text-slate-900">Something went wrong on this page</h2>
          <p className="mt-1 text-sm text-slate-500">The rest of the app is fine — try reloading, or head back to the dashboard.</p>
          <p className="mt-3 break-words rounded-lg bg-slate-50 px-3 py-2 text-left font-mono text-xs text-slate-400">
            {String(this.state.error?.message || this.state.error)}
          </p>
          <div className="mt-5 flex justify-center gap-3">
            <button onClick={() => window.location.reload()} className="rounded-xl bg-gradient-to-b from-indigo-500 to-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition active:scale-95">
              Reload page
            </button>
            <a href="/" className="rounded-xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-50">
              Go to Dashboard
            </a>
          </div>
        </div>
      </div>
    );
  }
}
