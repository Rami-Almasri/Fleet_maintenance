import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import Button from '../components/ui/Button';
import { Input } from '../components/ui/Field';
import { useToast } from '../components/ui/Toast';
import { Card, PageHeader, EmptyState } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import { Skeleton } from '../components/ui/Skeleton';
import Icon from '../components/ui/Icon';
import { aed2, fmtDate, num } from '../lib/format';

/**
 * Quick Cost Input — closes the understated-spend gap behind Negative Yield. Lists recent
 * repairs that carry no cost; entering an amount writes it to the workshop log and instantly
 * re-computes that vehicle's Real-Net-Profit yield (flagging it if it just turned negative).
 */
export default function QuickCostInput() {
  const toast = useToast();
  const [repairs, setRepairs] = useState([]);
  const [windowMonths, setWindowMonths] = useState(12);
  const [loading, setLoading] = useState(true);
  const [amounts, setAmounts] = useState({});   // id -> input string
  const [saving, setSaving] = useState({});     // id -> bool
  const [done, setDone] = useState(0);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/Maintenance/cost-capture');
      setRepairs(data.data.repairs || []);
      setWindowMonths(data.data.window_months || 12);
    } catch (e) {
      toast.error('Could not load uncosted repairs');
    } finally {
      setLoading(false);
    }
  }, [toast]);

  useEffect(() => { load(); }, [load]);

  const save = async (r) => {
    const raw = amounts[r.id];
    const cost = Number(raw);
    if (raw == null || raw === '' || Number.isNaN(cost) || cost < 0) {
      toast.error('Enter a valid repair amount');
      return;
    }
    setSaving((s) => ({ ...s, [r.id]: true }));
    try {
      const { data } = await api.post(`/Maintenance/cost-capture/${r.id}`, { cost });
      const res = data.data;
      setRepairs((list) => list.filter((x) => x.id !== r.id));   // drop the costed repair
      setDone((d) => d + 1);
      if (res.became_negative) {
        toast.error(`📉 ${r.plate || 'Vehicle'} is now NEGATIVE YIELD — earns less than it costs to keep`);
      } else {
        toast.success(`Saved ${aed2(cost)} · ${r.plate || 'vehicle'} repair spend updated`);
      }
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not save the cost');
    } finally {
      setSaving((s) => ({ ...s, [r.id]: false }));
    }
  };

  return (
    <div className="py-8">
      <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Quick Cost Input"
          subtitle={`Recent repairs (last ${windowMonths} months) with no cost recorded. Enter the amount to fix each vehicle's repair spend and re-check its Negative-Yield flag.`}
        />

        <MetricGrid cols={2}>
          <MetricCard
            label="Awaiting Cost"
            value={num(repairs.length)}
            tone="amber"
            icon={<Icon.Wrench className="h-5 w-5" />}
            hint={`Recent repairs (last ${windowMonths} months) with no cost recorded`}
            tooltip="Repairs found in the workshop log that carry no cost — these understate spend and hide Negative-Yield vehicles."
          />
          <MetricCard
            label="Costed This Session"
            value={num(done)}
            tone={done > 0 ? 'emerald' : 'slate'}
            icon={<Icon.Check className="h-5 w-5" />}
            hint={done > 0 ? 'Repair spend & Negative-Yield flags updated' : 'Enter an amount on any repair below'}
            tooltip="Repairs you have costed since opening this page. Each save instantly re-computes that vehicle's Real-Net-Profit yield."
          />
        </MetricGrid>

        {loading ? (
          <div className="space-y-3">
            {Array.from({ length: 5 }).map((_, i) => (
              <Card key={i} className="p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                  <div className="min-w-0 space-y-2">
                    <Skeleton className="h-4 w-40" />
                    <Skeleton className="h-3 w-64" />
                  </div>
                  <Skeleton className="h-10 w-56 shrink-0 rounded-xl" />
                </div>
              </Card>
            ))}
          </div>
        ) : repairs.length === 0 ? (
          <Card className="p-2">
            <EmptyState
              title={done > 0 ? 'All caught up — nice work!' : 'No uncosted repairs'}
              message={done > 0 ? 'Every recent repair now has a cost. Repair spend and Negative-Yield flags are up to date.' : 'Every recent repair already has a cost recorded.'}
            />
          </Card>
        ) : (
          <div className="space-y-3">
            {repairs.map((r) => (
              <Card key={r.id} className="p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <Link to={`/vehicles/${r.vehicle_id}`} className="text-sm font-bold text-slate-900 hover:text-indigo-600">
                        {r.plate || r.code || `#${r.vehicle_id}`}
                      </Link>
                      {r.car && <span className="text-sm text-slate-500">{r.car}</span>}
                      {r.events > 1 && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500">{r.events} events</span>}
                    </div>
                    <p className="mt-1 text-xs text-slate-500">
                      {r.problem && <span className="font-medium text-slate-700">{r.problem}</span>}
                      {r.problem && (r.garage || r.out_date) && <span> · </span>}
                      {r.garage && <span>{r.garage}</span>}
                      {r.garage && r.out_date && <span> · </span>}
                      {r.out_date && <span>{fmtDate(r.out_date)}{r.in_date && r.in_date !== r.out_date && <> → {fmtDate(r.in_date)}</>}</span>}
                    </p>
                  </div>
                  <div className="flex shrink-0 items-center gap-2">
                    <div className="relative">
                      <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs font-medium text-slate-400">AED</span>
                      <Input
                        type="number"
                        min="0"
                        step="0.01"
                        inputMode="decimal"
                        placeholder="0.00"
                        className="w-36 pl-11"
                        value={amounts[r.id] ?? ''}
                        onChange={(e) => setAmounts((a) => ({ ...a, [r.id]: e.target.value }))}
                        onKeyDown={(e) => { if (e.key === 'Enter') save(r); }}
                      />
                    </div>
                    <Button onClick={() => save(r)} disabled={saving[r.id]}>
                      {saving[r.id] ? 'Saving…' : 'Save'}
                    </Button>
                  </div>
                </div>
              </Card>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
