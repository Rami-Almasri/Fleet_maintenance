import api from './client';

// The Odoo financial bridge, as the UI sees it.
//
// Everything here is a thin wrapper over the API — no business logic lives in the client. In
// particular the UI never decides whether an event may be sent, what is blocking it, or which buttons
// to show: the server answers all three (see FinancialEventResource), and the panel renders the
// answer. A client that re-derived any of it would drift from the routes and start showing buttons
// that 403.

export const listFinancialEvents = (params = {}) =>
  api.get('/financial-events', { params }).then((r) => r.data.data);

export const getFinancialEvent = (id) =>
  api.get(`/financial-events/${id}`).then((r) => r.data.data);

export const financialSummary = () =>
  api.get('/financial-events/summary').then((r) => r.data.data);

/** Re-check against the mappings as they stand now — the action after fixing one. */
export const validateFinancialEvent = (id) =>
  api.post(`/financial-events/${id}/validate`).then((r) => r.data.data);

/**
 * Send it. Queued by default; `now` runs it inline and returns the outcome in the response, which is
 * what the panel wants — a user who pressed "Send to Odoo" is waiting for an answer, not for a poll.
 */
export const syncFinancialEvent = (id, { now = true } = {}) =>
  api.post(`/financial-events/${id}/sync`, null, { params: now ? { now: 1 } : {} }).then((r) => r.data.data);

export const retryFinancialEvent = (id) =>
  api.post(`/financial-events/${id}/retry`).then((r) => r.data.data);

export const approveFinancialEvent = (id) =>
  api.post(`/financial-events/${id}/approve`).then((r) => r.data.data);

export const cancelFinancialEvent = (id, reason) =>
  api.post(`/financial-events/${id}/cancel`, { reason }).then((r) => r.data.data);

// ── Mappings ────────────────────────────────────────────────────────────────────────────────────

export const odooHealth = () => api.get('/odoo/health').then((r) => r.data.data);

export const listMappings = (kind, params = {}) =>
  api.get(`/odoo/mappings/${kind}`, { params }).then((r) => r.data.data);

export const mappingOptions = (kind, params = {}) =>
  api.get(`/odoo/mappings/${kind}/options`, { params }).then((r) => r.data.data);

export const mappingSuggestions = (kind, id) =>
  api.get(`/odoo/mappings/${kind}/${id}/suggestions`).then((r) => r.data.data);

/** Pass `odoo_id: null` to record that there is deliberately no counterpart. */
export const saveMapping = (kind, id, payload) =>
  api.put(`/odoo/mappings/${kind}/${id}`, payload).then((r) => r.data.data);

export const removeMapping = (kind, id) => api.delete(`/odoo/mappings/${kind}/${id}`);

export const listExpenseTypes = () => api.get('/odoo/expense-types').then((r) => r.data.data);

export const saveExpenseType = (expenseType, payload) =>
  api.put(`/odoo/expense-types/${expenseType}`, payload).then((r) => r.data.data);

export const odooAccountOptions = (params = {}) =>
  api.get('/odoo/accounts', { params }).then((r) => r.data.data);

/** Record what a tow cost, on the ticket that already holds the recovery leg. */
export const recordRecoveryCost = (ticketId, formData) =>
  api.post(`/maintenance-tickets/${ticketId}/recovery-cost`, formData).then((r) => r.data.data);
