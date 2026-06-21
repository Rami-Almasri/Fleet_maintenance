import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './auth/AuthContext';
import { ToastProvider } from './components/ui/Toast';
import { NotificationsProvider } from './hooks/useNotifications';
import ProtectedRoute from './components/ProtectedRoute';
import RequirePermission from './components/RequirePermission';
import AppLayout from './layouts/AppLayout';
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';
import Vehicles from './pages/Vehicles';
import VehicleProfile from './pages/vehicles/VehicleProfile';
import Customers from './pages/Customers';
import CustomerProfile from './pages/customers/CustomerProfile';
import Drivers from './pages/Drivers';
import Contracts from './pages/Contracts';
import ContractDetail from './pages/contracts/ContractDetail';
import ContractForm from './pages/contracts/ContractForm';
import Registrations from './pages/Registrations';
import Vendors from './pages/Vendors';
import MaintenanceAnalytics from './pages/MaintenanceAnalytics';
import MaintenanceApprovals from './pages/MaintenanceApprovals';
import MaintenanceBoard from './pages/MaintenanceBoard';
import MaintenanceReturns from './pages/MaintenanceReturns';
import Garages from './pages/Garages';
import DamageAccidents from './pages/DamageAccidents';
import OverdueRentals from './pages/OverdueRentals';
import Anomalies from './pages/Anomalies';
import DataHealth from './pages/DataHealth';
import StatusMismatch from './pages/StatusMismatch';
import SheetApiDiff from './pages/SheetApiDiff';
import Sync from './pages/Sync';
import SyncAudit from './pages/SyncAudit';
import Notifications from './pages/Notifications';
import NotFound from './pages/NotFound';

export default function App() {
  return (
    <AuthProvider>
      <ToastProvider>
        <NotificationsProvider>
        <BrowserRouter>
          <Routes>
            <Route path="/login" element={<Login />} />

            {/* Authenticated area. ProtectedRoute enforces login; the nested
                RequirePermission groups enforce per-page permissions, mirroring
                the backend `permission:` middleware in routes/api.php. */}
            <Route element={<ProtectedRoute />}>
              <Route element={<AppLayout />}>
                {/* Always available to any authenticated user */}
                <Route path="/notifications" element={<Notifications />} />

                <Route element={<RequirePermission permission="dashboard.view" />}>
                  <Route path="/" element={<Dashboard />} />
                  <Route path="/overdue-rentals" element={<OverdueRentals />} />
                </Route>

                <Route element={<RequirePermission permission="vehicles.view" />}>
                  <Route path="/vehicles" element={<Vehicles />} />
                  <Route path="/vehicles/:id" element={<VehicleProfile />} />
                </Route>

                <Route element={<RequirePermission permission="customers.view" />}>
                  <Route path="/customers" element={<Customers />} />
                  <Route path="/customers/:id" element={<CustomerProfile />} />
                </Route>

                <Route element={<RequirePermission permission="drivers.view" />}>
                  <Route path="/drivers" element={<Drivers />} />
                </Route>

                <Route element={<RequirePermission permission="contracts.view" />}>
                  <Route path="/contracts" element={<Contracts />} />
                  <Route path="/contracts/new" element={<ContractForm />} />
                  <Route path="/contracts/:id/edit" element={<ContractForm />} />
                  <Route path="/contracts/:id" element={<ContractDetail />} />
                </Route>

                <Route element={<RequirePermission permission="registration.view" />}>
                  <Route path="/registrations" element={<Registrations />} />
                </Route>

                <Route element={<RequirePermission permission="vendors.view" />}>
                  <Route path="/vendors" element={<Vendors />} />
                </Route>

                <Route element={<RequirePermission permission="maintenance.view" />}>
                  <Route path="/maintenance" element={<MaintenanceBoard />} />
                  <Route path="/maintenance-returns" element={<MaintenanceReturns />} />
                  <Route path="/garages" element={<Garages />} />
                  <Route path="/maintenance-analytics" element={<MaintenanceAnalytics />} />
                  <Route path="/damage-accidents" element={<DamageAccidents />} />
                </Route>

                <Route element={<RequirePermission permission="maintenance.approve" />}>
                  <Route path="/maintenance-approvals" element={<MaintenanceApprovals />} />
                </Route>

                <Route element={<RequirePermission permission="insights.view" />}>
                  <Route path="/anomalies" element={<Anomalies />} />
                  <Route path="/data-health" element={<DataHealth />} />
                  <Route path="/status-mismatch" element={<StatusMismatch />} />
                  <Route path="/sheet-api-diff" element={<SheetApiDiff />} />
                </Route>

                <Route element={<RequirePermission permission="sync.run" />}>
                  <Route path="/sync" element={<Sync />} />
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
  );
}
