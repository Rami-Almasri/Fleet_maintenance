import { useState, useEffect, useRef } from 'react';
import { NavLink, Outlet, useNavigate, useLocation } from 'react-router-dom';
import api from '../api/client';
import { useAuth } from '../auth/AuthContext';
import { usePermissions } from '../hooks/usePermissions';
import useActivityTracker from '../hooks/useActivityTracker';
import CommandPalette from '../components/CommandPalette';
import ShortcutsHelp from '../components/ShortcutsHelp';
import ErrorBoundary from '../components/ErrorBoundary';
import NotificationBell from '../components/NotificationBell';
import ThemeToggle from '../components/ThemeToggle';
import LanguageToggle from '../components/LanguageToggle';
import Brand from '../components/Brand';
import { useNotifications } from '../hooks/useNotifications';
import { PageStatProvider, PageStatGauge } from '../components/PageStat';
import { SHOW_FINANCIALS, DEMO_MODE } from '../config/features';
import { pathBlockedForRoles } from '../config/access';

// Thin gradient bar at the very top that fills as you scroll the page.
function ScrollProgress() {
  const [pct, setPct] = useState(0);
  useEffect(() => {
    const onScroll = () => {
      const h = document.documentElement;
      const max = h.scrollHeight - h.clientHeight;
      setPct(max > 0 ? (h.scrollTop / max) * 100 : 0);
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    return () => {
      window.removeEventListener('scroll', onScroll);
      window.removeEventListener('resize', onScroll);
    };
  }, []);
  return <div className="scroll-progress" style={{ width: `${pct}%`, opacity: pct > 0.5 ? 1 : 0 }} />;
}

const greet = (h) => (h < 5 ? 'Good night' : h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : h < 22 ? 'Good evening' : 'Good night');

// Live clock + time-of-day greeting chip for the header.
function LiveClock({ name }) {
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1000 * 30);
    return () => clearInterval(id);
  }, []);
  const time = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  const date = now.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' });
  const first = (name || '').trim().split(/\s+/)[0];
  return (
    <div className="hidden items-center gap-3 rounded-xl border border-slate-200/70 bg-white/60 px-3 py-1.5 md:flex">
      <div className="leading-tight">
        <p className="text-[11px] font-semibold text-slate-700">{greet(now.getHours())}{first ? `, ${first}` : ''}</p>
        <p className="text-[10px] font-medium text-slate-400">{date}</p>
      </div>
      <div className="h-7 w-px bg-slate-200" />
      <p className="font-mono text-sm font-semibold tabular-nums text-slate-600">{time}</p>
    </div>
  );
}

// Navigation grouped into role-based hubs for a calmer, scannable sidebar.
// Three primary work-hubs mirror the three people who use the app:
//   • Operations  — the "Fleet Manager": live rentals, movements, check-in/out.
//   • Maintenance — the "Shop Foreman": the whole repair pipeline + service.
//   • Analytics & Admin — the analyst/admin: finance, insights, data quality, sync.
// Overview / Records / Settings are thin utility rails around those hubs.
// `desc` powers the per-page info circle (bottom-right "?" button).
const NAV_SECTIONS = [
  {
    title: 'Overview',
    items: [
      { name: 'Dashboard', to: '/', icon: 'M3 12l9-9 9 9M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10', desc: 'Fleet-wide overview: how many cars are available, rented, or in maintenance, plus key totals. Availability and composition come from the OfficeManager lifecycle status.' },
      { name: 'Notifications', to: '/notifications', icon: 'M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 0 0-4-5.7V5a2 2 0 1 0-4 0v.3A6 6 0 0 0 6 11v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0v1a3 3 0 1 1-6 0v-1m6 0H9', desc: 'Live fleet alerts — overdue rentals, maintenance overruns, expiring documents, service-due cars and approvals. The bell in the top bar updates in real time.' },
    ],
  },
  {
    title: 'Records',
    items: [
      { name: 'Vehicles', to: '/vehicles', icon: 'M5 17h14M5 17a2 2 0 0 1-2-2v-3l2-5a2 2 0 0 1 2-1.4h8A2 2 0 0 1 19 7l2 5v3a2 2 0 0 1-2 2M7 17v1a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-1m14 0v1a1 1 0 0 1-1 1h0a1 1 0 0 1-1-1v-1M7 12h10', desc: 'Every car in the fleet. The OfficeManager API is the sole source of which cars exist; the sheet only enriches matched cars. Click a row to open its full profile.' },
      { name: 'Odometer Approvals', to: '/odometer-approvals', icon: 'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', desc: 'Review queue for significant manual odometer edits (more than 10 km from the car\'s current reading) — each carries the editor\'s reason note and the car\'s workflow stage at the time. Approve to apply the new reading, or reject to leave it untouched.' },
      { name: 'Customers', to: '/customers', icon: 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM3 21v-1a6 6 0 0 1 6-6h6a6 6 0 0 1 6 6v1', desc: 'All customers with their contact details and available wallet (carried-forward credit). Open a customer to see their contracts and balance history.' },
      { name: 'Contracts', to: '/contracts', icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2z', desc: 'Rental contracts synced from OfficeManager — all open contracts plus the last 3 months of closed ones. Open or closed status is detected on each sync.' },
      { name: 'Drivers', to: '/drivers', icon: 'M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM6 21v-1a6 6 0 0 1 6-6 6 6 0 0 1 6 6v1M3 9l2 2 3-3', desc: 'Fleet drivers with their licence number, expiry and status. Add, edit or suspend drivers; expiring licences are flagged.' },
    ],
  },
  {
    title: 'Operations',
    items: [
      { name: 'Delivery Command', to: '/ops-dashboard', icon: 'M3 7h11v8H3zM14 10h3.5L21 13v2h-7M6.5 18.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM17.5 18.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z', desc: 'Delivery/dispatch command dashboard built from the live Main Trip Dashboard sheet — on-time rate, total trips, vehicles in rotation, completion rate, a recent-days activity chart, a live trips table and top drivers, in a high-contrast control-room view.' },
      { name: 'Orders Board', to: '/orders-board', icon: 'M4 5h6v6H4zM14 5h6v6h-6zM4 15h6v4H4zM14 15h6v4h-6z', desc: 'Three-lane trips Kanban (Scheduled → Completed → Cancelled) from the Main Trip Dashboard sheet, each card a real pickup/drop-off trip with its vehicle, assignee, destination and date.' },
      { name: 'Rental Operations', to: '/rental-contracts', icon: 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-6 9 2 2 4-4', desc: 'The Rental Manager\'s check-in / check-out board: every active rental and upcoming booking with its car\'s live 9-point readiness verdict. Open a contract to see the full condition checklist before handing over the keys.' },
      { name: 'Booking Readiness', to: '/booking-readiness', icon: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z', desc: 'Pickup-prep board: every booking due for pickup in the next few days with its car\'s live readiness checklist. Cars with a valid recent pre-rental inspection read as done, so you only chase what\'s actually pending. Urgent pickups (within the alert lead) are flagged red; the Settings tab tunes the look-ahead, alert lead, inspection validity and holiday exclusions.' },
      { name: 'Overdue Rentals', to: '/overdue-rentals', icon: 'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', desc: 'Open contracts that are past their expected return date — the cars that should be back but are not.' },
      { name: 'Check-in / Check-out', to: '/inspection-prototype', icon: 'M3 9a2 2 0 0 1 2-2h1.6l1-1.6A2 2 0 0 1 10.3 4h3.4a2 2 0 0 1 1.7 1.4l1 1.6H18a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM12 16a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4z', desc: 'Rental check-in / check-out condition capture: tap a zone on the interactive car diagram to record the required condition checks and photos, then compare pre-rental vs post-return with the before/after slider. Prototype — not yet wired to live contracts.' },
      { name: 'Driver Dispatch', to: '/driver-dispatch', icon: 'M3 7h11v8H3zM14 10h3.5L21 13v2h-7M6.5 18.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM17.5 18.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z', desc: 'Driver Dispatch: send a vehicle between locations (to Deals on Wheels, the garage, …) and track which driver has it and where. Dispatching a car flips it to “In Transit to …” on the grid and drops an Action Required task into the assignee’s My Queue.' },
      { name: "Who's Where", to: '/team-presence', icon: 'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a4 4 0 0 1 3-3.87m10-5.13a4 4 0 1 0-4-4 4 4 0 0 0 4 4zM9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6z', desc: 'Live team availability — everyone on the team and whether they’re free or busy right now, and if busy, exactly why (driving a move, on a maintenance pickup, or inspecting a car), which car and for how long.' },
      { name: 'Fleet Health', to: '/inspections/schedules', icon: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM9 15l2 2 4-4', desc: 'The fleet\'s neural center — a unified hub with tabs for Service Due (odometer-based) and Registration & Insurance expiry, consolidating the live per-car health surfaces in one place.' },
    ],
  },
  {
    title: 'Maintenance',
    items: [
      { name: 'Workflow Journey', to: '/inspections/history', icon: 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-5 7h4m-4 4h4', desc: 'A stage-by-stage timeline of every step each car takes through the workflow — inspection, dispatch, garage arrival, repair, movement and readiness — each row headlined by the workflow stage it reached (absorbs the old Activity Feed, Vehicle Status, Vehicle Life-Stream & Workflow Hub). Click any car to follow just its own journey.' },
      { name: 'Workflow', to: '/maintenance-workflow', icon: 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-3 7-3 3 3 3m6-6 3 3-3 3', desc: 'The live maintenance ticket pipeline (Inspector → Supervisor → Driver → Garage → Re-inspection). Open a ticket and advance it through the stages; the board updates in real time.' },
      { name: 'Recommendations', to: '/maintenance-recommendations', icon: 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-3 8h.01M9 11h6m-6 3h4', desc: 'Inspection recommendations awaiting a supervisor’s review, before any maintenance starts. Approve to begin work, order parts first, schedule for later, or dismiss — a recommendation-only car never clutters the active board.' },
      { name: 'My Queue', to: '/my-maintenance-queue', icon: 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-3 9 2 2 4-4', desc: 'Your role-scoped maintenance work in one place: Abu Maroof (Inspector) sees pending inspections and final re-inspections; a Supervisor (Dispatcher) sees tickets awaiting a garage + driver assignment; a Driver sees active trips/dispatches and cars waiting on a follow-up.' },
      { name: 'Inspection Review', to: '/inspection-review', icon: 'M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', desc: 'Controllers (Lin & Marwa) review inspection requests before they reach Abu Maroof — approve to send it on, or reject with a reason.' },
      { name: 'Inspection Intelligence', to: '/inspection-intelligence', icon: 'M12 3v1m0 16v1m9-9h-1M4 12H3m15.36 6.36-.7-.7M6.34 6.34l-.7-.7m12.72 0-.7.7M6.34 17.66l-.7.7M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z', desc: 'Mission Control for the AUTOMATIC inspection engine (the daily Proactive Diagnostic Monitor): why the system requests inspections, which rule fired, which cars qualify now, and which were skipped and why. Read-only monitoring & debugging.' },
      { name: 'Approvals', to: '/maintenance-approvals', icon: 'M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', desc: 'Maintenance items waiting for sign-off before work proceeds.' },
      { name: 'Pending Invoices', to: '/invoices/pending-submission', icon: 'M9 12h6m-6 4h4m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2zM14 3v5h5M12 8v.01', desc: 'Repairs that are done and the car is back in service, but the garage invoice hasn’t arrived yet. Anything past the 3-day window is flagged red; mark an invoice received to close the ticket.' },
      { name: 'Completed Repairs', to: '/completed-repairs', icon: 'M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', desc: 'The ledger of every car whose repair is done and signed off — who requested it, who drove it, where it was fixed, what was found and repaired, and what it cost. Expand any row for the full custody chain, the resolved faults and the odometer readings.' },
      { name: 'Foresight', to: '/maintenance-foresight', icon: 'M9.66 17h4.68M12 3v1m6.36 1.64-.7.7M21 12h-1M4 12H3m3.34-5.66-.7-.7M7 17a5 5 0 1 1 10 0', desc: 'Predictive maintenance: cars showing early mechanical warning signs (service overdue, chronic faults, aging battery) caught before they fail — with the downtime, parts-wait risk and lost rental revenue estimated from the fleet’s own repair history.' },
      { name: 'Cost Capture', to: '/cost-capture', icon: 'M12 8c-1.7 0-3 .9-3 2s1.3 2 3 2 3 .9 3 2-1.3 2-3 2m0-8V6m0 12v-2m9-4a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', desc: 'Quick Cost Input: recent repairs with no cost recorded. Enter the amount in one tap to fix each vehicle’s repair spend and re-check its Negative-Yield flag — the tool for closing the understated-spend gap.' },
      { name: 'Damage & Accidents', to: '/damage-accidents', icon: 'M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z', desc: 'Damage and accident records shown as-is per vehicle. Fault is colored red/green based on the liable party and insurance.' },
      { name: 'Garages', to: '/garages', icon: 'M3 9l9-6 9 6v11a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z', desc: 'Garages where fleet cars are serviced, with the work routed to each.' },
      { name: 'Vendors', to: '/vendors', icon: 'M3 9l1-5h16l1 5M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9M3 9h18M9 20v-6h6v6', desc: 'Suppliers and service vendors referenced by maintenance and contracts.' },
      { name: 'Keyword Risk', to: '/finding-keywords', icon: 'M20.59 13.41 13.42 20.6a2 2 0 0 1-2.83 0l-7-7A2 2 0 0 1 3 12V5a2 2 0 0 1 2-2h7a2 2 0 0 1 1.42.59l7.17 7.17a2 2 0 0 1 0 2.83zM7.5 7.5h.01', desc: 'The fault-keyword library the inspection picker offers, each graded by risk (🔴 critical / 🟡 moderate / 🟢 routine). Add, edit or retire keywords and set how serious each fault type is.' },
      { name: 'Parts Purchase', to: '/parts', icon: 'M20 7h-9M14 17H5M17 20a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM7 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6z', desc: 'The Parts Purchase + Repair Intelligence board: request a part (customer or garage), approve, buy (garage or supplier) and install it — with duplicate-purchase detection and repair history.' },
      { name: 'Part Investigations', to: '/part-investigations', icon: 'M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z', desc: 'Admin inbox for duplicate-purchase and fault-recurrence alerts: review why the same part or fault repeated, capture the reason, and approve or reject the exception.' },
      { name: 'Recurring Fault Reviews', to: '/recurring-fault-reviews', icon: 'M3 2v6h6M3 8a9 9 0 1 0 2.6-4.36L3 8', desc: 'Cars that came back with the SAME confirmed fault after a completed repair. Each case shows the previous ticket, garage, parts used, days and distance since the repair, and how many times it recurred — so management can decide whether the earlier repair failed, it is a new failure, workshop responsibility, customer misuse, or needs investigation.' },
      { name: 'Cost Analytics', to: '/maintenance-analytics', financial: true, icon: 'M3 3v18h18M7 15l3-3 3 3 5-5', desc: 'Maintenance cost trends and breakdowns across the fleet — spend by car, garage, and over time.' },
    ],
  },
  {
    title: 'Workflow Oversight',
    items: [
      { name: 'Oversight', to: '/oversight', icon: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z', desc: 'The maintenance-workflow accountability & data-integrity hub — one landing page linking the four audit surfaces below with their live counts.' },
      { name: 'Mileage Discrepancies', to: '/oversight/mileage', icon: 'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', desc: 'Every workflow stage where the odometer entered didn\'t match what was expected — a reading that ran backwards (a real data error), a big jump, or a garage test-drive — with the before/after figures, the note left and who entered it.' },
      { name: 'Stage Accountability', to: '/oversight/stages', icon: 'M4 7h16M4 12h16M4 17h16M7 4v16', desc: 'Per ticket, the whole workflow chain laid out stage by stage: who owned each stage and the mileage they recorded there.' },
      { name: 'Garage Invoices Due', to: '/oversight/left-garage', icon: 'M3 7h11v8H3zM14 10h3.5L21 13v2h-7M6.5 18.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM17.5 18.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z', desc: 'Cars that have physically left the garage but still owe an invoice — the actionable list of which garages to chase, each deep-linking to the ticket to request / record the bill.' },
      { name: 'Severity Review', to: '/oversight/severity', icon: 'M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z', desc: 'Tickets whose fault-severity grade looks too low for the situation — graded Routine / Moderate where a critical-risk keyword, a breakdown or a red-graded car says it should be Critical.' },
      { name: 'Mis-Diagnosis', to: '/oversight/misdiagnoses', icon: 'M18.36 6.64A9 9 0 1 1 5.64 6.64m6.36-3.14v6', desc: 'Every fault the inspector diagnosed that a supervisor later overruled as wrong (the "mark fault incorrect" override) — the symptom he called, who overruled it and why, with a per-inspector tally so a recurring mis-caller stands out.' },
    ],
  },
  {
    title: 'Analytics & Admin',
    items: [
      { name: 'Profitability', to: '/profitability', financial: true, icon: 'M12 8c-1.7 0-3 .9-3 2s1.3 2 3 2 3 .9 3 2-1.3 2-3 2m0-8V6m0 12v-2m9-4a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', desc: 'Operational profit per car across the whole fleet — rental income (type-R, ex-VAT) minus logged maintenance cost. Sorted best-to-worst to spot top assets and liabilities.' },
      { name: 'Fleet Utilization', to: '/fleet-utilization', icon: 'M3 3v18h18M7 15l3-3 3 3 5-5M8 21V9m4 12V5m4 16v-7', desc: 'Per-car split of owned time into rented, in-maintenance, and idle days — utilization and downtime % against how long you have owned each car, with rent lost to downtime. Filter by period (e.g. last month) and sort to find the cars stuck in the workshop.' },
      { name: 'Fuel & Mileage', to: '/mileage', icon: 'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', desc: 'One home for every odometer/fuel tool, in three tabs: Fuel & Mileage (real travel vs. contract km, off-contract leakage, fuel debits), Reconciliation (stored odometer vs. the scanner baseline, adopt with one click) and Chain Audit (contract-to-contract odometer handoffs with a non-destructive Quick Fix).' },
      { name: 'Maintenance Swap', to: '/maintenance-swap', icon: 'M4 5h16M4 12h16M4 19h16M9 5v14', desc: 'Live triage of the fleet in three columns — Action Required / In Workshop / Available Pool — with a Swap & Renew engine that keeps a customer on the road while their car is repaired.' },
      { name: 'Net Profit', to: '/net-profit', financial: true, icon: 'M3 3v18h18M7 14l3-3 3 3 5-6', desc: 'Fleet-wide cash-basis Net Profit for a month: total net cash collected on rentals returned in the month, minus the cost of maintenance contracts closed in the month. Step through months to compare. Built from synced figures for speed — use Reconcile on a single contract to verify its real cash live.' },
      { name: 'Financial Conflicts', to: '/financial-conflicts', financial: true, icon: 'M12 8c-1.7 0-3 .9-3 2s1.3 2 3 2 3 .9 3 2-1.3 2-3 2m0-8V6m0 12v-2M5 21h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2z', desc: 'Accounting clean-up hub: only the broken invoices — VAT that does not add up, invoices that disagree with their contract, and overlapping (double) billing.' },
      { name: 'Reconciliation', to: '/financial-reconciliation', financial: true, icon: 'M8 7h12m0 0-4-4m4 4-4 4M16 17H4m0 0 4 4m-4-4 4-4', desc: 'Bridge one contract to the official accounting system: its Fleet ledger side-by-side with the real cash collected (accounting receipts) and the vouchers booked against it. A fee/rounding tolerance keeps the noise out, so you’re only alerted on significant gaps. Read-only MVP.' },
      { name: 'Data Health', to: '/data-health', icon: 'M3 12h4l2 5 4-12 2 7h6', desc: 'Overall data quality in two tabs: Data Quality (incomplete/broken records — missing VINs, mileage, unlinked contracts) and Status Mismatches (cars whose status disagrees with their contracts).' },
      { name: 'Sync Audit', to: '/sync-audit', icon: 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-6 9 2 2 4-4', desc: 'Read-only history of CMD sync runs: how many contracts each execution scanned, updated, and auto-corrected (e.g. stale dates cleared).' },
      { name: 'Notification Test', to: '/notification-test', icon: 'M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 0 0-4-5.7V5a2 2 0 1 0-4 0v.3A6 6 0 0 0 6 11v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0v1a3 3 0 1 1-6 0v-1m6 0H9', desc: 'Admin-only console to fire realistic test notifications on demand (overdue service, rental expiry, invoice overdue, inspection due) and confirm they reach the bell — with a Force flag to trigger even when the live condition isn’t met.' },
      { name: 'Simulation', to: '/simulation', demoOnly: true, icon: 'M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z', desc: 'Admin-only demo console (only shown in Demo Mode): force a real "Service Due" oil alert or a fault-discovery ticket on a real car, watch the system react end-to-end, then roll it all back with one click.' },
      { name: 'Users', to: '/users', icon: 'M16 5.5a3 3 0 0 1 0 5.8M3 20a6 6 0 0 1 12 0M9 8.2a3.2 3.2 0 1 1 0-6.4 3.2 3.2 0 0 1 0 6.4zM21 20a6 6 0 0 0-4-5.6', desc: 'Every account, its status and Spatie role(s) — admin-only.' },
    ],
  },
  {
    title: 'Settings',
    items: [
      { name: 'Settings', to: '/settings', icon: 'M10.3 4.3a1 1 0 0 1 .95-.7h1.5a1 1 0 0 1 .95.7l.35 1.1a7 7 0 0 1 1.5.87l1.1-.4a1 1 0 0 1 1.2.45l.75 1.3a1 1 0 0 1-.25 1.25l-.9.74a7 7 0 0 1 0 1.74l.9.74a1 1 0 0 1 .25 1.25l-.75 1.3a1 1 0 0 1-1.2.45l-1.1-.4a7 7 0 0 1-1.5.87l-.35 1.1a1 1 0 0 1-.95.7h-1.5a1 1 0 0 1-.95-.7l-.35-1.1a7 7 0 0 1-1.5-.87l-1.1.4a1 1 0 0 1-1.2-.45l-.75-1.3a1 1 0 0 1 .25-1.25l.9-.74a7 7 0 0 1 0-1.74l-.9-.74a1 1 0 0 1-.25-1.25l.75-1.3a1 1 0 0 1 1.2-.45l1.1.4a7 7 0 0 1 1.5-.87zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z', desc: 'Your account & preferences in one place: appearance (theme / language), the roles and permissions granted to you, keyboard shortcuts, and sign-out.' },
    ],
  },
];

const ALL_ITEMS = NAV_SECTIONS.flatMap((s) => s.items);
const SEARCH_ITEMS = NAV_SECTIONS.flatMap((s) => s.items.map((i) => ({ ...i, section: s.title })));

// Friendly module name for a route — powers the activity tracker's "last page"
// so the Workforce Operations Center reads "Workflow", not "/maintenance-workflow".
const PATH_LABELS = Object.fromEntries(ALL_ITEMS.map((i) => [i.to, i.name]));
const resolveModuleLabel = (path) => {
  if (PATH_LABELS[path]) return PATH_LABELS[path];
  const seg = (path || '/').split('/')[1];
  return seg ? seg.replace(/-/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : 'Dashboard';
};

// Permission required to see each nav destination. A `null`/missing entry means
// "always visible to any authenticated user". These mirror the route guards in
// App.js and the `permission:` middleware on the backend — keep the three in sync.
const NAV_PERMISSIONS = {
  '/': 'dashboard.view',
  '/ops-dashboard': 'dashboard.view',
  '/orders-board': 'dashboard.view',
  '/notifications': null,
  '/settings': null,
  '/team-presence': 'logistics.view',
  '/invoices/pending-submission': 'maintenance.view',
  '/completed-repairs': 'maintenance.view',
  '/finding-keywords': 'maintenance.view',
  '/parts': 'parts.view',
  '/part-investigations': 'parts.investigate',
  '/recurring-fault-reviews': 'maintenance.recurring.view',
  '/vehicles': 'vehicles.view',
  '/odometer-approvals': 'vehicles.approve_odometer',
  '/driver-dispatch': 'logistics.view',
  '/customers': 'customers.view',
  '/drivers': 'drivers.view',
  '/contracts': 'contracts.view',
  '/rental-contracts': 'contracts.view',
  '/booking-readiness': 'booking_readiness.view',
  '/inspection-prototype': 'inspections.view',
  '/inspections/schedules': 'inspections.view',
  '/reminders/service': 'reminders.view',
  '/registrations': 'registration.view',
  '/overdue-rentals': 'dashboard.view',
  '/maintenance': 'maintenance.view',
  '/inspections/history': 'insights.view',
  '/maintenance-hub': 'maintenance.view',
  '/maintenance-workflow': 'maintenance.view',
  '/maintenance-recommendations': 'maintenance.view',
  '/my-maintenance-queue': 'maintenance.view',
  '/maintenance-foresight': 'maintenance.view',
  '/cost-capture': 'maintenance.manage',
  '/inspection-review': 'maintenance.manage',
  '/inspection-intelligence': 'maintenance.view',
  '/maintenance-approvals': 'maintenance.approve',
  '/garages': 'maintenance.view',
  '/vendors': 'vendors.view',
  '/maintenance-analytics': 'maintenance.view',
  '/damage-accidents': 'maintenance.view',
  '/profitability': 'insights.view',
  '/mileage': 'insights.view',
  '/fleet-utilization': 'insights.view',
  '/maintenance-swap': 'insights.view',
  '/data-health': 'insights.view',
  '/financial-conflicts': 'insights.view',
  '/financial-reconciliation': 'insights.view',
  '/net-profit': 'insights.view',
  '/oversight': 'insights.view',
  '/oversight/mileage': 'insights.view',
  '/oversight/stages': 'insights.view',
  '/oversight/left-garage': 'insights.view',
  '/oversight/severity': 'insights.view',
  '/oversight/misdiagnoses': 'insights.view',
  '/sync-audit': 'sync.run',
  '/notification-test': 'users.manage',
  '/simulation': 'users.manage',
  '/users': 'users.manage',
};

// Quick actions surfaced at the top of the command palette. `run` receives a
// small context ({ navigate }) so actions stay declarative here.
const QUICK_ACTIONS = [
  { id: 'new-contract', label: 'New contract', keywords: 'create add rental', icon: 'M12 5v14M5 12h14', run: ({ navigate }) => navigate('/contracts/new') },
  { id: 'data-health', label: 'Check data health', keywords: 'issues quality clean', icon: 'M3 12h4l2 5 4-12 2 7h6', run: ({ navigate }) => navigate('/data-health') },
  { id: 'reload', label: 'Reload app', keywords: 'refresh reset', icon: 'M4 4v6h6M20 9A8 8 0 0 0 6.3 5.3L4 8', run: () => window.location.reload() },
];

// Permission gate per quick action (null = always available).
const QUICK_ACTION_PERMISSIONS = {
  'new-contract': 'contracts.manage',
  'run-sync': 'sync.run',
  'data-health': 'insights.view',
  'reload': null,
};

// Floating "back to top" button that appears once you scroll down a long page.
function ScrollTop() {
  const [show, setShow] = useState(false);
  useEffect(() => {
    const onScroll = () => setShow(document.documentElement.scrollTop > 400);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);
  if (!show) return null;
  return (
    <button
      onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
      className="fixed bottom-[11rem] end-6 z-30 flex h-11 w-11 items-center justify-center rounded-full bg-slate-900/90 text-white shadow-lg ring-1 ring-white/10 backdrop-blur transition hover:bg-slate-900 active:scale-90"
      title="Back to top"
      aria-label="Back to top"
    >
      <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 19V5M5 12l7-7 7 7" /></svg>
    </button>
  );
}

// `collapsed` only takes effect at the lg breakpoint (the icon-rail); below lg
// the sidebar is a full-width drawer and always shows labels.
function NavItem({ item, onNavigate, collapsed, badgeCount = 0 }) {
  // Live unread count, only surfaced on the Notifications row. Reuses the same
  // polling context that drives the top-bar bell, so the badge and bell agree.
  // Other rows can pass an attention `badgeCount` (e.g. overdue reminders/schedules).
  const { unreadCount } = useNotifications();
  const count = item.to === '/notifications' ? unreadCount : badgeCount;
  const badge = count > 99 ? '99+' : String(count);
  return (
    <NavLink
      to={item.to}
      end={item.to === '/'}
      onClick={onNavigate}
      className={({ isActive }) =>
        [
          'group relative flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors duration-150',
          collapsed ? 'lg:justify-center lg:gap-0 lg:px-0' : '',
          isActive
            ? 'bg-accent-400/[0.10] text-white ring-1 ring-inset ring-accent-400/15'
            : 'text-steel-300 hover:bg-white/[0.05] hover:text-white',
        ].join(' ')
      }
    >
      {({ isActive }) => (
        <>
          {/* active accent bar — Faster yellow signature */}
          <span
            className={`absolute start-0 top-1/2 h-5 w-0.5 -translate-y-1/2 rounded-e-full bg-accent-400 transition-opacity duration-150 ${
              isActive ? 'opacity-100' : 'opacity-0'
            }`}
          />
          <svg
            className={`h-[18px] w-[18px] shrink-0 transition-colors ${isActive ? 'text-accent-400' : 'text-steel-400 group-hover:text-steel-200'}`}
            fill="none" viewBox="0 0 24 24" stroke="currentColor"
            strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"
          >
            <path d={item.icon} />
          </svg>
          {/* unread dot on the icon — only visible in the collapsed icon-rail (lg+) */}
          {count > 0 && collapsed && (
            <span className="absolute end-1 top-1 hidden h-2 w-2 rounded-full bg-rose-500 ring-2 ring-slate-900 lg:block" />
          )}
          <span className={`truncate ${collapsed ? 'lg:hidden' : ''}`}>{item.name}</span>
          {/* unread count pill — shown whenever the label is visible (expanded sidebar / mobile drawer) */}
          {count > 0 && (
            <span
              className={`ms-auto inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-rose-500 px-1.5 text-[11px] font-semibold tabular-nums text-white ${
                collapsed ? 'lg:hidden' : ''
              }`}
            >
              {badge}
            </span>
          )}
          {/* hover tooltip, only in the collapsed rail (lg+) */}
          <span className={`rail-tip ${collapsed ? 'hidden lg:block' : 'hidden'}`}>{item.name}</span>
        </>
      )}
    </NavLink>
  );
}

// A nav section with a collapsible header (accordion). In the lg icon-rail
// (`collapsed`) there are no labels to click, so the section just renders its
// items with a thin divider, exactly as before. `open` is forced true for the
// section holding the current page so you always see where you are.
function NavSection({ section, collapsed, open, onToggle, onNavigate, badges = {} }) {
  return (
    <div>
      {collapsed ? (
        <>
          {/* below lg the drawer is full-width, so the label still shows */}
          <p className="px-3 pb-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-steel-500 lg:hidden">
            {section.title}
          </p>
          <div className="mx-auto mb-1.5 hidden h-px w-6 bg-white/10 lg:block" />
        </>
      ) : (
        <button
          type="button"
          onClick={onToggle}
          className="group/sec mb-0.5 flex w-full items-center justify-between rounded-lg px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-steel-500 transition hover:text-steel-300"
        >
          <span>{section.title}</span>
          <svg
            className={`h-3.5 w-3.5 text-steel-500 transition-transform duration-200 group-hover/sec:text-steel-300 ${open ? '' : '-rotate-90'}`}
            fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round"
          >
            <path d="M6 9l6 6 6-6" />
          </svg>
        </button>
      )}
      {(collapsed || open) && (
        <div className="space-y-0.5">
          {section.items.map((item) => (
            <NavItem key={item.name} item={item} onNavigate={onNavigate} collapsed={collapsed} badgeCount={badges[item.to] || 0} />
          ))}
        </div>
      )}
    </div>
  );
}

export default function AppLayout() {
  const { user, logout } = useAuth();
  const { can, roles } = usePermissions();
  const navigate = useNavigate();
  const location = useLocation();

  // Report presence + module usage to the backend (Workforce Operations Center).
  useActivityTracker(resolveModuleLabel);

  // Hide nav items the user can't open, and drop sections left empty. While
  // SHOW_FINANCIALS is off (Financial Decoupling), the money-rollup destinations
  // (Cost Analytics, Profitability, Financial Conflicts, Reconciliation, Net
  // Profit) are also hidden so no financial page is reachable from the UI.
  const navVisible = (i) =>
    can(NAV_PERMISSIONS[i.to]) &&
    !pathBlockedForRoles(i.to, roles) &&
    (SHOW_FINANCIALS || !i.financial) &&
    (!i.demoOnly || DEMO_MODE);
  const visibleSections = NAV_SECTIONS
    .map((s) => ({ ...s, items: s.items.filter(navVisible) }))
    .filter((s) => s.items.length > 0);
  const visibleSearch = SEARCH_ITEMS.filter(navVisible);

  // Sidebar attention badges — overdue counts for the Inspections & Reminders items,
  // so what needs attention is visible before clicking. Polled lightly (60s) and only
  // for the sections the user can actually see. Contact "overdue" = open + past due.
  const canInspections = can('inspections.view');
  const canReminders = can('reminders.view');
  const canReview = can('maintenance.manage'); // gates the Inspection Review row + its badge
  const [attention, setAttention] = useState({});
  useEffect(() => {
    let alive = true;
    const load = async () => {
      const jobs = [];
      if (canInspections) {
        jobs.push(['/inspections/schedules',
          api.get('/InspectionSchedules', { params: { status: 'overdue' } }).then((r) => (r.data?.data || []).length)]);
      }
      if (canReminders) {
        jobs.push(['/reminders/service',
          api.get('/ServiceReminders', { params: { status: 'overdue' } }).then((r) => (r.data?.data || []).length)]);
      }
      if (canReview) {
        // How many requests are sitting in the Inspection Review queue right now — a live
        // notification-style count on the sidebar row, so Lin/Marwa see the backlog at a glance.
        jobs.push(['/inspection-review',
          api.get('/maintenance-tickets/pending-review').then((r) => (r.data?.data || r.data || []).length)]);
      }
      if (!jobs.length) return;
      const results = await Promise.all(jobs.map(([, p]) => p.catch(() => 0)));
      if (!alive) return;
      const next = {};
      jobs.forEach(([path], i) => { next[path] = results[i]; });
      setAttention(next);
    };
    load();
    const id = setInterval(() => { if (document.visibilityState === 'visible') load(); }, 60000);
    return () => { alive = false; clearInterval(id); };
  }, [canInspections, canReminders, canReview]);

  const [open, setOpen] = useState(false);
  const [cmdOpen, setCmdOpen] = useState(false);
  const [helpOpen, setHelpOpen] = useState(false);
  // Desktop sidebar collapse (icon-rail), remembered across sessions.
  const [collapsed, setCollapsed] = useState(() => localStorage.getItem('fv:rail') === '1');
  // Which nav sections are collapsed (accordion), remembered across sessions.
  const [collapsedSections, setCollapsedSections] = useState(() => {
    try { return new Set(JSON.parse(localStorage.getItem('fv:navsections') || '[]')); } catch { return new Set(); }
  });
  const toggleSection = (title) =>
    setCollapsedSections((prev) => {
      const next = new Set(prev);
      next.has(title) ? next.delete(title) : next.add(title);
      localStorage.setItem('fv:navsections', JSON.stringify([...next]));
      return next;
    });
  // Recently visited pages (paths), most-recent first — feeds the command palette.
  const [recents, setRecents] = useState(() => {
    try { return JSON.parse(localStorage.getItem('fv:recents') || '[]'); } catch { return []; }
  });
  const lastG = useRef(0); // timestamp of the last "g" press, for g+key chords

  useEffect(() => {
    localStorage.setItem('fv:rail', collapsed ? '1' : '0');
  }, [collapsed]);

  const handleLogout = async () => {
    await logout();
    navigate('/login', { replace: true });
  };

  // Global keyboard shortcuts: ⌘K palette, ? help, t top, g+<key> jumps.
  useEffect(() => {
    const onKey = (e) => {
      if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        setCmdOpen((v) => !v);
        return;
      }
      // Ignore single-key shortcuts while typing in a field.
      const tag = (e.target?.tagName || '').toLowerCase();
      if (e.metaKey || e.ctrlKey || e.altKey || tag === 'input' || tag === 'textarea' || e.target?.isContentEditable) return;

      if (e.key === '?') { e.preventDefault(); setHelpOpen((v) => !v); return; }
      if (e.key === 't' || e.key === 'T') { window.scrollTo({ top: 0, behavior: 'smooth' }); return; }

      // g + d/v/c/m chord navigation.
      const now = Date.now();
      if (e.key === 'g' || e.key === 'G') { lastG.current = now; return; }
      if (now - lastG.current < 800) {
        const map = { d: '/', v: '/vehicles', c: '/contracts', m: '/maintenance-workflow' };
        const dest = map[e.key.toLowerCase()];
        if (dest) { e.preventDefault(); navigate(dest); lastG.current = 0; }
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [navigate]);

  // Derive the current page name for the topbar (longest matching path wins).
  const current = [...ALL_ITEMS]
    .filter((i) => (i.to === '/' ? location.pathname === '/' : location.pathname.startsWith(i.to)))
    .sort((a, b) => b.to.length - a.to.length)[0];

  // The section holding the current page — kept open even if the user collapsed it.
  const activeSectionTitle = visibleSections.find((s) => s.items.includes(current))?.title;

  const initial = (user?.name || '?').charAt(0).toUpperCase();
  const pageName = current?.name;

  // Reflect the current page in the browser tab title.
  useEffect(() => {
    document.title = pageName ? `${pageName} · Faster` : 'Faster';
  }, [pageName]);

  // Track recently visited nav pages (exact matches only) for the palette.
  useEffect(() => {
    const hit = ALL_ITEMS.find((i) => (i.to === '/' ? location.pathname === '/' : location.pathname === i.to));
    if (!hit) return;
    setRecents((prev) => {
      const next = [hit.to, ...prev.filter((t) => t !== hit.to)].slice(0, 6);
      localStorage.setItem('fv:recents', JSON.stringify(next));
      return next;
    });
  }, [location.pathname]);

  const runAction = (a) => a.run?.({ navigate });

  return (
    <PageStatProvider>
    <div className="min-h-screen">
      <ScrollProgress />
      {/* Mobile overlay */}
      {open && (
        <div className="fixed inset-0 z-30 bg-slate-900/50 backdrop-blur-sm lg:hidden" onClick={() => setOpen(false)} />
      )}

      {/* Sidebar */}
      <aside
        className={`fixed inset-y-0 start-0 z-40 flex w-64 transform flex-col bg-navy-950 text-steel-300 shadow-xl transition-all duration-300 ease-out lg:translate-x-0 ${
          collapsed ? 'lg:w-20' : 'lg:w-64'
        } ${open ? 'translate-x-0' : 'max-lg:-translate-x-full max-lg:rtl:translate-x-full'}`}
      >
        {/* plain hairline seam along the sidebar's outer edge */}
        <div className="pointer-events-none absolute inset-y-0 end-0 w-px bg-white/[0.07]" />

        {/* Brand — top-left identity slot (logo-ready; see components/Brand.js). */}
        <div className={`flex h-16 shrink-0 items-center border-b border-white/[0.06] px-5 ${collapsed ? 'lg:justify-center lg:px-0' : ''}`}>
          <Brand collapsed={collapsed} />
        </div>

        {/* Nav */}
        <nav className="sidebar-scroll flex-1 space-y-5 overflow-y-auto px-3 py-5">
          {visibleSections.map((section) => (
            <NavSection
              key={section.title}
              section={section}
              collapsed={collapsed}
              open={!collapsedSections.has(section.title) || section.title === activeSectionTitle}
              onToggle={() => toggleSection(section.title)}
              onNavigate={() => setOpen(false)}
              badges={attention}
            />
          ))}
        </nav>

        {/* Footer: live status + desktop collapse toggle */}
        <div className="shrink-0 border-t border-white/[0.06] px-3 py-3">
          <div className={`flex items-center ${collapsed ? 'lg:flex-col lg:gap-2' : 'justify-between'} gap-2 px-2`}>
            <div className={`flex items-center gap-2 text-[11px] font-medium text-steel-400 ${collapsed ? 'lg:hidden' : ''}`}>
              <span className="relative flex h-2 w-2">
                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60" />
                <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-400" />
              </span>
              Live — OfficeManager connected
            </div>
            {/* collapsed state: just the pulsing dot */}
            {collapsed && (
              <span className="relative hidden h-2 w-2 lg:flex">
                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60" />
                <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-400" />
              </span>
            )}
            <button
              onClick={() => setCollapsed((v) => !v)}
              className="hidden rounded-lg p-1.5 text-steel-400 transition hover:bg-white/[0.06] hover:text-white lg:inline-flex"
              title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
              aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            >
              <svg className={`h-5 w-5 transition-transform ${collapsed ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                <path d="M15 6l-6 6 6 6" />
              </svg>
            </button>
          </div>
        </div>
      </aside>

      {/* Main column */}
      <div className={`transition-all duration-300 ${collapsed ? 'lg:ps-20' : 'lg:ps-64'}`}>
        <header className="glass sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-slate-200/70 px-4 sm:px-6 lg:px-8">
          <button
            className="rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 lg:hidden"
            onClick={() => setOpen(true)}
            aria-label="Open menu"
          >
            <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
              <path strokeLinecap="round" strokeLinejoin="round" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>

          <div className="flex min-w-0 flex-1 items-center gap-2">
            <h2 className="truncate text-[15px] font-semibold text-slate-800">{current?.name || 'Faster'}</h2>
          </div>

          <div className="flex items-center gap-3">
            <LiveClock name={user?.name} />

            <LanguageToggle />

            <ThemeToggle />

            <button
              onClick={() => setHelpOpen(true)}
              className="hidden h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-400 shadow-sm transition hover:text-slate-600 hover:ring-1 hover:ring-slate-200 lg:inline-flex"
              title="Keyboard shortcuts ( ? )"
              aria-label="Keyboard shortcuts"
            >
              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8"><path strokeLinecap="round" strokeLinejoin="round" d="M9.1 9a3 3 0 1 1 5.8 1c0 2-3 3-3 3M12 17h.01" /></svg>
            </button>

            <button
              onClick={() => setCmdOpen(true)}
              className="hidden items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-400 shadow-sm transition hover:text-slate-600 hover:ring-1 hover:ring-slate-200 sm:flex"
              title="Search (Ctrl / ⌘ K)"
            >
              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8"><path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-4.3-4.3M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16z" /></svg>
              Search
              <kbd className="rounded border border-slate-200 bg-slate-50 px-1 text-[10px] font-semibold text-slate-400">⌘K</kbd>
            </button>

            <NotificationBell />

            {/* subtle divider between actions and the user identity block */}
            <span className="hidden h-6 w-px bg-slate-200 sm:block" />

            <div className="hidden text-right sm:block">
              <p className="text-sm font-semibold leading-tight text-slate-900">{user?.name}</p>
              <p className="text-xs text-slate-500">{user?.email}</p>
            </div>
            <div className="flex h-9 w-9 items-center justify-center rounded-full bg-indigo-600 text-sm font-semibold text-white shadow-sm ring-2 ring-white">
              {initial}
            </div>
            <button
              onClick={handleLogout}
              className="rounded-lg p-2 text-slate-400 transition hover:bg-red-50 hover:text-red-600"
              title="Log out"
            >
              <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
                <path d="M16 17l5-5-5-5M21 12H9M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
              </svg>
            </button>
          </div>
        </header>

        {/* Page content — re-animates on every route change; an error here can't blank the app */}
        <main>
          <ErrorBoundary key={location.pathname}>
            <div className="animate-fade-in-up">
              <Outlet />
            </div>
          </ErrorBoundary>
        </main>
      </div>

      <CommandPalette
        open={cmdOpen}
        onClose={() => setCmdOpen(false)}
        items={visibleSearch}
        actions={QUICK_ACTIONS.filter((a) => can(QUICK_ACTION_PERMISSIONS[a.id])).map((a) => ({ ...a, run: () => runAction(a) }))}
        recents={recents}
      />
      <ShortcutsHelp open={helpOpen} onClose={() => setHelpOpen(false)} />
      <PageStatGauge />
      <ScrollTop />
    </div>
    </PageStatProvider>
  );
}
