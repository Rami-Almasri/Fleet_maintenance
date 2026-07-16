// A small, dependency-free icon set (24×24 stroke icons) matching the app's
// existing hand-rolled SVGs. Use via the `Icon` namespace:
//
//   import Icon from '../components/ui/Icon';
//   <Icon.Cash className="h-5 w-5" />
//
// Every icon forwards className/props, defaults to 1.7 stroke + currentColor, so
// it inherits text color (great inside MetricCard icon bubbles and buttons).

function base(paths, { fill = false } = {}) {
  return function IconCmp({ className = 'h-5 w-5', strokeWidth = 1.7, ...props }) {
    return (
      <svg
        className={className}
        viewBox="0 0 24 24"
        fill={fill ? 'currentColor' : 'none'}
        stroke={fill ? 'none' : 'currentColor'}
        strokeWidth={strokeWidth}
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
        {...props}
      >
        {paths}
      </svg>
    );
  };
}

const Icon = {
  // Finance
  Cash: base(<><rect x="2" y="6" width="20" height="12" rx="2" /><circle cx="12" cy="12" r="2.5" /><path d="M6 12h.01M18 12h.01" /></>),
  Coins: base(<><ellipse cx="9" cy="7" rx="6" ry="3" /><path d="M3 7v5c0 1.7 2.7 3 6 3s6-1.3 6-3" /><path d="M9 12v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5" /><ellipse cx="15" cy="12" rx="6" ry="3" /></>),
  Invoice: base(<><path d="M6 2h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z" /><path d="M14 2v6h6M9 13h6M9 17h6M9 9h2" /></>),
  Card: base(<><rect x="2" y="5" width="20" height="14" rx="2" /><path d="M2 10h20M6 15h4" /></>),
  Scale: base(<><path d="M12 3v18M7 21h10M5 7h14M5 7l-3 6h6zM19 7l-3 6h6z" /></>),
  TrendUp: base(<><path d="M3 17l6-6 4 4 8-8" /><path d="M14 7h7v7" /></>),
  TrendDown: base(<><path d="M3 7l6 6 4-4 8 8" /><path d="M14 17h7v-7" /></>),
  Chart: base(<><path d="M3 3v18h18" /><path d="M7 14v3M12 9v8M17 5v12" /></>),
  Percent: base(<><path d="M19 5L5 19" /><circle cx="7" cy="7" r="2.2" /><circle cx="17" cy="17" r="2.2" /></>),

  // Fleet / ops
  Car: base(<><path d="M5 13l1.5-4.5A2 2 0 0 1 8.4 7h7.2a2 2 0 0 1 1.9 1.5L19 13" /><path d="M3 13h18v4a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1v-1H6v1a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z" /><path d="M6.5 16h.01M17.5 16h.01" /></>),
  Wrench: base(<><path d="M14.7 6.3a4 4 0 0 0-5.3 5l-6 6 2.3 2.3 6-6a4 4 0 0 0 5-5.3l-2.4 2.4-2-2z" /></>),
  Truck: base(<><path d="M3 6a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v9H3z" /><path d="M14 9h3.5L21 12.5V15h-7z" /><circle cx="7" cy="17.5" r="1.5" /><circle cx="17.5" cy="17.5" r="1.5" /></>),
  Gauge: base(<><path d="M12 14l4-4" /><path d="M3.5 18a9 9 0 1 1 17 0" /><circle cx="12" cy="14" r="1.5" /></>),
  Activity: base(<><path d="M3 12h4l3 8 4-16 3 8h4" /></>),
  Shield: base(<><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z" /><path d="M9 12l2 2 4-4" /></>),
  Clock: base(<><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>),
  Calendar: base(<><rect x="3" y="4" width="18" height="17" rx="2" /><path d="M3 9h18M8 2v4M16 2v4" /></>),
  Users: base(<><circle cx="9" cy="8" r="3.2" /><path d="M3 20a6 6 0 0 1 12 0" /><path d="M16 5.5a3 3 0 0 1 0 5.8M21 20a6 6 0 0 0-4-5.6" /></>),
  Route: base(<><circle cx="6" cy="19" r="2.5" /><circle cx="18" cy="5" r="2.5" /><path d="M8.5 19H15a3.5 3.5 0 0 0 0-7H9a3.5 3.5 0 0 1 0-7h6.5" /></>),

  // Status / semantic
  Check: base(<><circle cx="12" cy="12" r="9" /><path d="M8.5 12.5l2.5 2.5 4.5-5" /></>),
  Alert: base(<><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" /><path d="M12 9v4M12 17h.01" /></>),
  XCircle: base(<><circle cx="12" cy="12" r="9" /><path d="M15 9l-6 6M9 9l6 6" /></>),
  Info: base(<><circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8h.01" /></>),
  Spark: base(<><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z" /><path d="M19 16l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7z" /></>),
  Flag: base(<><path d="M5 21V4M5 4h11l-1.5 4L16 12H5" /></>),

  // Generic
  Refresh: base(<><path d="M4 4v6h6M20 20v-6h-6" /><path d="M20 9A8 8 0 0 0 6.3 5.3L4 8M4 15a8 8 0 0 0 13.7 2.7L20 16" /></>),
  Search: base(<><circle cx="11" cy="11" r="7" /><path d="M21 21l-4.3-4.3" /></>),
  Filter: base(<><path d="M3 5h18l-7 8v6l-4-2v-4z" /></>),
  Download: base(<><path d="M12 3v12M7 11l5 5 5-5" /><path d="M5 21h14" /></>),
  Plus: base(<><path d="M12 5v14M5 12h14" /></>),
  ArrowRight: base(<><path d="M5 12h14M13 6l6 6-6 6" /></>),
  ChevronDown: base(<><path d="M6 9l6 6 6-6" /></>),
  Video: base(<><rect x="2" y="6" width="14" height="12" rx="2" /><path d="M16 10l6-3v10l-6-3z" /></>),
  Camera: base(<><path d="M4 8a2 2 0 0 1 2-2h1.5l1-1.5h5l1 1.5H18a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z" /><circle cx="12" cy="13" r="3.2" /></>),
  X: base(<><path d="M6 6l12 12M18 6L6 18" /></>),
};

export default Icon;
