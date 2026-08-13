import { useCallback, useEffect, useState } from 'react';
import api from '../../api/client';
import { usePermissions } from '../../hooks/usePermissions';
import { useAuth } from '../../auth/AuthContext';
import { useToast } from '../ui/Toast';
import TicketCommandView from './TicketCommandView';
import TicketActionModal from './TicketActionModal';
import TaskRoutingModal from './TaskRoutingModal';
import ComplaintTriageModal from './ComplaintTriageModal';
import CreateMoveModal from '../../pages/logistics/CreateMoveModal';

/**
 * TicketWorkflowPanel — the deep-link single-ticket workflow surface, extracted so it can be embedded
 * anywhere (the Maintenance Workflow deep link AND the Car Status vehicle page) WITHOUT duplicating any
 * workflow logic. It is a faithful lift of MaintenanceWorkflow.js's focusId branch: it renders the
 * existing `TicketCommandView` (which fetches its own ticket by id and shows the full stage journey +
 * actions) and wires the primary-action CTA to the SAME `TicketActionModal` / routing / triage / move
 * modals the board uses. Every stage transition still POSTs through the existing endpoints — nothing is
 * reimplemented here.
 *
 *   <TicketWorkflowPanel ticketId={123} onChanged={() => reload()} />
 *
 * @param ticketId  the maintenance ticket id to render
 * @param onChanged optional callback fired after any action completes (so a host page can refresh)
 */
export default function TicketWorkflowPanel({ ticketId, onChanged, hideBack = false }) {
  const { can } = usePermissions();
  const { user } = useAuth();
  const toast = useToast();

  const [modal, setModal] = useState(null);
  const [reloadKey, setReloadKey] = useState(0);

  // Action-modal catalogs — loaded once, exactly as the board loads them (same endpoints + filtering).
  const [vehicles, setVehicles] = useState([]);
  const [garages, setGarages] = useState([]);
  const [findingsCatalog, setFindingsCatalog] = useState([]);
  const [keywordMeta, setKeywordMeta] = useState({});
  const [faultCausesCatalog, setFaultCausesCatalog] = useState({});
  // WHERE ON THE CAR — the shared location vocabulary + the per-fault-type policy that says which
  // findings take a place at all. Ships inside the findings catalog (one request), so the detail
  // editor can render the instant a fault chip is tapped. See lib/faultLocations.
  const [locationCatalog, setLocationCatalog] = useState({ groups: [], policy: {}, maxQuantity: 40 });
  const [maintTypes, setMaintTypes] = useState([]);
  const [drivers, setDrivers] = useState([]);

  const canDelegate = can('maintenance.delegate');

  useEffect(() => {
    let alive = true;
    Promise.all([api.get('/Vehicle'), api.get('/Vendor'), api.get('/maintenance-tickets/findings-catalog')])
      .then(([v, g, f]) => {
        if (!alive) return;
        const vlist = v.data?.data;
        const allVehicles = Array.isArray(vlist) ? vlist : vlist?.items || [];
        setVehicles(allVehicles.filter((veh) => ['ready', 'rented'].includes(veh.status)));
        const glist = g.data?.data;
        const all = Array.isArray(glist) ? glist : glist?.items || [];
        const shops = all.filter((x) => x.type === 'garage');
        setGarages(shops.length ? shops : all);
        setFindingsCatalog(f.data?.data?.categories || []);
        setKeywordMeta(f.data?.data?.keyword_risk || {});
        setFaultCausesCatalog(f.data?.data?.fault_causes || {});
        setLocationCatalog({
          groups: f.data?.data?.locations || [],
          policy: f.data?.data?.location_policy || {},
          maxQuantity: f.data?.data?.max_quantity || 40,
        });
        setMaintTypes((f.data?.data?.maintenance_types || []).map((x) => x.value));
      })
      .catch(() => { /* pickers fall back to empty */ });
    return () => { alive = false; };
  }, []);

  useEffect(() => {
    if (!canDelegate) return undefined;
    let alive = true;
    api.get('/maintenance-tickets/assignable-drivers')
      .then((r) => { if (alive) setDrivers(Array.isArray(r.data?.data) ? r.data.data : []); })
      .catch(() => {});
    return () => { alive = false; };
  }, [canDelegate]);

  const bump = useCallback(() => { setReloadKey((n) => n + 1); onChanged?.(); }, [onChanged]);

  const onDone = useCallback((message) => {
    setModal(null);
    if (message) toast.success(message);
    bump();
  }, [toast, bump]);

  if (!ticketId) return null;

  return (
    <>
      <TicketCommandView
        ticketId={ticketId}
        can={can}
        userId={user?.id}
        reloadKey={reloadKey}
        hideBack={hideBack}
        onAct={(action, ticket) => setModal({ action, ticket })}
      />

      {modal?.action === 'logistics' && (
        <CreateMoveModal
          open
          lockedVehicle={modal.ticket ? { id: modal.ticket.vehicle_id, plate: modal.ticket.plate, label: modal.ticket.car } : null}
          maintenanceId={modal.ticket?.id || null}
          onClose={() => setModal(null)}
          onCreated={() => onDone()}
        />
      )}
      {modal?.action === 'route' && (
        <TaskRoutingModal ticket={modal.ticket} garages={garages} onClose={() => setModal(null)} onDone={() => { setModal(null); bump(); }} />
      )}
      {modal?.action === 'triage' && (
        <ComplaintTriageModal ticket={modal.ticket} vehicles={vehicles} onClose={() => setModal(null)} onDone={onDone} />
      )}
      {modal && !['logistics', 'route', 'complaint', 'breakdown', 'triage', 'test'].includes(modal.action) && (
        <TicketActionModal
          action={modal.action}
          ticket={modal.ticket || null}
          vehicles={vehicles}
          garages={garages}
          findingsCatalog={findingsCatalog}
          keywordMeta={keywordMeta}
          faultCausesCatalog={faultCausesCatalog}
          locationCatalog={locationCatalog}
          assignableDrivers={drivers}
          allowedTypes={maintTypes}
          onClose={() => setModal(null)}
          onDone={onDone}
        />
      )}
    </>
  );
}
