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
import Dashboard from './pages/Dashboard';
import OpsDashboard from './pages/command/OpsDashboard';
import OrdersBoard from './pages/command/OrdersBoard';
import Vehicles from './pages/Vehicles';
import VehicleProfile from './pages/vehicles/VehicleProfile';
import OdometerApprovals from './pages/vehicles/OdometerApprovals';
import Customers from './pages/Customers';
import CustomerProfile from './pages/customers/CustomerProfile';
import Drivers from './pages/Drivers';
import Contracts from './pages/Contracts';
import ContractDetail from './pages/contracts/ContractDetail';
import InspectionPrototype from './pages/InspectionPrototype';
import ContractForm from './pages/contracts/ContractForm';
import Vendors from './pages/Vendors';
import MaintenanceAnalytics from './pages/MaintenanceAnalytics';
import MaintenanceHistory from './pages/MaintenanceHistory';
import MaintenanceApprovals from './pages/MaintenanceApprovals';
import MaintenanceWorkflow from './pages/MaintenanceWorkflow';
import CarStatus from './pages/CarStatus';
import CarStatusVehicle from './pages/CarStatusVehicle';
import MaintenanceRecommendations from './pages/MaintenanceRecommendations';
import MyMaintenanceQueue from './pages/MyMaintenanceQueue';
import InspectionReviewQueue from './pages/InspectionReviewQueue';
import InspectionIntelligenceCenter from './pages/InspectionIntelligenceCenter';
import MaintenanceForesight from './pages/MaintenanceForesight';
import FleetUtilization from './pages/FleetUtilization';
import MaintenanceSwap from './pages/MaintenanceSwap';
import LogisticsDispatch from './pages/LogisticsDispatch';
import TeamPresence from './pages/TeamPresence';
import QuickCostInput from './pages/QuickCostInput';
import Garages from './pages/Garages';
import FindingKeywords from './pages/FindingKeywords';
import Parts from './pages/Parts';
import PartInvestigations from './pages/PartInvestigations';
import RecurringFaultReviews from './pages/RecurringFaultReviews';
import DamageAccidents from './pages/DamageAccidents';
import OverdueRentals from './pages/OverdueRentals';
import Profitability from './pages/Profitability';
import CostIntelligence from './pages/CostIntelligence';
import ServiceDueBoard from './pages/ServiceDueBoard';
import MileageCenter from './pages/MileageCenter';
import DataHealth from './pages/DataHealth';
import FinancialConflicts from './pages/FinancialConflicts';
import FinancialReconciliation from './pages/FinancialReconciliation';
import FleetNetProfit from './pages/FleetNetProfit';
import SyncAudit from './pages/SyncAudit';
import Notifications from './pages/Notifications';
import Settings from './pages/Settings';
import NotificationTestConsole from './pages/NotificationTestConsole';
import SimulationPanel from './pages/SimulationPanel';
import Users from './pages/Users';
import NotFound from './pages/NotFound';
import GarageInvoicePortal from './pages/GarageInvoicePortal';
import PendingInvoices from './pages/PendingInvoices';
import CompletedRepairs from './pages/CompletedRepairs';
import WorkflowMovements from './pages/workflow/WorkflowMovements';
import WorkflowOversight from './pages/oversight/WorkflowOversight';
import MileageDiscrepancies from './pages/oversight/MileageDiscrepancies';
import StageAccountability from './pages/oversight/StageAccountability';
import GarageInvoiceQueue from './pages/oversight/GarageInvoiceQueue';
import SeverityReview from './pages/oversight/SeverityReview';
import Misdiagnoses from './pages/oversight/Misdiagnoses';
import ResolvedTransfers from './pages/oversight/ResolvedTransfers';
import RentalOperationsHub from './pages/rentals/RentalOperationsHub';
import BookingReadiness from './pages/booking/BookingReadiness';
import CleaningCapture from './pages/cleaning/CleaningCapture';
import FleetHealth from './pages/inspections/FleetHealth';

// Index route ("/"). Normally the Dashboard, but roles blocked from it (the
// driver) are redirected to a home they can actually see instead of hitting the
// inline "Forbidden" notice on every fresh load. See config/access.js.
function HomeGate() {
  const { can, roles } = usePermissions();
  if (pathBlockedForRoles('/', roles)) {
    return <Navigate to={homePathForRoles(roles)} replace />;
  }
  if (!can('dashboard.view')) {
    return <Navigate to="/notifications" replace />;
  }
  return <Dashboard />;
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
                {/* Vehicle Readiness board retired — send the old path to the Fleet Health hub. */}
                <Route path="/readiness" element={<Navigate to="/inspections/schedules" replace />} />

                {/* Admin-only Notification Test Console — fire test alerts to verify the bell pipeline. */}
                <Route element={<RequirePermission permission="users.manage" />}>
                  <Route path="/notification-test" element={<NotificationTestConsole />} />
                  {/* Admin-only Simulation Panel — force a live demo scenario, gated again server-side by demo_mode. */}
                  <Route path="/simulation" element={<SimulationPanel />} />
                  {/* Admin-only account directory — every user, their status and role(s). */}
                  <Route path="/users" element={<Users />} />
                </Route>

                {/* Index route handles its own gating (redirects roles blocked from the Dashboard). */}
                <Route path="/" element={<HomeGate />} />

                <Route element={<RequirePermission permission="dashboard.view" />}>
                  <Route path="/ops-dashboard" element={<OpsDashboard />} />
                  <Route path="/orders-board" element={<OrdersBoard />} />
                  <Route path="/overdue-rentals" element={<OverdueRentals />} />
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
                  <Route path="/team-presence" element={<TeamPresence />} />
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
                  {/* Booking Readiness — pickup-prep board: upcoming bookings + per-car readiness + triggers. */}
                  <Route path="/booking-readiness" element={<BookingReadiness />} />
                  {/* Cleaning — before/after photo capture behind the readiness Cleaning "Fix" link. */}
                  <Route path="/cleaning" element={<CleaningCapture />} />
                </Route>

                <Route element={<RequirePermission permission="contracts.view" />}>
                  {/* Rental Operations Hub — check-in/out board: active + upcoming rentals with live readiness. */}
                  <Route path="/rental-contracts" element={<RentalOperationsHub />} />
                  <Route path="/contracts" element={<Contracts />} />
                  <Route path="/contracts/new" element={<ContractForm />} />
                  <Route path="/contracts/:id/edit" element={<ContractForm />} />
                  <Route path="/contracts/:id" element={<ContractDetail />} />
                </Route>

                <Route element={<RequirePermission permission="inspections.view" />}>
                  <Route path="/inspection-prototype" element={<InspectionPrototype />} />
                  {/* Unified "Fleet Health" hub — tabs for Readiness / Service Due / Registrations. */}
                  <Route path="/inspections/schedules" element={<FleetHealth />} />
                </Route>

                <Route element={<RequirePermission permission="reminders.view" />}>
                  {/* Service Reminders now lives inside the Fleet Health hub — redirect the old path. */}
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

                <Route element={<RequirePermission permission="maintenance.view" />}>
                  {/* Car Status — the operations control center (KPIs + live table + widgets), and the
                      per-vehicle operational profile it opens into (hosts the live Maintenance Workflow). */}
                  <Route path="/car-status" element={<CarStatus />} />
                  <Route path="/car-status/:vehicleId" element={<CarStatusVehicle />} />
                  {/* Old Maintenance Board retired — the Workflow board is now the single maintenance hub. */}
                  <Route path="/maintenance" element={<Navigate to="/maintenance-workflow" replace />} />
                  {/* Old Workflow Hub — folded into the plain Workflow Movements feed. */}
                  <Route path="/maintenance-hub" element={<Navigate to="/inspections/history" replace />} />
                  {/* "Booked in Shop" now lives inside the Fleet Health hub — redirect the old path. */}
                  <Route path="/maintenance-bookings" element={<Navigate to="/inspections/schedules?tab=bookings" replace />} />
                  <Route path="/maintenance-workflow" element={<MaintenanceWorkflow />} />
                  {/* Deep link from notifications: focuses one ticket on the board */}
                  <Route path="/maintenance-workflow/:id" element={<MaintenanceWorkflow />} />
                  {/* Pre-maintenance Recommendation queue — Supervisor triage before the active board */}
                  <Route path="/maintenance-recommendations" element={<MaintenanceRecommendations />} />
                  <Route path="/my-maintenance-queue" element={<MyMaintenanceQueue />} />
                  <Route path="/invoices/pending-submission" element={<PendingInvoices />} />
                  {/* Fixed & Completed Repairs ledger — every closed ticket with its full story */}
                  <Route path="/completed-repairs" element={<CompletedRepairs />} />
                  <Route path="/maintenance-foresight" element={<MaintenanceForesight />} />
                  <Route path="/garages" element={<Garages />} />
                  <Route path="/finding-keywords" element={<FindingKeywords />} />
                  <Route path="/maintenance-analytics" element={<MaintenanceAnalytics />} />
                  <Route path="/maintenance-history" element={<MaintenanceHistory />} />
                  <Route path="/damage-accidents" element={<DamageAccidents />} />
                </Route>

                {/* Parts Purchase + Repair Intelligence — its own permission group so a parts-only role
                    (e.g. finance with parts.view) sees the board without needing maintenance.view. */}
                <Route element={<RequirePermission permission="parts.view" />}>
                  <Route path="/parts" element={<Parts />} />
                </Route>
                <Route element={<RequirePermission permission="parts.investigate" />}>
                  <Route path="/part-investigations" element={<PartInvestigations />} />
                </Route>
                {/* Recurring Fault Reviews — management inbox for confirmed faults that came back after a fix. */}
                <Route element={<RequirePermission permission="maintenance.recurring.view" />}>
                  <Route path="/recurring-fault-reviews" element={<RecurringFaultReviews />} />
                </Route>

                <Route element={<RequirePermission permission="maintenance.manage" />}>
                  <Route path="/cost-capture" element={<QuickCostInput />} />
                  {/* Inspection Request Review Gate — Controllers (Lin & Marwa) approve/reject before Abu Maroof is notified */}
                  <Route path="/inspection-review" element={<InspectionReviewQueue />} />
                  <Route path="/inspection-intelligence" element={<InspectionIntelligenceCenter />} />
                </Route>

                <Route element={<RequirePermission permission="maintenance.approve" />}>
                  <Route path="/maintenance-approvals" element={<MaintenanceApprovals />} />
                </Route>

                <Route element={<RequirePermission permission="insights.view" />}>
                  {/* Workflow Movements — one plain feed of every workflow step (inspections, tickets,
                      dispatch, repairs, movements, readiness), click a car to follow its record. This is
                      the single page that replaced the old Vehicle Life-Stream / Workflow Hub. */}
                  <Route path="/inspections/history" element={<WorkflowMovements />} />
                  {/* Activity Feed and Vehicle Status were absorbed into it — keep old paths alive. */}
                  <Route path="/activity" element={<Navigate to="/inspections/history" replace />} />
                  <Route path="/vehicle-status" element={<Navigate to="/inspections/history" replace />} />
                  <Route path="/profitability" element={<Profitability />} />
                  <Route path="/cost-intelligence" element={<CostIntelligence />} />
                  <Route path="/service-due" element={<ServiceDueBoard />} />
                  {/* Fuel & Mileage, Reconciliation and Chain Audit are unified into one tabbed page. */}
                  <Route path="/mileage" element={<MileageCenter />} />
                  <Route path="/fuel-mileage" element={<Navigate to="/mileage" replace />} />
                  <Route path="/mileage-reconciliation" element={<Navigate to="/mileage?tab=recon" replace />} />
                  <Route path="/mileage-chain-audit" element={<Navigate to="/mileage?tab=chain" replace />} />
                  <Route path="/fleet-utilization" element={<FleetUtilization />} />
                  <Route path="/maintenance-swap" element={<MaintenanceSwap />} />
                  {/* Data Health absorbed Status Mismatch as its second tab — keep the old path alive. */}
                  <Route path="/data-health" element={<DataHealth />} />
                  <Route path="/status-mismatch" element={<Navigate to="/data-health?tab=status" replace />} />
                  <Route path="/financial-conflicts" element={<FinancialConflicts />} />
                  <Route path="/financial-reconciliation" element={<FinancialReconciliation />} />
                  <Route path="/net-profit" element={<FleetNetProfit />} />
                  {/* Workflow Oversight — accountability & data-integrity suite over the maintenance workflow. */}
                  <Route path="/oversight" element={<WorkflowOversight />} />
                  <Route path="/oversight/mileage" element={<MileageDiscrepancies />} />
                  <Route path="/oversight/stages" element={<StageAccountability />} />
                  <Route path="/oversight/left-garage" element={<GarageInvoiceQueue />} />
                  <Route path="/oversight/severity" element={<SeverityReview />} />
                  <Route path="/oversight/misdiagnoses" element={<Misdiagnoses />} />
                  <Route path="/oversight/resolved-transfers" element={<ResolvedTransfers />} />
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
