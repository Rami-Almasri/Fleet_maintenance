// RepairIntelligencePanel — the fault-knowledge surface for one fault. REUSABLE across mount points:
// pass `taskId` for an existing fault, or `preview={{ vehicleId, symptom, categoryKey }}` for a fault
// being typed at registration.
//
// WHAT THIS NO LONGER SHOWS. It used to also print the cohort argument — a confidence band, a
// recommended garage, the list of similar repairs, the "why this recommendation" bullets and an
// evidence disclosure. All of that was removed: the garage call belongs to the dispatch card, which
// makes it once with the whole ticket in view, and repeating a second, differently-computed
// recommendation here meant the same screen answered "which garage" twice. What is left is the part
// that is about the FAULT rather than about a shop — what it is, what usually causes it, what usually
// fixes it — which is the only thing this panel was uniquely carrying.
//
// It binds ONLY the frozen contract (lib/repairIntelligence) — never a backend internal shape.

import { useEffect, useState } from 'react';
import { useI18n } from '../../i18n/I18nContext';
import { getRepairIntelligenceForTask, previewRepairIntelligence } from '../../lib/repairIntelligence';
import FaultKnowledgeCard from './FaultKnowledgeCard';

export default function RepairIntelligencePanel({ taskId, preview, className = '' }) {
  const { t } = useI18n();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState('');

  const previewKey = preview ? `${preview.vehicleId}|${preview.symptom || ''}|${preview.categoryKey || ''}` : '';

  useEffect(() => {
    let alive = true;
    if (!taskId && !preview?.vehicleId) { setLoading(false); return; }
    setLoading(true);
    setErr('');
    const p = taskId ? getRepairIntelligenceForTask(taskId) : previewRepairIntelligence(preview);
    p.then((d) => { if (alive) setData(d); })
      .catch((e) => { if (alive) setErr(e?.response?.data?.message || 'Could not load repair intelligence'); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, [taskId, previewKey]); // eslint-disable-line react-hooks/exhaustive-deps

  if (loading) {
    return <div className={`rounded-xl border border-slate-200 bg-slate-50/60 px-3.5 py-3 text-sm text-slate-500 ${className}`}>{t('common.loading')}</div>;
  }
  // Read-only intelligence: never block the workflow if it can't load, and never render an empty
  // frame when the platform has nothing to say about the fault.
  if (err || !data || !data.fault_knowledge) return null;

  return <FaultKnowledgeCard data={data.fault_knowledge} matching={false} className={className} />;
}
