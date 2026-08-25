// ROLE NOTIFICATIONS — what has actually been RAISED for the seat you are sitting in.
//
// This replaces the old "Live Activity" rail on My Queue. That panel replayed the handoff stamps of
// the tickets already listed on the page: it told you what had happened to cards you were looking
// at, which is the one thing you could already see. A notification is the opposite — it is the thing
// nobody has looked at yet, addressed to a role, and it was only reachable from the bell.
//
// The lanes are NOT invented here. They are the Action Center's own role lanes (lib/notifications
// LANES), each already gated by the permission that role uniquely holds and already owning the alert
// `type`s that belong to it. My Queue simply asks for the lanes of the tab you are on, so the two
// pages can never disagree about which alert belongs to which seat.
//
// Each lane is its OWN request (`?types=a,b,c`), so the server filters the whole feed and returns
// that lane's real total. Bucketing one page of fifteen rows in the browser would show "nothing
// here" for a lane whose alerts sit six pages down — the exact bug the Action Center already fixed.
//
// Refresh rides on the bell: the notifications context polls every 12s, and when its newest id or
// unread count moves we re-pull. No second timer.

import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import { useNotifications } from '../../hooks/useNotifications';
import { LANES, actionTarget, iconPath, typeIcon, typeLabel } from '../../lib/notifications';
import { CommandPanel } from '../ops';
import Icon from '../ui/Icon';
import { ago } from './meta';
import './QueueNotifications.css';

// Which Action Center lanes belong to each seat on My Queue. A lane still renders only if the user
// holds its permission, so a driver who is also a supervisor sees the supervisor lanes on the
// supervisor tab and nothing extra on the driver one.
export const ROLE_LANES = {
  inspector:  ['complaints', 'awaiting_test', 'reinspect', 'car_received'],
  dispatcher: ['assign_garage', 'assignments', 'invoice_missing', 'test_approvals', 'test_interrupted'],
  driver:     ['pickup_dropoff'],
};

// Lane → rail colour. Mirrors the section tones on the queue itself so a lane and the section it
// feeds read as the same colour of work.
const LANE_RAIL = {
  complaints: '#f43f5e', awaiting_test: '#8b5cf6', reinspect: '#10b981', car_received: '#f59e0b',
  assign_garage: '#a855f7', assignments: '#3b82f6', invoice_missing: '#f97316',
  test_approvals: '#0ea5e9', test_interrupted: '#dc2626', pickup_dropoff: '#2563eb',
};

// Payload severity → the .opx severity vocabulary.
const SEV_CLASS = { critical: 'crit', warning: 'paused', success: 'ok' };

// How many rows a lane shows before the rest collapse behind "Show N more".
const LANE_PAGE = 3;

function NotificationRow({ n, onOpen, onDismiss, t }) {
  const sev = n.resolved ? 'ok' : (SEV_CLASS[n.severity] || 'info');
  const target = actionTarget(n);
  const plate = n.meta?.plate || null;

  return (
    <div
      className={`qn-row ${!n.read && !n.resolved ? 'unread' : ''} ${n.resolved ? 'settled' : ''}`}
      role="button"
      tabIndex={0}
      title={target ? t('queue.notifications.open') : n.title}
      onClick={() => onOpen(n)}
      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onOpen(n); } }}
    >
      <span className={`qn-ic ${sev}`}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9"
          strokeLinecap="round" strokeLinejoin="round">
          <path d={iconPath(typeIcon(n.type))} />
        </svg>
      </span>
      <div className="qn-bd">
        {/* Title and body are backend copy — the alert's own words, shown as sent. */}
        <div className="qn-t">{n.title}</div>
        {n.body ? <p className="qn-b">{n.body}</p> : null}
        <div className="qn-m">
          {plate ? <span className="plate">{plate}</span> : null}
          <span>{t(typeLabel(n.type))}</span>
          {n.created_at ? <span>{ago(n.created_at, t)}</span> : null}
          {n.resolved ? <span>{t('queue.notifications.settled')}</span> : null}
        </div>
      </div>
      <button
        type="button"
        className="qn-x"
        title={t('queue.notifications.dismiss')}
        aria-label={t('queue.notifications.dismiss')}
        onClick={(e) => { e.stopPropagation(); onDismiss(n.id); }}
      >
        <Icon.X />
      </button>
    </div>
  );
}

function Lane({ lane, state, onOpen, onDismiss, t }) {
  const [expanded, setExpanded] = useState(false);
  const items = state?.items || [];
  const shown = expanded ? items : items.slice(0, LANE_PAGE);
  const hidden = items.length - shown.length;
  const total = state?.total ?? items.length;

  return (
    <div className="qn-lane">
      <div className="qn-lane-hd" title={t(lane.blurb)}>
        <span className="rail" style={{ background: LANE_RAIL[lane.key] || '#64748b' }} />
        <span className="nm">{t(lane.label)}</span>
        <span className={`ct ${total ? 'live' : ''}`}>{total}</span>
      </div>
      {items.length === 0 ? (
        <p className="qn-lane-empty">{t(lane.empty)}</p>
      ) : (
        <>
          {shown.map((n) => (
            <NotificationRow key={n.id} n={n} onOpen={onOpen} onDismiss={onDismiss} t={t} />
          ))}
          {hidden > 0 && (
            <button type="button" className="qn-more" onClick={() => setExpanded(true)}>
              {t('queue.notifications.showMore', { n: hidden })}
            </button>
          )}
          {expanded && items.length > LANE_PAGE && (
            <button type="button" className="qn-more" onClick={() => setExpanded(false)}>
              {t('queue.notifications.showLess')}
            </button>
          )}
          {/* The lane holds more than the page we fetched — say so rather than let the list look
              complete. The Action Center is where the rest of it lives. */}
          {total > items.length && (
            <p className="qn-lane-empty">{t('queue.notifications.andMore', { n: total - items.length })}</p>
          )}
        </>
      )}
    </div>
  );
}

export default function QueueNotifications({ role, can, roleLabel }) {
  const { t } = useI18n();
  const navigate = useNavigate();
  const { latest, unreadCount, refresh } = useNotifications();

  const [filter, setFilter] = useState('all');   // all | unread — sent to the server
  const [state, setState] = useState({});        // laneKey → { items, total }
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  // The lanes of THIS seat that this user is actually allowed to see.
  const lanes = useMemo(
    () => (ROLE_LANES[role] || [])
      .map((key) => LANES.find((l) => l.key === key))
      .filter((l) => l && can(l.permission)),
    [role, can]
  );

  const load = useCallback(async () => {
    if (!lanes.length) { setState({}); setLoading(false); return; }
    setFailed(false);
    const results = await Promise.all(lanes.map(async (lane) => {
      try {
        const { data } = await api.get('/notifications', {
          params: { filter, page: 1, types: lane.types.join(',') },
        });
        return [lane.key, { items: data.data?.items || [], total: data.data?.total || 0 }];
      } catch {
        // One lane failing must not blank the rail — it reports empty and the panel says so.
        return [lane.key, { items: [], total: 0, failed: true }];
      }
    }));
    setState(Object.fromEntries(results));
    setFailed(results.every(([, r]) => r.failed));
    setLoading(false);
  }, [lanes, filter]);

  // Re-pull whenever the bell's own poll sees something move (new arrival or a read/dismiss
  // elsewhere), plus on lane/filter change. No timer of our own.
  const pulse = `${latest[0]?.id || ''}:${unreadCount}`;
  useEffect(() => { load(); }, [load, pulse]);

  const open = async (n) => {
    if (!n.read) {
      setState((s) => Object.fromEntries(Object.entries(s).map(([k, v]) => [
        k, { ...v, items: v.items.map((x) => (x.id === n.id ? { ...x, read: true } : x)) },
      ])));
      try { await api.post(`/notifications/${n.id}/read`); } catch { /* the next poll reconciles */ }
      refresh();
    }
    const to = actionTarget(n);
    if (to) navigate(to);
  };

  const dismiss = async (id) => {
    setState((s) => Object.fromEntries(Object.entries(s).map(([k, v]) => [
      k, { items: v.items.filter((x) => x.id !== id), total: Math.max(0, (v.total || 0) - (v.items.some((x) => x.id === id) ? 1 : 0)) },
    ])));
    try { await api.delete(`/notifications/${id}`); } catch { /* the next poll reconciles */ }
    refresh();
  };

  const shownTotal = lanes.reduce((n, l) => n + (state[l.key]?.total || 0), 0);

  return (
    <CommandPanel
      title={t('queue.notifications.title')}
      dotColor="#f59e0b"
      action={(
        <div className="qn-tools">
          <div className="qn-seg" role="group" aria-label={t('queue.notifications.filter')}>
            <button type="button" className={filter === 'all' ? 'on' : ''} onClick={() => setFilter('all')}>
              {t('queue.notifications.all')}
            </button>
            <button type="button" className={filter === 'unread' ? 'on' : ''} onClick={() => setFilter('unread')}>
              {t('queue.notifications.unread')}
            </button>
          </div>
        </div>
      )}
    >
      <p className="qsec-hint">
        {roleLabel
          ? t('queue.notifications.hintRole', { role: roleLabel })
          : t('queue.notifications.hint')}
      </p>

      {lanes.length === 0 ? (
        <p className="opx-empty" style={{ padding: '22px 10px' }}>{t('queue.notifications.noLanes')}</p>
      ) : loading ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          <div className="opx-skel" style={{ height: 46 }} />
          <div className="opx-skel" style={{ height: 46 }} />
          <div className="opx-skel" style={{ height: 46 }} />
        </div>
      ) : failed ? (
        <p className="opx-empty" style={{ padding: '22px 10px' }}>{t('queue.notifications.failed')}</p>
      ) : (
        <div className="qn-lanes">
          {lanes.map((lane) => (
            <Lane key={lane.key} lane={lane} state={state[lane.key]} onOpen={open} onDismiss={dismiss} t={t} />
          ))}
        </div>
      )}

      <div className="qn-foot">
        <span>{t('queue.notifications.foot', { n: shownTotal })}</span>
        <Link to="/notifications">{t('queue.notifications.openCenter')}</Link>
      </div>
    </CommandPanel>
  );
}
