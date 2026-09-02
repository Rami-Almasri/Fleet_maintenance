// Odoo Mappings — the admin surface for the four decisions the financial bridge depends on (§39).
//
//   Vehicles  → Odoo analytic accounts   (what makes a cost roll up against the CAR)
//   Parts     → Odoo products
//   Suppliers → Odoo partners
//   Expense types → an account AND a document type, set independently
//
// The screen is built around one idea: emptying the backlog. The default view is "not mapped yet",
// because a list of everything already answered is not work. Suggestions are offered per row, and they
// are always PROPOSALS — confirming one is a click, because a suggestion the system accepted on its own
// would be name matching wearing a mapping's clothes (§18).
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useI18n } from '../i18n/I18nContext';
import { usePermissions } from '../hooks/usePermissions';
import {
  listExpenseTypes,
  listMappings,
  mappingOptions,
  mappingSuggestions,
  odooAccountOptions,
  odooHealth,
  saveExpenseType,
  saveMapping,
} from '../api/financial';

const KINDS = [
  { key: 'vehicle', labelKey: 'odooMappings.tab.vehicles', label: 'Vehicles → Analytic accounts' },
  { key: 'part', labelKey: 'odooMappings.tab.parts', label: 'Parts → Products' },
  { key: 'supplier', labelKey: 'odooMappings.tab.suppliers', label: 'Suppliers → Partners' },
  { key: 'expense_type', labelKey: 'odooMappings.tab.expenseTypes', label: 'Expense types → Accounts' },
];

/** The connection banner. Says "not connected" plainly — that is a different problem from an unmapped row. */
function ConnectionBanner({ health, tf }) {
  if (!health) return null;

  if (!health.configured) {
    return (
      <div className="rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-700 ring-1 ring-inset ring-slate-200">
        {tf('odooMappings.notConfigured',
          'Odoo is not connected in this environment. Mappings can still be prepared, but nothing will sync until the connection details are set.')}
      </div>
    );
  }

  return health.connected ? (
    <div className="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-800 ring-1 ring-inset ring-emerald-200">
      {tf('odooMappings.connected', 'Connected to Odoo')} {health.version ? `(${health.version})` : ''}
    </div>
  ) : (
    <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-inset ring-red-200">
      {tf('odooMappings.notConnected', 'Odoo is configured but could not be reached')}
      {health.error ? `: ${health.error}` : '.'}
    </div>
  );
}

// The six states a mapping can be in. They are genuinely different answers and must never be collapsed
// into "mapped / not mapped": "suggested" means a matcher proposed something nobody confirmed, "stale"
// means Odoo no longer has the record, "none" means somebody looked and the answer is legitimately
// nothing, and "changed" means it is mapped but has been re-pointed since documents were created.
const STATES = {
  mapped: { tone: 'text-emerald-700', key: 'odooMappings.state.mapped', label: 'Mapped' },
  changed: { tone: 'text-emerald-700', key: 'odooMappings.state.changed', label: 'Mapped · re-pointed' },
  suggested: { tone: 'text-amber-700', key: 'odooMappings.state.suggested', label: 'Suggested — needs confirming' },
  stale: { tone: 'text-red-700', key: 'odooMappings.state.stale', label: 'Stale — the Odoo record is gone' },
  none: { tone: 'text-slate-500', key: 'odooMappings.state.none', label: 'No counterpart' },
  unmapped: { tone: 'text-amber-700', key: 'odooMappings.state.unmapped', label: 'Not mapped' },
};

/**
 * What this row resolves to, and how far that answer can be trusted.
 *
 * A usable mapping shows the Odoo record; anything else shows WHY it cannot be used. A `changed`
 * mapping shows both — it posts, and it says what it used to point at, because that is the row an
 * auditor needs to see when a previously-posted document looks wrong.
 */
function StateCell({ mapping, tf }) {
  const state = STATES[mapping?.displayState ?? mapping?.state] || STATES.unmapped;

  return (
    <div className="text-end">
      {mapping?.usable ? (
        <span className="text-sm text-emerald-700">
          {mapping.odoo_name || `#${mapping.odoo_id}`}
          {mapping.odoo_ref && <span className="ml-1 text-[11px] text-slate-400">({mapping.odoo_ref})</span>}
        </span>
      ) : (
        <span className={`text-sm ${state.tone}`}>{tf(state.key, state.label)}</span>
      )}

      <p className="text-[11px] text-slate-400">
        {tf(state.key, state.label)}
        {mapping?.matched_by && ` · ${tf('odooMappings.via', 'via {by}', { by: mapping.matched_by })}`}
        {mapping?.confirmed_at && ` · ${tf('odooMappings.confirmed', 'confirmed')} ${mapping.confirmed_at.slice(0, 10)}`}
        {mapping?.last_synced_at && ` · ${tf('odooMappings.seen', 'seen in Odoo')} ${mapping.last_synced_at.slice(0, 10)}`}
      </p>

      {mapping?.change_count > 0 && (
        <p className="text-[11px] text-amber-700">
          {tf('odooMappings.wasPointingAt', 'was {name}', {
            name: mapping.previous_odoo_name || `#${mapping.previous_odoo_id}`,
          })}
          {mapping.change_count > 1 && ` · ${tf('odooMappings.changes', '{n} changes', { n: mapping.change_count })}`}
        </p>
      )}
    </div>
  );
}

/** One mappable row: what it is, what it points at, and how to change that. */
function MappingRow({ kind, row, options, canEdit, onSaved, tf }) {
  const [open, setOpen] = useState(false);
  const [suggestions, setSuggestions] = useState(null);
  const [saving, setSaving] = useState(false);

  const mapping = row.mapping;
  const usable = mapping?.usable;

  const save = async (odooId, option) => {
    setSaving(true);
    try {
      await saveMapping(kind, row.id, {
        odoo_id: odooId,
        odoo_ref: option?.code ?? null,
        odoo_name: option?.name ?? null,
      });
      onSaved();
      setOpen(false);
    } finally {
      setSaving(false);
    }
  };

  const loadSuggestions = async () => {
    setSuggestions(await mappingSuggestions(kind, row.id).then((d) => d.items ?? []));
  };

  return (
    <li className="border-b border-slate-100 px-4 py-3 last:border-0">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate text-sm font-medium text-slate-800">{row.label}</p>
          {row.sublabel && <p className="truncate text-[11px] text-slate-400">{row.sublabel}</p>}
        </div>

        <div className="flex items-center gap-3">
          <StateCell mapping={mapping} tf={tf} />

          {canEdit && (
            <button
              type="button"
              onClick={() => { setOpen((v) => !v); if (!suggestions) loadSuggestions(); }}
              className="rounded-md px-2.5 py-1 text-sm text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-50"
            >
              {usable ? tf('odooMappings.change', 'Change') : tf('odooMappings.map', 'Map')}
            </button>
          )}
        </div>
      </div>

      {open && canEdit && (
        <div className="mt-3 rounded-md bg-slate-50 p-3">
          {suggestions?.length > 0 && (
            <div className="mb-3">
              <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                {tf('odooMappings.suggestions', 'Suggestions')}
              </p>
              <div className="mt-1 flex flex-wrap gap-2">
                {suggestions.map((s) => (
                  <button
                    key={s.odoo_id}
                    type="button"
                    disabled={saving}
                    onClick={() => save(s.odoo_id, s)}
                    className="rounded-full bg-white px-3 py-1 text-sm text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-100 disabled:opacity-50"
                    title={tf('odooMappings.matchedBy', 'Matched by {by}', { by: s.matched_by })}
                  >
                    {s.name}
                    {s.code && <span className="ml-1 text-[11px] text-slate-400">({s.code})</span>}
                  </button>
                ))}
              </div>
            </div>
          )}

          <label className="block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
            {tf('odooMappings.pick', 'Pick an Odoo record')}
          </label>
          <select
            className="mt-1 w-full rounded-md border-slate-300 text-sm"
            disabled={saving}
            defaultValue={mapping?.odoo_id ?? ''}
            onChange={(e) => {
              const id = e.target.value === '' ? null : Number(e.target.value);
              save(id, options.find((o) => o.odoo_id === id));
            }}
          >
            <option value="">{tf('odooMappings.noCounterpart', '— no Odoo counterpart —')}</option>
            {options.map((o) => (
              <option key={o.odoo_id} value={o.odoo_id}>
                {o.name}{o.code ? ` (${o.code})` : ''}
              </option>
            ))}
          </select>
          <p className="mt-1 text-[11px] text-slate-400">
            {tf('odooMappings.noCounterpartHint',
              'Choosing “no counterpart” records that this deliberately has none, and takes it out of the backlog.')}
          </p>
        </div>
      )}
    </li>
  );
}

/** Expense types: two independent answers per row — the account, and the document it becomes. */
function ExpenseTypeTab({ canEdit, tf }) {
  const [rows, setRows] = useState([]);
  const [docTypes, setDocTypes] = useState([]);
  const [accounts, setAccounts] = useState([]);

  const load = useCallback(async () => {
    const [types, accs] = await Promise.all([listExpenseTypes(), odooAccountOptions({ limit: 200 })]);
    setRows(types.items ?? []);
    setDocTypes(types.document_types ?? []);
    setAccounts(accs.items ?? []);
  }, []);

  useEffect(() => { load(); }, [load]);

  const patch = async (expenseType, payload) => {
    await saveExpenseType(expenseType, payload);
    load();
  };

  return (
    <div className="overflow-x-auto">
      <table className="min-w-full text-sm">
        <thead className="text-[11px] uppercase tracking-wide text-slate-500">
          <tr className="border-b border-slate-200">
            <th className="px-4 py-2 text-start">{tf('odooMappings.col.type', 'Expense type')}</th>
            <th className="px-4 py-2 text-start">{tf('odooMappings.col.account', 'Odoo account')}</th>
            <th className="px-4 py-2 text-start">{tf('odooMappings.col.document', 'Document')}</th>
            <th className="px-4 py-2 text-start">{tf('odooMappings.col.receipt', 'Receipt required')}</th>
            <th className="px-4 py-2 text-start">{tf('odooMappings.col.active', 'Active')}</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.expense_type} className="border-b border-slate-100 last:border-0">
              <td className="px-4 py-3">
                <p className="font-medium text-slate-800">{r.label}</p>
                {/* The intended account, carried from config as a label — display only, never the
                    identifier. It is here so somebody resolving the account knows which one to pick. */}
                {!r.odoo_account_id && r.odoo_account_name && (
                  <p className="text-[11px] text-amber-700">
                    {tf('odooMappings.intended', 'Intended')}: {r.odoo_account_name}
                  </p>
                )}
              </td>
              <td className="px-4 py-3">
                <select
                  className="w-full min-w-[16rem] rounded-md border-slate-300 text-sm"
                  disabled={!canEdit}
                  value={r.odoo_account_id ?? ''}
                  onChange={(e) => patch(r.expense_type, {
                    odoo_account_id: e.target.value === '' ? null : Number(e.target.value),
                    odoo_account_name: accounts.find((a) => a.odoo_id === Number(e.target.value))?.name ?? null,
                  })}
                >
                  <option value="">{tf('odooMappings.unresolved', '— not resolved —')}</option>
                  {accounts.map((a) => (
                    <option key={a.odoo_id} value={a.odoo_id}>{a.code ? `${a.code} · ` : ''}{a.name}</option>
                  ))}
                </select>
              </td>
              <td className="px-4 py-3">
                <select
                  className="rounded-md border-slate-300 text-sm"
                  disabled={!canEdit}
                  value={r.odoo_document_type ?? ''}
                  onChange={(e) => patch(r.expense_type, { odoo_document_type: e.target.value || null })}
                >
                  <option value="">—</option>
                  {docTypes.map((d) => <option key={d.value} value={d.value}>{d.label}</option>)}
                </select>
              </td>
              {/* Three states, not a checkbox: "follow the document type" is the normal answer and must
                  stay distinguishable from an explicit yes or no. The effective outcome is shown
                  beside it so nobody has to remember what the default is. */}
              <td className="px-4 py-3">
                <select
                  className="rounded-md border-slate-300 text-sm"
                  disabled={!canEdit}
                  value={r.requires_attachment === null || r.requires_attachment === undefined ? '' : String(r.requires_attachment)}
                  onChange={(e) => patch(r.expense_type, {
                    requires_attachment: e.target.value === '' ? null : e.target.value === 'true',
                  })}
                >
                  <option value="">{tf('odooMappings.receiptDefault', 'Default')}</option>
                  <option value="true">{tf('odooMappings.receiptYes', 'Always required')}</option>
                  <option value="false">{tf('odooMappings.receiptNo', 'Not required')}</option>
                </select>
                <span className="ms-2 text-[11px] text-slate-400">
                  {r.requirements?.attachment
                    ? tf('odooMappings.receiptEffectiveYes', 'blocks without a receipt')
                    : tf('odooMappings.receiptEffectiveNo', 'no receipt needed')}
                </span>
              </td>
              <td className="px-4 py-3">
                <input
                  type="checkbox"
                  disabled={!canEdit}
                  checked={!!r.active}
                  onChange={(e) => patch(r.expense_type, { active: e.target.checked })}
                />
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {accounts.length === 0 && (
        <p className="px-4 py-3 text-sm text-amber-700">
          {tf('odooMappings.noAccounts',
            'No Odoo accounts have been pulled yet. Run `php artisan odoo:pull-master-data` so there is something to choose from.')}
        </p>
      )}
    </div>
  );
}

export default function OdooMappings() {
  const { tf } = useI18n();
  const { can } = usePermissions();
  const canEdit = can('financial.manage_mappings');

  const [tab, setTab] = useState('vehicle');
  const [health, setHealth] = useState(null);
  const [rows, setRows] = useState([]);
  const [options, setOptions] = useState([]);
  const [search, setSearch] = useState('');
  const [unmappedOnly, setUnmappedOnly] = useState(true);
  const [loading, setLoading] = useState(true);

  useEffect(() => { odooHealth().then(setHealth).catch(() => setHealth(null)); }, []);

  const load = useCallback(async () => {
    if (tab === 'expense_type') return;
    setLoading(true);
    try {
      const [list, opts] = await Promise.all([
        listMappings(tab, { search, unmapped: unmappedOnly ? 1 : 0, per_page: 50 }),
        mappingOptions(tab, { limit: 100 }),
      ]);
      setRows(list.items ?? []);
      setOptions(opts.items ?? []);
    } finally {
      setLoading(false);
    }
  }, [tab, search, unmappedOnly]);

  useEffect(() => { load(); }, [load]);

  const cacheEmpty = useMemo(() => options.length === 0, [options]);

  return (
    <div className="space-y-4 p-6">
      <header>
        <h1 className="text-xl font-semibold text-slate-900">{tf('odooMappings.title', 'Odoo mappings')}</h1>
        <p className="mt-1 text-sm text-slate-500">
          {tf('odooMappings.subtitle',
            'What each FleetView record is in Odoo. Nothing is posted to the accounting system until these are answered.')}
        </p>
      </header>

      <ConnectionBanner health={health} tf={tf} />

      <nav className="flex flex-wrap gap-2">
        {KINDS.map((k) => (
          <button
            key={k.key}
            type="button"
            onClick={() => setTab(k.key)}
            className={[
              'rounded-md px-3 py-1.5 text-sm font-medium',
              tab === k.key ? 'bg-slate-900 text-white' : 'text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-50',
            ].join(' ')}
          >
            {tf(k.labelKey, k.label)}
          </button>
        ))}
      </nav>

      <div className="rounded-lg border border-slate-200 bg-white">
        {tab === 'expense_type' ? (
          <ExpenseTypeTab canEdit={canEdit} tf={tf} />
        ) : (
          <>
            <div className="flex flex-wrap items-center gap-3 border-b border-slate-200 px-4 py-3">
              <input
                type="search"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder={tf('odooMappings.search', 'Search…')}
                className="w-64 rounded-md border-slate-300 text-sm"
              />
              <label className="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" checked={unmappedOnly} onChange={(e) => setUnmappedOnly(e.target.checked)} />
                {tf('odooMappings.unmappedOnly', 'Only what still needs mapping')}
              </label>
            </div>

            {cacheEmpty && (
              <p className="px-4 py-3 text-sm text-amber-700">
                {tf('odooMappings.cacheEmpty',
                  'No Odoo records have been pulled yet. Run `php artisan odoo:pull-master-data` so there is something to choose from.')}
              </p>
            )}

            {loading ? (
              <p className="px-4 py-6 text-sm text-slate-400">{tf('odooMappings.loading', 'Loading…')}</p>
            ) : rows.length === 0 ? (
              <p className="px-4 py-6 text-sm text-emerald-700">
                {unmappedOnly
                  ? tf('odooMappings.allMapped', 'Nothing left to map here.')
                  : tf('odooMappings.empty', 'Nothing to show.')}
              </p>
            ) : (
              <ul>
                {rows.map((row) => (
                  <MappingRow
                    key={row.id}
                    kind={tab}
                    row={row}
                    options={options}
                    canEdit={canEdit}
                    onSaved={load}
                    tf={tf}
                  />
                ))}
              </ul>
            )}
          </>
        )}
      </div>
    </div>
  );
}
