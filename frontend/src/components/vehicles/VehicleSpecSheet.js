// What this vehicle TAKES — the fitment sheet, and the one place it is controlled.
//
// The sibling of VehicleComponentsPanel and deliberately its opposite. That panel is read-only
// because what is FITTED is derived from the workflow and must never be typed. This one is
// writable, because what a car TAKES is not an event anybody can derive — it is knowledge, and the
// people who hold it are the ones reading this screen.
//
// Two kinds of row, and the difference is the whole point:
//
//   observed  — copied automatically from the last part fitted. Evidence of what was DONE, never a
//               ruling that it was right. Shown with its provenance sentence so nobody mistakes
//               "this is what the garage poured in" for "this is what the engine needs".
//   confirmed — a person said so. Outranks anything the system observes, and can never be silently
//               overwritten by a later fitting.
//
// So the primary action on an observed row is not "edit" — it is CONFIRM. Turning a guess into a
// fact is the work this screen exists for.

import { useCallback, useMemo, useState } from 'react';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { SectionCard } from '../../components/ui/Table';
import Button from '../../components/ui/Button';
import Modal from '../../components/ui/Modal';
import Icon from '../../components/ui/Icon';
import { Textarea } from '../../components/ui/Field';
import { EmptyState, ErrorState } from '../../components/ui/Misc';
import { Skeleton } from '../../components/ui/Skeleton';
import { useToast } from '../../components/ui/Toast';
import { useI18n } from '../../i18n/I18nContext';
import CatalogPartPicker from '../parts/CatalogPartPicker';
import PartSpecFields, { PartSpecDetail } from '../parts/PartSpecFields';
import { loadSpecDictionary, partTypeHasSpecs, saveVehicleSpec } from '../../lib/partSpecs';

/**
 * @param {number|string} vehicleId
 * @param {boolean} canEdit  false hides every write control (vehicles.update)
 */
export default function VehicleSpecSheet({ vehicleId, canEdit = true }) {
  const { t, lang } = useI18n();
  const toast = useToast();

  const [editing, setEditing] = useState(null); // { catalogId, partName, specs, notes, isNew }
  const [saving, setSaving] = useState(false);

  // Polling is paused while the modal is open — the sheet must not shift under a half-typed spec.
  const fetchSheet = useCallback(
    () => api.get(`/Vehicle/${vehicleId}/part-specs`, { params: { locale: lang } }).then((r) => r?.data?.data || {}),
    [vehicleId, lang]
  );
  const { data: sheet, loading, error, reload } = useFetch(fetchSheet, [vehicleId, lang], {
    paused: () => editing !== null,
  });

  // The catalog and the dictionary are only needed once someone opens the editor, so they are
  // fetched alongside it rather than on every profile view.
  const fetchEditorData = useCallback(
    () =>
      Promise.all([
        api.get('/parts-catalog').then((r) => (r?.data?.data?.parts || []).filter((p) => p.is_active)),
        loadSpecDictionary(lang),
      ]).then(([catalog, dictionary]) => ({ catalog, dictionary })),
    [lang]
  );
  const { data: editorData } = useFetch(fetchEditorData, [lang], { revalidateOnFocus: false });

  // Only part types that actually HAVE specs may be added. Offering "Brake Caliper" here would open
  // an editor with no fields in it, which reads as the screen being broken.
  const speccable = useMemo(() => {
    if (!editorData?.catalog || !editorData?.dictionary) return [];
    return editorData.catalog.filter((p) => partTypeHasSpecs(editorData.dictionary, p.id));
  }, [editorData]);

  const rows = useMemo(() => Object.values(sheet || {}), [sheet]);

  const save = async ({ confirmOnly = false } = {}) => {
    if (!editing?.catalogId) return;

    setSaving(true);
    try {
      await saveVehicleSpec(vehicleId, editing.catalogId, editing.specs, {
        // Confirming an observation without changing it is still a person vouching for it — it
        // lands as `manual`, which is what stops the next fitting from quietly rewriting it.
        source: 'manual',
        notes: editing.notes || null,
      });
      toast.success(confirmOnly ? t('Confirmed') : t('Saved'));
      setEditing(null);
      reload({ silent: true });
    } catch (e) {
      toast.error(e?.response?.data?.message || t('Could not save'));
    } finally {
      setSaving(false);
    }
  };

  const forget = async (catalogId) => {
    setSaving(true);
    try {
      await api.delete(`/Vehicle/${vehicleId}/part-specs/${catalogId}`);
      toast.success(t('Removed'));
      setEditing(null);
      reload({ silent: true });
    } catch (e) {
      toast.error(e?.response?.data?.message || t('Could not save'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <SectionCard
      title={t('What this vehicle takes')}
      subtitle={t('The oil, battery and tyre sizes this car needs — shown wherever a part is chosen for it.')}
      bodyClass="p-5"
      actions={
        canEdit && speccable.length > 0 ? (
          <Button
            variant="secondary"
            size="sm"
            onClick={() => setEditing({ catalogId: null, partName: '', specs: {}, notes: '', isNew: true })}
          >
            <Icon.Plus className="h-4 w-4" />
            {t('Add')}
          </Button>
        ) : null
      }
    >
      {loading && <Skeleton className="h-24 w-full" />}
      {error && <ErrorState onRetry={reload} />}

      {!loading && !error && rows.length === 0 && (
        <EmptyState
          title={t('Nothing recorded yet')}
          // Says what will happen on its own, so an empty sheet does not read as a broken one.
          message={t('This fills in by itself as parts are fitted. You can also enter what the handbook says.')}
        />
      )}

      {!loading && !error && rows.length > 0 && (
        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
          {rows.map((row) => (
            <div
              key={row.component_catalog_id}
              className="rounded-xl border border-slate-200 bg-white p-4"
            >
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="text-xs uppercase tracking-wide text-slate-500">{row.part_type}</div>
                  <div className="truncate text-base font-semibold text-slate-900">
                    {row.summary || t('Not recorded')}
                  </div>
                </div>

                {/* The trust label. An observed figure is amber not because it is wrong but
                    because nobody has vouched for it — and that is exactly the state this screen
                    is here to resolve. */}
                <span
                  className={
                    row.is_observed
                      ? 'shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-amber-700 ring-1 ring-inset ring-amber-500/25'
                      : 'shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-emerald-700 ring-1 ring-inset ring-emerald-500/25'
                  }
                >
                  {row.is_observed ? t('Not confirmed') : t('Confirmed')}
                </span>
              </div>

              <p className="mt-1 text-xs text-slate-500">{row.provenance}</p>

              {row.detail?.length > 0 && (
                <div className="mt-3">
                  <PartSpecDetail detail={row.detail} emptyLabel={t('Not recorded')} />
                </div>
              )}

              {row.notes && <p className="mt-2 text-xs italic text-slate-500">{row.notes}</p>}

              {canEdit && (
                <div className="mt-3 flex gap-2">
                  <Button
                    variant={row.is_observed ? 'primary' : 'secondary'}
                    size="sm"
                    onClick={() =>
                      setEditing({
                        catalogId: row.component_catalog_id,
                        partName: row.part_type,
                        specs: row.specs || {},
                        notes: row.notes || '',
                        isNew: false,
                      })
                    }
                  >
                    {row.is_observed ? t('Confirm or correct') : t('Edit')}
                  </Button>
                </div>
              )}
            </div>
          ))}
        </div>
      )}

      <Modal
        open={editing !== null}
        onClose={() => !saving && setEditing(null)}
        title={editing?.isNew ? t('What this vehicle takes') : editing?.partName}
        subtitle={t('This is shown wherever someone chooses a part for this vehicle.')}
        size="lg"
        footer={
          <div className="flex w-full items-center justify-between gap-2">
            <div>
              {!editing?.isNew && (
                <Button
                  variant="ghost"
                  size="sm"
                  disabled={saving}
                  onClick={() => forget(editing.catalogId)}
                  className="text-red-600"
                >
                  {t('Remove')}
                </Button>
              )}
            </div>
            <div className="flex gap-2">
              <Button variant="secondary" disabled={saving} onClick={() => setEditing(null)}>
                {t('Cancel')}
              </Button>
              <Button disabled={saving || !editing?.catalogId} onClick={() => save()}>
                {saving ? t('Saving…') : t('Save')}
              </Button>
            </div>
          </div>
        }
      >
        {editing && (
          <div className="space-y-4">
            {/* The part type is fixed once a row exists: changing it would silently move the specs
                onto a different part rather than correcting them. Remove and re-add instead. */}
            {editing.isNew ? (
              <CatalogPartPicker
                value={editing.catalogId ? { component_catalog_id: editing.catalogId, part_name: editing.partName } : null}
                catalog={speccable}
                onChange={(v) =>
                  setEditing((prev) => ({
                    ...prev,
                    catalogId: v?.component_catalog_id || null,
                    partName: v?.part_name || '',
                    // Switching part type clears the fields — a viscosity means nothing on a tyre.
                    specs: {},
                  }))
                }
              />
            ) : (
              <div className="rounded-lg bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700">
                {editing.partName}
              </div>
            )}

            {/* No `expected` is passed: on THIS screen the values being typed ARE what the car
                takes, so comparing them against themselves would raise a mismatch with itself. */}
            <PartSpecFields
              catalogId={editing.catalogId}
              value={editing.specs}
              disabled={saving}
              onChange={(specs) => setEditing((prev) => ({ ...prev, specs }))}
            />

            <Textarea
              label={t('Note')}
              rows={2}
              value={editing.notes}
              disabled={saving}
              placeholder={t('Where this came from — the handbook, the dealer, the sticker under the bonnet')}
              onChange={(e) => setEditing((prev) => ({ ...prev, notes: e.target.value }))}
            />
          </div>
        )}
      </Modal>
    </SectionCard>
  );
}
