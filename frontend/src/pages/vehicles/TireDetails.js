import { useCallback } from 'react';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { usePermissions } from '../../hooks/usePermissions';
import DataTable, { SectionCard } from '../../components/ui/Table';
import Badge from '../../components/ui/Badge';
import { Skeleton } from '../../components/ui/Skeleton';
import { aed2, fmtDate, num } from '../../lib/format';
import { SHOW_FINANCIALS } from '../../config/features';
import { useI18n } from '../../i18n/I18nContext';

/**
 * Tire Details — every tyre installed on this car (brand · DOT · tread depth · warranty), newest
 * install first, straight from the maintenance line items (category 'tyres'). Lives on the
 * Maintenance tab of the Vehicle Profile. Lazily self-fetches only when that tab is opened, and
 * hides entirely for users without maintenance.view (like the workflow panel), so the tab degrades
 * cleanly. Line cost is shown only when SHOW_FINANCIALS is on.
 * Fed by GET /Vehicle/{id}/tire-history.
 */

// Legal minimum tread is ~1.6 mm; under 3 mm is "replace soon". Colour the depth accordingly.
const treadTone = (mm) => (mm == null ? 'gray' : mm < 1.6 ? 'red' : mm < 3 ? 'amber' : 'green');

// A DOT code ends in WWYY (week + 2-digit year). Turn it into an approximate age in years so an
// aging tyre is obvious even if its tread still looks fine (rubber perishes ~6 yr regardless).
const dotAgeYears = (dot) => {
  const m = String(dot || '').match(/(\d{2})(\d{2})\s*$/);
  if (!m) return null;
  const week = Number(m[1]);
  if (week < 1 || week > 53) return null;
  const made = new Date(2000 + Number(m[2]), 0, 1 + (week - 1) * 7);
  if (isNaN(made)) return null;
  const years = (Date.now() - made.getTime()) / (365.25 * 24 * 3600 * 1000);
  return years >= 0 ? years : null;
};

export default function TireDetails({ vehicleId }) {
  const { t } = useI18n();
  const { can } = usePermissions();
  const allowed = can('maintenance.view');

  const fetcher = useCallback(async () => {
    if (!allowed) return { items: [] };
    return (await api.get(`/Vehicle/${vehicleId}/tire-history`)).data.data;
  }, [vehicleId, allowed]);
  const { data, loading } = useFetch(fetcher, [vehicleId, allowed]);
  const items = data?.items || [];

  if (!allowed) return null;

  const worn = items.filter((row) => row.tread_mm != null && row.tread_mm < 1.6).length;

  return (
    <SectionCard
      title={t('Tire Details')}
      subtitle={t('Every tyre fitted to this car — brand, DOT age, tread depth & warranty — newest first.')}
      actions={(
        <div className="flex items-center gap-2">
          {worn > 0 && <Badge tone="red">{t('{n} below legal tread', { n: worn })}</Badge>}
          <Badge tone="gray">{items.length === 1 ? t('1 tyre') : t('{n} tyres', { n: items.length })}</Badge>
        </div>
      )}
    >
      {loading ? (
        <div className="space-y-2 p-4"><Skeleton className="h-12 rounded-lg" /><Skeleton className="h-12 rounded-lg" /></div>
      ) : (
        <DataTable
          rows={items}
          rowKey={(row) => row.id}
          empty={t('No tyre records yet — captured when a tyre line is added to a maintenance ticket (category “tyres”).')}
          columns={[
            {
              key: 'installed', header: t('Installed'), cellClass: 'whitespace-nowrap',
              render: (row) => (
                <div>
                  <span className="font-medium text-slate-800">{row.installed_on ? fmtDate(row.installed_on) : '—'}</span>
                  {row.installed_odometer != null && <div className="text-xs text-slate-400">{num(row.installed_odometer)} km</div>}
                </div>
              ),
            },
            {
              key: 'brand', header: t('Brand / Item'),
              render: (row) => (
                <div className="min-w-0">
                  <span className="font-medium text-slate-800">{row.brand || row.description || '—'}</span>
                  <div className="text-xs text-slate-400">
                    {row.quantity > 1 && <span>×{num(row.quantity)} </span>}
                    {row.part_number && <span className="font-mono">{row.part_number}</span>}
                  </div>
                </div>
              ),
            },
            {
              key: 'dot', header: 'DOT', tooltip: t('Manufacture code (week + year). Age matters — rubber perishes around 6 years old regardless of tread.'),
              render: (row) => {
                if (!row.dot) return <span className="text-slate-300">—</span>;
                const age = dotAgeYears(row.dot);
                return (
                  <div>
                    <span className="font-mono text-slate-700">{row.dot}</span>
                    {age != null && (
                      <div className={`text-xs ${age >= 6 ? 'font-semibold text-red-500' : 'text-slate-400'}`}>
                        {age >= 6 ? t('{years} yr · aged', { years: age.toFixed(1) }) : t('{years} yr', { years: age.toFixed(1) })}
                      </div>
                    )}
                  </div>
                );
              },
            },
            {
              key: 'tread', header: t('Tread'), align: 'center',
              tooltip: t('Remaining tread depth. Under 1.6 mm is below the legal minimum; under 3 mm, plan a replacement.'),
              render: (row) => (row.tread_mm == null
                ? <span className="text-slate-300">—</span>
                : <Badge tone={treadTone(row.tread_mm)}>{row.tread_mm} mm</Badge>),
            },
            {
              key: 'warranty', header: t('Warranty'),
              render: (row) => {
                if (!row.warranty_until) return <span className="text-slate-300">—</span>;
                const active = new Date(row.warranty_until) >= new Date(new Date().toDateString());
                return active
                  ? <Badge tone="green">{t('until {date}', { date: fmtDate(row.warranty_until) })}</Badge>
                  : <span className="text-xs text-slate-400">{t('expired {date}', { date: fmtDate(row.warranty_until) })}</span>;
              },
            },
            { key: 'garage', header: t('Garage'), cellClass: 'text-slate-500', render: (row) => row.garage || '—' },
            ...(SHOW_FINANCIALS ? [{
              key: 'cost', header: t('Cost'), align: 'right',
              cellClass: 'tabular-nums text-slate-600',
              render: (row) => (Number(row.cost) > 0 ? aed2(row.cost) : '—'),
            }] : []),
          ]}
        />
      )}
    </SectionCard>
  );
}
