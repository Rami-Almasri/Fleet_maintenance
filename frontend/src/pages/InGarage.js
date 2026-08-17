import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { PageHeader, SearchInput, EmptyState, ErrorState } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import DataTable, { SectionCard } from '../components/ui/Table';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import FilterChips from '../components/ui/FilterChips';
import { Select } from '../components/ui/Field';
import { useToast } from '../components/ui/Toast';
import { usePermissions } from '../hooks/usePermissions';
import { useI18n } from '../i18n/I18nContext';
import { fmtDate } from '../lib/format';

/**
 * IN THE GARAGE — the board that replaces the hand-kept list.
 *
 * Two columns carry the whole point: the CAR on the left, the GARAGE it is standing at on the
 * right. Everything else on the row (how long it has been there, what put it there) is supporting
 * detail, and the page shows the ORIGIN of each garage name rather than presenting them as one
 * uniform fact — because they are not one fact:
 *
 *   • Sent by the workflow → the Supervisor picked the garage at dispatch. The system knows it, and
 *     the cell is READ-ONLY here: moving a car to a different garage is a transfer on the ticket,
 *     not a note typed on a board.
 *   • Out on an OfficeManager maintenance contract → OM records that the car went for maintenance
 *     but has no field for WHERE. Those rows say "Not recorded yet" until a person picks the garage,
 *     and then carry that person's name and the moment they said it.
 *
 * The page never guesses. A car's last workshop trip in July says nothing about where it is today,
 * so an unrecorded row stays unrecorded and asks to be filled in.
 */

/** Where a garage name on this board came from — the sentence under the name. */
const GARAGE_ORIGIN = {
  workflow:   { label: 'From the ticket',    tone: 'indigo', hint: 'The Supervisor picked this garage when the car was dispatched.' },
  garage_log: { label: 'From the ticket',    tone: 'indigo', hint: 'The name written on the ticket. No garage record is linked to it.' },
  sheet:      { label: 'From the sheet',     tone: 'blue',   hint: 'The N-Location tab of the maintenance sheet — the list the Controllers keep.' },
  recorded:   { label: 'Recorded here',      tone: 'emerald', hint: 'OfficeManager has no garage field for a maintenance contract, so a person recorded this.' },
};

/**
 * The car as one line, the way the list was always written: make, colour, year, plate.
 * The plate NUMBER only — see InGarageService::row() for why the letter is left off.
 */
export const carLabel = (r) =>
  [
    [r.make, r.model].filter(Boolean).join(' '),
    r.color,
    r.year,
    r.plate_no,
  ].filter(Boolean).join(' - ');

/** Plain words for how long the car has been there. Null days stay honest — never "0 days". */
export const daysLabel = (days, t) => {
  if (days === null || days === undefined) return t('No start date on record');
  if (days === 0) return t('Went in today');
  return days === 1 ? t('1 day') : t('{n} days', { n: days });
};

export default function InGarage() {
  const { t, tf } = useI18n();
  const toast = useToast();
  const { can } = usePermissions();
  const canRecord = can('maintenance.manage');

  const [query, setQuery] = useState('');
  const [lane, setLane] = useState('all');
  const [editing, setEditing] = useState(null);   // the row whose garage is being recorded

  const fetcher = useCallback(async () => (await api.get('/InGarage')).data.data, []);
  const { data, loading, error, reload, mutate } = useFetch(fetcher, [], {
    refreshInterval: 120000,
    paused: () => editing !== null,
  });

  const rows = useMemo(() => data?.rows ?? [], [data]);
  const summary = data?.summary ?? {};
  const garages = useMemo(() => data?.garages ?? [], [data]);
  const sheetOnly = useMemo(() => data?.sheet_only ?? [], [data]);
  const sheet = data?.sheet ?? {};

  const lanes = [
    { key: 'all',     label: tf('inGarage.lane.all', 'All cars'), n: summary.total || 0 },
    { key: 'missing', label: tf('inGarage.lane.missing', 'Garage not recorded'), n: summary.garage_missing || 0, tone: 'amber' },
    { key: 'known',   label: tf('inGarage.lane.known', 'Garage known'), n: summary.garage_known || 0 },
  ];

  const visible = useMemo(() => {
    const q = query.trim().toLowerCase();
    return rows.filter((r) => {
      if (lane === 'missing' && r.garage.name) return false;
      if (lane === 'known' && !r.garage.name) return false;
      if (!q) return true;
      return `${carLabel(r)} ${r.garage.name ?? ''} ${r.stay.contract_no ?? ''}`.toLowerCase().includes(q);
    });
  }, [rows, lane, query]);

  // Save the picked garage and swap that ONE row in place — the board must not jump under someone
  // who is working down a list of ten cars.
  const save = async (row, vendorId) => {
    try {
      const { data: res } = await api.post(`/Contract/${row.stay.contract_id}/garage`, {
        vendor_id: vendorId === '' ? null : Number(vendorId),
      });
      const saved = res.data.row;
      mutate((prev) => (prev ? { ...prev, rows: prev.rows.map((r) => (r.vehicle_id === saved.vehicle_id ? saved : r)) } : prev));
      setEditing(null);
      toast.success(vendorId === '' ? t('Garage cleared') : t('Garage recorded'));
      reload({ silent: true });   // re-count the headline tiles
    } catch (e) {
      toast.error(e.response?.data?.message || t('Could not save the garage'));
    }
  };

  const columns = [
    {
      key: 'car',
      header: tf('inGarage.col.car', 'Car'),
      render: (r) => (
        <div>
          <Link to={`/vehicles/${r.vehicle_id}`} className="font-semibold text-slate-800 hover:text-indigo-600">
            {carLabel(r)}
          </Link>
          {!r.in_active_fleet && (
            <div className="mt-0.5 text-xs text-slate-400">
              {r.for_sale ? tf('inGarage.forSale', 'Up for sale') : r.vehicle_status}
              {' · '}{tf('inGarage.outsideFleet', 'not part of the operational fleet')}
            </div>
          )}
        </div>
      ),
    },
    {
      key: 'garage',
      header: tf('inGarage.col.garage', 'Garage'),
      render: (r) => <GarageCell row={r} canRecord={canRecord} onEdit={() => setEditing(r)} />,
    },
    {
      key: 'since',
      header: tf('inGarage.col.since', 'There since'),
      render: (r) => (
        <div>
          <div className="font-medium text-slate-700">{daysLabel(r.stay.days, t)}</div>
          <div className="text-xs text-slate-400">{r.stay.since ? fmtDate(r.stay.since) : '—'}</div>
        </div>
      ),
    },
    {
      key: 'why',
      header: tf('inGarage.col.why', 'What put it there'),
      render: (r) => <StayCell row={r} />,
    },
  ];

  if (error) return <ErrorState title={t('Could not load the board')} message={error} onRetry={reload} />;

  return (
    <div className="space-y-6">
      <PageHeader
        title={tf('inGarage.title', 'In the Garage')}
        subtitle={tf(
          'inGarage.subtitle',
          'Every car standing at a garage right now, and which garage it is at. A car is here because the workflow sent it to a garage, or because OfficeManager has an open maintenance contract on it. The garage comes from the ticket, from the sheet\'s N-Location tab, or from someone recording it here — in that order, and each row says which. Nothing is guessed: where no source names a garage, the row says so and asks for it.',
        )}
      />

      <MetricGrid>
        <MetricCard label={tf('inGarage.kpi.total', 'Cars at a garage')} value={summary.total ?? 0} tone="indigo" />
        <MetricCard
          label={tf('inGarage.kpi.known', 'Garage known')}
          value={summary.garage_known ?? 0}
          tone="emerald"
          hint={tf('inGarage.kpi.knownHint', 'From the ticket, the sheet, or recorded here')}
        />
        <MetricCard
          label={tf('inGarage.kpi.missing', 'Garage not recorded')}
          value={summary.garage_missing ?? 0}
          tone={summary.garage_missing ? 'amber' : 'slate'}
          hint={tf('inGarage.kpi.missingHint', 'In a shop, and the sheet does not list it either')}
        />
        <MetricCard
          label={tf('inGarage.kpi.enRoute', 'On the way')}
          value={summary.en_route ?? 0}
          tone="slate"
          hint={tf('inGarage.kpi.enRouteHint', 'Dispatched, not arrived yet')}
        />
      </MetricGrid>

      {(summary.by_garage?.length ?? 0) > 0 && (
        <SectionCard
          title={tf('inGarage.byGarage.title', 'Cars per garage')}
          subtitle={tf('inGarage.byGarage.subtitle', 'Counted from the names on this board — cars whose garage is not recorded are not in these numbers.')}
        >
          <div className="flex flex-wrap gap-2 px-5 py-4">
            {summary.by_garage.map((g) => (
              <span key={g.name} className="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1.5 text-sm text-slate-700">
                <span className="font-medium">{g.name}</span>
                <span className="tabular-nums text-slate-500">{g.cars}</span>
              </span>
            ))}
          </div>
        </SectionCard>
      )}

      <SectionCard
        title={tf('inGarage.table.title', 'The board')}
        actions={
          <div className="flex flex-wrap items-center gap-3">
            <FilterChips options={lanes.map((l) => ({ key: l.key, label: l.label, count: l.n, tone: l.tone }))} value={lane} onChange={setLane} />
            <SearchInput value={query} onChange={setQuery} placeholder={tf('inGarage.search', 'Search car or garage…')} className="w-56" />
          </div>
        }
        bodyClass="p-0"
      >
        {!loading && visible.length === 0 ? (
          <EmptyState
            title={tf('inGarage.empty.title', 'No car is at a garage')}
            message={tf('inGarage.empty.body', 'Nothing is out on a maintenance contract and no ticket has a car at a garage right now.')}
          />
        ) : (
          <DataTable
            columns={columns}
            rows={visible}
            rowKey={(r) => r.vehicle_id}
            loading={loading}
            highlightRow={(r) => !r.garage.name}
          />
        )}
      </SectionCard>

      {sheetOnly.length > 0 && (
        <SectionCard
          title={tf('inGarage.sheetOnly.title', 'On the sheet, but not on this board')}
          subtitle={tf('inGarage.sheetOnly.subtitle', 'The N-Location tab lists these; the system does not have the car in a shop. Either the sheet row is stale, or the plate names no car we hold.')}
          bodyClass="px-5 py-4"
        >
          <ul className="space-y-2 text-sm">
            {sheetOnly.map((s) => (
              <li key={`${s.sheet_row}-${s.car_label}`} className="flex flex-wrap items-center gap-2">
                <span className="text-slate-700">{s.car_label}</span>
                <span className="text-slate-400">→</span>
                <span className="font-medium text-slate-700">{s.garage_name}</span>
                <Badge tone="amber">
                  {s.reason === 'car_not_matched'
                    ? tf('inGarage.sheetOnly.noCar', 'No car matches that plate')
                    : tf('inGarage.sheetOnly.notInShop', 'Not in a shop per the system')}
                </Badge>
                <span className="text-xs text-slate-400">{tf('inGarage.sheetOnly.row', 'row {n}', { n: s.sheet_row })}</span>
              </li>
            ))}
          </ul>
        </SectionCard>
      )}

      <p className="text-xs text-slate-400">
        {sheet.imported_at
          ? tf('inGarage.sheetRead', 'The sheet\'s N-Location tab ({n} rows) was last read {when}.', { n: sheet.rows, when: fmtDate(sheet.imported_at) })
          : tf('inGarage.sheetNever', 'The sheet\'s N-Location tab has never been read — run import:garage-locations.')}
      </p>

      <RecordGarageModal
        row={editing}
        garages={garages}
        onClose={() => setEditing(null)}
        onSave={save}
      />
    </div>
  );
}

/** The garage half of the row: the name, where it came from, and — when we don't have it — the ask. */
function GarageCell({ row, canRecord, onEdit }) {
  const { t, tf } = useI18n();
  const g = row.garage;

  if (!g.name) {
    return (
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-amber-700">{tf('inGarage.notRecorded', 'Not recorded yet')}</span>
        {row.editable && canRecord && (
          <Button size="sm" variant="secondary" onClick={onEdit}>{tf('inGarage.record', 'Record the garage')}</Button>
        )}
        {!row.editable && (
          <span className="text-xs text-slate-400">{tf('inGarage.noGarageOnTicket', 'The ticket names no garage')}</span>
        )}
      </div>
    );
  }

  const origin = GARAGE_ORIGIN[g.origin] || {};

  return (
    <div>
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-semibold text-slate-800">{g.name}</span>
        {origin.label && <Badge tone={origin.tone}>{t(origin.label)}</Badge>}
        {row.editable && canRecord && (
          <button type="button" onClick={onEdit} className="text-xs text-indigo-600 underline-offset-2 hover:underline">
            {tf('inGarage.change', 'Change')}
          </button>
        )}
      </div>
      {g.recorded_by && (
        <div className="mt-0.5 text-xs text-slate-400">
          {tf('inGarage.recordedBy', 'Recorded by {who}', { who: g.recorded_by })}
          {g.recorded_at ? ` · ${fmtDate(g.recorded_at)}` : ''}
        </div>
      )}
      {!g.recorded_by && origin.hint && <div className="mt-0.5 text-xs text-slate-400">{t(origin.hint)}</div>}
      {g.disagrees && (
        <div className="mt-1 text-xs font-medium text-amber-700">
          {tf('inGarage.disagrees', 'The sheet says {name} — the two do not agree.', { name: g.disagrees.name })}
        </div>
      )}
    </div>
  );
}

/** What put the car in a shop — the ticket, or the OfficeManager contract. Named, never implied. */
function StayCell({ row }) {
  const { tf } = useI18n();
  const s = row.stay;

  if (s.source === 'workflow_ticket') {
    return (
      <div>
        <Link to={`/maintenance-workflow/${s.ticket_id}`} className="text-indigo-600 hover:underline">
          {tf('inGarage.ticket', 'Ticket #{id}', { id: s.ticket_id })}
        </Link>
        <div className="mt-0.5 text-xs text-slate-400">
          {s.en_route ? tf('inGarage.enRoute', 'On the way to the garage') : tf('inGarage.atGarage', 'At the garage')}
          {s.contract_no ? ` · ${tf('inGarage.alsoContract', 'OM contract {no}', { no: s.contract_no })}` : ''}
        </div>
      </div>
    );
  }

  return (
    <div>
      <span className="text-slate-700">
        {s.contract_no
          ? tf('inGarage.omContract', 'OM maintenance contract {no}', { no: s.contract_no })
          : tf('inGarage.omContractNoNo', 'OM maintenance contract')}
      </span>
      <div className="mt-0.5 text-xs text-slate-400">{tf('inGarage.noTicket', 'No workflow ticket — went out through OfficeManager')}</div>
    </div>
  );
}

/** Picking the garage for one maintenance visit. Clearing it is a first-class choice. */
function RecordGarageModal({ row, garages, onClose, onSave }) {
  const { t, tf } = useI18n();
  const [vendorId, setVendorId] = useState('');
  const [saving, setSaving] = useState(false);

  // Re-seed the picker each time a different row opens the dialog.
  const key = row?.vehicle_id ?? 'none';
  const [seeded, setSeeded] = useState(key);
  if (seeded !== key) {
    setSeeded(key);
    setVendorId(row?.garage?.vendor_id ? String(row.garage.vendor_id) : '');
  }

  const submit = async () => {
    setSaving(true);
    await onSave(row, vendorId);
    setSaving(false);
  };

  return (
    <Modal
      open={!!row}
      onClose={onClose}
      title={tf('inGarage.modal.title', 'Which garage is this car at?')}
      subtitle={row ? carLabel(row) : ''}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t('Cancel')}</Button>
          <Button onClick={submit} loading={saving} disabled={saving}>
            {vendorId === '' ? tf('inGarage.modal.clear', 'Clear the garage') : tf('inGarage.modal.save', 'Save')}
          </Button>
        </div>
      }
    >
      <p className="mb-4 text-sm text-slate-500">
        {tf(
          'inGarage.modal.blurb',
          'OfficeManager records that this car went out for maintenance, but not where it went, and the sheet\'s N-Location tab does not list it. Pick the garage it is actually at — your name and the time are saved with it, and what you pick here wins over the sheet from now on. Leaving it blank clears the entry: an honest "we don\'t know" is better than a wrong name.',
        )}
      </p>
      <Select
        label={tf('inGarage.modal.field', 'Garage')}
        value={vendorId}
        onChange={(e) => setVendorId(e.target.value)}
      >
        <option value="">{tf('inGarage.modal.none', '— not recorded —')}</option>
        {garages.map((g) => (
          <option key={g.id} value={g.id}>{g.name}</option>
        ))}
      </Select>
      <p className="mt-3 text-xs text-slate-400">
        {tf('inGarage.modal.vendorNote', 'The list is our own garage records, so this board can never name a garage the rest of the system has never heard of.')}
      </p>
    </Modal>
  );
}
