import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Drawer from './ui/Drawer';
import Badge from './ui/Badge';
import { Skeleton } from './ui/Skeleton';
import { aed2, num, fmtDate } from '../lib/format';

// ---- Value formatting per unit -----------------------------------------------------------------
// Money → AED with 2dp; per-km ratio → 4dp (fractions of a fils matter at fleet scale); counts/
// distances → plain number + unit. A null value is "not measured" (—), never a fake 0.
function fmtVal(value, unit) {
  if (value == null) return '—';
  if (unit === 'AED') return aed2(value);
  if (unit === 'AED/km') return `AED ${Number(value).toLocaleString('en-AE', { minimumFractionDigits: 4, maximumFractionDigits: 4 })} / km`;
  if (unit === 'AED/day') return `${aed2(value)} / day`;
  if (unit === 'AED/rental') return `${aed2(value)} / rental`;
  if (unit === 'km') return `${num(value)} km`;
  if (unit === 'km/day') return `${num(value)} km/day`;
  if (unit === 'days') return `${num(value)} days`;
  if (unit === 'rentals') return `${num(value)} rentals`;
  if (unit === 'years') return `${num(value)} years`;
  if (!unit) return num(value);
  return `${num(value)} ${unit}`;
}

// A raw record field: dates rendered nicely, money as AED, everything else as-is.
function fmtField(key, v) {
  if (v == null || v === '') return <span className="text-slate-300">—</span>;
  if (/date|paid on|returned|as of/i.test(key) && /^\d{4}-\d{2}-\d{2}/.test(String(v))) return fmtDate(v);
  if (/price|cost|total|amount|discount|value|rent|usage|collected/i.test(key) && typeof v === 'number') return aed2(v);
  return String(v);
}

// ---- Confidence — how much to trust a value, and why --------------------------------------------
const CONFIDENCE_TONE = {
  measured: 'emerald', imported: 'blue', calculated: 'indigo', estimated: 'amber',
  corrected: 'violet', validated: 'green', missing: 'red',
};
function ConfidenceBadge({ value }) {
  if (!value) return null;
  return <Badge tone={CONFIDENCE_TONE[value] || 'slate'} className="text-[10px]">{value}</Badge>;
}

// ---- Reconciliation strip — proves the number ties out to its sources ---------------------------
function Recon({ recon, unit }) {
  if (!recon) return null;
  const dec = unit === 'AED/km' ? 4 : 2;
  const f = (n) => Number(n).toLocaleString('en-AE', { minimumFractionDigits: dec, maximumFractionDigits: dec });
  return (
    <div className={`rounded-xl px-4 py-3 text-xs ring-1 ring-inset ${recon.ok ? 'bg-emerald-50 text-emerald-800 ring-emerald-600/15' : 'bg-red-50 text-red-800 ring-red-600/25'}`}>
      <div className="mb-1.5 flex items-center justify-between">
        <span className="font-semibold">{recon.ok ? '✓ Reconciled — the number ties out' : '⚠ Discrepancy — this does not tie out'}</span>
        <span className="text-[11px] opacity-70">{recon.basis}</span>
      </div>
      <div className="grid grid-cols-3 gap-2 tabular-nums">
        <div><div className="opacity-60">Displayed value</div><div className="font-semibold">{f(recon.displayed)}</div></div>
        <div><div className="opacity-60">Sum of sources</div><div className="font-semibold">{f(recon.source_sum)}</div></div>
        <div><div className="opacity-60">Difference</div><div className="font-semibold">{f(recon.difference)} {recon.ok ? '✓' : '✗'}</div></div>
      </div>
    </div>
  );
}

// ---- Business-rule card — WHY a decision was taken (rule + measured + threshold + result) -------
function BusinessRule({ rule, onOpen }) {
  if (!rule) return null;
  const matched = /matched|overdue|approaching/i.test(rule.result || '');
  const Line = ({ label, item }) => (
    <div className="flex items-baseline justify-between gap-4 py-1 text-sm">
      <span className="text-slate-500">{label}</span>
      {item.ref ? (
        <button type="button" onClick={() => onOpen(item.ref)} className="font-medium text-indigo-700 underline decoration-dotted underline-offset-2 tabular-nums">
          {fmtVal(item.value, item.unit)} ▸
        </button>
      ) : (
        <span className="font-medium tabular-nums text-slate-900">{fmtVal(item.value, item.unit)}</span>
      )}
    </div>
  );
  return (
    <div className="rounded-xl border border-slate-200/70 bg-white px-4 py-3">
      <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Business rule</p>
      <p className="mb-2 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">{rule.statement}</p>
      {(rule.measured || []).map((m, i) => <Line key={i} label={m.label || 'Measured'} item={m} />)}
      {rule.threshold && <Line label={`Threshold (${rule.operator || ''})`} item={rule.threshold} />}
      <div className="mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-dashed border-slate-200 pt-2 text-sm">
        <span className="font-semibold text-slate-900">Result</span>
        <Badge tone={matched ? 'amber' : 'green'}>{rule.result}</Badge>
      </div>
      {rule.action && rule.action !== 'None' && (
        <p className="mt-1 text-xs text-slate-500">→ {rule.action}</p>
      )}
    </div>
  );
}

// ---- Formula card — the exact working; each operand with a `ref` drills deeper ------------------
function Formula({ formula, onOpen }) {
  if (!formula) return null;
  return (
    <div className="rounded-xl border border-slate-200/70 bg-white px-1 py-1 shadow-soft">
      <p className="px-3 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-slate-400">How it is calculated</p>
      <div className="divide-y divide-slate-100">
        {formula.terms.map((t, i) => {
          const clickable = !!t.ref;
          const Row = clickable ? 'button' : 'div';
          return (
            <Row
              key={i}
              type={clickable ? 'button' : undefined}
              onClick={clickable ? () => onOpen(t.ref) : undefined}
              className={`flex w-full items-center justify-between gap-3 px-3 py-2 text-start text-sm ${clickable ? 'cursor-pointer hover:bg-indigo-50/60' : ''}`}
            >
              <span className="flex items-center gap-2">
                <span className="w-4 text-center font-mono text-slate-400">{t.op}</span>
                <span className={clickable ? 'font-medium text-indigo-700 underline decoration-dotted underline-offset-2' : 'text-slate-600'}>{t.label}</span>
                {clickable && <span className="text-[10px] text-slate-400">drill ▸</span>}
              </span>
              <span className="tabular-nums font-medium text-slate-900">{fmtVal(t.value, t.unit)}</span>
            </Row>
          );
        })}
        <div className="flex items-center justify-between gap-3 border-t-2 border-slate-200 px-3 py-2.5">
          <span className="font-display text-sm font-bold text-slate-900">= {formula.result_label}</span>
          <span className="font-display tabular-nums text-base font-bold text-slate-900">{fmtVal(formula.result, formula.result_unit)}</span>
        </div>
      </div>
    </div>
  );
}

// ---- Evidence chain — supporting artifacts (measurements, invoices, records) --------------------
function Evidence({ items, onOpen }) {
  if (!items || !items.length) return null;
  return (
    <div className="rounded-xl border border-slate-200/70 bg-white">
      <p className="border-b border-slate-100 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Evidence</p>
      <div className="divide-y divide-slate-100">
        {items.map((e, i) => {
          const clickable = !!e.ref;
          const Row = clickable ? 'button' : 'div';
          return (
            <Row key={i} type={clickable ? 'button' : undefined} onClick={clickable ? () => onOpen(e.ref) : undefined}
              className={`flex w-full items-center justify-between gap-3 px-4 py-2 text-start text-sm ${clickable ? 'hover:bg-indigo-50/60' : ''}`}>
              <span className={clickable ? 'font-medium text-indigo-700' : 'text-slate-600'}>{e.label}</span>
              <span className="flex items-center gap-2">
                {e.kind && <Badge tone="slate" className="text-[10px]">{e.kind}</Badge>}
                {clickable && <span className="text-[10px] text-slate-400">▸</span>}
              </span>
            </Row>
          );
        })}
      </div>
    </div>
  );
}

// ---- Record card — raw fields of an original business transaction (leaf) ------------------------
function Record({ record }) {
  const entries = Object.entries(record).filter(([, v]) => v != null && v !== '');
  if (!entries.length) return null;
  return (
    <div className="rounded-xl border border-slate-200/70 bg-white px-4 py-3">
      <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Original record</p>
      <dl className="grid grid-cols-1 gap-x-6 gap-y-1 sm:grid-cols-2">
        {entries.map(([k, v]) => (
          <div key={k} className="flex items-baseline justify-between gap-4 border-b border-slate-50 py-1 text-sm">
            <dt className="text-slate-500">{k}</dt>
            <dd className="tabular-nums font-medium text-slate-800">{fmtField(k, v)}</dd>
          </div>
        ))}
      </dl>
    </div>
  );
}

// ---- Children list — the contributing records, each drillable -----------------------------------
// When the parent declares `children_facets`, the list gains a type filter. A ledger of 262 lines
// mixing insurance, tyres, salik and sub-rental is unreadable as one list; the chips narrow it while
// the header keeps showing the FULL total alongside the filtered subtotal, so a filtered view can
// never be mistaken for the whole number.
function Children({ ids, label, facets, facetLabel, facetNote, nodes, onOpen }) {
  const [facet, setFacet] = useState(null);
  const list = useMemo(
    () => (facet ? (ids || []).filter((id) => nodes[id]?.group?.key === facet) : ids || []),
    [ids, facet, nodes],
  );
  const subtotal = useMemo(
    () => (facet ? list.reduce((s, id) => s + (Number(nodes[id]?.value) || 0), 0) : null),
    [facet, list, nodes],
  );

  if (!ids || !ids.length) return null;
  const active = (facets || []).find((f) => f.key === facet);

  return (
    <div className="rounded-xl border border-slate-200/70 bg-white">
      <p className="border-b border-slate-100 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{label || `Contributing records (${ids.length})`}</p>

      {facets?.length > 1 && (
        <div className="border-b border-slate-100 px-3 py-2.5">
          <div className="mb-1.5 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
            {facetLabel || 'Type'}
            {facetNote && <span className="cursor-help text-slate-300" title={facetNote}>ⓘ</span>}
          </div>
          <div className="flex flex-wrap gap-1.5">
            <button
              type="button"
              onClick={() => setFacet(null)}
              className={`rounded-full px-2.5 py-1 text-[11px] font-medium ${facet === null ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
            >
              All ({ids.length})
            </button>
            {facets.map((f) => (
              <button
                key={f.key}
                type="button"
                onClick={() => setFacet((cur) => (cur === f.key ? null : f.key))}
                title={`${f.label} — ${f.count} line${f.count === 1 ? '' : 's'}, ${aed2(f.total)}`}
                className={`rounded-full px-2.5 py-1 text-[11px] font-medium ${facet === f.key ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
              >
                {f.label} ({f.count})
              </button>
            ))}
          </div>
          {active && (
            <p className="mt-2 rounded-lg bg-indigo-50/70 px-3 py-1.5 text-[11px] text-indigo-800">
              Showing <span className="font-semibold">{list.length}</span> of {ids.length} lines ·{' '}
              <span className="font-semibold tabular-nums">{aed2(subtotal)}</span> of this bucket
            </p>
          )}
        </div>
      )}

      <div className="max-h-[420px] divide-y divide-slate-100 overflow-y-auto">
        {list.map((id) => {
          const c = nodes[id];
          if (!c) return null;
          return (
            <button
              key={id}
              type="button"
              onClick={() => onOpen(id)}
              className="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-start hover:bg-indigo-50/60"
            >
              <span className="min-w-0">
                <span className="block truncate text-sm font-medium text-indigo-700">{c.label}</span>
                <span className="flex items-center gap-1.5">
                  {c.subtitle && <span className="truncate text-xs text-slate-400">{c.subtitle}</span>}
                  {c.group && !facet && (
                    <span className="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500">{c.group.label}</span>
                  )}
                </span>
              </span>
              <span className="flex shrink-0 items-center gap-2">
                <span className="tabular-nums text-sm font-semibold text-slate-900">{fmtVal(c.value, c.unit)}</span>
                <span className="text-[10px] text-slate-400">▸</span>
              </span>
            </button>
          );
        })}
      </div>
    </div>
  );
}

// ---- Excluded lines — records that exist on this vehicle but are NOT part of the total -----------
// The counterpart to Children: a total that leaves records out has to name them, or it is a smaller
// number with no explanation. Styled distinctly (amber, struck value) so it can never be misread as
// part of the sum, and still drillable down to the original record.
function Excluded({ ids, label, note, nodes, onOpen }) {
  const [open, setOpen] = useState(false);
  if (!ids || !ids.length) return null;
  return (
    <div className="rounded-xl border border-amber-300/60 bg-amber-50/40">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center justify-between gap-3 px-4 py-2 text-start"
      >
        <span className="text-xs font-semibold uppercase tracking-wide text-amber-700">{label}</span>
        <span className="text-[11px] text-amber-700">{open ? 'hide' : 'show'} {open ? '▴' : '▾'}</span>
      </button>
      {note && <p className="border-t border-amber-200/70 px-4 py-2 text-[11px] text-amber-800">{note}</p>}
      {open && (
        <div className="max-h-[320px] divide-y divide-amber-200/60 overflow-y-auto border-t border-amber-200/70">
          {ids.map((id) => {
            const c = nodes[id];
            if (!c) return null;
            return (
              <button key={id} type="button" onClick={() => onOpen(id)}
                className="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-start hover:bg-amber-100/50">
                <span className="min-w-0">
                  <span className="block truncate text-sm font-medium text-amber-900">{c.label}</span>
                  {c.subtitle && <span className="block truncate text-xs text-amber-700/70">{c.subtitle}</span>}
                </span>
                <span className="flex shrink-0 items-center gap-2">
                  <span className="tabular-nums text-sm font-medium text-amber-700 line-through">{fmtVal(c.value, c.unit)}</span>
                  <span className="text-[10px] text-amber-500">▸</span>
                </span>
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

// ---- Impact panel — reverse lineage: "what depends on this?" ------------------------------------
function Impact({ ids, nodes, onOpen }) {
  if (!ids || !ids.length) return null;
  return (
    <div className="rounded-xl border border-slate-200/70 bg-white">
      <p className="border-b border-slate-100 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
        Impact — what depends on this ({ids.length})
      </p>
      <div className="flex flex-wrap gap-2 p-3">
        {ids.map((id) => {
          const n = nodes[id];
          if (!n) return null;
          return (
            <button key={id} type="button" onClick={() => onOpen(id)}
              className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700 hover:bg-indigo-100 hover:text-indigo-700">
              {n.label} ↑
            </button>
          );
        })}
      </div>
    </div>
  );
}

// ---- Audit footer — under what conditions this was explained -----------------------------------
function Audit({ audit, context }) {
  const a = audit || {};
  const c = context || {};
  const rows = [
    ['Engine', a.engine_version || c.engine_version],
    ['Snapshot', c.snapshot],
    ['Window', a.window],
    ['Currency', a.currency || c.currency],
    ['Included records', a.included_records],
    ['Basis', a.basis],
    ['Generated', c.generated_at ? fmtDate(c.generated_at) : null],
  ].filter(([, v]) => v != null && v !== '');
  if (!rows.length) return null;
  return (
    <div className="rounded-xl border border-dashed border-slate-200 px-4 py-3 text-[11px] text-slate-500">
      <p className="mb-1 font-semibold uppercase tracking-wide text-slate-400">Audit</p>
      <div className="grid grid-cols-2 gap-x-4 gap-y-0.5">
        {rows.map(([k, v]) => (
          <div key={k} className="flex justify-between gap-2"><span>{k}</span><span className="font-medium text-slate-600">{String(v)}</span></div>
        ))}
      </div>
    </div>
  );
}

// ---- Lineage breadcrumb — the dependency chain from the root to the current node ----------------
function Lineage({ path, nodes, onJump }) {
  return (
    <div className="flex flex-wrap items-center gap-1 text-xs">
      {path.map((id, i) => {
        const n = nodes[id];
        const last = i === path.length - 1;
        return (
          <span key={`${id}-${i}`} className="flex items-center gap-1">
            {i > 0 && <span className="text-slate-300">←</span>}
            <button
              type="button"
              disabled={last}
              onClick={() => onJump(i)}
              className={last ? 'font-semibold text-slate-700' : 'text-indigo-600 hover:underline'}
            >
              {n?.label || id}
            </button>
          </span>
        );
      })}
    </div>
  );
}

// ---- Recursive node view -----------------------------------------------------------------------
function NodeView({ node, nodes, context, onOpen }) {
  if (!node) return null;
  const type = node.type || node.kind;
  return (
    <div className="space-y-4">
      <div className="rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft">
        <div className="flex items-start justify-between gap-4">
          <div className="min-w-0">
            <p className="font-display text-lg font-bold text-slate-900">{node.label}</p>
            {node.subtitle && <p className="text-xs text-slate-400">{node.subtitle}</p>}
            {node.source_module && <p className="mt-1 text-[11px] text-slate-400">Source: {node.source_module}</p>}
            {(node.links?.length ? node.links : node.link ? [node.link] : []).length > 0 && (
              <div className="mt-2 flex flex-wrap gap-2">
                {(node.links?.length ? node.links : [node.link]).map((l, i) => (
                  <Link
                    key={i}
                    to={l.to}
                    className="inline-flex items-center gap-1 rounded-lg bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-100"
                  >
                    {l.label} →
                  </Link>
                ))}
              </div>
            )}
          </div>
          <div className="flex shrink-0 flex-col items-end gap-1.5">
            {node.value != null && (
              <Badge tone={type === 'ratio' ? 'violet' : type === 'rule' ? 'amber' : 'indigo'} className="text-sm">{fmtVal(node.value, node.unit)}</Badge>
            )}
            <ConfidenceBadge value={node.confidence} />
          </div>
        </div>
        {node.note && (
          <p className={`mt-2 rounded-lg px-3 py-2 text-xs ${node.excluded ? 'bg-amber-50 text-amber-800 ring-1 ring-inset ring-amber-600/20' : 'bg-slate-50 text-slate-500'}`}>
            {node.excluded && <span className="font-semibold">Not counted in the total · </span>}
            {node.note}
          </p>
        )}
      </div>

      <BusinessRule rule={node.business_rule} onOpen={onOpen} />
      <Recon recon={node.reconciliation} unit={node.unit} />
      <Formula formula={node.formula} onOpen={onOpen} />
      <Evidence items={node.evidence} onOpen={onOpen} />
      {node.record && <Record record={node.record} />}
      <Children
        ids={node.children}
        label={node.children_label}
        facets={node.children_facets}
        facetLabel={node.children_facet_label}
        facetNote={node.children_facet_note}
        nodes={nodes}
        onOpen={onOpen}
      />
      <Excluded
        ids={node.excluded_children}
        label={node.excluded_children_label}
        note={node.excluded_note}
        nodes={nodes}
        onOpen={onOpen}
      />
      <Impact ids={node.reverse_dependencies} nodes={nodes} onOpen={onOpen} />
      <Audit audit={node.audit} context={context} />
    </div>
  );
}

function ExplainBody({ vehicleId, metric }) {
  const [asOf, setAsOf] = useState(''); // '' = live; 'YYYY-MM-DD' = historical snapshot
  const [activeModule, setActiveModule] = useState(null);

  const fetcher = useCallback(async () => {
    const q = asOf ? `?as_of=${asOf}` : '';
    const { data } = await api.get(`/intelligence/vehicle/${vehicleId}/explain${q}`);
    return data.data;
  }, [vehicleId, asOf]);
  const { data, loading, error } = useFetch(fetcher, [vehicleId, asOf]);

  // Which module owns the clicked metric (so the right tab is active on open).
  const metricModule = useMemo(() => {
    if (!data?.modules) return null;
    return data.modules.find((m) => Object.values(m.roots || {}).includes(data.roots?.[metric]))?.key
      || data.modules[0]?.key;
  }, [data, metric]);

  const module = activeModule || metricModule;

  const rootId = useMemo(() => {
    if (!data) return null;
    // When the user is on the metric's own module, honour the clicked metric; otherwise open that
    // module's primary root.
    if (module === metricModule && data.roots?.[metric]) return data.roots[metric];
    const m = data.modules?.find((x) => x.key === module);
    return (m && Object.values(m.roots || {})[0]) || data.roots?.[metric] || data.default;
  }, [data, metric, module, metricModule]);

  const [path, setPath] = useState([]);
  useEffect(() => { if (rootId) setPath([rootId]); }, [rootId]);
  // Snap the active tab back to the clicked metric's module whenever the metric/car changes.
  useEffect(() => { setActiveModule(null); }, [metric, vehicleId]);

  const open = useCallback((id) => setPath((p) => (p[p.length - 1] === id ? p : [...p, id])), []);
  const jump = useCallback((i) => setPath((p) => p.slice(0, i + 1)), []);

  if (loading) {
    return (
      <div className="space-y-4 p-4">
        <Skeleton className="h-6 w-56" />
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }
  if (error) return <div className="m-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>;
  if (!data || !path.length) return null;

  const node = data.nodes[path[path.length - 1]];

  return (
    <div className="space-y-4 p-4">
      {/* Vehicle header + snapshot */}
      <div className="flex items-center justify-between rounded-xl bg-slate-900 px-4 py-3 text-white">
        <div>
          <p className="font-display text-base font-bold">{data.vehicle.plate || `#${data.vehicle.id}`}</p>
          <p className="text-xs text-slate-300">{data.vehicle.car || '—'}</p>
        </div>
        <label className="flex items-center gap-2 text-[11px] text-slate-300">
          Snapshot
          <input
            type="date"
            value={asOf}
            max={new Date().toISOString().slice(0, 10)}
            onChange={(e) => setAsOf(e.target.value)}
            className="rounded-md border-0 bg-slate-700 px-2 py-1 text-xs text-white [color-scheme:dark]"
            title="Explain the value as of this date (blank = live)"
          />
          {asOf && (
            <button type="button" onClick={() => setAsOf('')} className="rounded bg-slate-600 px-1.5 py-0.5 hover:bg-slate-500" title="Back to live">live</button>
          )}
        </label>
      </div>

      {/* Module tabs */}
      {data.modules?.length > 1 && (
        <div className="flex flex-wrap gap-2">
          {data.modules.map((m) => (
            <button
              key={m.key}
              type="button"
              onClick={() => setActiveModule(m.key)}
              className={`rounded-full px-3 py-1 text-xs font-medium ${module === m.key ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
            >
              {m.label}
            </button>
          ))}
        </div>
      )}

      {/* Dependency chain */}
      {path.length > 1 && (
        <div className="rounded-lg bg-slate-50 px-3 py-2">
          <Lineage path={path} nodes={data.nodes} onJump={jump} />
        </div>
      )}

      <NodeView node={node} nodes={data.nodes} context={data.context} onOpen={open} />
    </div>
  );
}

/**
 * Explainability drawer — the vehicle-scoped view onto the shared Explainability platform. Opens from
 * any number on Profitability or Cost Intelligence and lets you drill recursively (formula → operands →
 * evidence → source records → original transaction) until you hit a contract, payment, invoice line or
 * purchase record. Every node proves itself (reconciliation), shows its confidence, its business rule
 * (for decisions), what depends on it (reverse lineage), and its audit context. Switch modules with the
 * tabs; change the snapshot date to explain a historical value. `metric` picks the initial root.
 */
export default function FinancialBreakdownDrawer({ vehicleId, metric, onClose }) {
  return (
    <Drawer open={!!vehicleId} onClose={onClose} width="lg" eyebrow="Explainability" title="Where did this number come from?">
      {vehicleId && <ExplainBody vehicleId={vehicleId} metric={metric} />}
    </Drawer>
  );
}
