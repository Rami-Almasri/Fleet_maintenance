// THE ACCIDENT PROCESS, EDITED BY THE PEOPLE WHO RUN IT.
//
// This screen is the whole point of the accident-workflow rebuild. The process a crashed car is
// walked through used to be a list of constants in PHP, which meant "put the report before the damage
// assessment" was a developer ticket and a deployment. Here it is a drag.
//
// THE SCREEN IS NOT THE RULES. Every button below greys itself out when it would produce a workflow
// that cannot be walked — no starting stage, no ending, a terminal rung sitting in the middle — but
// none of that is where the rule lives. The backend re-derives all of it at publish and refuses
// independently (AccidentWorkflowConfigService::validate). What you see here is a courtesy so
// problems are named while they are cheap to fix; a stale tab or a curl command hits exactly the same
// wall. That separation is deliberate and must not be "simplified" by trusting this file.
//
// DRAFT, THEN PUBLISH. The live process is never edited. Clicking Edit opens a draft copy; publishing
// archives the old version and activates the new one. Cases already in flight keep the version they
// were reported under, so a reorder this morning cannot rewrite a case opened last month.
//
// WHY DROPDOWNS AND NOT A RULE BOX. A stage's gate is a KEY into a registry of compiled classes —
// there is no expression language and nothing to type, which is what keeps an admin from writing a
// rule nobody can audit. If a gate the office needs is missing, that is a real, small development
// task; it is not something to paper over with a text field.
import { useCallback, useState } from 'react';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { usePermissions } from '../../hooks/usePermissions';
import { useI18n } from '../../i18n/I18nContext';
import { useToast } from '../../components/ui/Toast';
import Badge from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import Icon from '../../components/ui/Icon';
import { SectionCard } from '../../components/ui/Table';

// Problem keys the backend returns → the sentence an operations manager can act on. Falls back to the
// backend's own message, so a validation rule added later still says something useful here without a
// frontend change.
const PROBLEM_TEXT = {
  empty: 'This process has no stages at all. Add at least a starting stage and an ending one.',
  no_initial: 'No stage is marked as the starting point, so a new accident would have nowhere to begin.',
  many_initial: 'Two stages are both marked as the starting point. Only one can be.',
  initial_disabled: 'The starting stage is switched off, so no new accident could be reported.',
  no_terminal: 'No stage ends the process, so every case would stay open forever.',
  terminal_before_active: 'An ending stage sits before stages that still have work in them — cases would finish early and skip the rest.',
  duplicate_key: 'Two stages share the same internal key.',
  duplicate_position: 'Two stages claim the same position.',
  unknown_requirement: 'A stage is gated on a requirement this system does not have.',
  unknown_condition: 'A stage applies under a condition this system does not have.',
  all_disabled: 'Every stage is switched off.',
};

/** One rung. Draggable when the workflow is a draft; a read-only summary when it is published. */
function StageRow({ stage, editable, dragging, onDragStart, onDragEnter, onDragEnd, onEdit, onDelete, onSetInitial, onSetTerminal }) {
  const { t } = useI18n();

  return (
    <div
      draggable={editable}
      onDragStart={() => onDragStart(stage.key)}
      onDragEnter={() => onDragEnter(stage.key)}
      onDragEnd={onDragEnd}
      onDragOver={(e) => e.preventDefault()}
      className={`flex items-start gap-3 border-b border-slate-100 px-4 py-3 last:border-b-0 ${
        dragging === stage.key ? 'opacity-40' : ''
      } ${editable ? 'cursor-grab active:cursor-grabbing hover:bg-slate-50' : ''} ${
        stage.is_enabled ? '' : 'bg-slate-50/60'
      }`}
    >
      {/* The grab handle is the affordance — without it a draggable row is a row nobody discovers. */}
      <span className="mt-0.5 shrink-0 text-slate-300" aria-hidden="true">
        {editable ? <Icon.Menu className="h-4 w-4" /> : <span className="font-mono text-xs">{stage.position}</span>}
      </span>

      <span className="mt-1 h-3 w-3 shrink-0 rounded-full" style={{ background: stage.tone || '#94a3b8' }} />

      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className={`text-sm font-semibold ${stage.is_enabled ? 'text-slate-900' : 'text-slate-400 line-through'}`}>
            {stage.label}
          </span>
          {stage.is_initial && <Badge tone="violet">{t('accidentWorkflow.badge.start')}</Badge>}
          {stage.is_terminal && <Badge tone="slate">{t('accidentWorkflow.badge.end')}</Badge>}
          {!stage.is_mandatory && <Badge tone="amber">{t('accidentWorkflow.badge.optional')}</Badge>}
          {stage.blocks_rental && <Badge tone="red">{t('accidentWorkflow.badge.holdsCar')}</Badge>}
          {!stage.is_enabled && <Badge tone="slate">{t('accidentWorkflow.badge.off')}</Badge>}
        </div>

        {/* The rule, in a sentence. Two dropdown keys mean nothing to the person choosing them. */}
        <p className="mt-1 text-xs text-slate-500">
          {stage.requirement_key === 'none'
            ? t('accidentWorkflow.row.noGate')
            : t('accidentWorkflow.row.gate', { rule: stage.requirement_hint || stage.requirement_label })}
          {stage.applies_when !== 'always' && (
            <span className="text-slate-400"> · {t('accidentWorkflow.row.only', { when: stage.condition_label })}</span>
          )}
        </p>
        <p className="mt-0.5 font-mono text-[10px] text-slate-300">{stage.key}</p>
      </div>

      {editable && (
        <div className="flex shrink-0 items-center gap-1">
          <Button size="xs" variant="ghost" onClick={() => onEdit(stage)}>{t('common.edit')}</Button>
          {!stage.is_initial && (
            <Button size="xs" variant="ghost" onClick={() => onSetInitial(stage)}>{t('accidentWorkflow.action.makeStart')}</Button>
          )}
          <Button size="xs" variant="ghost" onClick={() => onSetTerminal(stage, !stage.is_terminal)}>
            {stage.is_terminal ? t('accidentWorkflow.action.notEnd') : t('accidentWorkflow.action.makeEnd')}
          </Button>
          {/* The starting stage has no delete button: a process with no beginning cannot be saved, and
              offering the action only to refuse it teaches nothing. The backend refuses too. */}
          {!stage.is_initial && (
            <Button size="xs" variant="ghost" tone="danger" onClick={() => onDelete(stage)}>{t('common.remove')}</Button>
          )}
        </div>
      )}
    </div>
  );
}

/** Add / edit a stage. Every rule is a dropdown — see the file header for why. */
function StageEditor({ stage, vocabulary, onCancel, onSave, saving }) {
  const { t } = useI18n();
  const [form, setForm] = useState({
    label: stage?.label || '',
    label_ar: stage?.label_ar || '',
    description: stage?.description || '',
    requirement_key: stage?.requirement_key || 'none',
    applies_when: stage?.applies_when || 'always',
    is_mandatory: stage?.is_mandatory ?? true,
    blocks_rental: stage?.blocks_rental ?? false,
    is_enabled: stage?.is_enabled ?? true,
    tone: stage?.tone || '#6366f1',
  });
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }));

  const chosen = vocabulary.requirements.find((r) => r.key === form.requirement_key);

  return (
    <div className="space-y-3 border-b border-slate-100 bg-indigo-50/40 px-4 py-4">
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block">
          <span className="text-xs font-medium text-slate-500">{t('accidentWorkflow.field.label')}</span>
          <input value={form.label} onChange={set('label')} className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        </label>
        <label className="block">
          <span className="text-xs font-medium text-slate-500">{t('accidentWorkflow.field.labelAr')}</span>
          <input value={form.label_ar} onChange={set('label_ar')} dir="rtl" className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        </label>
      </div>

      <label className="block">
        <span className="text-xs font-medium text-slate-500">{t('accidentWorkflow.field.description')}</span>
        <input value={form.description} onChange={set('description')} className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
      </label>

      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block">
          <span className="text-xs font-medium text-slate-500">{t('accidentWorkflow.field.gate')}</span>
          <select value={form.requirement_key} onChange={set('requirement_key')} className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
            {vocabulary.requirements.map((r) => <option key={r.key} value={r.key}>{r.label}</option>)}
          </select>
          {chosen?.description && <p className="mt-1 text-xs text-slate-500">{chosen.description}</p>}
        </label>
        <label className="block">
          <span className="text-xs font-medium text-slate-500">{t('accidentWorkflow.field.appliesWhen')}</span>
          <select value={form.applies_when} onChange={set('applies_when')} className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
            {vocabulary.conditions.map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}
          </select>
          <p className="mt-1 text-xs text-slate-500">{t('accidentWorkflow.field.appliesWhenHint')}</p>
        </label>
      </div>

      <div className="flex flex-wrap items-center gap-4">
        <label className="flex items-center gap-2 text-xs text-slate-600">
          <input type="checkbox" checked={form.is_mandatory} onChange={set('is_mandatory')} />
          {t('accidentWorkflow.field.mandatory')}
        </label>
        <label className="flex items-center gap-2 text-xs text-slate-600">
          <input type="checkbox" checked={form.blocks_rental} onChange={set('blocks_rental')} />
          {t('accidentWorkflow.field.holdsCar')}
        </label>
        <label className="flex items-center gap-2 text-xs text-slate-600">
          <input type="checkbox" checked={form.is_enabled} onChange={set('is_enabled')} />
          {t('accidentWorkflow.field.enabled')}
        </label>
        <label className="flex items-center gap-2 text-xs text-slate-600">
          {t('accidentWorkflow.field.colour')}
          <input type="color" value={form.tone} onChange={set('tone')} className="h-7 w-10 rounded border border-slate-200" />
        </label>
      </div>

      <div className="flex items-center gap-2">
        <Button size="sm" onClick={() => onSave(form)} disabled={saving || !form.label.trim()}>
          {stage ? t('common.save') : t('accidentWorkflow.action.addStage')}
        </Button>
        <Button size="sm" variant="ghost" onClick={onCancel}>{t('common.cancel')}</Button>
      </div>
    </div>
  );
}

export default function AccidentWorkflowSettings() {
  const { t } = useI18n();
  const { can } = usePermissions();
  const toast = useToast();
  const mayConfigure = can('accidents.workflow.configure');

  const [busy, setBusy] = useState(false);
  const [editing, setEditing] = useState(null);   // a stage object, or 'new', or null
  const [dragging, setDragging] = useState(null);
  const [order, setOrder] = useState(null);       // local order while a drag is in flight

  const { data, loading, error, reload } = useFetch(
    useCallback(() => api.get('/accident-workflow').then((r) => r.data.data), []),
    [],
  );

  const draft = data?.draft;
  const active = data?.active;
  const shown = draft || active;
  const stages = order || shown?.stages || [];

  // Keyed on the draft ID alone, not the draft object: the object is a fresh reference after every
  // reload, and depending on it would re-fetch the validation on every keystroke-triggered refresh.
  const draftId = draft?.id ?? null;
  const { data: check, reload: recheck } = useFetch(
    useCallback(
      () => (draftId ? api.get(`/accident-workflow/${draftId}/validate`).then((r) => r.data.data) : Promise.resolve(null)),
      [draftId],
    ),
    [draftId],
  );

  // Every mutation funnels through here so the draft and its validation are refetched together —
  // otherwise the problem list on screen describes a draft that no longer exists.
  const run = async (fn, okMessage) => {
    setBusy(true);
    try {
      await fn();
      await reload({ silent: true });
      await recheck({ silent: true });
      setOrder(null);
      if (okMessage) toast.success(okMessage);
    } catch (e) {
      toast.error(e?.response?.data?.message || t('common.somethingWentWrong'));
    } finally {
      setBusy(false);
    }
  };

  // ── drag to reorder ───────────────────────────────────────────────────────────────────────
  // Reordered locally as the pointer moves so the list follows the cursor, then saved as a WHOLE
  // ORDER on drop. Sending "move X to index 3" instead would let two people dragging at once
  // interleave into an order neither of them chose.
  const onDragEnter = (key) => {
    if (!dragging || dragging === key) return;
    const list = [...stages];
    const from = list.findIndex((s) => s.key === dragging);
    const to = list.findIndex((s) => s.key === key);
    if (from < 0 || to < 0) return;
    list.splice(to, 0, list.splice(from, 1)[0]);
    setOrder(list);
  };

  const onDragEnd = () => {
    const moved = order;
    setDragging(null);
    if (!moved || !draft) { setOrder(null); return; }
    run(
      () => api.post(`/accident-workflow/${draft.id}/reorder`, { order: moved.map((s) => s.key) }),
      t('accidentWorkflow.toast.reordered'),
    );
  };

  if (loading) return <div className="p-6 text-sm text-slate-500">{t('common.loading')}</div>;
  if (error) return <div className="p-6 text-sm text-rose-600">{error}</div>;

  const problems = check?.problems || [];
  const canPublish = draft && problems.length === 0;

  return (
    <div className="space-y-4 p-4 sm:p-6">
      <header>
        <h1 className="text-xl font-semibold text-slate-900">{t('accidentWorkflow.title')}</h1>
        <p className="mt-1 max-w-3xl text-sm text-slate-500">{t('accidentWorkflow.intro')}</p>
      </header>

      {/* WHERE THIS DATA COMES FROM — every page states its origin. */}
      <p className="text-xs text-slate-400">
        {t('accidentWorkflow.origin', {
          version: active?.version ?? '—',
          by: active?.published_by || '—',
        })}
      </p>

      <SectionCard
        title={draft ? t('accidentWorkflow.draftTitle', { version: draft.version }) : t('accidentWorkflow.liveTitle', { version: active?.version ?? '—' })}
        subtitle={draft ? t('accidentWorkflow.draftSubtitle') : t('accidentWorkflow.liveSubtitle')}
        actions={
          mayConfigure && (
            <div className="flex items-center gap-2">
              {!draft && (
                <Button size="sm" disabled={busy} onClick={() => run(() => api.post('/accident-workflow/draft'), t('accidentWorkflow.toast.draftOpened'))}>
                  {t('accidentWorkflow.action.edit')}
                </Button>
              )}
              {draft && (
                <>
                  <Button size="sm" variant="ghost" onClick={() => setEditing('new')}>{t('accidentWorkflow.action.addStage')}</Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    tone="danger"
                    disabled={busy}
                    onClick={() => run(() => api.delete(`/accident-workflow/${draft.id}/draft`), t('accidentWorkflow.toast.discarded'))}
                  >
                    {t('common.discard')}
                  </Button>
                  <Button
                    size="sm"
                    disabled={busy || !canPublish}
                    title={canPublish ? '' : t('accidentWorkflow.fixFirst')}
                    onClick={() => run(() => api.post(`/accident-workflow/${draft.id}/publish`), t('accidentWorkflow.toast.published'))}
                  >
                    {t('accidentWorkflow.action.publish')}
                  </Button>
                </>
              )}
            </div>
          )
        }
      >
        {/* PROBLEMS FIRST. Named while they are cheap to fix, not at the moment somebody tries to
            save. The publish button is disabled alongside — but the backend is what actually
            refuses; see the file header. */}
        {problems.length > 0 && (
          <div className="border-b border-amber-200 bg-amber-50 px-4 py-3">
            <p className="text-xs font-semibold text-amber-900">{t('accidentWorkflow.problemsTitle')}</p>
            <ul className="mt-1 space-y-1">
              {problems.map((p) => (
                <li key={p.key} className="text-xs text-amber-800">• {PROBLEM_TEXT[p.key] || p.message}</li>
              ))}
            </ul>
          </div>
        )}

        {draft?.has_cases && (
          <p className="border-b border-slate-100 bg-slate-50 px-4 py-2 text-xs text-slate-500">
            {t('accidentWorkflow.hasCasesNote')}
          </p>
        )}

        {editing === 'new' && (
          <StageEditor
            vocabulary={data.vocabulary}
            saving={busy}
            onCancel={() => setEditing(null)}
            onSave={(form) => run(
              () => api.post(`/accident-workflow/${draft.id}/stages`, form).then(() => setEditing(null)),
              t('accidentWorkflow.toast.stageAdded'),
            )}
          />
        )}

        {stages.map((s) => (
          editing?.id === s.id ? (
            <StageEditor
              key={s.id}
              stage={s}
              vocabulary={data.vocabulary}
              saving={busy}
              onCancel={() => setEditing(null)}
              onSave={(form) => run(
                () => api.patch(`/accident-workflow/stages/${s.id}`, form).then(() => setEditing(null)),
                t('accidentWorkflow.toast.stageSaved'),
              )}
            />
          ) : (
            <StageRow
              key={s.id}
              stage={s}
              editable={Boolean(draft) && mayConfigure}
              dragging={dragging}
              onDragStart={setDragging}
              onDragEnter={onDragEnter}
              onDragEnd={onDragEnd}
              onEdit={setEditing}
              onDelete={(st) => run(() => api.delete(`/accident-workflow/stages/${st.id}`), t('accidentWorkflow.toast.stageRemoved'))}
              onSetInitial={(st) => run(() => api.post(`/accident-workflow/${draft.id}/initial`, { stage: st.key }), t('accidentWorkflow.toast.startSet'))}
              onSetTerminal={(st, on) => run(() => api.post(`/accident-workflow/${draft.id}/terminal`, { stage: st.key, terminal: on }), t('accidentWorkflow.toast.endSet'))}
            />
          )
        ))}
      </SectionCard>

      {/* THE VERSION HISTORY is not decoration: it is the answer to "which process was this case
          actually worked through?", which is the first question asked when an old case is disputed. */}
      <SectionCard title={t('accidentWorkflow.historyTitle')} subtitle={t('accidentWorkflow.historySubtitle')}>
        <div className="divide-y divide-slate-100">
          {(data.versions || []).map((v) => (
            <div key={v.id} className="flex items-center justify-between gap-3 px-4 py-2.5">
              <div className="min-w-0">
                <span className="text-sm font-medium text-slate-800">v{v.version} · {v.name}</span>
                <p className="text-xs text-slate-400">
                  {v.published_at ? new Date(v.published_at).toLocaleDateString() : t('accidentWorkflow.notPublished')}
                  {v.published_by ? ` · ${v.published_by}` : ''}
                </p>
              </div>
              <Badge tone={v.status === 'active' ? 'green' : v.status === 'draft' ? 'amber' : 'slate'}>{v.status}</Badge>
            </div>
          ))}
        </div>
      </SectionCard>
    </div>
  );
}
