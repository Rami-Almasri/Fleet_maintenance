import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useNotifications } from '../hooks/useNotifications';
import { actionTarget } from '../lib/notifications';
import NotificationRow from './NotificationRow';
import Button from './ui/Button';

export default function NotificationBell() {
  const navigate = useNavigate();
  const { unreadCount, latest, markRead, markAllRead, dismiss, sendDemo } = useNotifications();
  const [open, setOpen] = useState(false);
  const [ringing, setRinging] = useState(false);
  const wrapRef = useRef(null);
  const prevUnread = useRef(unreadCount);

  // Give the bell a quick wiggle whenever the unread count climbs.
  useEffect(() => {
    if (unreadCount > prevUnread.current) {
      setRinging(true);
      const t = setTimeout(() => setRinging(false), 900);
      prevUnread.current = unreadCount;
      return () => clearTimeout(t);
    }
    prevUnread.current = unreadCount;
  }, [unreadCount]);

  // Close on outside click + Escape.
  useEffect(() => {
    if (!open) return;
    const onDown = (e) => { if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false); };
    const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  const openNotification = (n) => {
    if (!n.read) markRead(n.id);
    setOpen(false);
    const to = actionTarget(n);
    if (to) navigate(to);
  };

  const badge = unreadCount > 99 ? '99+' : unreadCount;

  return (
    <div ref={wrapRef} className="relative">
      <button
        onClick={() => setOpen((v) => !v)}
        className={`relative rounded-xl p-2 text-slate-500 transition-colors duration-150 hover:bg-slate-100 hover:text-slate-700 ${open ? 'bg-slate-100 text-slate-700' : ''}`}
        title="Notifications"
        aria-label={`Notifications${unreadCount ? `, ${unreadCount} unread` : ''}`}
        aria-haspopup="true"
        aria-expanded={open}
      >
        <svg
          aria-hidden="true"
          className={`h-[22px] w-[22px] ${ringing ? 'animate-bell' : ''}`}
          style={{ transformOrigin: 'top center' }}
          fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"
        >
          <path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 0 0-4-5.7V5a2 2 0 1 0-4 0v.3A6 6 0 0 0 6 11v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0v1a3 3 0 1 1-6 0v-1m6 0H9" />
        </svg>

        {unreadCount > 0 && (
          <span className="absolute -right-0.5 -top-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white shadow-sm ring-2 ring-white">
            {badge}
          </span>
        )}
      </button>

      {open && (
        <>
          {/* mobile scrim */}
          <div className="fixed inset-0 z-30 sm:hidden" onClick={() => setOpen(false)} />

          <div className="absolute right-0 z-40 mt-2 w-[min(92vw,24rem)] origin-top-right animate-pop overflow-hidden rounded-2xl border border-slate-200/60 bg-white shadow-card">
            {/* header */}
            <div className="flex items-center justify-between gap-2 border-b border-slate-100 bg-slate-50 px-4 py-3">
              <div className="flex items-center gap-2">
                <h3 className="text-sm font-semibold text-slate-800">Notifications</h3>
                {unreadCount > 0 && (
                  <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-[11px] font-bold tabular-nums text-indigo-700">{unreadCount} new</span>
                )}
              </div>
              {unreadCount > 0 && (
                <Button variant="ghost" size="sm" onClick={markAllRead} className="text-indigo-600 hover:bg-indigo-50 hover:text-indigo-700">
                  Mark all read
                </Button>
              )}
            </div>

            {/* list */}
            <div className="max-h-[60vh] divide-y divide-slate-100 overflow-y-auto sidebar-scroll">
              {latest.length === 0 ? (
                <div className="flex flex-col items-center justify-center px-6 py-12 text-center">
                  <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-300">
                    <svg aria-hidden="true" className="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
                      <path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 0 0-4-5.7V5a2 2 0 1 0-4 0v.3A6 6 0 0 0 6 11v3.2a2 2 0 0 1-.6 1.4L4 17h5" />
                    </svg>
                  </div>
                  <p className="mt-3 text-sm font-semibold text-slate-700">You're all caught up</p>
                  <p className="mt-0.5 text-xs text-slate-400">New fleet alerts will appear here in real time.</p>
                  <Button variant="secondary" size="sm" onClick={sendDemo} className="mt-4">
                    Send a test alert
                  </Button>
                </div>
              ) : (
                latest.map((n) => (
                  <NotificationRow key={n.id} n={n} onOpen={openNotification} onDismiss={dismiss} compact />
                ))
              )}
            </div>

            {/* footer */}
            <div className="flex items-center justify-between border-t border-slate-100 bg-slate-50/60 px-4 py-2.5">
              <Button variant="ghost" size="sm" onClick={sendDemo} className="font-medium text-slate-400 hover:text-slate-600">
                Send test
              </Button>
              <Button
                variant="ghost"
                size="sm"
                onClick={() => { setOpen(false); navigate('/notifications'); }}
                className="text-indigo-600 hover:bg-indigo-50 hover:text-indigo-700"
              >
                View all →
              </Button>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
