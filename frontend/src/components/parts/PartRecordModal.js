import { useEffect, useState } from 'react';
import api from '../../api/client';
import Modal from '../ui/Modal';
import Button from '../ui/Button';
import PartPurchaseHistory from './PartPurchaseHistory';
import { useI18n } from '../../i18n/I18nContext';

// Envelope-aware unwrap: the API wraps most payloads in { data: … }.
const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);

/**
 * Every purchase of ONE part on ONE car, opened on demand from a "Record" button.
 *
 * The approve/purchase modals surface this automatically when there IS a history, but "nothing happened"
 * and "nothing to show" look identical from the outside — so the useful thing is a button that always
 * gives a straight answer: the full list, or a plain statement that this car has never had this part.
 * Read-only; it approves, buys and changes nothing.
 *
 * Takes the part identity from a part REQUEST row ({ vehicle, part_name, part_number }), which is the
 * shape both the /parts board and the ticket's Parts card already hold.
 */
export default function PartRecordModal({ open, request, onClose }) {
  const { t } = useI18n();
  const [state, setState] = useState({ loading: true, history: null, error: '' });

  useEffect(() => {
    if (!open || !request) return undefined;
    let alive = true;
    setState({ loading: true, history: null, error: '' });
    api.get('/part-purchases/duplicate-check', {
      params: {
        vehicle_id: request.vehicle?.id || request.vehicle_id,
        part_name: request.part_name,
        part_number: request.part_number || undefined,
      },
    })
      .then((r) => { if (alive) setState({ loading: false, history: payload(r)?.history || null, error: '' }); })
      // A failed lookup is REPORTED, never swallowed — an error that renders as an empty list would tell
      // the buyer "this car never had this part", which is a different and possibly expensive claim.
      .catch((err) => {
        if (alive) {
          setState({
            loading: false,
            history: null,
            error: err.response?.data?.message || err.response?.data?.msg || t('Could not load the purchase record'),
          });
        }
      });
    return () => { alive = false; };
  }, [open, request, t]);

  const plate = request?.vehicle?.plate || request?.plate || (request?.vehicle?.id ? `#${request.vehicle.id}` : '');

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('Purchase record')}
      subtitle={request ? `${request.part_name}${plate ? ` · ${plate}` : ''}` : ''}
      size="lg"
      footer={<Button variant="secondary" onClick={onClose}>{t('Close')}</Button>}
    >
      {state.error ? (
        <div className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-inset ring-red-600/25">
          {state.error}
        </div>
      ) : (
        <PartPurchaseHistory
          history={state.history}
          partName={request?.part_name}
          loading={state.loading}
          showEmpty
        />
      )}
    </Modal>
  );
}
