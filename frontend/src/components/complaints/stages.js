// Complaint lifecycle statuses — the shared frontend vocabulary, mirroring App\Models\Complaint
// (STATUS_*) and App\Services\ComplaintService (STATUS_META). One place, so the table chip, the filter
// tabs and the KPI cards never drift.
// Lifecycle order: NEW → NOTIFIED → CONTACTED → IN_MAINTENANCE → RESOLVED → CLOSED.

export const STAGE_ORDER = ['new', 'notified', 'contacted', 'in_maintenance', 'resolved', 'closed'];

export const STAGE_META = {
  new:            { label: 'New',            emoji: '🆕', tone: 'slate' },
  notified:       { label: 'Notified',       emoji: '🔔', tone: 'amber' },
  contacted:      { label: 'Contacted',      emoji: '📞', tone: 'blue' },
  in_maintenance: { label: 'In maintenance', emoji: '🔧', tone: 'violet' },
  resolved:       { label: 'Resolved',       emoji: '✅', tone: 'emerald' },
  closed:         { label: 'Closed',         emoji: '🗂️', tone: 'slate' },
};

// The triage decision vocabulary (mirrors App\Models\Complaint DECISION_*).
export const DECISION_META = {
  continue_driving:      { label: 'Continue driving',     emoji: '🚗' },
  bring_for_inspection:  { label: 'Bring for inspection', emoji: '🔍' },
  replace_vehicle:       { label: 'Replace vehicle',      emoji: '🔀' },
  roadside_assistance:   { label: 'Roadside assistance',  emoji: '🛟' },
};
