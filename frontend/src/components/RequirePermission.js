import { Outlet, useLocation, useNavigate, Link } from 'react-router-dom';
import { usePermissions } from '../hooks/usePermissions';
import { pathBlockedForRoles } from '../config/access';

/**
 * Route-level permission gate. Use as a layout route around pages that need a
 * specific permission:
 *
 *   <Route element={<RequirePermission permission="sync.run" />}>
 *     <Route path="/sync" element={<Sync />} />
 *   </Route>
 *
 * A user who lacks the permission sees an inline "no access" notice rendered
 * INSIDE the app shell — NOT a redirect. (Auth itself is handled upstream by
 * ProtectedRoute.)
 *
 * Why inline instead of `<Navigate to="/">`: the dashboard route itself is gated
 * by `dashboard.view`, so bouncing a permission failure to `/` would bounce again
 * and again for any role without that permission — an infinite redirect that
 * white-screens the whole app. Rendering the notice in place keeps the sidebar
 * and top bar intact so the user can simply navigate somewhere they *can* go.
 */
function Forbidden() {
  const location = useLocation();
  const navigate = useNavigate();
  return (
    <div className="py-8">
      <div className="mx-auto max-w-lg px-4 sm:px-6 lg:px-8">
        <div className="flex flex-col items-center justify-center rounded-2xl border border-slate-200/60 bg-white px-6 py-16 text-center shadow-soft">
          <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-slate-100 text-slate-400 ring-1 ring-inset ring-slate-200/70">
            <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
              <path d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zM8 11V7a4 4 0 1 1 8 0v4" />
            </svg>
          </div>
          <h1 className="font-display text-lg font-semibold text-slate-900">You don’t have access to this page</h1>
          <p className="mt-1 max-w-sm text-sm text-slate-500">
            Your account isn’t permitted to view <span className="font-medium text-slate-600">{location.pathname}</span>.
            Contact an administrator if you think this is a mistake.
          </p>
          <div className="mt-5 flex items-center gap-3">
            <button
              type="button"
              onClick={() => navigate(-1)}
              className="focus-ring-self inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-400"
            >
              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
              Go back
            </button>
            <Link
              to="/notifications"
              className="text-sm font-semibold text-indigo-600 transition hover:text-indigo-700"
            >
              Go to notifications
            </Link>
          </div>
        </div>
      </div>
    </div>
  );
}

export default function RequirePermission({ permission }) {
  const { can, roles } = usePermissions();
  const location = useLocation();

  // Permission gate first, then the role-based path block (a driver can hold the
  // permission but still be walled off from specific management surfaces — even
  // when they type the URL directly). See config/access.js.
  if (!can(permission) || pathBlockedForRoles(location.pathname, roles)) {
    return <Forbidden />;
  }
  return <Outlet />;
}
