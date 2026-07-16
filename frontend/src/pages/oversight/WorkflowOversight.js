// Workflow Oversight hub (/oversight) — the landing page for the maintenance-workflow accountability &
// data-integrity suite. Four cards, each with its live count, linking to the focused audit surface:
// mileage discrepancies, stage accountability, the left-the-garage invoice queue, and severity review.
// Backed by GET /Oversight/overview.

import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../../components/ui/Icon';
import { Skeleton } from '../../components/ui/Skeleton';
import { PageHeader } from '../../components/ui/Misc';

const CARDS = [
  { to: '/oversight/mileage',    key: 'mileage',  icon: 'Gauge', titleKey: 'oversight.hub.mileageCard',  descKey: 'oversight.hub.mileageDesc',  count: 'mileage_flags',       sub: 'mileage_discrepancies', subLabel: 'oversight.mileage.discrepancies', tone: 'indigo' },
  { to: '/oversight/stages',     key: 'stages',   icon: 'Route', titleKey: 'oversight.hub.stagesCard',   descKey: 'oversight.hub.stagesDesc',   count: null,                  sub: null,                    subLabel: null,                              tone: 'slate' },
  { to: '/oversight/left-garage',key: 'garage',   icon: 'Truck', titleKey: 'oversight.hub.garageCard',   descKey: 'oversight.hub.garageDesc',   count: 'left_garage',         sub: 'needs_invoice',         subLabel: 'oversight.garage.needsRequest',   tone: 'amber' },
  { to: '/oversight/severity',   key: 'severity', icon: 'Flag',  titleKey: 'oversight.hub.severityCard', descKey: 'oversight.hub.severityDesc', count: 'severity_mismatches', sub: 'severity_critical',     subLabel: 'oversight.severity.criticalMissed', tone: 'red' },
  { to: '/oversight/misdiagnoses',key: 'misdiag', icon: 'XCircle', titleKey: 'oversight.hub.misdiagCard', descKey: 'oversight.hub.misdiagDesc', count: 'misdiagnoses',      sub: null,                    subLabel: null,                              tone: 'red' },
  { to: '/oversight/resolved-transfers', key: 'resolved', icon: 'ArrowRight', titleKey: 'oversight.hub.resolvedCard', descKey: 'oversight.hub.resolvedDesc', count: 'resolved_transfers', sub: null, subLabel: null, tone: 'amber' },
];

const TONE = {
  indigo: 'bg-indigo-50 text-indigo-600 ring-indigo-100',
  slate:  'bg-slate-100 text-slate-600 ring-slate-200',
  amber:  'bg-amber-50 text-amber-600 ring-amber-100',
  red:    'bg-red-50 text-red-600 ring-red-100',
};

export default function WorkflowOversight() {
  const { t } = useI18n();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  useEffect(() => {
    let alive = true;
    api.get('/Oversight/overview')
      .then((res) => { if (alive) setData(res.data.data); })
      .catch(() => { if (alive) setError(true); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1100px] space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title={t('oversight.hub.title')} subtitle={t('oversight.hub.subtitle')} />

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">
            Couldn’t load the live counts — the audit pages below still work.
          </div>
        )}

        <div className="stagger grid gap-4 sm:grid-cols-2">
          {CARDS.map((c) => {
            const Ico = Icon[c.icon] || Icon.Activity;
            const total = c.count && data ? data[c.count] : null;
            const sub = c.sub && data ? data[c.sub] : null;
            return (
              <Link
                key={c.key}
                to={c.to}
                className="group hover-lift flex flex-col rounded-2xl border border-slate-200/60 bg-white p-6 shadow-soft"
              >
                <div className="flex items-start justify-between">
                  <span className={`flex h-11 w-11 items-center justify-center rounded-xl ring-1 ${TONE[c.tone]}`}>
                    <Ico className="h-5 w-5" />
                  </span>
                  {loading ? (
                    <Skeleton className="h-8 w-12 rounded-lg" />
                  ) : c.count ? (
                    <div className="text-right">
                      <p className="text-2xl font-bold tabular-nums text-slate-900">{total ?? 0}</p>
                      {sub != null && sub > 0 && (
                        <p className={`text-[11px] font-semibold ${c.tone === 'red' ? 'text-red-600' : c.tone === 'amber' ? 'text-amber-600' : 'text-slate-400'}`}>
                          {sub} {t(c.subLabel)}
                        </p>
                      )}
                    </div>
                  ) : null}
                </div>
                <h2 className="mt-4 flex items-center gap-1.5 font-display text-base font-semibold text-slate-900">
                  {t(c.titleKey)}
                  <Icon.ArrowRight className="h-4 w-4 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-slate-500" />
                </h2>
                <p className="mt-1 text-sm leading-relaxed text-slate-500">{t(c.descKey)}</p>
              </Link>
            );
          })}
        </div>
      </div>
    </div>
  );
}
