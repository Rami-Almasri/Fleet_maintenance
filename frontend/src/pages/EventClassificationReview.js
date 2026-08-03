// Classification Review — the human-in-the-loop queue for maintenance events the resolver was unsure about
// (needs_review). Each card shows the ORIGINAL text, the CURRENT classification and the resolver's SUGGESTED
// one; the reviewer confirms or corrects the kind (+ an optional catalog row), which is written back as an
// authoritative decision. This is the foundation for improving the classifier over time.

import { useCallback, useEffect, useState } from 'react';
import api from '../api/client';
import { useI18n } from '../i18n/I18nContext';
import Icon from '../components/ui/Icon';

// The four operational kinds, mirroring MaintenanceTask::KINDS. `damage` belongs here as a first-class
// choice: this queue exists to correct misclassification, and until it could offer damage a reviewer
// looking at "Rim Scratch" had no right answer available.
const KINDS = ['fault', 'service', 'damage', 'inspection'];
const KIND_TONE = {
  fault: 'bg-red-100 text-red-800 ring-red-600/20',
  service: 'bg-blue-100 text-blue-800 ring-blue-600/20',
  damage: 'bg-purple-100 text-purple-800 ring-purple-600/20',
  inspection: 'bg-amber-100 text-amber-800 ring-amber-600/20',
};

function KindBadge({ kind, t }) {
  if (!kind) return <span className="text-slate-400">—</span>;
  return (
    <span className={`inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${KIND_TONE[kind] || KIND_TONE.fault}`}>
      {t(`classReview.${kind}`)}
    </span>
  );
}

export default function EventClassificationReview() {
  const { t } = useI18n();
  const [state, setState] = useState({ loading: true, error: false, tasks: [], catalogs: null });
  const [draft, setDraft] = useState({});   // { [taskId]: { kind, catalog_id } }
  const [busy, setBusy] = useState(null);

  const load = useCallback(() => {
    setState((s) => ({ ...s, loading: true, error: false }));
    api.get('/event-classification/review')
      .then((r) => {
        const d = r.data?.data || {};
        setState({ loading: false, error: false, tasks: d.tasks || [], catalogs: d.catalogs || null });
        // seed each draft with the suggested kind
        const seed = {};
        (d.tasks || []).forEach((tk) => { seed[tk.id] = { kind: tk.suggested_kind || tk.current?.kind || 'fault', catalog_id: '' }; });
        setDraft(seed);
      })
      .catch(() => setState({ loading: false, error: true, tasks: [], catalogs: null }));
  }, []);

  useEffect(() => { load(); }, [load]);

  const setKind = (id, kind) => setDraft((d) => ({ ...d, [id]: { kind, catalog_id: '' } }));
  const setCatalog = (id, catalog_id) => setDraft((d) => ({ ...d, [id]: { ...d[id], catalog_id } }));

  const confirm = async (id) => {
    const choice = draft[id];
    if (!choice?.kind) return;
    setBusy(id);
    try {
      await api.post(`/event-classification/review/${id}/confirm`, {
        kind: choice.kind,
        catalog_id: choice.catalog_id ? Number(choice.catalog_id) : null,
      });
      setState((s) => ({ ...s, tasks: s.tasks.filter((tk) => tk.id !== id) }));
    } catch {
      // leave the card in place on failure
    } finally {
      setBusy(null);
    }
  };

  const { loading, error, tasks, catalogs } = state;

  return (
    <div className="mx-auto max-w-4xl px-4 py-6">
      <div className="mb-5 flex items-center gap-3">
        <div className="grid h-10 w-10 place-items-center rounded-xl bg-indigo-600 text-white"><Icon.Wrench className="h-5 w-5" /></div>
        <div>
          <h1 className="text-xl font-bold tracking-tight text-slate-900">{t('classReview.title')}</h1>
          <p className="text-sm text-slate-500">{t('classReview.subtitle')}</p>
        </div>
        <span className="ms-auto rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-600">{tasks.length}</span>
      </div>

      {loading && <p className="text-sm text-slate-400">{t('classReview.loading')}</p>}
      {error && <p className="text-sm text-red-600">{t('classReview.loadError')}</p>}
      {!loading && !error && tasks.length === 0 && (
        <div className="rounded-xl border border-slate-200 bg-white p-8 text-center text-slate-500">
          <Icon.Check className="mx-auto mb-2 h-6 w-6 text-emerald-500" />
          {t('classReview.empty')}
        </div>
      )}

      <div className="space-y-3">
        {tasks.map((tk) => {
          const choice = draft[tk.id] || { kind: 'fault', catalog_id: '' };
          const opts = (catalogs?.[choice.kind]) || [];
          return (
            <div key={tk.id} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('classReview.original')}</p>
                  <p className="text-[15px] font-semibold text-slate-900">{tk.symptom || '—'}</p>
                  <p className="mt-0.5 text-xs text-slate-400">
                    {tk.vehicle ? `${tk.vehicle.make || ''} ${tk.vehicle.model || ''} · ${tk.vehicle.plate_no || ''}` : ''}
                    {tk.ticket?.visit_context ? ` · ${tk.ticket.visit_context}` : ''}
                  </p>
                </div>
                <div className="text-end text-xs text-slate-500">
                  <div>{t('classReview.current')}: <KindBadge kind={tk.current?.kind} t={t} /></div>
                  <div className="mt-1">{t('classReview.suggested')}: <KindBadge kind={tk.suggested_kind} t={t} /></div>
                </div>
              </div>

              <div className="mt-3 flex flex-wrap items-center gap-2">
                <div className="inline-flex rounded-lg bg-slate-100 p-0.5">
                  {KINDS.map((k) => (
                    <button
                      key={k}
                      type="button"
                      onClick={() => setKind(tk.id, k)}
                      className={`rounded-md px-3 py-1.5 text-[13px] font-semibold transition ${choice.kind === k ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
                    >
                      {t(`classReview.${k}`)}
                    </button>
                  ))}
                </div>

                <select
                  value={choice.catalog_id}
                  onChange={(e) => setCatalog(tk.id, e.target.value)}
                  className="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[13px] text-slate-700"
                >
                  <option value="">{t('classReview.pickCatalog')}</option>
                  {opts.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>

                <button
                  type="button"
                  disabled={busy === tk.id}
                  onClick={() => confirm(tk.id)}
                  className="ms-auto rounded-lg bg-indigo-600 px-4 py-1.5 text-[13px] font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-50"
                >
                  {busy === tk.id ? '…' : t('classReview.confirm')}
                </button>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
