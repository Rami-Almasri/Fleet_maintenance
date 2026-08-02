import Modal from './Modal';
import Button from './Button';
import { useI18n } from '../../i18n/I18nContext';

export default function ConfirmDialog({
  open,
  onClose,
  onConfirm,
  // `title`/`confirmText` fall back to translated defaults inside the body —
  // a default in the signature can't call the hook.
  title,
  message,
  confirmText,
  variant = 'danger',
  loading = false,
  confirmDisabled = false,
  children,
}) {
  const { t } = useI18n();
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title ?? t('confirm.title')}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={loading}>
            {t('common.cancel')}
          </Button>
          <Button variant={variant} onClick={onConfirm} loading={loading} disabled={confirmDisabled}>
            {confirmText ?? t('confirm.confirm')}
          </Button>
        </>
      }
    >
      {message && <p className="text-sm text-slate-600">{message}</p>}
      {children}
    </Modal>
  );
}
