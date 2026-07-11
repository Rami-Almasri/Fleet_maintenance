import { useState } from 'react';
import api from '../api/client';
import { useToast } from '../components/ui/Toast';
import { PageHeader } from '../components/ui/Misc';
import { SectionCard } from '../components/ui/Table';
import Button from '../components/ui/Button';

// The four test triggers, mirroring the real detectors they simulate.
const TRIGGERS = [
  { key: 'overdue_service', label: 'Overdue Service', emoji: '🔧', hint: 'Simulates a brake-pads service overdue on a vehicle.' },
  { key: 'rental_expiry', label: 'Rental Expiry', emoji: '📅', hint: 'Simulates a rental contract expiring in 3 days.' },
  { key: 'invoice_overdue', label: 'Invoice Overdue', emoji: '💳', hint: 'Simulates a customer with a pending (overdue) payment.' },
  { key: 'inspection_due', label: 'Inspection Due', emoji: '📋', hint: 'Simulates an upcoming/overdue scheduled inspection.' },
];

export default function NotificationTestConsole() {
  const toast = useToast();
  const [force, setForce] = useState(true);        // default ON — always fire, even if no live condition
  const [broadcast, setBroadcast] = useState(false); // OFF — only fire to me, not the whole role
  const [busy, setBusy] = useState(null);
  const [log, setLog] = useState([]);              // most-recent-first fired-event log

  const fire = async (trigger) => {
    setBusy(trigger.key);
    try {
      const { data } = await api.post('/admin/notifications/test', { trigger: trigger.key, force, broadcast });
      const d = data.data || {};
      toast.success(`${trigger.label} fired — check the bell 🔔`);
      setLog((l) => [{
        at: new Date().toLocaleTimeString(),
        label: trigger.label,
        mode: d.mode,
        delivered: d.delivered_to,
        title: d.payload?.title,
        body: d.payload?.body,
      }, ...l].slice(0, 20));
    } catch (e) {
      const msg = e.response?.data?.message || 'Could not fire the test notification';
      toast.error(msg);
      // A real-mode miss is expected info, not a hard error — surface it in the log too.
      setLog((l) => [{ at: new Date().toLocaleTimeString(), label: trigger.label, mode: 'real', error: msg }, ...l].slice(0, 20));
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1100px] space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Notification Test Console"
          subtitle="Fire a realistic alert on demand to verify the whole pipeline — raise → database → bell — without waiting for the 10-minute scanner. Admin-only; test alerts are marked 🧪 and tagged simulated."
        />

        <SectionCard title="Options">
          <div className="flex flex-wrap items-center gap-6 px-1 py-1">
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" checked={force} onChange={(e) => setForce(e.target.checked)} className="h-4 w-4 rounded border-slate-300" />
              <span><span className="font-medium">Force</span> — fire even if the live condition isn’t met (uses sample data)</span>
            </label>
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" checked={broadcast} onChange={(e) => setBroadcast(e.target.checked)} className="h-4 w-4 rounded border-slate-300" />
              <span><span className="font-medium">Broadcast</span> — fan out to everyone with the receiving permission (default: just me)</span>
            </label>
          </div>
          <p className="mt-2 px-1 text-xs text-slate-400">
            With Force off, a trigger only fires when a genuine matching record exists (otherwise it tells you to use Force).
          </p>
        </SectionCard>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          {TRIGGERS.map((t) => (
            <div key={t.key} className="flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
              <div className="min-w-0">
                <p className="font-semibold text-slate-900">{t.emoji} {t.label}</p>
                <p className="mt-0.5 text-xs text-slate-500">{t.hint}</p>
              </div>
              <Button variant="primary" size="sm" loading={busy === t.key} onClick={() => fire(t)}>Fire</Button>
            </div>
          ))}
        </div>

        <SectionCard title="Fired events" subtitle="Most recent first — this session only.">
          {log.length === 0 ? (
            <p className="px-1 py-2 text-sm text-slate-400">Nothing fired yet. Hit a trigger above, then open the bell 🔔.</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {log.map((r, i) => (
                <li key={i} className="flex items-start gap-3 py-2.5 text-sm">
                  <span className="w-16 shrink-0 tabular-nums text-xs text-slate-400">{r.at}</span>
                  {r.error ? (
                    <span className="text-amber-700">{r.label} — <span className="text-slate-500">{r.error}</span></span>
                  ) : (
                    <span className="min-w-0">
                      <span className="font-medium text-slate-800">{r.title}</span>
                      <span className="ml-2 rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-slate-500">{r.mode}{r.delivered > 1 ? ` · ${r.delivered}` : ''}</span>
                      <span className="block truncate text-xs text-slate-500">{r.body}</span>
                    </span>
                  )}
                </li>
              ))}
            </ul>
          )}
        </SectionCard>
      </div>
    </div>
  );
}
