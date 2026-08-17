import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
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
import Vehicles from './pages/Vehicles';
import VehicleProfile from './pages/vehicles/VehicleProfile';
import OdometerApprovals from './pages/vehicles/OdometerApprovals';
import Customers from './pages/Customers';
import CustomerProfile from './pages/customers/CustomerProfile';
import Drivers from './pages/Drivers';
import Contracts from './pages/Contracts';
import ContractDetail from './pages/contracts/ContractDetail';
import ContractForm from './pages/contracts/ContractForm';
import Vendors from './pages/Vendors';
import MaintenanceHistory from './pages/MaintenanceHistory';
import MaintenanceWorkflow from './pages/MaintenanceWorkflow';
import MaintenanceCheckpoints from './pages/MaintenanceCheckpoints';
import CarStatus from './pages/CarStatus';
// ComponentsDashboard is held back as "Coming Soon" — the page file stays in the
// repo; re-import it here when /components is switched back on.
import CarStatusVehicle from './pages/CarStatusVehicle';
import MyMaintenanceQueue from './pages/MyMaintenanceQueue';
import InspectionReviewQueue from './pages/InspectionReviewQueue';
import InGarage from './pages/InGarage';
import ComplaintsCenter from './pages/ComplaintsCenter';
import DriverObservations from './pages/DriverObservations';
import FleetUtilization from './pages/FleetUtilization';
import MaintenanceSwap from './pages/MaintenanceSwap';
import LogisticsDispatch from './pages/LogisticsDispatch';
import QuickCostInput from './pages/QuickCostInput';
import Garages from './pages/Garages';
import GarageProfile from './pages/intelligence/GarageProfile';
import GarageCompare from './pages/intelligence/GarageCompare';
import Executive from './pages/intelligence/Executive';
import FindingKeywords from './pages/FindingKeywords';
import VehicleLocations from './pages/VehicleLocations';
import Parts from './pages/Parts';
import PartsCatalog from './pages/PartsCatalog';
import Warranties from './pages/Warranties';
import PartInvoices from './pages/PartInvoices';
import Procurement from './pages/Procurement';
import RecurringFaultReviews from './pages/RecurringFaultReviews';
import DamageAccidents from './pages/DamageAccidents';
import CostIntelligence from './pages/CostIntelligence';
import RecommendationIntelligence from './pages/RecommendationIntelligence';
import OilProjection from './pages/reminders/OilProjection';
import GarageFinder from './pages/GarageFinder';
import EventClassificationReview from './pages/EventClassificationReview';
import ConceptBridgeReview from './pages/ConceptBridgeReview';
import MileageCenter from './pages/MileageCenter';
import DataHealth from './pages/DataHealth';
import IntelligenceCenter from './pages/IntelligenceCenter';
import SyncAudit from './pages/SyncAudit';
import Notifications from './pages/Notifications';
import Settings from './pages/Settings';
import SimulationPanel from './pages/SimulationPanel';
import Users from './pages/Users';
import NotFound from './pages/NotFound';
import GarageInvoicePortal from './pages/GarageInvoicePortal';
import CompletedRepairs from './pages/CompletedRepairs';
import InvoiceMatching from './pages/InvoiceMatching';
import MileageDiscrepancies from './pages/oversight/MileageDiscrepancies';
import GarageInvoiceQueue from './pages/oversight/GarageInvoiceQueue';
import SeverityReview from './pages/oversight/SeverityReview';
import Misdiagnoses from './pages/oversight/Misdiagnoses';
import ResolvedTransfers from './pages/oversight/ResolvedTransfers';
import CheckpointCompliance from './pages/oversight/CheckpointCompliance';
import CleaningCapture from './pages/cleaning/CleaningCapture';
import FleetHealth from './pages/inspections/FleetHealth';

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
                {/* Vehicle Readiness board retired — send the old path to the Fleet Health hub. */}
                <Route path="/readiness" element={<Navigate to="/inspections/schedules" replace />} />

                {/* Event Type layer — human-in-the-loop classification review (needs_review queue). */}
                <Route element={<RequirePermission permission="maintenance.manage" />}>
                  <Route path="/classification-review" element={<EventClassificationReview />} />
                  {/* Concept Bridge benchmark — the human ground truth that gates legacy concept enrichment. */}
                  <Route path="/concept-bridge-review" element={<ConceptBridgeReview />} />
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

                <Route element={<RequirePermission permission="vehicles.view" />}>
                  <Route path="/vehicles" element={<Vehicles />} />
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
                  <Route path="/oil-projection" element={<OilProjection />} />
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

                <Route element={<RequirePermission permission="vendors.view" />}>
                  <Route path="/vendors" element={<Vendors />} />
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
                  {/* Procurement — payables aging, supplier performance and payments made. Reading is a
                      maintenance.view question ("what do we owe?"); recording a payment is gated to
                      maintenance.manage on the API, so a viewer sees the reports without the actions. */}
                  <Route path="/procurement" element={<Procurement />} />
                  {/* Garage Finder — "this car has this fault; who is best at it?", asked BEFORE a ticket
                      exists. Same engine as the assign step, read-only: it answers, it does not dispatch. */}
                  <Route path="/garage-finder" element={<GarageFinder />} />
                  {/* Car Status — the live stage board: every car in the maintenance workflow by the stage
                      it's in and who's responsible for it there; opens into the per-vehicle operational profile. */}
                  <Route path="/car-status" element={<CarStatus />} />
                  <Route path="/car-status/:vehicleId" element={<CarStatusVehicle />} />
                  {/* Old Maintenance Operations control center — folded into Car Status; keep the path alive. */}
                  <Route path="/maintenance-operations" element={<Navigate to="/car-status" replace />} />
                  {/* Old Maintenance Board retired — the Workflow board is now the single maintenance hub. */}
                  <Route path="/maintenance" element={<Navigate to="/maintenance-workflow" replace />} />
                  {/* Old Workflow Hub — retired; point at the live Workflow board. */}
                  <Route path="/maintenance-hub" element={<Navigate to="/maintenance-workflow" replace />} />
                  {/* "Booked in Shop" now lives inside the Fleet Health hub — redirect the old path. */}
                  <Route path="/maintenance-bookings" element={<Navigate to="/inspections/schedules?tab=bookings" replace />} />
                  <Route path="/maintenance-workflow" element={<MaintenanceWorkflow />} />
                  {/* In the Garage — which cars are at a garage right now, and which garage each is at. */}
                  <Route path="/in-garage" element={<InGarage />} />
                  {/* Complaints Center — management & follow-up view of every customer complaint + its timeline. */}
                  <Route path="/complaints" element={<ComplaintsCenter />} />
                  {/* Deep link from a complaint notification: opens that complaint's drawer over the Center. */}
                  <Route path="/complaints/:id" element={<ComplaintsCenter />} />
                  {/* Driver Observations — lightweight handover notes; may raise an inspection request. */}
                  <Route path="/driver-observations" element={<DriverObservations />} />
                  {/* Deep link from notifications: focuses one ticket on the board */}
                  <Route path="/maintenance-workflow/:id" element={<MaintenanceWorkflow />} />
                  {/* Pre-maintenance Recommendation queue — Supervisor triage before the active board */}
                  <Route path="/my-maintenance-queue" element={<MyMaintenanceQueue />} />
                  {/* Maintenance Progress — the supervisors' checkpoint queue; notifications deep-link here
                      (?ticket=<id>) to open a car's progress form directly. */}
                  <Route path="/maintenance-progress" element={<MaintenanceCheckpoints />} />
                  {/* Fixed & Completed Repairs ledger — every closed ticket with its full story */}
                  <Route path="/completed-repairs" element={<CompletedRepairs />} />
                  {/* Invoice Matching — the car is back: key each garage's bill beside the work it covers */}
                  <Route path="/invoice-matching" element={<InvoiceMatching />} />
                  {/* /maintenance-foresight is retired — its "keeps breaking down" evidence now
                      lives on each car's own profile (Overview → Repeat faults). Old links land
                      on the fleet list rather than a dead route. */}
                  <Route path="/maintenance-foresight" element={<Navigate to="/vehicles" replace />} />
                  <Route path="/garages" element={<Garages />} />
                  <Route path="/executive" element={<Executive />} />
                  <Route path="/intelligence/garages/compare" element={<GarageCompare />} />
                  <Route path="/intelligence/garages/:id" element={<GarageProfile />} />
                  <Route path="/finding-keywords" element={<FindingKeywords />} />
                  <Route path="/vehicle-locations" element={<VehicleLocations />} />
                  {/* Maintenance Analytics is marked "Coming Soon" in the module registry —
                      redirect the old URL so the unfinished page isn't reachable directly. */}
                  <Route path="/maintenance-analytics" element={<Navigate to="/apps/fleet-intelligence" replace />} />
                  <Route path="/maintenance-history" element={<MaintenanceHistory />} />
                  <Route path="/damage-accidents" element={<DamageAccidents />} />
                </Route>

                {/* Parts Purchase + Repair Intelligence — its own permission group so a parts-only role
                    (e.g. finance with parts.view) sees the board without needing maintenance.view. */}
                <Route element={<RequirePermission permission="parts.view" />}>
                  <Route path="/parts" element={<Parts />} />
                  {/* The parts VOCABULARY (names, Arabic terms, search aliases, warranty defaults).
                      Viewing sits with parts.view like the board above; editing is gated inside the
                      page on components.manage, which already means "curate the catalog". */}
                  <Route path="/parts-catalog" element={<PartsCatalog />} />
                  {/* The warranty register. Reading rides with parts.view — the people chasing a
                      warranty are the people who bought the part; recording and adjudicating are
                      gated per-action on the API. */}
                  <Route path="/warranties" element={<Warranties />} />
                  {/* Supplier parts invoices — the paper behind what a part cost. Reading sits with
                      parts.view like the board; keying an invoice is a money action and is gated on the
                      API with parts.purchase, so a viewer sees the ledger without the write buttons. */}
                  <Route path="/part-invoices" element={<PartInvoices />} />
                </Route>
                {/* Recurring Fault Reviews — management inbox for confirmed faults that came back after a fix. */}
                <Route element={<RequirePermission permission="maintenance.recurring.view" />}>
                  <Route path="/recurring-fault-reviews" element={<RecurringFaultReviews />} />
                </Route>

                <Route element={<RequirePermission permission="maintenance.manage" />}>
                  <Route path="/cost-capture" element={<QuickCostInput />} />
                  {/* Inspection Request Review Gate — Controllers (Lin & Marwa) approve/reject before Abu Maroof is notified */}
                  <Route path="/inspection-review" element={<InspectionReviewQueue />} />
                </Route>

                <Route element={<RequirePermission permission="insights.view" />}>
                  {/* Workflow Movements page retired — old aliases now point at the live Workflow board. */}
                  <Route path="/inspections/history" element={<Navigate to="/maintenance-workflow" replace />} />
                  <Route path="/activity" element={<Navigate to="/maintenance-workflow" replace />} />
                  <Route path="/vehicle-status" element={<Navigate to="/maintenance-workflow" replace />} />
                  <Route path="/cost-intelligence" element={<CostIntelligence />} />
                  <Route path="/recommendation-intelligence" element={<RecommendationIntelligence />} />
                  {/* Service Due board retired — service reminders live in the Fleet Health hub now. */}
                  <Route path="/service-due" element={<Navigate to="/inspections/schedules?tab=service" replace />} />
                  {/* Fuel & Mileage, Reconciliation and Chain Audit are unified into one tabbed page. */}
                  <Route path="/mileage" element={<MileageCenter />} />
                  <Route path="/fuel-mileage" element={<Navigate to="/mileage" replace />} />
                  <Route path="/mileage-reconciliation" element={<Navigate to="/mileage?tab=recon" replace />} />
                  <Route path="/mileage-chain-audit" element={<Navigate to="/mileage?tab=chain" replace />} />
                  <Route path="/fleet-utilization" element={<FleetUtilization />} />
                  <Route path="/maintenance-swap" element={<MaintenanceSwap />} />
                  {/* Data Health absorbed Status Mismatch as its second tab — keep the old path alive. */}
                  <Route path="/data-health" element={<DataHealth />} />
                  {/* The platform's own operating state — previously reachable only via artisan. */}
                  <Route path="/intelligence-center" element={<IntelligenceCenter />} />
                  <Route path="/status-mismatch" element={<Navigate to="/data-health?tab=status" replace />} />
                  {/* Workflow Oversight — accountability & data-integrity suite over the maintenance workflow. */}
                  <Route path="/oversight/mileage" element={<MileageDiscrepancies />} />
                  <Route path="/oversight/left-garage" element={<GarageInvoiceQueue />} />
                  <Route path="/oversight/severity" element={<SeverityReview />} />
                  <Route path="/oversight/misdiagnoses" element={<Misdiagnoses />} />
                  <Route path="/oversight/resolved-transfers" element={<ResolvedTransfers />} />
                  <Route path="/oversight/checkpoint-compliance" element={<CheckpointCompliance />} />
                </Route>

                <Route element={<RequirePermission permission="sync.run" />}>
                  <Route path="/sync-audit" element={<SyncAudit />} />
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
