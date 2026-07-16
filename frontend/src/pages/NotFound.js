import { Link } from 'react-router-dom';

export default function NotFound() {
  return (
    <div className="flex min-h-[70vh] items-center justify-center px-4">
      <div className="text-center">
        <p className="font-display text-7xl font-black tracking-tight text-slate-300">404</p>
        <h1 className="mt-2 font-display text-xl font-bold tracking-tight text-slate-900">Page not found</h1>
        <p className="mt-1 text-sm text-slate-500">The page you’re looking for doesn’t exist or has moved.</p>
        <Link
          to="/"
          className="focus-ring-self mt-6 inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 active:translate-y-px"
        >
          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M3 12l9-9 9 9M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10" /></svg>
          Back to Dashboard
        </Link>
      </div>
    </div>
  );
}
