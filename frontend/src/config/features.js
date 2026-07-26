// Front-end feature switches.
//
// SHOW_FINANCIALS — "Financial Decoupling" (added 2026-06-29, pre-demo).
// While this is `false`, every COMPUTED money display is hidden across the UI:
// account balances, customer wallets / deposits, invoice & payment totals, and
// maintenance cost / net-margin calculations. This avoids showing figures that
// could be inconsistent while the backend reconciliation for the API sync is
// still being aligned.
//
// What this does NOT do: it never removes operational record-keeping. Invoice
// upload, workshop-event CRUD and payment entry all keep working — only the
// rolled-up money NUMBERS are hidden from view.
//
// To restore all financial widgets after the demo / once the backend is aligned,
// flip this single flag back to `true`.
export const SHOW_FINANCIALS = true;

// SHOW_VIDEO_REVIEW — "Supervisor Video-Review" gate (parked 2026-07-04, pre-launch).
// While this is `false`, every UI element for the video-review stage is hidden: the
// "Video Review" board lane (repair_review), the Video Evidence upload/watch panel on
// the ticket drawer, and the approve / request-re-fix affordances. The backend behaves
// in lock-step (config/features.php → video_review): "Mark Ready" flows straight to the
// final re-inspection gate, so no ticket ever lands in this stage.
//
// The architecture is intact — flip this back to `true` (and FEATURE_VIDEO_REVIEW=true
// on the backend) to re-enable the whole gate exactly as it was.
export const SHOW_VIDEO_REVIEW = false;

// DEMO_MODE — arms the admin-only Simulation Panel (added 2026-07-06, for team demos).
// While `false`, the "Simulation" entry is hidden from the sidebar so the panel stays out of the
// way in normal use. Flip to `true` to surface it for a demo. This is only the UI mirror — the
// backend independently refuses every simulation action unless FEATURE_DEMO_MODE=true is also set
// (config/features.php → demo_mode), so a stray page open can never touch data on its own.
export const DEMO_MODE = false;

// SHOW_FLEET_INTELLIGENCE — the Phase-1 Fleet Intelligence layer (added 2026-07-21).
// Gates the NEW intelligence surfaces (economic profit on Profitability, and — as they land —
// the Cost Intelligence and Service-Due boards). Kept OFF so each PR ships DARK: the code is in
// production but nothing new is visible until we flip this one flag. This is deliberately
// SEPARATE from SHOW_FINANCIALS so the intelligence layer can be revealed WITHOUT un-parking the
// customer-balance widgets (a different reconciliation concern). Money numbers inside these
// surfaces still honour SHOW_FINANCIALS via the usual nav `financial: true` gating.
export const SHOW_FLEET_INTELLIGENCE = true;

// Shared gate for the Workspace catalog / KPIs — resolves a declarative `flag`
// field ('financial' | 'intel') to its live switch. One place so the launcher,
// KPI strip and Action Center never drift on how a flag is interpreted.
export function isFeatureEnabled(flag) {
  if (flag === 'financial') return SHOW_FINANCIALS;
  if (flag === 'intel') return SHOW_FLEET_INTELLIGENCE;
  return true;
}
