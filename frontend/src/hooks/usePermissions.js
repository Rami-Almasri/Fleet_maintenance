import { useAuth } from '../auth/AuthContext';

/**
 * Permission/role helpers derived from the logged-in user.
 *
 * The backend embeds `roles` and `permissions` (flat string arrays) in the
 * user object returned by /auth/login — see UserResource. This hook is the
 * single place the UI asks "is this user allowed to…". It mirrors, but does
 * NOT replace, the server-side `permission:` middleware: the API is still the
 * source of truth, this just hides things the user can't use anyway.
 */
export function usePermissions() {
  const { user } = useAuth();
  const roles = user?.roles ?? [];
  const permissions = user?.permissions ?? [];

  // super-admin can do everything, even permissions added after this build.
  const isSuperAdmin = roles.includes('super-admin');

  const can = (permission) => {
    if (!permission) return true; // unguarded item — always visible
    if (isSuperAdmin) return true;
    return permissions.includes(permission);
  };

  // True if the user has at least one of the given permissions.
  const canAny = (perms = []) => perms.length === 0 || perms.some(can);

  const hasRole = (role) => roles.includes(role);

  return { can, canAny, hasRole, roles, permissions, isSuperAdmin };
}
