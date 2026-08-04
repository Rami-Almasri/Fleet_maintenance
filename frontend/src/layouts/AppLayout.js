import { useState, useEffect, useRef } from 'react';
import { Outlet, useNavigate, useLocation } from 'react-router-dom';
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
import { PageStatProvider, PageStatGauge } from '../components/PageStat';
import { SHOW_FINANCIALS, DEMO_MODE, SHOW_FLEET_INTELLIGENCE } from '../config/features';
import { pathBlockedForRoles, homePathForRoles } from '../config/access';
import { moduleForPath } from '../config/moduleRegistry';
import ModuleTabBar from '../components/workspace/ModuleTabBar';
import { useI18n } from '../i18n/I18nContext';

// Stable i18n key for a nav destination, derived from its route so the label
// catalog and the nav table can never drift apart: '/inspections/schedules' →
// 'inspections-schedules', '/' → 'home'.
const navKey = (to) => (to || '/').replace(/^\//, '').replace(/\//g, '-') || 'home';
const sectionKey = (title) => title.toLowerCase().replace(/[^a-z0-9]+/g, '-');

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

const greetKey = (h) => (h < 5 ? 'night' : h < 12 ? 'morning' : h < 17 ? 'afternoon' : h < 22 ? 'evening' : 'night');

// Live clock + time-of-day greeting chip for the header.
function LiveClock({ name }) {
  const { t, lang } = useI18n();
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1000 * 30);
    return () => clearInterval(id);
  }, []);
  // Arabic uses the Gregorian calendar with Arabic month/day names (ar-u-ca-gregory)
  // rather than the default Hijri, so the date still matches every other date in
  // the app; Latin digits keep it aligned with the tabular-nums clock.
  const locale = lang === 'ar' ? 'ar-AE-u-ca-gregory-nu-latn' : 'en-GB';
  const time = now.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
  const date = now.toLocaleDateString(locale, { weekday: 'short', month: 'short', day: 'numeric' });
  const first = (name || '').trim().split(/\s+/)[0];
  return (
    <div className="hidden items-center gap-3 rounded-xl border border-slate-200/70 bg-white/60 px-3 py-1.5 lg:flex">
      <div className="leading-tight">
        <p className="text-[11px] font-semibold text-slate-700">
          {first ? t(`shell.greet.${greetKey(now.getHours())}Named`, { name: first }) : t(`shell.greet.${greetKey(now.getHours())}`)}
        </p>
        <p className="text-[10px] font-medium text-slate-400">{date}</p>
      </div>
      <div className="h-7 w-px bg-slate-200" />
      <p className="font-mono text-sm font-semibold tabular-nums text-slate-600">{time}</p>
    </div>
  );
}

// Navigation for a maintenance-first fleet management system: Maintenance is
// the first major operational block, directly after the Dashboard:
//   Overview → MAINTENANCE (Operations · Control · Intelligence ·
//   Parts & Suppliers · Damage) → Fleet Operations → Records →
//   Rentals & Delivery → Fleet Intelligence → Analytics & Finance → Administration.
// The maintenance sub-areas are contiguous sections ("Maintenance · X") so they
// read as one block in daily-workflow order:
//   Inspection → Diagnosis → Approval → Repair → Invoice → History → Intelligence.
// `desc` powers the per-page info circle (bottom-right "?" button).
const NAV_SECTIONS = [
  {
    title: 'Overview',
    items: [
      { name: 'Workspace', to: '/', icon: 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z', desc: 'Your fleet command center — quick access to every module you can reach, a live operational KPI strip, and an Action Center of what needs attention today.' },
      { name: 'Dashboard', to: '/dashboard', icon: 'M3 12l9-9 9 9M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10', desc: 'Fleet-wide overview: how many cars are available, rented, or in maintenance, plus key totals. Availability and composition come from the OfficeManager lifecycle status.' },
      { name: 'Notifications', to: '/notifications', icon: 'M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 0 0-4-5.7V5a2 2 0 1 0-4 0v.3A6 6 0 0 0 6 11v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0v1a3 3 0 1 1-6 0v-1m6 0H9', desc: 'Live fleet alerts — overdue rentals, maintenance overruns, expiring documents, service-due cars and approvals. The bell in the top bar updates in real time.' },
    ],
  },
  // ── MAINTENANCE — the core operational block. Contiguous sections, in
  // daily-workflow order: run the pipeline → control/sign-off → intelligence →
  // parts & suppliers → damage. Keep these together; don't scatter maintenance
  // pages into other sections.
  {
    title: 'Maintenance Operations',
    items: [
      { name: 'Maintenance Cycle', to: '/maintenance-workflow', icon: 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-3 7-3 3 3 3m6-6 3 3-3 3', desc: 'The live maintenance ticket pipeline (Inspector → Supervisor → Driver → Garage → Re-inspection). Open a ticket and advance it through the stages; the board updates in real time.' },
      { name: 'My Queue', to: '/my-maintenance-queue', icon: 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-3 9 2 2 4-4', desc: 'Your role-scoped maintenance work in one place: Abu Maroof (Inspector) sees pending inspections and final re-inspections; a Supervisor (Dispatcher) sees tickets awaiting a garage + driver assignment; a Driver sees active trips/dispatches and cars waiting on a follow-up.' },
      { name: 'Inspection Review', to: '/inspection-review', icon: 'M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', desc: 'Controllers (Lin & Marwa) review inspection requests before they reach Abu Maroof — approve to send it on, or reject with a reason.' },
      { name: 'Garage Finder', to: '/garage-finder', icon: 'M21 21l-4.35-4.35M11 19a8 8 0 1 1 0-16 8 8 0 0 1 0 16z', desc: 'Pick a car and the faults it has, and get the same fault-by-fault garage report the assign step shows — before any ticket exists. Each fault names its strongest garage, the best alternative, and the trade-off between them, with the repair history behind every figure. Read-only: it answers the question, it does not dispatch the car.' },
      { name: 'Service Reminders', to: '/service-reminders', icon: 'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', desc: 'Every car’s recurring service due points — oil change, filters, brakes, tires, battery — with how far each is from due in km or days. Oil and tire reminders are seeded per car from the Oil Change intervals; add or edit any of them. Notify alerts the drivers and technicians; Schedule opens the maintenance ticket that performs the work.' },
      { name: 'Oil Mileage Follow-up', to: '/oil-projection', icon: 'M12 3v4m0 10v4m9-9h-4M7 12H3m2.6-6.4 2.8 2.8m7.2 7.2 2.8 2.8m0-12.8-2.8 2.8m-7.2 7.2-2.8 2.8', desc: 'Cars that are OUT on rental and heading for their oil limit. Service Reminders reads the odometer we hold, which stops being true the moment a car drives off; this page projects forward from the mileage recorded at handover at 200 km/day and lists the cars that have probably passed the limit. Controllers (Lin & Marwa) call the customer, enter the odometer they report, and the projection re-anchors on that real number — a customer-reported reading is stored against the contract and never changes the car’s odometer.' },
    ],
  },
  // Customer Care — the two intake surfaces that feed the pipeline from outside
  // the workshop: what the customer said (Complaint) and what the driver noticed
  // (Observation). They lived only as Workspace launcher tiles, which made them
  // unreachable for the roles blocked from the launcher (inspector, supervisor,
  // driver) — including Abu Maroof, whose complaint-triage lane they drive.
  {
    title: 'Customer Care',
    items: [
      { name: 'Complaints', to: '/complaints', icon: 'M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0l-7-7A2 2 0 0 1 3 12V5a2 2 0 0 1 2-2h7a2 2 0 0 1 1.4.6l7.2 7.2a2 2 0 0 1 0 2.8zM7.5 7.5h.01', desc: 'Every customer complaint and its follow-up timeline in one management view. Ops logs the complaint here; Abu Maroof triages it from the detail drawer — talk to the customer, resolve it on-site, or send the car in as a maintenance ticket.' },
      { name: 'Driver Observations', to: '/driver-observations', icon: 'M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12zM12 14.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z', desc: 'Internal handover notes from drivers — something they noticed on the car that is not (yet) a customer complaint. Review each note and escalate it into an inspection request when it deserves one.' },
    ],
  },
  {
    title: 'Maintenance Control',
    items: [
      { name: 'Completed Repairs', to: '/completed-repairs', icon: 'M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', desc: 'The ledger of every car whose repair is done and signed off — who requested it, who drove it, where it was fixed, what was found and repaired, and what it cost. Expand any row for the full custody chain, the resolved faults and the odometer readings.' },
      { name: 'Maintenance History', to: '/maintenance-history', icon: 'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', desc: 'Every car that saw the workshop over the chosen window — how often it went in (visits) and how long it spent there (total days in the shop), sortable and searchable. Open a car\'s visit list to see each individual trip: date, garage, what was done and the cost.' },
    ],
  },
  {
    title: 'Maintenance Intelligence',
    items: [
      { name: 'Keyword Risk', to: '/finding-keywords', icon: 'M20.59 13.41 13.42 20.6a2 2 0 0 1-2.83 0l-7-7A2 2 0 0 1 3 12V5a2 2 0 0 1 2-2h7a2 2 0 0 1 1.42.59l7.17 7.17a2 2 0 0 1 0 2.83zM7.5 7.5h.01', desc: 'The fault-keyword library the inspection picker offers, each graded by risk (🔴 critical / 🟡 moderate / 🟢 routine). Add, edit or retire keywords and set how serious each fault type is.' },
      { name: 'Recurring Fault Reviews', to: '/recurring-fault-reviews', icon: 'M3 2v6h6M3 8a9 9 0 1 0 2.6-4.36L3 8', desc: 'Cars that came back with the SAME confirmed fault after a completed repair. Each case shows the previous ticket, garage, parts used, days and distance since the repair, and how many times it recurred — so management can decide whether the earlier repair failed, it is a new failure, workshop responsibility, customer misuse, or needs investigation.' },
      { name: 'Concept Bridge Review', to: '/concept-bridge-review', icon: 'M9 12h6m-3-3v6M5 8V6a2 2 0 0 1 2-2h2M5 16v2a2 2 0 0 0 2 2h2m6-16h2a2 2 0 0 1 2 2v2m-4 12h2a2 2 0 0 0 2-2v-2', desc: 'Teach the system to read workshop language. You are shown one real line from a maintenance note and asked what it means — BEFORE the computer\'s answer is revealed, so your judgement stays independent. Roughly 90 lines, mostly button clicks. The result is the benchmark that decides whether we may translate 26,839 historical tickets into automotive concepts, or need to fix the matching first.' },
    ],
  },
  {
    title: 'Parts & Suppliers',
    items: [
      { name: 'Parts Purchase', to: '/parts', icon: 'M20 7h-9M14 17H5M17 20a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM7 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6z', desc: 'The Parts Purchase + Repair Intelligence board: request a part (customer or garage), approve, buy (garage or supplier) and install it — with duplicate-purchase detection and repair history.' },
      { name: 'Parts Catalog', to: '/parts-catalog', icon: 'M4 6h16M4 12h16M4 18h10M18 15v6m3-3h-6', desc: 'The list of part names the whole app selects from, in English and Arabic. Each part also carries the other names and the problem wording people use for it, so a technician who types "battery not charging" still finds the alternator. Set the default supplier warranty for each part (months and kilometres) here.' },
      { name: 'Garages', to: '/garages', icon: 'M3 9l9-6 9 6v11a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z', desc: 'Garage ratings by repair area — who is strong at what, who has a problem, and what is in each workshop now.' },
      { name: 'Vendors', to: '/vendors', icon: 'M3 9l1-5h16l1 5M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9M3 9h18M9 20v-6h6v6', desc: 'Suppliers and service vendors referenced by maintenance and contracts.' },
    ],
  },
  {
    title: 'Damage Management',
    items: [
      { name: 'Damage & Accidents', to: '/damage-accidents', icon: 'M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z', desc: 'Damage and accident records shown as-is per vehicle. Fault is colored red/green based on the liable party and insurance.' },
    ],
  },
  {
    title: 'Fleet Operations',
    items: [
      { name: 'Driver Dispatch', to: '/driver-dispatch', icon: 'M3 7h11v8H3zM14 10h3.5L21 13v2h-7M6.5 18.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM17.5 18.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z', desc: 'Driver Dispatch: send a vehicle between locations (to Deals on Wheels, the garage, …) and track which driver has it and where. Dispatching a car flips it to “In Transit to …” on the grid and drops an Action Required task into the assignee’s My Queue.' },
      { name: 'Fleet Health', to: '/inspections/schedules', icon: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM9 15l2 2 4-4', desc: 'The fleet\'s neural center — a unified hub with tabs for Service Due (odometer-based) and Registration & Insurance expiry, consolidating the live per-car health surfaces in one place.' },
      { name: 'Car Status', to: '/car-status', icon: 'M5 17h14M5 17a2 2 0 0 1-2-2v-3l2-5a2 2 0 0 1 2-1.4h8A2 2 0 0 1 19 7l2 5v3a2 2 0 0 1-2 2M7 17v1a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-1m14 0v1a1 1 0 0 1-1 1h0a1 1 0 0 1-1-1v-1M9 12l2 2 4-4', desc: 'The live stage board — every car in the maintenance workflow laid out by the exact stage it\'s in right now (Needs Test Drive → Being Inspected → Needs Dispatch → Awaiting Pickup → En Route → In Workshop → Ready for Pickup → Final QA), each showing who is responsible for it at that stage: the inspector, the supervisor who must dispatch, the driver who holds the car, or the garage. A stage reads “Waiting” until someone takes it, then shows their name. Click any car to open its ticket.' },
      // Component Intelligence (/components) is held back as "Coming Soon" — see the
      // module registry. Kept out of the sidebar so it is never a link to a redirect.
    ],
  },
  {
    title: 'Records',
    items: [
      { name: 'Vehicles', to: '/vehicles', icon: 'M5 17h14M5 17a2 2 0 0 1-2-2v-3l2-5a2 2 0 0 1 2-1.4h8A2 2 0 0 1 19 7l2 5v3a2 2 0 0 1-2 2M7 17v1a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-1m14 0v1a1 1 0 0 1-1 1h0a1 1 0 0 1-1-1v-1M7 12h10', desc: 'Every car in the fleet. The OfficeManager API is the sole source of which cars exist; the sheet only enriches matched cars. Click a row to open its full profile.' },
      { name: 'Customers', to: '/customers', icon: 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM3 21v-1a6 6 0 0 1 6-6h6a6 6 0 0 1 6 6v1', desc: 'All customers with their contact details and available wallet (carried-forward credit). Open a customer to see their contracts and balance history.' },
      { name: 'Contracts', to: '/contracts', icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2z', desc: 'Rental contracts synced from OfficeManager — all open contracts plus the last 3 months of closed ones. Open or closed status is detected on each sync.' },
      { name: 'Drivers', to: '/drivers', icon: 'M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM6 21v-1a6 6 0 0 1 6-6 6 6 0 0 1 6 6v1M3 9l2 2 3-3', desc: 'Fleet drivers with their licence number, expiry and status. Add, edit or suspend drivers; expiring licences are flagged.' },
      { name: 'Odometer Approvals', to: '/odometer-approvals', icon: 'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', desc: 'Review queue for significant manual odometer edits (more than 10 km from the car\'s current reading) — each carries the editor\'s reason note and the car\'s workflow stage at the time. Approve to apply the new reading, or reject to leave it untouched.' },
    ],
  },
  {
    // Phase-1 Fleet Intelligence layer — gated by SHOW_FLEET_INTELLIGENCE (items marked `intel`),
    // so the whole section ships dark until the flag is flipped, independently of SHOW_FINANCIALS.
    title: 'Fleet Intelligence',
    items: [
      { name: 'Cost Intelligence', to: '/cost-intelligence', intel: true, icon: 'M3 12h4l2-7 4 14 2-7h4', desc: 'Maintenance cost per kilometre, per day and per rental for every car — the true running cost of each asset. Numerator is the same logged repair spend as the Profit Bridge; denominators are validated distance, in-service days and rental count. Cars with no measured distance show “—”, never a misleading zero.' },
      { name: 'Recommendation Intelligence', to: '/recommendation-intelligence', intel: true, icon: 'M9 12l2 2 4-4M12 3l7 4v5c0 4.4-3 8.3-7 9-4-.7-7-4.6-7-9V7z', desc: 'Whether supervisors actually take the garage recommendation, and what they overrule it for. Reports the engine-judgeable acceptance rate SEPARATELY from the raw one — an override because a customer asked for a specific workshop is not a model failure, and blending the two produces a number that worsens the better the operation serves its customers. Weight suggestions stay hidden until enough decisions exist to mean anything.' },
      // Reuses the existing utilization board (/fleet-utilization) — no rebuild. When the intelligence
      // layer is on it lives here; when off it stays in Analytics & Admin (see hideWhenIntel below).
      { name: 'Fleet Utilization', to: '/fleet-utilization', intel: true, icon: 'M3 3v18h18M7 15l3-3 3 3 5-5M8 21V9m4 12V5m4 16v-7', desc: 'Per-car split of owned time into rented, in-maintenance, and idle days — utilization and downtime % against how long you have owned each car, with rent lost to downtime.' },
    ],
  },
  {
    title: 'Analytics & Finance',
    items: [
      { name: 'Fleet Utilization', to: '/fleet-utilization', hideWhenIntel: true, icon: 'M3 3v18h18M7 15l3-3 3 3 5-5M8 21V9m4 12V5m4 16v-7', desc: 'Per-car split of owned time into rented, in-maintenance, and idle days — utilization and downtime % against how long you have owned each car, with rent lost to downtime. Filter by period (e.g. last month) and sort to find the cars stuck in the workshop.' },
      { name: 'Fuel & Mileage', to: '/mileage', icon: 'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', desc: 'One home for every odometer/fuel tool, in three tabs: Fuel & Mileage (real travel vs. contract km, off-contract leakage, fuel debits), Reconciliation (stored odometer vs. the scanner baseline, adopt with one click) and Chain Audit (contract-to-contract odometer handoffs with a non-destructive Quick Fix).' },
      { name: 'Maintenance Swap', to: '/maintenance-swap', icon: 'M4 5h16M4 12h16M4 19h16M9 5v14', desc: 'Live triage of the fleet in three columns — Action Required / In Workshop / Available Pool — with a Swap & Renew engine that keeps a customer on the road while their car is repaired.' },
      { name: 'Data Health', to: '/data-health', icon: 'M3 12h4l2 5 4-12 2 7h6', desc: 'Overall data quality in two tabs: Data Quality (incomplete/broken records — missing VINs, mileage, unlinked contracts) and Status Mismatches (cars whose status disagrees with their contracts).' },
      { name: 'Intelligence Center', to: '/intelligence-center', icon: 'M12 3a4 4 0 0 1 4 4v1a4 4 0 0 1 0 8v1a4 4 0 0 1-8 0v-1a4 4 0 0 1 0-8V7a4 4 0 0 1 4-4z', desc: 'What the platform actually knows and how sure it is: evidence readiness and health for every intelligence capability, QC verdict throughput, promotion decisions with full provenance, feature-flag status, background-job health, and the data-quality problems blocking each capability.' },
      { name: 'Sync Audit', to: '/sync-audit', icon: 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-6 9 2 2 4-4', desc: 'Read-only history of CMD sync runs: how many contracts each execution scanned, updated, and auto-corrected (e.g. stale dates cleared).' },
      { name: 'Simulation', to: '/simulation', demoOnly: true, icon: 'M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z', desc: 'Admin-only demo console (only shown in Demo Mode): force a real "Service Due" oil alert or a fault-discovery ticket on a real car, watch the system react end-to-end, then roll it all back with one click.' },
    ],
  },
  {
    title: 'Administration',
    items: [
      { name: 'Users', to: '/users', icon: 'M16 5.5a3 3 0 0 1 0 5.8M3 20a6 6 0 0 1 12 0M9 8.2a3.2 3.2 0 1 1 0-6.4 3.2 3.2 0 0 1 0 6.4zM21 20a6 6 0 0 0-4-5.6', desc: 'Every account, its status and Spatie role(s) — admin-only.' },
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
  '/dashboard': 'dashboard.view',
  '/notifications': null,
  '/settings': null,
  '/completed-repairs': 'maintenance.view',
  '/complaints': 'maintenance.view',
  '/driver-observations': 'maintenance.view',
  '/maintenance-history': 'dashboard.view',
  '/finding-keywords': 'maintenance.view',
  '/parts': 'parts.view',
  '/parts-catalog': 'parts.view',
  '/recurring-fault-reviews': 'maintenance.recurring.view',
  '/vehicles': 'vehicles.view',
  '/odometer-approvals': 'vehicles.approve_odometer',
  '/driver-dispatch': 'logistics.view',
  '/customers': 'customers.view',
  '/drivers': 'drivers.view',
  '/contracts': 'contracts.view',
  '/inspections/schedules': 'inspections.view',
  '/reminders/service': 'reminders.view',
  '/service-reminders': 'reminders.view',
  '/oil-projection': 'reminders.view',
  '/registrations': 'registration.view',
  '/maintenance': 'maintenance.view',
  '/maintenance-hub': 'maintenance.view',
  '/car-status': 'maintenance.view',
  '/components': 'components.view',
  '/maintenance-workflow': 'maintenance.view',
  '/my-maintenance-queue': 'maintenance.view',
  '/cost-capture': 'maintenance.manage',
  '/inspection-review': 'maintenance.manage',
  '/garages': 'maintenance.view',
  '/vendors': 'vendors.view',
  '/maintenance-analytics': 'maintenance.view',
  '/damage-accidents': 'maintenance.view',
  '/cost-intelligence': 'insights.view',
  '/recommendation-intelligence': 'insights.view',
  '/mileage': 'insights.view',
  '/fleet-utilization': 'insights.view',
  '/maintenance-swap': 'insights.view',
  '/data-health': 'insights.view',
  '/intelligence-center': 'insights.view',
  '/oversight/mileage': 'insights.view',
  '/oversight/left-garage': 'insights.view',
  '/oversight/severity': 'insights.view',
  '/oversight/misdiagnoses': 'insights.view',
  '/oversight/checkpoint-compliance': 'insights.view',
  '/sync-audit': 'sync.run',
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
  const { t } = useI18n();
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
      title={t('shell.backToTop')}
      aria-label={t('shell.backToTop')}
    >
      <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 19V5M5 12l7-7 7 7" /></svg>
    </button>
  );
}

export default function AppLayout() {
  const { user, logout } = useAuth();
  const { can, roles } = usePermissions();
  const { t, tf } = useI18n();
  const navigate = useNavigate();
  const location = useLocation();

  // Report presence + module usage to the backend (Workforce Operations Center).
  useActivityTracker(resolveModuleLabel);

  // Hide nav items the user can't open, and drop sections left empty. While
  // SHOW_FINANCIALS is off (Financial Decoupling), the money-rollup destinations
  // (Cost Analytics, Net Profit) are also hidden so no financial page is
  // reachable from the UI.
  const navVisible = (i) =>
    can(NAV_PERMISSIONS[i.to]) &&
    !pathBlockedForRoles(i.to, roles) &&
    (SHOW_FINANCIALS || !i.financial) &&
    (SHOW_FLEET_INTELLIGENCE || !i.intel) &&
    (!i.hideWhenIntel || !SHOW_FLEET_INTELLIGENCE) &&
    (!i.demoOnly || DEMO_MODE);
  // Feeds the ⌘K command palette — the sidebar is gone, so search + the launcher
  // (/) + the per-module tab bar are the navigation surfaces. Names, sections and
  // descriptions resolve through the label catalog so the palette is searchable
  // in whichever language is active.
  const visibleSearch = SEARCH_ITEMS.filter(navVisible).map((i) => ({
    ...i,
    name: tf(`nav.items.${navKey(i.to)}.name`, i.name),
    desc: tf(`nav.items.${navKey(i.to)}.desc`, i.desc),
    section: tf(`nav.sections.${sectionKey(i.section)}`, i.section),
  }));

  const [cmdOpen, setCmdOpen] = useState(false);
  const [helpOpen, setHelpOpen] = useState(false);
  // Recently visited pages (paths), most-recent first — feeds the command palette.
  const [recents, setRecents] = useState(() => {
    try { return JSON.parse(localStorage.getItem('fv:recents') || '[]'); } catch { return []; }
  });
  const lastG = useRef(0); // timestamp of the last "g" press, for g+key chords

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

  // Module-first shell: the launcher (/) is the home surface; every other page
  // that belongs to a module shows that module's persistent tab bar under the
  // header. There is no sidebar — navigation is the launcher, the module tab
  // bar, ⌘K search, and the header Back / Home buttons.
  const isLauncher = location.pathname === '/';
  const activeModule = moduleForPath(location.pathname);

  // Where "Home" actually goes. The launcher (/) is Dashboard-gated, so the
  // operational roles walled off from it (supervisor, inspector, driver) would
  // hit the Forbidden wall on every Home click. homePathForRoles resolves each
  // of them to the board they actually work out of instead — the supervisor and
  // the inspector to My Queue, the driver to Driver Dispatch. Everyone else
  // still gets '/'. The button is hidden when Home IS the current page, so it
  // never renders as a no-op.
  const homePath = homePathForRoles(roles);
  const atHome = location.pathname === homePath;

  const initial = (user?.name || '?').charAt(0).toUpperCase();
  // Note: resolveModuleLabel above deliberately stays English — it is reported to
  // the backend activity tracker as data, not shown to this user.
  const pageName = current ? tf(`nav.items.${navKey(current.to)}.name`, current.name) : undefined;

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

      {/* Full-width shell — no sidebar. The launcher (/) is home; the header
          Back/Home buttons + the module tab bar + ⌘K search carry navigation. */}
      <div>
        <header className="glass sticky top-0 z-20 flex h-16 items-center gap-2 border-b border-slate-200/70 px-4 sm:gap-4 sm:px-6 lg:px-8">
          {isLauncher ? (
            // Home surface — show the full brand lockup, no nav buttons needed.
            <div className="flex min-w-0 flex-1 items-center">
              <Brand />
            </div>
          ) : (
            // Inner page — a single compact nav cluster (Back · Home) followed by
            // the page title. The module bar below carries the module identity, so
            // the header stays clean and un-duplicated.
            <div className="flex min-w-0 flex-1 items-center gap-2 sm:gap-3">
              <div className="flex shrink-0 items-center gap-1 rounded-xl border border-slate-200 bg-white p-0.5 shadow-sm">
                <button
                  onClick={() => navigate(-1)}
                  className="flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900"
                  title={t('shell.backHint')}
                  aria-label={t('shell.backHint')}
                >
                  {/* Chevron mirrors with the document direction so "back" always points
                      away from the reading direction. */}
                  <svg className="h-4 w-4 rtl:-scale-x-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M15 18l-6-6 6-6" />
                  </svg>
                  <span className="hidden sm:inline">{t('shell.back')}</span>
                </button>
                {!atHome && (
                  <>
                    <span className="h-5 w-px bg-slate-200" />
                    <button
                      onClick={() => navigate(homePath)}
                      className="flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900"
                      title={t('shell.homeHint')}
                      aria-label={t('shell.homeHint')}
                    >
                      <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M3 12l9-9 9 9M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10" />
                      </svg>
                      <span className="hidden sm:inline">{t('shell.home')}</span>
                    </button>
                  </>
                )}
              </div>
              <span className="hidden h-6 w-px bg-slate-200 sm:block" />
              <h2 className="truncate text-[15px] font-semibold text-slate-800">{pageName || 'Faster'}</h2>
            </div>
          )}

          <div className="flex items-center gap-1.5 sm:gap-3">
            <LiveClock name={user?.name} />

            <LanguageToggle />

            <ThemeToggle />

            <button
              onClick={() => setHelpOpen(true)}
              className="hidden h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-400 shadow-sm transition hover:text-slate-600 hover:ring-1 hover:ring-slate-200 lg:inline-flex"
              title={t('shell.shortcutsHint')}
              aria-label={t('shell.shortcuts')}
            >
              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8"><path strokeLinecap="round" strokeLinejoin="round" d="M9.1 9a3 3 0 1 1 5.8 1c0 2-3 3-3 3M12 17h.01" /></svg>
            </button>

            <button
              onClick={() => setCmdOpen(true)}
              className="hidden items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-400 shadow-sm transition hover:text-slate-600 hover:ring-1 hover:ring-slate-200 sm:flex"
              title={t('shell.searchHint')}
            >
              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8"><path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-4.3-4.3M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16z" /></svg>
              {t('shell.search')}
              <kbd className="rounded border border-slate-200 bg-slate-50 px-1 text-[10px] font-semibold text-slate-400">⌘K</kbd>
            </button>

            <NotificationBell />

            {/* subtle divider between actions and the user identity block */}
            <span className="hidden h-6 w-px bg-slate-200 lg:block" />

            <div className="hidden text-end lg:block">
              <p className="text-sm font-semibold leading-tight text-slate-900">{user?.name}</p>
              <p className="text-xs text-slate-500">{user?.email}</p>
            </div>
            <div className="flex h-9 w-9 items-center justify-center rounded-full bg-indigo-600 text-sm font-semibold text-white shadow-sm ring-2 ring-white">
              {initial}
            </div>
            <button
              onClick={handleLogout}
              className="rounded-lg p-2 text-slate-400 transition hover:bg-red-50 hover:text-red-600"
              title={t('shell.logout')}
              aria-label={t('shell.logout')}
            >
              <svg className="h-5 w-5 rtl:-scale-x-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
                <path d="M16 17l5-5-5-5M21 12H9M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
              </svg>
            </button>
          </div>
        </header>

        {/* Persistent module tab bar — the primary in-app navigation. Appears on
            the module Overview and every section page that belongs to a module. */}
        {activeModule && <ModuleTabBar module={activeModule} />}

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
        actions={QUICK_ACTIONS.filter((a) => can(QUICK_ACTION_PERMISSIONS[a.id])).map((a) => ({
          ...a,
          label: tf(`shell.quickActions.${a.id}`, a.label),
          // Keep the English keywords appended so ⌘K still matches typed English
          // even while the UI is in Arabic.
          keywords: `${a.keywords} ${a.label} ${tf(`shell.quickActionKeywords.${a.id}`, '')}`,
          run: () => runAction(a),
        }))}
        recents={recents}
      />
      <ShortcutsHelp open={helpOpen} onClose={() => setHelpOpen(false)} />
      <PageStatGauge />
      <ScrollTop />
    </div>
    </PageStatProvider>
  );
}
