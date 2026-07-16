// Admin-only account directory with full CRUD — every user, their status and Spatie role(s).
// An admin can create, edit, suspend/re-activate and delete accounts here. Writes go through
// the admin-gated /auth endpoints (users.manage); the same role ceiling the backend enforces
// (only a super-admin may grant/modify super-admin/admin) is mirrored in the form's role list.

import { useCallback, useMemo, useState } from 'react';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useAuth } from '../auth/AuthContext';
import { useToast } from '../components/ui/Toast';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import ConfirmDialog from '../components/ui/ConfirmDialog';
import { Input, Select } from '../components/ui/Field';
import { PageHeader, SearchInput } from '../components/ui/Misc';
import { SectionCard } from '../components/ui/Table';
import { Skeleton } from '../components/ui/Skeleton';

const ROLE_TONE = {
  'super-admin': 'red',
  admin: 'red',
  manager: 'indigo',
  operations: 'blue',
  maintenance: 'violet',
  supervisor: 'amber',
  inspector: 'cyan',
  logistics: 'blue',
  finance: 'emerald',
  viewer: 'slate',
};

const initialsOf = (name) =>
  (name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';

// The backend returns `msg` as either a plain string or a Laravel validation-errors
// object ({ field: [messages] }). Flatten either into a single readable line.
const messageOf = (err, fallback) => {
  const msg = err?.response?.data?.msg;
  if (typeof msg === 'string') return msg;
  if (msg && typeof msg === 'object') {
    const first = Object.values(msg)[0];
    return Array.isArray(first) ? first[0] : String(first);
  }
  return fallback;
};

const EMPTY_FORM = { name: '', email: '', password: '', role: 'viewer', status: 'active' };

export default function Users() {
  const { user: currentUser } = useAuth();
  const toast = useToast();

  const fetcher = useCallback(async () => (await api.get('/auth/users')).data.data, []);
  const { data, loading, error, reload } = useFetch(fetcher);

  // Assignable roles for the picker (backend already trims privileged roles for plain admins).
  const rolesFetcher = useCallback(async () => (await api.get('/auth/roles')).data.data, []);
  const { data: rolesData } = useFetch(rolesFetcher);
  const roles = useMemo(() => (Array.isArray(rolesData) ? rolesData : []), [rolesData]);

  const users = useMemo(() => (Array.isArray(data) ? data : []), [data]);
  const [search, setSearch] = useState('');

  // Modal state: `editing` holds the user being edited, or null for a create.
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(EMPTY_FORM);
  const [fieldErrors, setFieldErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const [deleting, setDeleting] = useState(null);
  const [deleteBusy, setDeleteBusy] = useState(false);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return users;
    return users.filter((u) =>
      [u.name, u.email, ...(u.roles || [])].some((f) => (f || '').toLowerCase().includes(q))
    );
  }, [users, search]);

  const openCreate = () => {
    setEditing(null);
    setForm({ ...EMPTY_FORM, role: roles.includes('viewer') ? 'viewer' : roles[0] || '' });
    setFieldErrors({});
    setFormOpen(true);
  };

  const openEdit = (u) => {
    setEditing(u);
    setForm({ name: u.name || '', email: u.email || '', password: '', role: (u.roles || [])[0] || '', status: u.status || 'active' });
    setFieldErrors({});
    setFormOpen(true);
  };

  const setField = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const submit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFieldErrors({});
    try {
      if (editing) {
        const payload = { name: form.name, email: form.email, role: form.role, status: form.status };
        if (form.password) payload.password = form.password;
        await api.put(`/auth/users/${editing.id}`, payload);
        toast.success('User updated');
      } else {
        await api.post('/auth/signup', {
          name: form.name,
          email: form.email,
          password: form.password,
          role: form.role,
          status: form.status,
        });
        toast.success('User created');
      }
      setFormOpen(false);
      reload();
    } catch (err) {
      // Surface field-level validation errors inline when the backend sends them.
      const msg = err?.response?.data?.msg;
      if (msg && typeof msg === 'object') {
        setFieldErrors(Object.fromEntries(Object.entries(msg).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v])));
      }
      toast.error(messageOf(err, editing ? 'Could not update the user.' : 'Could not create the user.'));
    } finally {
      setSaving(false);
    }
  };

  const confirmDelete = async () => {
    if (!deleting) return;
    setDeleteBusy(true);
    try {
      await api.delete(`/auth/users/${deleting.id}`);
      toast.success('User deleted');
      setDeleting(null);
      reload();
    } catch (err) {
      toast.error(messageOf(err, 'Could not delete the user.'));
    } finally {
      setDeleteBusy(false);
    }
  };

  return (
    <div className="py-8">
      <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Users" subtitle={loading ? 'Loading…' : `${users.length} account${users.length === 1 ? '' : 's'}`}>
          <Button onClick={openCreate}>Add user</Button>
        </PageHeader>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <SearchInput value={search} onChange={setSearch} placeholder="Search name, email or role…" />

        <SectionCard title="All accounts">
          {loading ? (
            <div className="space-y-2 p-4">
              <Skeleton className="h-14 rounded-xl" />
              <Skeleton className="h-14 rounded-xl" />
              <Skeleton className="h-14 rounded-xl" />
            </div>
          ) : filtered.length === 0 ? (
            <div className="px-6 py-12 text-center text-sm text-slate-500">No users match this search.</div>
          ) : (
            <div className="divide-y divide-slate-100">
              {filtered.map((u) => (
                <div key={u.id} className="flex items-center gap-3 px-4 py-3">
                  <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-600">
                    {initialsOf(u.name)}
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                      <span className="truncate text-sm font-semibold text-slate-800">{u.name}</span>
                      <Badge tone={u.status === 'active' ? 'emerald' : 'red'}>{u.status}</Badge>
                      {currentUser?.id === u.id && <span className="text-xs text-slate-400">(you)</span>}
                    </div>
                    <p className="truncate text-xs text-slate-400">{u.email}</p>
                  </div>
                  <div className="hidden flex-wrap items-center justify-end gap-1.5 sm:flex">
                    {(u.roles || []).length === 0 ? (
                      <span className="text-xs italic text-slate-300">no role</span>
                    ) : (
                      u.roles.map((r) => <Badge key={r} tone={ROLE_TONE[r] || 'gray'}>{r}</Badge>)
                    )}
                  </div>
                  <div className="ml-2 flex shrink-0 items-center gap-1">
                    <Button variant="ghost" size="sm" onClick={() => openEdit(u)}>Edit</Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      className="text-red-600 hover:bg-red-50"
                      disabled={currentUser?.id === u.id}
                      title={currentUser?.id === u.id ? 'You cannot delete your own account' : undefined}
                      onClick={() => setDeleting(u)}
                    >
                      Delete
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </SectionCard>
      </div>

      <Modal
        open={formOpen}
        onClose={() => !saving && setFormOpen(false)}
        title={editing ? 'Edit user' : 'Add user'}
        subtitle={editing ? editing.email : 'Create a new account and assign a role.'}
        footer={
          <>
            <Button variant="secondary" onClick={() => setFormOpen(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" form="user-form" loading={saving}>{editing ? 'Save changes' : 'Create user'}</Button>
          </>
        }
      >
        <form id="user-form" onSubmit={submit} className="space-y-4">
          <Input label="Full name" required value={form.name} onChange={setField('name')} error={fieldErrors.name} placeholder="Jane Doe" />
          <Input label="Email" type="email" required value={form.email} onChange={setField('email')} error={fieldErrors.email} placeholder="jane@example.com" />
          <Input
            label={editing ? 'New password' : 'Password'}
            type="password"
            required={!editing}
            value={form.password}
            onChange={setField('password')}
            error={fieldErrors.password}
            placeholder={editing ? 'Leave blank to keep current' : 'At least 6 characters'}
            autoComplete="new-password"
          />
          <div className="grid grid-cols-2 gap-4">
            <Select label="Role" value={form.role} onChange={setField('role')} error={fieldErrors.role}>
              {roles.length === 0 && <option value="">viewer</option>}
              {roles.map((r) => <option key={r} value={r}>{r}</option>)}
            </Select>
            <Select label="Status" value={form.status} onChange={setField('status')} error={fieldErrors.status}>
              <option value="active">active</option>
              <option value="suspended">suspended</option>
            </Select>
          </div>
        </form>
      </Modal>

      <ConfirmDialog
        open={!!deleting}
        onClose={() => setDeleting(null)}
        onConfirm={confirmDelete}
        title="Delete user"
        confirmText="Delete"
        loading={deleteBusy}
        message={deleting ? `Permanently delete ${deleting.name} (${deleting.email})? This cannot be undone.` : ''}
      />
    </div>
  );
}
