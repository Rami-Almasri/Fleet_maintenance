import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { usePermissions } from '../hooks/usePermissions';

/**
 * Route-level permission gate. Use as a layout route around pages that need a
 * specific permission:
 *
 *   <Route element={<RequirePermission permission="sync.run" />}>
 *     <Route path="/sync" element={<Sync />} />
 *   </Route>
 *
 * Users who lack the permission are bounced to the dashboard with a flag the
 * Forbidden notice can read. (Auth itself is handled upstream by ProtectedRoute.)
 */
export default function RequirePermission({ permission }) {
  const { can } = usePermissions();
  const location = useLocation();

  if (!can(permission)) {
    return <Navigate to="/" replace state={{ forbidden: location.pathname }} />;
  }
  return <Outlet />;
}
