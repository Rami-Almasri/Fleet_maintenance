import { BrowserRouter, Routes, Route, Navigate, useLocation, useParams } from 'react-router-dom';
import { ThemeProvider } from './theme/ThemeContext';
import { I18nProvider } from './i18n/I18nContext';
import { AuthProvider } from './auth/AuthContext';
import { ToastProvider } from './components/ui/Toast';
import { NotificationsProvider } from './hooks/useNotifications';
import ProtectedRoute from './components/ProtectedRoute';
import RequirePermission from './components/RequirePermission';
import { usePermissions } from './hooks/usePermissions';
import { pathBlockedForRoles, homePathForRoles } from './config/access';
import AppLayout from './layouts/AppLayout';
import Login from './pages/Login';
import ModuleLauncher from './pages/ModuleLauncher';
import ModuleOverview from './pages/ModuleOverview';
import Dashboard from './pages/Dashboard';
// Vehicles is a hub now: the fleet registry plus the two repair ledgers that used to be the
// Repair Records page. App.js mounts the hub; the registry itself is only reachable as its tab.
import VehiclesHub from './pages/VehiclesHub';
import VehicleProfile from './pages/vehicles/VehicleProfile';
import OdometerApprovals from './pages/vehicles/OdometerApprovals';
import Customers from './pages/Customers';
import CustomerProfile from './pages/customers/CustomerProfile';
import Drivers from './pages/Drivers';
import Contracts from './pages/Contracts';
import ContractDetail from './pages/contracts/ContractDetail';
import ContractForm from './pages/contracts/ContractForm';
import MaintenanceWorkflow from './pages/MaintenanceWorkflow';
// ComponentsDashboard is held back as "Coming Soon" — the page file stays in the
// repo; re-import it here when /components is switched back on.
import MyMaintenanceQueue from './pages/MyMaintenanceQueue';
// Control Desk — the Controllers' hub. It owns the five pages that used to be their own routes
// (Inspection Review, Oil Follow-up, Invoice Matching, Keyword Risk, Vehicle Locations), so App.js no
// longer mounts them directly; the old paths redirect into their section below.
import ControlDesk from './pages/ControlDesk';
import ComplaintsCenter from './pages/ComplaintsCenter';
import MaintenanceSwap from './pages/MaintenanceSwap';
import LogisticsDispatch from './pages/LogisticsDispatch';
import QuickCostInput from './pages/QuickCostInput';
import GarageProfile from './pages/intelligence/GarageProfile';
import GarageCompare from './pages/intelligence/GarageCompare';
import Executive from './pages/intelligence/Executive';
import PartsHub from './pages/PartsHub';
import WarrantyCases from './pages/WarrantyCases';
import SuppliersHub from './pages/SuppliersHub';
import GaragesHub from './pages/GaragesHub';
import FieldReportsHub from './pages/FieldReportsHub';
import RecurringFaultReviews from './pages/RecurringFaultReviews';
import DamageAccidents from './pages/DamageAccidents';
import AccidentCases from './pages/AccidentCases';
import RecommendationIntelligence from './pages/RecommendationIntelligence';
import DailyMaintenanceIntelligence from './pages/reports/DailyMaintenanceIntelligence';
import VehicleSystemDashboard from './pages/reports/VehicleSystemDashboard';
import VehicleReport from './pages/reports/VehicleReport';
import EventClassificationReview from './pages/EventClassificationReview';
import ConceptBridgeReview from './pages/ConceptBridgeReview';
import MileageCenter from './pages/MileageCenter';
import DataHealth from './pages/DataHealth';
import Notifications from './pages/Notifications';
import Settings from './pages/Settings';
import OdooMappings from './pages/OdooMappings';
import SimulationPanel from './pages/SimulationPanel';
import Users from './pages/Users';
import NotFound from './pages/NotFound';
import GarageInvoicePortal from './pages/GarageInvoicePortal';
import OversightHub from './pages/oversight/OversightHub';
import CleaningCapture from './pages/cleaning/CleaningCapture';
import FleetHealth from './pages/inspections/FleetHealth';

// Pages that became a tab on a hub keep their old URL working through this. The incoming query is
// carried over — /part-invoices?invoice=8 has to arrive as /parts?tab=invoices&invoice=8 or the
// deep link from the contract page would open the ledger without selecting the invoice. A plain
// <Navigate to="/parts?tab=invoices"> would silently drop it.
function RedirectToTab({ to, tab }) {
  const { search } = useLocation();
  const params = new URLSearchParams(search);
  params.set('tab', tab);
  return <Navigate to={`${to}?${params}`} replace />;
}

// The retired /maintenance-progress queue. A checkpoint reminder carried ?ticket=<id>; that ticket's
// own page has the same checkpoint panel and "File update" button, and unlike the Dashboard it is
// reachable by the supervisors the reminder is sent to. With no ticket id, the board is the queue.
function RedirectCheckpoint() {
  const { search } = useLocation();
  const ticket = new URLSearchParams(search).get('ticket');
  return <Navigate to={ticket ? `/maintenance-workflow/${ticket}` : '/maintenance-workflow'} replace />;
}

// Redirect a retired per-record route onto its surviving one, carrying the record id and the query
// string across (e.g. /car-status/628 → /vehicles/628). `param` is the route param to substitute.
function RedirectToRecord({ to, param }) {
  const { search } = useLocation();
  const params = useParams();
  return <Navigate to={`${to}/${params[param]}${search}`} replace />;
}

// Index route ("/"). Normally the Workspace command center, but roles blocked
// from the dashboard (the driver / supervisor) keep their existing dedicated
// landing pages — they are redirected to a home they can actually see instead of
// hitting the inline "Forbidden" notice on every fresh load. So only roles that
// used to land on the Dashboard now land on the Workspace; specialised workflows
// are unchanged. See config/access.js. The classic Dashboard lives on at
// /dashboard for anyone with dashboard.view.
function HomeGate() {
  const { can, roles } = usePermissions();
  if (pathBlockedForRoles('/', roles)) {
    return <Navigate to={homePathForRoles(roles)} replace />;
  }
  if (!can('dashboard.view')) {
    return <Navigate to="/notifications" replace />;
  }
  return <ModuleLauncher />;
}

export default function App() {
  return (
    <ThemeProvider>
    <I18nProvider>
    <AuthProvider>
      <ToastProvider>
        <NotificationsProvider>
        <BrowserRouter>
          <Routes>
            <Route path="/login" element={<Login />} />
            {/* Public, token-gated garage invoice portal — no login (a garage opens it from a link). */}
            <Route path="/garage-invoice/:token" element={<GarageInvoicePortal />} />

            {/* Authenticated area. ProtectedRoute enforces login; the nested
                RequirePermission groups enforce per-page permissions, mirroring
                the backend `permission:` middleware in routes/api.php. */}
            <Route element={<ProtectedRoute />}>
              <Route element={<AppLayout />}>
                {/* Always available to any authenticated user */}
                <Route path="/notifications" element={<Notifications />} />
                <Route path="/settings" element={<Settings />} />
                {/* Module Overview (Odoo-style mini-app home). Self-guards: redirects
                    to the launcher if the user can't reach the module. */}
                <Route path="/apps/:moduleId" element={<ModuleOverview />} />
                {/* Control Desk — the Controllers' five jobs behind one sidebar. Deliberately NOT
                    wrapped in a RequirePermission: its sections carry three different permissions
                    (maintenance.manage / reminders.view / maintenance.view), so a single route gate
                    would lock out someone who legitimately holds only one of them. SidebarHub filters
                    section by section instead — each declaring the route it replaced, so the role
                    deny list still applies — and says "no access" if nothing is left. The five old
                    paths below keep their own gates, so a direct URL is refused exactly as before. */}
                <Route path="/control-desk" element={<ControlDesk />} />
                {/* Vehicle Readiness board retired — send the old path to the Fleet Health hub. */}
                <Route path="/readiness" element={<Navigate to="/inspections/schedules" replace />} />

                {/* Event Type layer — human-in-the-loop classification review (needs_review queue). */}
                <Route element={<RequirePermission permission="maintenance.manage" />}>
                  <Route path="/classification-review" element={<EventClassificationReview />} />
                  {/* Concept Bridge benchmark — the human ground truth that gates legacy concept enrichment. */}
                  <Route path="/concept-bridge-review" element={<ConceptBridgeReview />} />
                </Route>

                {/* Odoo mappings — what each FleetView record IS in the accounting system. Gated on
                    financial.view to READ (a person fixing a blocked cost needs to see that a supplier
                    is unmapped); the page itself hides every edit control without
                    financial.manage_mappings, and the routes refuse it regardless. */}
                <Route element={<RequirePermission permission="financial.view" />}>
                  <Route path="/odoo-mappings" element={<OdooMappings />} />
                </Route>

                <Route element={<RequirePermission permission="users.manage" />}>
                  {/* Admin-only Simulation Panel — force a live demo scenario, gated again server-side by demo_mode. */}
                  <Route path="/simulation" element={<SimulationPanel />} />
                  {/* Admin-only account directory — every user, their status and role(s). */}
                  <Route path="/users" element={<Users />} />
                </Route>

                {/* Index route handles its own gating (redirects roles blocked from the Dashboard). */}
                <Route path="/" element={<HomeGate />} />

                {/* Classic fleet Dashboard — no longer the landing page (the Workspace is),
                    but preserved verbatim at its own path for anyone with dashboard.view. */}
                <Route element={<RequirePermission permission="dashboard.view" />}>
                  <Route path="/dashboard" element={<Dashboard />} />
                </Route>

                {/* Vehicles — the fleet registry plus the two repair ledgers, one tab strip.
                    Deliberately NOT wrapped in a RequirePermission: its tabs carry two different
                    permissions (vehicles.view for the registry, maintenance.view for the ledgers)
                    and three different deny rules, so a single route gate would lock out someone who
                    can legitimately open one of them. TabbedHub filters tab by tab instead — each
                    declaring the route it replaced, so those deny rules still apply — and says
                    "no access" if nothing is left. A car's own profile is NOT a tab of the hub, so
                    it keeps its own gate. */}
                <Route path="/vehicles" element={<VehiclesHub />} />
                <Route element={<RequirePermission permission="vehicles.view" />}>
                  <Route path="/vehicles/:id" element={<VehicleProfile />} />
                </Route>

                <Route element={<RequirePermission permission="vehicles.approve_odometer" />}>
                  <Route path="/odometer-approvals" element={<OdometerApprovals />} />
                </Route>

                <Route element={<RequirePermission permission="logistics.view" />}>
                  {/* "Drivers" dispatch board. Route renamed from /logistics → /driver-dispatch
                      (the bare /drivers route is the fleet-driver records page). Old links redirect. */}
                  <Route path="/driver-dispatch" element={<LogisticsDispatch />} />
                  <Route path="/logistics" element={<Navigate to="/driver-dispatch" replace />} />
                </Route>

                <Route element={<RequirePermission permission="customers.view" />}>
                  <Route path="/customers" element={<Customers />} />
                  <Route path="/customers/:id" element={<CustomerProfile />} />
                </Route>

                <Route element={<RequirePermission permission="drivers.view" />}>
                  <Route path="/drivers" element={<Drivers />} />
                </Route>

                <Route element={<RequirePermission permission="booking_readiness.view" />}>
                  {/* Cleaning — before/after photo capture behind the readiness Cleaning "Fix" link. */}
                  <Route path="/cleaning" element={<CleaningCapture />} />
                </Route>

                <Route element={<RequirePermission permission="contracts.view" />}>
                  <Route path="/contracts" element={<Contracts />} />
                  <Route path="/contracts/new" element={<ContractForm />} />
                  <Route path="/contracts/:id/edit" element={<ContractForm />} />
                  <Route path="/contracts/:id" element={<ContractDetail />} />
                </Route>

                <Route element={<RequirePermission permission="inspections.view" />}>
                  {/* Unified "Fleet Health" hub — tabs for Readiness / Service Due / Registrations. */}
                  <Route path="/inspections/schedules" element={<FleetHealth />} />
                </Route>

                <Route element={<RequirePermission permission="reminders.view" />}>
                  {/* Oil Mileage Follow-up — the mid-rental half of the oil story: cars already out
                      whose projected mileage is nearing the oil limit, and the customer-reported
                      readings that re-anchor the projection. */}
                  <Route path="/oil-projection" element={<RedirectToTab to="/control-desk" tab="oil" />} />
                  {/* The standalone Service Reminders board is retired — reminder management now lives
                      only as the Service Reminders tab of the Fleet Health hub. The auto-seeder,
                      notification scanner and ticket roll-forward are untouched; this was purely a
                      surface consolidation, so old paths deep-link straight into the tab. */}
                  <Route path="/service-reminders" element={<Navigate to="/inspections/schedules?tab=service" replace />} />
                  <Route path="/reminders/service" element={<Navigate to="/inspections/schedules?tab=service" replace />} />
                </Route>

                {/* Registrations now lives inside the Fleet Health hub — keep the old path working
                    (deep-links / bookmarks) by redirecting into its tab. */}
                <Route element={<RequirePermission permission="registration.view" />}>
                  <Route path="/registrations" element={<Navigate to="/inspections/schedules?tab=registrations" replace />} />
                </Route>

                {/* The supplier register is the second tab of /suppliers now. The redirect keeps its
                    own vendors.view gate, so a direct /vendors link still fails the same way it did. */}
                <Route element={<RequirePermission permission="vendors.view" />}>
                  <Route path="/vendors" element={<RedirectToTab to="/suppliers" tab="register" />} />
                </Route>

                {/* Component Intelligence — the fleet-wide asset layer: warranty exposure, expected
                    service life, replacement churn and installed value. Read-only: a component only
                    ever reaches this data by the maintenance workflow installing it.
                    Marked "Coming Soon" in the module registry — redirect the URL so the page
                    isn't reachable directly while it is held back. The per-vehicle Installed
                    Components tab on each car's profile is unaffected. */}
                <Route element={<RequirePermission permission="components.view" />}>
                  <Route path="/components" element={<Navigate to="/apps/maintenance" replace />} />
                </Route>

                <Route element={<RequirePermission permission="maintenance.view" />}>
                  {/* Suppliers — who we buy from and what we owe them, in two tabs. Procurement is the
                      "what do we owe?" ledger: reading it is a maintenance.view question, recording a
                      payment is gated to maintenance.manage on the API, so a viewer sees the reports
                      without the actions. The supplier register tab carries its own vendors.view check
                      inside the hub. Both old URLs redirect into their tab. */}
                  <Route path="/suppliers" element={<SuppliersHub />} />
                  <Route path="/procurement" element={<RedirectToTab to="/suppliers" tab="owed" />} />
                  {/* Garage Finder — "this car has this fault; who is best at it?", asked BEFORE a ticket
                      exists. Same engine as the assign step, read-only: it answers, it does not dispatch. */}
                  <Route path="/garage-finder" element={<RedirectToTab to="/garages" tab="finder" />} />
                  {/* Car Status retired. The stage board's three charts (pipeline by stage, longest in the
                      workshop, who's holding the work) moved onto the Dashboard, and the per-vehicle
                      operational profile IS the vehicle profile. Old links keep working. */}
                  {/* The board, not the Dashboard: Car Status WAS the stage board, and the supervisor /
                      driver roles that lived on it are denied /dashboard (config/access.js). */}
                  <Route path="/car-status" element={<Navigate to="/maintenance-workflow" replace />} />
                  <Route path="/car-status/:vehicleId" element={<RedirectToRecord to="/vehicles" param="vehicleId" />} />
                  {/* Management reports — the day's workshop file read back as a document, and one car's
                      whole history on one system (engine, brakes, …). Both render on their own fixed dark
                      surface and print straight to PDF, because they are made to be read on the office wall
                      screen and sent on. Reads only, over the same log the boards read. */}
                  <Route path="/reports/daily-maintenance" element={<DailyMaintenanceIntelligence />} />
                  {/* One car, everything wrong with it: the ranked problem list, the contracts and
                      garages behind each fault, and the whole record underneath. This is the entry
                      point; the per-system page below is its deep dive. */}
                  <Route path="/reports/vehicle/:vehicleId" element={<VehicleReport />} />
                  <Route path="/reports/vehicle-system/:vehicleId" element={<VehicleSystemDashboard />} />
                  {/* Old Maintenance Operations control center — the Dashboard now carries the pipeline. */}
                  <Route path="/maintenance-operations" element={<Navigate to="/maintenance-workflow" replace />} />
                  {/* Old Maintenance Board retired — the Workflow board is now the single maintenance hub. */}
                  <Route path="/maintenance" element={<Navigate to="/maintenance-workflow" replace />} />
                  {/* Old Workflow Hub — retired; point at the live Workflow board. */}
                  <Route path="/maintenance-hub" element={<Navigate to="/maintenance-workflow" replace />} />
                  {/* "Booked in Shop" now lives inside the Fleet Health hub — redirect the old path. */}
                  <Route path="/maintenance-bookings" element={<Navigate to="/inspections/schedules?tab=bookings" replace />} />
                  <Route path="/maintenance-workflow" element={<MaintenanceWorkflow />} />
                  {/* In the Garage — which cars are at a garage right now, and which garage each is at.
                      A tab of /garages now. */}
                  <Route path="/in-garage" element={<RedirectToTab to="/garages" tab="now" />} />
                  {/* What people report — complaints and driver observations, two tabs. */}
                  <Route path="/field-reports" element={<FieldReportsHub />} />
                  <Route path="/complaints" element={<RedirectToTab to="/field-reports" tab="complaints" />} />
                  {/* Deep link from a complaint notification: opens that complaint's drawer over the
                      Center. Stays a route of its own — the drawer needs the id in the path. */}
                  <Route path="/complaints/:id" element={<ComplaintsCenter />} />
                  {/* Driver Observations — lightweight handover notes; may raise an inspection request. */}
                  <Route path="/driver-observations" element={<RedirectToTab to="/field-reports" tab="observations" />} />
                  {/* Deep link from notifications: focuses one ticket on the board */}
                  <Route path="/maintenance-workflow/:id" element={<MaintenanceWorkflow />} />
                  {/* Pre-maintenance Recommendation queue — Supervisor triage before the active board */}
                  <Route path="/my-maintenance-queue" element={<MyMaintenanceQueue />} />
                  {/* Maintenance Progress retired — Proactive Flags on the Dashboard IS the checkpoint
                      queue now: every car in the shop, how it's tracking against its ETA, and the form to
                      file the update.
                      A ?ticket=<id> deep-link (an old checkpoint reminder) goes to THAT TICKET, not to the
                      Dashboard: the supervisors who file checkpoints are denied /dashboard in
                      config/access.js, and the ticket carries the same checkpoint form. Without the
                      ticket id there is nothing to open, so it falls back to the board. */}
                  <Route path="/maintenance-progress" element={<RedirectCheckpoint />} />
                  {/* Repair Records is retired — the signed-off ledger and each car's workshop
                      history are tabs of the Vehicles hub now, because both answer a question about
                      the CARS and asking it used to mean leaving the car list. */}
                  <Route path="/repair-records" element={<RedirectToTab to="/vehicles" tab="signed-off" />} />
                  {/* Fixed & Completed Repairs ledger — every closed ticket with its full story */}
                  <Route path="/completed-repairs" element={<RedirectToTab to="/vehicles" tab="signed-off" />} />
                  {/* Invoice Matching — the car is back: key each garage's bill beside the work it covers */}
                  <Route path="/invoice-matching" element={<RedirectToTab to="/control-desk" tab="invoices" />} />
                  {/* /maintenance-foresight is retired — its "keeps breaking down" evidence now
                      lives on each car's own profile (Overview → Repeat faults). Old links land
                      on the fleet list rather than a dead route. */}
                  <Route path="/maintenance-foresight" element={<Navigate to="/vehicles" replace />} />
                  {/* Garages — scorecard, "which garage for this car", and who is in a workshop now. */}
                  <Route path="/garages" element={<GaragesHub />} />
                  <Route path="/garage-scorecard" element={<RedirectToTab to="/garages" tab="scorecard" />} />
                  <Route path="/executive" element={<Executive />} />
                  <Route path="/intelligence/garages/compare" element={<GarageCompare />} />
                  <Route path="/intelligence/garages/:id" element={<GarageProfile />} />
                  <Route path="/finding-keywords" element={<RedirectToTab to="/control-desk" tab="keywords" />} />
                  {/* Where on the car a fault can be — the other half of the fault vocabulary, so it
                      sits beside Keyword Risk on the Control Desk rather than on its own route. */}
                  <Route path="/fault-types" element={<RedirectToTab to="/control-desk" tab="fault-types" />} />
                  <Route path="/vehicle-locations" element={<RedirectToTab to="/control-desk" tab="locations" />} />
                  {/* Maintenance Analytics is marked "Coming Soon" in the module registry —
                      redirect the old URL so the unfinished page isn't reachable directly. */}
                  <Route path="/maintenance-analytics" element={<Navigate to="/apps/fleet-intelligence" replace />} />
                  <Route path="/maintenance-history" element={<RedirectToTab to="/vehicles" tab="per-car" />} />
                  <Route path="/damage-accidents" element={<DamageAccidents />} />
                </Route>

                {/* Parts Purchase + Repair Intelligence — its own permission group so a parts-only role
                    (e.g. finance with parts.view) sees the board without needing maintenance.view. */}
                <Route element={<RequirePermission permission="parts.view" />}>
                  {/* One Parts page, three tabs: the purchase board, the supplier invoices behind
                      what a part cost, and the catalog of part names. The two retired routes below
                      redirect into their tab, so old links and bookmarks still land correctly. */}
                  <Route path="/parts" element={<PartsHub />} />
                  {/* The parts VOCABULARY (names, Arabic terms, search aliases, warranty defaults).
                      Viewing sits with parts.view like the board above; editing is gated inside the
                      page on components.manage, which already means "curate the catalog". */}
                  <Route path="/parts-catalog" element={<RedirectToTab to="/parts" tab="catalog" />} />
                  {/* The warranty register. Reading rides with parts.view — the people chasing a
                      warranty are the people who bought the part; recording and adjudicating are
                      gated per-action on the API. It is the fourth tab on /parts now. */}
                  <Route path="/warranties" element={<RedirectToTab to="/parts" tab="warranties" />} />
                  {/* Supplier parts invoices — the paper behind what a part cost. Reading sits with
                      parts.view like the board; keying an invoice is a money action and is gated on the
                      API with parts.purchase, so a viewer sees the ledger without the write buttons. */}
                  <Route path="/part-invoices" element={<RedirectToTab to="/parts" tab="invoices" />} />
                </Route>
                {/* Warranty CASES — "could somebody else be paying for this?".
                    A separate route from the /parts warranty REGISTER on purpose: the register is a
                    record of what we were promised, this is the WORK of deciding whether a promise
                    applies and chasing the counterparty until it does or doesn't. Different people,
                    different lifecycle, different permission — `warranty.view` rather than
                    parts.view, so a finance or workshop role can work the desk without being given
                    the whole parts board. The deep-link route carries the case id because every
                    warranty notification lands on one. */}
                <Route element={<RequirePermission permission="warranty.view" />}>
                  <Route path="/warranty" element={<WarrantyCases />} />
                  <Route path="/warranty/cases" element={<WarrantyCases />} />
                  <Route path="/warranty/cases/:caseId" element={<WarrantyCases />} />
                </Route>
                {/* Accident cases — the crash file. A separate route from /damage-accidents on
                    purpose: that page is a READ of the historical maintenance log (damage records
                    as they were imported), while this is the live WORKFLOW — who had the car, the
                    police report, the liability verdict, the insurer and the money. Different
                    lifecycle, different permission (`accidents.view`), and it is where every
                    accident notification deep-links, which is why the case id is in the path. */}
                <Route element={<RequirePermission permission="accidents.view" />}>
                  <Route path="/accidents" element={<AccidentCases />} />
                  <Route path="/accidents/:caseId" element={<AccidentCases />} />
                </Route>
                {/* Recurring Fault Reviews — management inbox for confirmed faults that came back after a fix. */}
                <Route element={<RequirePermission permission="maintenance.recurring.view" />}>
                  <Route path="/recurring-fault-reviews" element={<RecurringFaultReviews />} />
                </Route>

                <Route element={<RequirePermission permission="maintenance.manage" />}>
                  <Route path="/cost-capture" element={<QuickCostInput />} />
                  {/* Inspection Request Review Gate — Controllers (Lin & Marwa) approve/reject before
                      Abu Maroof is notified. The first section of the Control Desk now; the redirect
                      carries the query string through, which the notification deep-links
                      (/inspection-review?ticket=<id>) rely on to focus one card. */}
                  <Route path="/inspection-review" element={<RedirectToTab to="/control-desk" tab="review" />} />
                </Route>

                <Route element={<RequirePermission permission="insights.view" />}>
                  {/* Workflow Movements page retired — old aliases now point at the live Workflow board. */}
                  <Route path="/inspections/history" element={<Navigate to="/maintenance-workflow" replace />} />
                  <Route path="/activity" element={<Navigate to="/maintenance-workflow" replace />} />
                  <Route path="/vehicle-status" element={<Navigate to="/maintenance-workflow" replace />} />
                  {/* Cost Intelligence and Fleet Utilization are tabs of the Vehicles hub now — both
                      are per-car tables over the same fleet, and reading either meant leaving the car
                      list. Their old routes redirect onto their tab. */}
                  <Route path="/cost-intelligence" element={<RedirectToTab to="/vehicles" tab="cost" />} />
                  <Route path="/recommendation-intelligence" element={<RecommendationIntelligence />} />
                  {/* Service Due board retired — service reminders live in the Fleet Health hub now. */}
                  <Route path="/service-due" element={<Navigate to="/inspections/schedules?tab=service" replace />} />
                  {/* Fuel & Mileage, Reconciliation and Chain Audit are unified into one tabbed page. */}
                  <Route path="/mileage" element={<MileageCenter />} />
                  <Route path="/fuel-mileage" element={<Navigate to="/mileage" replace />} />
                  <Route path="/mileage-reconciliation" element={<Navigate to="/mileage?tab=recon" replace />} />
                  <Route path="/mileage-chain-audit" element={<Navigate to="/mileage?tab=chain" replace />} />
                  {/* Mileage Discrepancies left the oversight group for the odometer hub. */}
                  <Route path="/oversight/mileage" element={<RedirectToTab to="/mileage" tab="discrepancies" />} />
                  <Route path="/fleet-utilization" element={<RedirectToTab to="/vehicles" tab="utilization" />} />
                  <Route path="/maintenance-swap" element={<MaintenanceSwap />} />
                  {/* Data Health absorbed Status Mismatch as its second tab — keep the old path alive. */}
                  <Route path="/data-health" element={<DataHealth />} />
                  {/* Intelligence Center is deleted. It reported the platform's own operating state —
                      evidence readiness, QC throughput, promotion history — which is an engineering
                      surface, not an operational one; `php artisan intelligence:evidence-health` is
                      still the way to read it. The path redirects rather than 404s, because links to
                      it exist in the docs. */}
                  <Route path="/intelligence-center" element={<Navigate to="/apps/reports" replace />} />
                  <Route path="/status-mismatch" element={<Navigate to="/data-health?tab=status" replace />} />
                  {/* Workflow Oversight — accountability & data-integrity suite over the maintenance
                      workflow, one page of five tabs. Each report kept its old URL as a redirect.
                      Mileage Discrepancies left this group for /mileage, where the rest of the
                      odometer tooling already lives. */}
                  <Route path="/oversight" element={<OversightHub />} />
                  <Route path="/oversight/left-garage" element={<RedirectToTab to="/oversight" tab="left-garage" />} />
                  <Route path="/oversight/severity" element={<RedirectToTab to="/oversight" tab="severity" />} />
                  <Route path="/oversight/misdiagnoses" element={<RedirectToTab to="/oversight" tab="misdiagnoses" />} />
                  <Route path="/oversight/resolved-transfers" element={<RedirectToTab to="/oversight" tab="resolved-transfers" />} />
                  <Route path="/oversight/checkpoint-compliance" element={<RedirectToTab to="/oversight" tab="checkpoint-compliance" />} />
                </Route>

                <Route element={<RequirePermission permission="sync.run" />}>
                  {/* Sync Audit is the last tab of Data Health — where the data came from is the same
                      question as whether the data is sound. Keeps its own sync.run gate here. */}
                  <Route path="/sync-audit" element={<RedirectToTab to="/data-health" tab="sync" />} />
                </Route>

                {/* Authenticated unknown path -> friendly 404 inside the app shell */}
                <Route path="*" element={<NotFound />} />
              </Route>
            </Route>

            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </BrowserRouter>
        </NotificationsProvider>
      </ToastProvider>
    </AuthProvider>
    </I18nProvider>
    </ThemeProvider>
  );
}
