import { useCallback, useMemo, useState } from 'react';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useToast } from '../components/ui/Toast';
import { usePermissions } from '../hooks/usePermissions';
import { useI18n } from '../i18n/I18nContext';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import { Card, PageHeader, SearchInput, TableSkeleton, EmptyState } from '../components/ui/Misc';
import { Select, Textarea } from '../components/ui/Field';
import { num } from '../lib/format';
import WarrantyForm from '../components/warranties/WarrantyForm';
import WarrantyClaims from '../components/warranties/WarrantyClaims';

/**
 * The warranty register — every promise a supplier or a garage made us, and whether it still holds.
 *
 * THE ONE THING THIS PAGE MUST NOT DO is show an expiry date as if it were the answer. A warranty
 * ends when EITHER leg runs out — months or kilometres, whichever comes first — and in a rental
 * fleet the distance leg is usually the binding one: a car doing 6,000 km a month burns 20,000 km of
 * cover in ten weeks while its 12-month date still looks perfectly healthy. So every row leads with
 * the server's computed verdict, and the dates are shown as supporting detail.
 *
 * Where the verdict says `distance_unknown`, the row says so rather than implying cover. A warranty
 * measured in kilometres and judged with no odometer is not "probably fine".
 */

const STATE_TONE = { active: 'green', expired: 'red', void: 'gray' };

export default function Warranties() {
  const toast = useToast();
  const { can } = usePermissions();
  const { t } = useI18n();
  const canRecord = can('parts.purchase') || can('maintenance.manage');
  const canAdjudicate = can('parts.investigate') || can('maintenance.manage');

  const [kind, setKind] = useState('');
  const [status, setStatus] = useState('');
  const [expiring, setExpiring] = useState('');
  const [search, setSearch] = useState('');

  const fetcher = useCallback(async () => {
    const params = new URLSearchParams({ per_page: '100' });
    if (kind) params.set('kind', kind);
    if (status) params.set('status', status);
    if (expiring) params.set('expiring_days', expiring);
    const { data } = await api.get(`/warranties?${params.toString()}`);
    return data.data || {};
  }, [kind, status, expiring]);

  const { data, loading, error, reload } = useFetch(fetcher, [kind, status, expiring]);

  const warranties = useMemo(() => data?.warranties || [], [data]);
  const summary = data?.summary || { total: 0, active: 0, expiring_soon: 0, expired: 0, void: 0, distance_unknown: 0 };

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [claimsFor, setClaimsFor] = useState(null);
  const [voiding, setVoiding] = useState(null);
  const [voidReason, setVoidReason] = useState('');
  const [busy, setBusy] = useState(false);

  const apiError = (err, fallback) => {
    if (!err?.response) return t('warranties.networkError');
    if (err.response.status === 403) return t('warranties.permissionDenied');
    return err.response.data?.message || fallback;
  };

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return warranties;
    return warranties.filter((w) =>
      [w.subject, w.provider_name, w.reference_no, w.vehicle?.plate_no]
        .some((f) => (f || '').toLowerCase().includes(q)));
  }, [warranties, search]);

  const confirmVoid = async () => {
    setBusy(true);
    try {
      await api.post(`/warranties/${voiding.id}/void`, { void_reason: voidReason });
      toast.success(t('warranties.voided'));
      setVoiding(null);
      setVoidReason('');
      reload();
    } catch (err) {
      toast.error(apiError(err, t('warranties.voidError')));
    } finally {
      setBusy(false);
    }
  };

  const reinstate = async (w) => {
    try {
      await api.post(`/warranties/${w.id}/reinstate`);
      toast.success(t('warranties.reinstated'));
      reload();
    } catch (err) {
      toast.error(apiError(err, t('warranties.reinstateError')));
    }
  };

  const legs = (w) => {
    const out = [];
    if (w.duration_months) out.push(t('warranties.nMonths', { n: num(w.duration_months) }));
    if (w.duration_km) out.push(t('warranties.nKm', { n: num(w.duration_km) }));
    return out.length ? out.join(t('warranties.orJoin')) : t('warranties.noLimit');
  };

  const tiles = [
    { key: 'total', value: summary.total, tone: 'text-slate-900' },
    { key: 'active', value: summary.active, tone: 'text-emerald-600' },
    { key: 'expiring_soon', value: summary.expiring_soon, tone: summary.expiring_soon > 0 ? 'text-amber-600' : 'text-slate-900' },
    { key: 'expired', value: summary.expired, tone: 'text-slate-500' },
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title={t('warranties.title')}
          subtitle={loading ? '…' : t('warranties.subtitle', { shown: num(filtered.length), total: num(summary.total) })}
        >
          {canRecord && (
            <Button onClick={() => { setEditing(null); setFormOpen(true); }}>
              {t('warranties.record')}
            </Button>
          )}
        </PageHeader>

        {/* Coverage summary — counted across everything that matches the filters, not just the page
            on screen, because a tile counting one page is a lie that looks like a number. */}
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          {tiles.map((tile) => (
            <div key={tile.key} className="rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft">
              <p className="text-xs font-medium text-slate-500">{t(`warranties.tile.${tile.key}`)}</p>
              <p className={`mt-1 font-display text-2xl font-bold tracking-tight ${tile.tone}`}>
                {loading ? '…' : num(tile.value)}
              </p>
            </div>
          ))}
        </div>

        {/* A km-bounded warranty judged with no odometer is not "probably fine" — say it plainly. */}
        {!loading && summary.distance_unknown > 0 && (
          <div className="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-inset ring-amber-600/20">
            {t('warranties.distanceUnknownWarning', { n: num(summary.distance_unknown) })}
          </div>
        )}

        <div className="flex flex-col gap-3 sm:flex-row">
          <SearchInput className="flex-1" value={search} onChange={setSearch} placeholder={t('warranties.search')} />
          <Select className="sm:w-44" value={kind} onChange={(e) => setKind(e.target.value)}>
            <option value="">{t('warranties.allKinds')}</option>
            <option value="part">{t('warranties.kind.part')}</option>
            <option value="repair">{t('warranties.kind.repair')}</option>
          </Select>
          <Select className="sm:w-40" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">{t('warranties.allStatuses')}</option>
            <option value="active">{t('warranties.statusActive')}</option>
            <option value="void">{t('warranties.statusVoid')}</option>
          </Select>
          <Select className="sm:w-44" value={expiring} onChange={(e) => setExpiring(e.target.value)}>
            <option value="">{t('warranties.anyExpiry')}</option>
            <option value="30">{t('warranties.expiring30')}</option>
            <option value="90">{t('warranties.expiring90')}</option>
          </Select>
        </div>

        {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

        <Card>
          <div className="overflow-x-auto">
            <table className="min-w-full border-separate border-spacing-0 text-sm">
              <thead className="bg-slate-50/90">
                <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                  {['colSubject', 'colVehicle', 'colKind', 'colProvider', 'colCover', 'colVerdict', 'colActions'].map((k) => (
                    <th key={k} className={`whitespace-nowrap border-b border-slate-200 px-5 py-3 ${k === 'colActions' ? 'text-end' : 'text-start'}`}>
                      {t(`warranties.${k}`)}
                    </th>
                  ))}
                </tr>
              </thead>

              {loading ? <TableSkeleton cols={7} /> : (
                <tbody>
                  {filtered.map((w) => (
                    <tr key={w.id} className="bg-white even:bg-slate-50/40 hover:bg-indigo-50/40">
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <div className="font-medium text-slate-900" dir="auto">{w.subject}</div>
                        {w.reference_no && <div className="text-xs text-slate-400">{w.reference_no}</div>}
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">{w.vehicle?.plate_no || '—'}</td>
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <Badge tone={w.kind === 'repair' ? 'violet' : 'blue'}>{t(`warranties.kind.${w.kind}`)}</Badge>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">{w.provider_name || '—'}</td>
                      <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">
                        <div>{legs(w)}</div>
                        <div className="text-xs text-slate-400">
                          {w.expires_on || '—'}
                          {w.expires_at_km ? ` · ${num(w.expires_at_km)} km` : ''}
                        </div>
                      </td>

                      {/* THE ANSWER. Never the date alone. */}
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <Badge tone={STATE_TONE[w.verdict?.state] || 'gray'}>
                          {t(`warranties.state.${w.verdict?.state}`)}
                          {w.verdict?.ended_by ? ` · ${t(`warranties.endedBy.${w.verdict.ended_by}`)}` : ''}
                        </Badge>
                        <div className="mt-0.5 text-xs text-slate-500">{w.verdict?.evidence}</div>
                        {w.verdict?.distance_unknown && (
                          <div className="text-xs text-amber-600">{t('warranties.noOdometer')}</div>
                        )}
                      </td>

                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <div className="flex justify-end gap-2">
                          <Button variant="secondary" size="sm" onClick={() => setClaimsFor(w)}>
                            {t('warranties.claims')}{w.claims_count ? ` (${w.claims_count})` : ''}
                          </Button>
                          {canRecord && w.status === 'active' && (
                            <Button variant="secondary" size="sm" onClick={() => { setEditing(w); setFormOpen(true); }}>
                              {t('common.edit')}
                            </Button>
                          )}
                          {canAdjudicate && (w.status === 'active'
                            ? <Button variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" onClick={() => setVoiding(w)}>{t('warranties.void')}</Button>
                            : <Button variant="ghost" size="sm" onClick={() => reinstate(w)}>{t('warranties.reinstate')}</Button>)}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              )}
            </table>

            {!loading && filtered.length === 0 && (
              <EmptyState title={t('warranties.empty')} message={t('warranties.emptyHint')} />
            )}
          </div>
        </Card>

        <p className="text-xs text-slate-500">{t('warranties.dataOrigin')}</p>
      </div>

      {formOpen && (
        <WarrantyForm
          warranty={editing}
          onClose={() => setFormOpen(false)}
          onSaved={() => { setFormOpen(false); reload(); }}
        />
      )}

      {claimsFor && (
        <WarrantyClaims
          warranty={claimsFor}
          canFile={canRecord}
          canAdjudicate={canAdjudicate}
          onClose={() => setClaimsFor(null)}
          onChanged={reload}
        />
      )}

      {/* Voiding needs a reason: "void" with no why cannot be defended when the supplier disputes it. */}
      <Modal
        open={!!voiding}
        onClose={() => !busy && setVoiding(null)}
        title={t('warranties.voidTitle')}
        subtitle={voiding?.subject}
        footer={
          <>
            <Button variant="secondary" onClick={() => setVoiding(null)} disabled={busy}>{t('common.cancel')}</Button>
            <Button variant="danger" onClick={confirmVoid} loading={busy} disabled={!voidReason.trim()}>
              {t('warranties.void')}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <p className="text-sm text-slate-600">{t('warranties.voidHint')}</p>
          <Textarea
            label={t('warranties.voidReason')}
            required
            rows={3}
            value={voidReason}
            onChange={(e) => setVoidReason(e.target.value)}
          />
        </div>
      </Modal>
    </div>
  );
}
