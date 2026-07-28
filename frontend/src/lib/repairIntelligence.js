// Fleet Knowledge Engine (P0) client — "Previous Similar Repairs + Recommendation Explanation".
//
// Read-only intelligence over maintenance history. The backend (RepairIntelligenceController) returns the
// FROZEN contract via RepairIntelligencePresenter — this client never touches internal shapes. Cost fields
// are redacted server-side for users without billing.view; `financials_visible` tells us which.
//
// Contract: { state, message, recommendation{action,summary,confidence,likely_cause,suggested_garage,
//   expected_parts[],expected_cost,expected_duration,recurrence_risk}, statistics, similar_repairs[],
//   explanation{why[],evidence[]}, financials_visible }.

import api from '../api/client';

/** Intelligence for an EXISTING fault (a maintenance_task id). */
export async function getRepairIntelligenceForTask(taskId) {
  const res = await api.get(`/maintenance-tasks/${taskId}/repair-intelligence`);
  return res.data?.data || null;
}

/** Intelligence for a vehicle + a symptom being typed, BEFORE a fault row exists (registration preview). */
export async function previewRepairIntelligence({ vehicleId, symptom, categoryKey, faultCatalogId }) {
  const res = await api.post('/repair-intelligence/preview', {
    vehicle_id: vehicleId,
    symptom: symptom || undefined,
    category_key: categoryKey || undefined,
    fault_catalog_id: faultCatalogId || undefined,
  });
  return res.data?.data || null;
}

// ── Presentation helpers (tone maps mirror the ui palette used across the drawer) ──────────────────

export const CONFIDENCE_TONE = {
  high: 'text-emerald-700 bg-emerald-50 ring-emerald-200',
  medium: 'text-amber-700 bg-amber-50 ring-amber-200',
  low: 'text-slate-600 bg-slate-100 ring-slate-200',
};

export const RISK_TONE = {
  low: 'text-emerald-700 bg-emerald-50 ring-emerald-200',
  medium: 'text-amber-700 bg-amber-50 ring-amber-200',
  high: 'text-red-700 bg-red-50 ring-red-200',
};

export const OUTCOME_TONE = {
  verified_fixed: 'text-emerald-700 bg-emerald-50 ring-emerald-200',
  fixed: 'text-slate-600 bg-slate-100 ring-slate-200',
  failed: 'text-red-700 bg-red-50 ring-red-200',
};

const TIER_ORDER = ['vehicle', 'model', 'make', 'fleet'];

/** Group the flat similar_repairs array by tier, preserving the vehicle→model→make→fleet order. */
export function groupByTier(similarRepairs = []) {
  const groups = {};
  for (const r of similarRepairs) {
    (groups[r.tier] = groups[r.tier] || []).push(r);
  }
  return TIER_ORDER.filter((tier) => groups[tier]?.length).map((tier) => ({ tier, rows: groups[tier] }));
}

/** A cost band → "745–795" (or a single median). Null-safe. */
export function fmtCostBand(band) {
  if (!band || (band.p25 == null && band.median == null)) return null;
  if (band.p25 != null && band.p75 != null && band.p25 !== band.p75) return `${Math.round(band.p25)}–${Math.round(band.p75)}`;
  return `${Math.round(band.median ?? band.p25)}`;
}

/** A duration band → "4–8d" (or "6d"). Null-safe. */
export function fmtDurationBand(band) {
  if (!band || (band.p25 == null && band.median == null)) return null;
  if (band.p25 != null && band.p75 != null && Math.round(band.p25) !== Math.round(band.p75)) {
    return `${Math.round(band.p25)}–${Math.round(band.p75)}d`;
  }
  return `${Math.round(band.median ?? band.p25)}d`;
}
