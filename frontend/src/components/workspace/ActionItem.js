// A single attention row inside an Action Center category. A severity dot, a
// primary label (plate / customer), a muted context line, and a chevron. The
// whole row is a deep link to the source record.

import { Link } from 'react-router-dom';
import Icon from '../ui/Icon';

const DOT = {
  red:   'bg-red-500',
  amber: 'bg-amber-500',
  blue:  'bg-blue-500',
  slate: 'bg-slate-400',
};

export default function ActionItem({ to, title, sub, tone = 'slate' }) {
  return (
    <Link
      to={to}
      className="group flex items-center gap-3 rounded-xl px-2.5 py-2 outline-none transition hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-indigo-500/50"
    >
      <span className={`h-2 w-2 shrink-0 rounded-full ${DOT[tone] || DOT.slate}`} />
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-semibold text-slate-800">{title}</span>
        {sub && <span className="block truncate text-xs text-slate-500">{sub}</span>}
      </span>
      <Icon.ArrowRight className="h-4 w-4 shrink-0 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-indigo-500" />
    </Link>
  );
}
