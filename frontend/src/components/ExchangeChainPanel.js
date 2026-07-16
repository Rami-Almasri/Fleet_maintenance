import { useCallback, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useToast } from './ui/Toast';
import Badge from './ui/Badge';
import Button from './ui/Button';
import { Card } from './ui/Misc';
import { aed2, fmtDate } from '../lib/format';
import { SHOW_FINANCIALS } from '../config/features';

const gapLabel = (h) => {
  if (h === null || h === undefined) return null;
  if (h === 0) return 'same day';
  if (h < 0) return `${Math.abs(h)}h overlap`;
  return `${h}h gap`;
};

// One node in the chain timeline.
function Node({ n }) {
  return (
    <div className={`flex items-center gap-3 rounded-xl border px-4 py-3 ${n.is_current ? 'border-indigo-300 bg-indigo-50/60 ring-1 ring-indigo-200' : 'border-slate-200 bg-white'}`}>
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <Link to={`/contracts/${n.id}`} className="truncate font-semibold text-indigo-600 hover:text-indigo-700">
            #{n.contract_no || n.id}
          </Link>
          {n.is_current && <Badge tone="indigo">This contract</Badge>}
          {n.state && <Badge tone={n.state === 'open' ? 'green' : 'gray'}>{n.state}</Badge>}
          {SHOW_FINANCIALS && Number(n.carried_balance) > 0 && <Badge tone="emerald" className="font-semibold">Carried {aed2(n.carried_balance)}</Badge>}
        </div>
        <div className="mt-0.5 truncate text-xs text-slate-500">
          {n.vehicle || '—'} · out {fmtDate(n.out_date)}{n.in_date ? ` · in ${fmtDate(n.in_date)}` : ' · not returned'}
        </div>
      </div>
    </div>
  );
}

export default function ExchangeChainPanel({ contract }) {
  const toast = useToast();
  const id = contract.id;
  const fetcher = useCallback(async () => {
    const { data } = await api.get(`/Contract/${id}/exchange`);
    return data.data;
  }, [id]);
  const { data, loading, reload } = useFetch(fetcher, [id]);
  const [busy, setBusy] = useState(false);

  // Nothing to show while loading, or when there's neither a chain nor a suggestion.
  const chain = data?.chain || [];
  const cand = data?.candidates || { parent: null, children: [] };
  const linked = chain.length > 1;
  const hasSuggestion = cand.parent || (cand.children && cand.children.length > 0);
  if (loading || (!linked && !hasSuggestion)) return null;

  const act = async (fn, okMsg) => {
    setBusy(true);
    try {
      await fn();
      toast.success(okMsg);
      reload();
    } catch (e) {
      toast.error(e.response?.data?.message || 'Action failed');
    } finally {
      setBusy(false);
    }
  };

  // Link this contract (child) to a suggested parent.
  const linkToParent = (p, carry) => act(
    () => api.post(`/Contract/${id}/exchange/link`, { parent_id: p.parent_id, carry }),
    carry === 'both' ? `Linked & recorded ${aed2(p.carried_total)} to carry` : 'Exchange linked',
  );
  // Link a suggested child to this contract (parent).
  const linkChild = (ch, carry) => act(
    () => api.post(`/Contract/${ch.child_id}/exchange/link`, { parent_id: id, carry }),
    carry === 'both' ? `Linked & recorded ${aed2(ch.carried_total)} to carry` : 'Exchange linked',
  );
  const unlink = () => act(() => api.delete(`/Contract/${id}/exchange/link`), 'Exchange link removed');

  // A suggestion banner with Link / Link & Carry buttons.
  const Suggestion = ({ pair, onLink, label }) => (
    <div className="rounded-xl border border-indigo-200 bg-indigo-50/50 p-4">
      <p className="text-sm text-slate-700">
        {label}{' '}
        <span className="font-semibold">{pair.parent_vehicle}</span>
        {' → '}
        <span className="font-semibold">{pair.child_vehicle}</span>
        {' '}
        <Badge tone="slate">{gapLabel(pair.gap_hours)}</Badge>
        {pair.held_days > 0 && <span className="ms-1 text-xs text-slate-500">held {pair.held_days}d</span>}
      </p>
      {SHOW_FINANCIALS && pair.carried_total > 0 && (
        <p className="mt-1 text-xs text-slate-600">
          Carry-over available: <span className="font-semibold text-emerald-700">{aed2(pair.carried_total)}</span>
          {' '}<span className="text-slate-400">(deposit {aed2(pair.parent_deposit)} + credit {aed2(pair.parent_credit)})</span>
        </p>
      )}
      <div className="mt-3 flex flex-wrap gap-2">
        {SHOW_FINANCIALS && pair.carried_total > 0 && (
          <Button size="sm" variant="success" loading={busy} disabled={busy} onClick={() => onLink(pair, 'both')}>
            Link &amp; Carry Balance
          </Button>
        )}
        <Button size="sm" variant={SHOW_FINANCIALS && pair.carried_total > 0 ? 'secondary' : 'primary'} loading={busy} disabled={busy} onClick={() => onLink(pair, 'none')}>
          Link only
        </Button>
        <Link to={`/contracts/${pair.parent_id === id ? pair.child_id : pair.parent_id}`} className="inline-flex items-center px-2 text-xs font-medium text-indigo-600 hover:text-indigo-700">
          Review other contract →
        </Link>
      </div>
    </div>
  );

  return (
    <Card className="p-6">
      <div className="mb-4 flex items-center justify-between">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-400">Chain of Contracts</h3>
        {linked && (
          <Button size="sm" variant="ghost" loading={busy} disabled={busy} onClick={unlink}>Unlink this contract</Button>
        )}
      </div>

      {linked ? (
        <div className="space-y-2">
          {chain.map((n, i) => (
            <div key={n.id}>
              {i > 0 && (
                <div className="flex items-center gap-2 py-1 ps-4 text-xs text-slate-400">
                  <svg aria-hidden="true" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2"><path strokeLinecap="round" strokeLinejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3" /></svg>
                  {gapLabel(n.gap_hours) || 'swap'}
                </div>
              )}
              <Node n={n} />
            </div>
          ))}
        </div>
      ) : (
        <p className="mb-3 text-sm text-slate-500">This rental looks like part of a car swap. Review and link it so the history reads as one continuous journey.</p>
      )}

      {(cand.parent || (cand.children && cand.children.length > 0)) && (
        <div className="mt-4 space-y-3">
          {cand.parent && <Suggestion pair={cand.parent} onLink={linkToParent} label="Likely swapped from" />}
          {(cand.children || []).map((ch) => (
            <Suggestion key={ch.child_id} pair={ch} onLink={linkChild} label="Likely swapped to" />
          ))}
        </div>
      )}
    </Card>
  );
}
