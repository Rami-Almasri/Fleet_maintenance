// "Required parts (from inspection)" — READ-ONLY traceability.
//
// This panel answers one question: what did the inspector say this repair would need, and what happened to
// it? It has no actions, because there is no decision left to take here. Filing the report raises the Part
// Requests automatically (MaintenanceRequiredPartService::raiseRequests) and hands them to the parts team —
// every sourcing decision from that point (buy it, reject it, which supplier, what price) lives in the part
// request's own lifecycle, on the Parts board.
//
// So each row shows the requirement in the inspector's words and the request(s) it became, with a live
// status chip. It renders nothing when the inspection listed no parts.

import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import Icon from '../ui/Icon';
import { useI18n } from '../../i18n/I18nContext';

const PRIORITY_DOT = {
  urgent: 'bg-red-500',
  high: 'bg-amber-500',
  normal: 'bg-slate-400',
  low: 'bg-slate-300',
};

// Part-request status → chip tone. Mirrors the Parts board so a status reads the same in both places.
const REQ_CHIP = {
  requested: 'bg-sky-50 text-sky-700 ring-sky-200',
  under_review: 'bg-sky-50 text-sky-700 ring-sky-200',
  approved: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
  purchased: 'bg-violet-50 text-violet-700 ring-violet-200',
  installed: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  completed: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  rejected: 'bg-red-50 text-red-700 ring-red-200',
  cancelled: 'bg-slate-100 text-slate-500 ring-slate-200',
};

export default function RequiredPartsPanel({ ticket }) {
  const { t } = useI18n();
  const [lines, setLines] = useState([]);
  const [loading, setLoading] = useState(true);

  const ticketId = ticket?.id;

  const load = useCallback(async () => {
    if (!ticketId) return;
    try {
      const res = await api.get(`/maintenance-tickets/${ticketId}/required-parts`);
      setLines(res?.data?.data || []);
    } catch {
      setLines([]); // traceability is supporting detail — a failure here just hides the panel
    } finally {
      setLoading(false);
    }
  }, [ticketId]);

  useEffect(() => {
    load();
  }, [load]);

  if (loading || !lines.length) return null;

  return (
    <section className="rounded-xl bg-white p-3 ring-1 ring-slate-200">
      <header className="mb-2">
        <h3 className="flex items-center gap-1.5 text-sm font-semibold text-slate-800">
          <Icon.Wrench className="h-4 w-4 text-slate-400" />
          {t('workflow.requiredParts.panelTitle')}
        </h3>
        <p className="mt-0.5 text-xs text-slate-500">{t('workflow.requiredParts.panelHint')}</p>
      </header>

      <ul className="space-y-1.5">
        {lines.map((line) => (
          <li key={line.id} className="rounded-lg bg-slate-50 p-2.5 ring-1 ring-inset ring-slate-200">
            <div className="flex flex-wrap items-center gap-1.5">
              <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${PRIORITY_DOT[line.priority] || PRIORITY_DOT.normal}`} />
              <span className="text-sm font-medium text-slate-800">{line.part_name}</span>
              <span className="text-xs text-slate-400">× {Number(line.quantity)}</span>
            </div>

            {line.finding && (
              <p className="mt-0.5 truncate text-xs text-slate-500">
                {t('workflow.requiredParts.forFault')} “{line.finding}”
              </p>
            )}
            {line.notes && <p className="mt-0.5 text-xs text-slate-500">{line.notes}</p>}
            {line.recorded_by && (
              <p className="mt-0.5 text-[11px] text-slate-400">
                {t('workflow.requiredParts.recordedBy')} {line.recorded_by}
              </p>
            )}

            {/* What procurement did with it — the whole point of keeping these rows. */}
            {line.requests?.length ? (
              <p className="mt-1 flex flex-wrap gap-1">
                {line.requests.map((r) => (
                  <Link
                    key={r.id}
                    to={`/parts?request_id=${r.id}`}
                    className={`rounded-full px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset transition hover:brightness-95 ${REQ_CHIP[r.status] || REQ_CHIP.requested}`}
                  >
                    #{r.id} · {r.status}
                  </Link>
                ))}
              </p>
            ) : (
              // A line with no request means raiseRequests couldn't reach procurement for it — worth
              // showing plainly rather than letting it look handled.
              <p className="mt-1 text-[11px] text-amber-700">{t('workflow.requiredParts.noRequest')}</p>
            )}
          </li>
        ))}
      </ul>
    </section>
  );
}
