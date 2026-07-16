import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { ContractTypeBadge } from '../../components/ui/Badge';
import { CommandPanel, StatGaugeTile } from '../../components/ops';
import { fmtDate, num } from '../../lib/format';

// Expected return = out date + contracted days (OfficeManager has no due-date field). Mirrors the
// same derivation the contract detail page uses.
function dueDate(out, days) {
  if (!out || !(Number(days) > 0)) return null;
  const d = new Date(out);
  d.setDate(d.getDate() + Number(days));
  return d.toISOString().slice(0, 10);
}

// Collapse the compact readiness verdict into one chip: red when a car can't be handed over, amber
// when it's deliverable but carrying advisories, green when it's clean. Null while readiness is absent.
function readinessChip(r) {
  if (!r) return { tone: 'blocked', label: 'No vehicle' };
  if (r.blocked) return { tone: 'crit', label: `Blocked · ${r.blocker_count}` };
  if (r.warn_count > 0) return { tone: 'paused', label: `Ready · ${r.warn_count} advisory` };
  return { tone: 'avail', label: 'Ready' };
}

function RentalRow({ r }) {
  const chip = readinessChip(r.readiness);
  const due = dueDate(r.out_date, r.days);
  const overdue = r.contract_type === 'C' && !r.in_date && due && new Date(due) < new Date();
  const vehLabel = r.vehicle?.plate_no || [r.vehicle?.make, r.vehicle?.model].filter(Boolean).join(' ') || '—';
  const rail = overdue || r.readiness?.blocked ? 'rt-crit' : (r.readiness && !r.readiness.blocked && !r.readiness.warn_count ? 'rt-avail' : '');
  return (
    <tr className={rail}>
      <td>
        <Link to={`/contracts/${r.id}`} className="opx-plate2">#{r.contract_no || r.id}</Link>
        <div className="opx-sub" style={{ marginTop: 4 }}><ContractTypeBadge type={r.contract_type} /></div>
      </td>
      <td>
        {r.vehicle ? (
          <Link to={`/vehicles/${r.vehicle.id}`} className="opx-mono2" style={{ textDecoration: 'none', color: 'var(--ink)' }}>{vehLabel}</Link>
        ) : <span className="opx-sub">—</span>}
        {r.readiness?.condition_grade && r.readiness.condition_grade !== 'green' && (
          <span className="opx-sub" style={{ marginLeft: 8, textTransform: 'capitalize' }}>{r.readiness.condition_grade}</span>
        )}
      </td>
      <td>
        {r.customer ? (
          <Link to={`/customers/${r.customer.id}`} style={{ fontSize: 13, color: 'var(--ink-2)', textDecoration: 'none' }}>
            {r.customer.name_en || `#${r.customer.customer_no}`}
          </Link>
        ) : <span className="opx-sub">—</span>}
      </td>
      <td className="opx-mono2">{fmtDate(r.out_date) || '—'}</td>
      <td className="opx-mono2">
        {due ? <span style={overdue ? { color: 'var(--crit)', fontWeight: 700 } : undefined}>{fmtDate(due)}{overdue ? ' · overdue' : ''}</span> : '—'}
      </td>
      <td className="r">
        <span className={`opx-chip ${chip.tone}`}><span className="cd" />{chip.label}</span>
        {r.readiness && <span className="opx-sub" style={{ marginLeft: 8, fontFamily: 'var(--mono)' }}>{r.readiness.pass_count}/{r.readiness.total}</span>}
      </td>
    </tr>
  );
}

/**
 * Rental Operations Hub — the Rental Manager's check-in / check-out board. Every car that's out on a
 * rental or reserved on a booking, each carrying the live 9-point readiness verdict for its vehicle so
 * the manager can see at a glance which cars are fit to hand over. Open a row for the full checklist.
 */
export default function RentalOperationsHub() {
  const [q, setQ] = useState('');
  const [onlyBlocked, setOnlyBlocked] = useState(false);

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/RentalOperations');
    return data.data;
  }, []);
  const { data, loading, error } = useFetch(fetcher, [], { refreshInterval: 60000 });

  const rows = useMemo(() => data?.items || [], [data]);
  const readyCount = useMemo(() => rows.filter((r) => r.readiness && !r.readiness.blocked).length, [rows]);
  const total = data?.total || rows.length;
  const blocked = data?.blocked || 0;

  const shown = useMemo(() => {
    const needle = q.trim().toLowerCase();
    return rows.filter((r) => {
      if (onlyBlocked && !(r.readiness?.blocked)) return false;
      if (!needle) return true;
      return [r.contract_no, r.vehicle?.plate_no, r.vehicle?.make, r.vehicle?.model, r.customer?.name_en]
        .filter(Boolean).some((s) => String(s).toLowerCase().includes(needle));
    });
  }, [rows, q, onlyBlocked]);

  return (
    <div className="opx">
      <div className="opx-body">
        <div style={{ marginBottom: 18 }}>
          <div className="opx-hint" style={{ letterSpacing: '.16em', textTransform: 'uppercase', marginBottom: 6 }}>Check-in · Check-out Control</div>
          <h1 className="font-display" style={{ fontSize: 24, fontWeight: 700, letterSpacing: '-.02em', color: 'var(--ink)', margin: 0 }}>Rental Operations</h1>
          <p style={{ marginTop: 6, maxWidth: 720, fontSize: 13.5, color: 'var(--ink-3)', lineHeight: 1.5 }}>
            Every active rental and upcoming booking with its car's live 9-point readiness — open a contract for the full condition checklist before you hand the keys over.
          </p>
        </div>

        {error && (
          <div style={{ marginBottom: 16, borderRadius: 12, border: '1px solid rgba(251,113,133,.3)', background: 'rgba(251,113,133,.08)', color: '#fb7185', padding: '12px 16px', fontSize: 13 }}>{error}</div>
        )}

        {/* KPI strip */}
        <div className="opx-grid opx-c12" style={{ marginBottom: 16 }}>
          <div className="opx-span-4">
            <StatGaugeTile label="Active & Upcoming" value={loading ? '—' : num(total)} hint="Rentals out + bookings" tone="cyan" icon="calendar" percent={100} />
          </div>
          <div className="opx-span-4">
            <StatGaugeTile label="Ready to Hand Over" value={loading ? '—' : num(readyCount)} hint="Passes the readiness gate" tone="avail" icon="check" percent={total ? (readyCount / total) * 100 : 0} />
          </div>
          <div className="opx-span-4">
            <StatGaugeTile label="Blocked" value={loading ? '—' : num(blocked)} hint="Cannot hand over yet" tone={blocked ? 'crit' : 'avail'} icon="alert" percent={total ? (blocked / total) * 100 : 4} active={onlyBlocked} onClick={() => setOnlyBlocked((v) => !v)} />
          </div>
        </div>

        {/* Rentals & bookings — telemetry table */}
        <CommandPanel
          title="Rentals & Bookings"
          dotColor="#22d3ee"
          label={loading ? 'loading' : `${shown.length} of ${rows.length}`}
          meta="active rentals (car out) · active/upcoming bookings"
          bodyFlush
        >
          <div className="opx-toolbar">
            <input
              className="opx-input"
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder="Search plate, customer, contract…"
            />
            <button
              type="button"
              onClick={() => setOnlyBlocked((v) => !v)}
              className={`opx-ibtn ${onlyBlocked ? 'danger' : ''}`}
              style={onlyBlocked ? { borderColor: 'var(--crit)', color: 'var(--crit)' } : undefined}
            >
              ⚠ Blocked only
            </button>
          </div>

          <div className="opx-tblwrap">
            <table className="opx-tbl">
              <thead>
                <tr>
                  <th>Contract</th>
                  <th>Vehicle</th>
                  <th>Customer</th>
                  <th>Out</th>
                  <th>Due Back</th>
                  <th className="r">Readiness</th>
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <tr><td colSpan={6}><div className="opx-skel" style={{ height: 260 }} /></td></tr>
                ) : shown.length ? shown.map((r) => <RentalRow key={r.id} r={r} />) : (
                  <tr><td colSpan={6}><div className="opx-empty"><div className="big">🅿️</div>{rows.length ? 'No rentals match.' : 'No active rentals or upcoming bookings.'}</div></td></tr>
                )}
              </tbody>
            </table>
          </div>
        </CommandPanel>
      </div>
    </div>
  );
}
