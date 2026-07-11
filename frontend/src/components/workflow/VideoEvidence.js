import { useCallback, useEffect, useRef, useState } from 'react';
import { useI18n } from '../../i18n/I18nContext';
import Button from '../ui/Button';
import Icon from '../ui/Icon';
import { listTicketMedia, deleteTicketMedia, uploadRepairVideo } from '../../lib/maintenanceMedia';

// Video Evidence — the garage's repair videos on a ticket, the permanent record the supervisor's video
// review is based on. Watching is open to anyone who can view the ticket; upload/delete is supervisor authority
// (Waleed/Abdullah, maintenance.delegate) — the `canManage` prop mirrors the backend route gate.
export default function VideoEvidence({ ticketId, canManage, reloadKey = 0, onChange }) {
  const { t } = useI18n();
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState(false);
  const [busy, setBusy] = useState(false);
  const [uploadErr, setUploadErr] = useState('');
  const fileRef = useRef(null);

  const load = useCallback(async () => {
    if (!ticketId) return;
    setLoading(true);
    setErr(false);
    try {
      setItems(await listTicketMedia(ticketId));
    } catch {
      setErr(true);
    } finally {
      setLoading(false);
    }
  }, [ticketId]);

  useEffect(() => { load(); }, [load, reloadKey]);

  const onPick = async (e) => {
    const file = e.target.files?.[0];
    if (fileRef.current) fileRef.current.value = ''; // allow re-picking the same file
    if (!file) return;
    setBusy(true);
    setUploadErr('');
    try {
      await uploadRepairVideo(ticketId, file);
      await load();
      onChange?.(); // let the ticket refresh (has_video / video_count) so the approve gate is accurate
    } catch (ex) {
      setUploadErr(ex?.response?.data?.message || t('workflow.video.uploadError'));
    } finally {
      setBusy(false);
    }
  };

  const onDelete = async (id) => {
    if (!window.confirm(t('workflow.video.confirmDelete'))) return;
    try {
      await deleteTicketMedia(ticketId, id);
      setItems((prev) => prev.filter((m) => m.id !== id));
      onChange?.();
    } catch { /* best-effort — a reload will reconcile */ }
  };

  return (
    <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-soft">
      <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-2.5">
        <h3 className="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-slate-500">
          <span aria-hidden>🎬</span>
          {t('workflow.video.title')}
          {items.length > 0 && <span className="rounded-full bg-slate-100 px-1.5 text-[11px] font-semibold text-slate-500">{items.length}</span>}
        </h3>
        {canManage && (
          <>
            <Button size="sm" variant="secondary" disabled={busy} onClick={() => fileRef.current?.click()}>
              {busy ? t('workflow.video.uploading') : <><Icon.Plus className="h-3.5 w-3.5" /> {t('workflow.video.upload')}</>}
            </Button>
            <input ref={fileRef} type="file" accept="video/*,image/*" className="hidden" onChange={onPick} />
          </>
        )}
      </div>
      <div className="px-4 py-3.5">
        {uploadErr && <p className="mb-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 ring-1 ring-inset ring-red-600/20">{uploadErr}</p>}
        {loading ? (
          <p className="text-sm text-slate-400">{t('workflow.video.loading')}</p>
        ) : err ? (
          <p className="text-sm text-red-600">{t('workflow.video.error')}</p>
        ) : items.length === 0 ? (
          <p className="text-sm text-slate-400">{t('workflow.video.empty')}</p>
        ) : (
          <ul className="space-y-2">
            {items.map((m) => (
              <li key={m.id} className="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
                <span aria-hidden className="text-lg">{m.kind === 'image' ? '🖼️' : '🎬'}</span>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium text-slate-700">{m.original_name || t('workflow.video.title')}</p>
                  <p className="truncate text-[11px] text-slate-400">
                    {m.uploaded_by_name ? t('workflow.video.by', { who: m.uploaded_by_name }) : ''}
                    {m.note ? ` · ${m.note}` : ''}
                  </p>
                </div>
                {m.url && (
                  <a href={m.url} target="_blank" rel="noreferrer"
                     className="shrink-0 rounded-lg px-2 py-1 text-xs font-semibold text-indigo-600 hover:bg-indigo-50">
                    {t('workflow.video.watch')}
                  </a>
                )}
                {canManage && (
                  <button type="button" onClick={() => onDelete(m.id)}
                          className="shrink-0 rounded-lg px-2 py-1 text-xs font-medium text-red-500 hover:bg-red-50">
                    {t('workflow.video.delete')}
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  );
}
