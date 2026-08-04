// The Parts Catalog's lifecycle contract, not its markup.
//
// A part type is reference data that operational history points at: fitted components, warranties,
// and the lines inspectors wrote. So Delete and Retire are different actions with different
// consequences, and the page must never blur them. These tests encode the rules that keep that
// honest:
//
//   · Delete is offered only where the server would allow it, and still asks first.
//   · A refused delete is an EXPLANATION with counts, never a red toast.
//   · Retire is its own deliberate action — never a delete quietly turned into something else.
//   · A retired part stays visible and reversible.
//   · The list reloads after anything that changes a part's lifecycle.

import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import PartsCatalog from './PartsCatalog';
import api from '../api/client';

jest.mock('../api/client', () => ({ get: jest.fn(), post: jest.fn(), delete: jest.fn() }));

const mockToast = { success: jest.fn(), error: jest.fn() };
jest.mock('../components/ui/Toast', () => ({ useToast: () => mockToast }));
jest.mock('../hooks/usePermissions', () => ({ usePermissions: () => ({ can: () => true }) }));
// t() returns the key, so assertions name the contract rather than a translation that may be reworded.
jest.mock('../i18n/I18nContext', () => ({
  useI18n: () => ({ t: (k) => k, lang: 'en' }),
}));

const part = (over = {}) => ({
  id: 1, slug: 'test-part', name: 'Test Part', name_ar: 'قطعة',
  aliases: [], category_key: 'engine', tracking_mode: 'batch',
  default_warranty_months: null, default_warranty_km: null,
  expected_life_km: null, expected_life_months: null,
  position_scheme: null, positions: [], is_active: true, notes: null,
  usage_count: 0,
  references: { fitted_components: 0, warranties: 0, required_parts: 0 },
  can_delete: true,
  edited_in_app: false, edited_at: null, edited_by_name: null,
  ...over,
});

const payload = (parts) => ({
  data: {
    data: {
      parts,
      categories: [{ key: 'engine', label: 'Engine', label_ar: 'المحرك' }],
      tracking_modes: [{ key: 'batch', label: 'Batch', label_ar: 'بالكمية', hint: 'h' }],
      position_schemes: [{ key: null, label: 'No position' }],
      counts: { total: parts.length, active: parts.length, retired: 0, edited: 0, missing_ar: 0 },
    },
  },
});

/** A 422 shaped exactly like the backend's refusal envelope. */
const blockedError = (references) => ({
  response: {
    status: 422,
    data: {
      success: false,
      message: 'Cannot delete "Test Part" — it is still referenced by 51 fitted components.',
      data: { references, can_retire: true, retire_url: '/api/parts-catalog/1/retire' },
    },
  },
});

const renderWith = async (parts) => {
  api.get.mockResolvedValue(payload(parts));
  render(<PartsCatalog />);
  await screen.findByText('Test Part');
};

beforeEach(() => jest.clearAllMocks());

// The row button and the dialog's confirm button carry the SAME label — that is deliberate (the
// dialog confirms the action you picked), so tests must say which one they mean. The row renders
// first, the dialog last.
const buttonsNamed = (label) => screen.getAllByRole('button', { name: label });
const openAction = (label) => fireEvent.click(buttonsNamed(label)[0]);
const confirmAction = (label) => {
  const all = buttonsNamed(label);
  fireEvent.click(all[all.length - 1]);
};

describe('delete', () => {
  it('is offered for a part nothing references, and asks before deleting', async () => {
    await renderWith([part()]);

    openAction('partsCatalog.remove');

    // A confirmation step, always — deletion is permanent.
    expect(screen.getByText('partsCatalog.removeTitle')).toBeInTheDocument();
    expect(api.delete).not.toHaveBeenCalled();
  });

  it('deletes and refreshes once confirmed', async () => {
    await renderWith([part()]);
    api.delete.mockResolvedValue({ data: { success: true } });

    openAction('partsCatalog.remove');
    confirmAction('partsCatalog.remove');

    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/parts-catalog/1'));
    expect(mockToast.success).toHaveBeenCalledWith('partsCatalog.removed');
    // The list is re-read, so what is on screen matches what the server now holds.
    await waitFor(() => expect(api.get).toHaveBeenCalledTimes(2));
  });

  it('is NOT offered for a referenced part — Retire is', async () => {
    await renderWith([part({
      can_delete: false, usage_count: 51,
      references: { fitted_components: 51, warranties: 0, required_parts: 0 },
    })]);

    expect(screen.queryByText('partsCatalog.remove')).not.toBeInTheDocument();
    expect(screen.getByText('partsCatalog.retire')).toBeInTheDocument();
  });

  it('explains a refusal with its counts instead of showing a generic error', async () => {
    // The list is stale: it says deletable, the server disagrees. This is the case the 422 exists for.
    await renderWith([part()]);
    api.delete.mockRejectedValue(blockedError({ fitted_components: 51, warranties: 2, required_parts: 0 }));

    openAction('partsCatalog.remove');
    confirmAction('partsCatalog.remove');

    await screen.findByText('partsCatalog.blockedTitle');

    // The counts are shown, named, and zero-references are omitted.
    expect(screen.getByText('partsCatalog.ref.fitted_components')).toBeInTheDocument();
    expect(screen.getByText('51')).toBeInTheDocument();
    expect(screen.getByText('partsCatalog.ref.warranties')).toBeInTheDocument();
    expect(screen.getByText('2')).toBeInTheDocument();
    expect(screen.queryByText('partsCatalog.ref.required_parts')).not.toBeInTheDocument();

    // A refusal is not an error toast.
    expect(mockToast.error).not.toHaveBeenCalled();
  });

  it('offers Retire from the refusal, and does not retire on its own', async () => {
    await renderWith([part()]);
    api.delete.mockRejectedValue(blockedError({ fitted_components: 51 }));

    openAction('partsCatalog.remove');
    confirmAction('partsCatalog.remove');
    await screen.findByText('partsCatalog.blockedTitle');

    // Nothing was retired behind the user's back.
    expect(api.post).not.toHaveBeenCalled();

    // Choosing Retire opens ITS confirmation — still no silent action.
    openAction('partsCatalog.retire');
    expect(screen.getByText('partsCatalog.retireTitle')).toBeInTheDocument();
    expect(api.post).not.toHaveBeenCalled();
  });
});

describe('retire', () => {
  const referenced = () => part({
    can_delete: false, usage_count: 51,
    references: { fitted_components: 51, warranties: 0, required_parts: 0 },
  });

  it('asks before retiring', async () => {
    await renderWith([referenced()]);

    openAction('partsCatalog.retire');

    expect(screen.getByText('partsCatalog.retireTitle')).toBeInTheDocument();
    expect(api.post).not.toHaveBeenCalled();
  });

  it('retires against the dedicated endpoint and refreshes', async () => {
    await renderWith([referenced()]);
    api.post.mockResolvedValue({ data: { success: true } });

    openAction('partsCatalog.retire');
    confirmAction('partsCatalog.retire');

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/parts-catalog/1/retire'));
    expect(mockToast.success).toHaveBeenCalledWith('partsCatalog.retired');
    await waitFor(() => expect(api.get).toHaveBeenCalledTimes(2));
    // Never converted into a delete.
    expect(api.delete).not.toHaveBeenCalled();
  });

  it('shows a retired part as retired, and offers Restore', async () => {
    await renderWith([part({ is_active: false, can_delete: false, usage_count: 3,
      references: { fitted_components: 3, warranties: 0, required_parts: 0 } })]);

    // Scoped to the row: 'retired' is also a filter option, and the badge is what matters here.
    const row = screen.getByRole('row', { name: /Test Part/ });
    expect(within(row).getByText('partsCatalog.retired')).toBeInTheDocument();
    expect(screen.getByText('partsCatalog.restore')).toBeInTheDocument();
    expect(screen.queryByText('partsCatalog.retire')).not.toBeInTheDocument();
  });

  it('lets an unreferenced retired part still be deleted — retiring is not permanent', async () => {
    await renderWith([part({ is_active: false, can_delete: true })]);

    expect(screen.getByText('partsCatalog.restore')).toBeInTheDocument();
    expect(screen.getByText('partsCatalog.remove')).toBeInTheDocument();
  });

  it('restores and refreshes', async () => {
    await renderWith([part({ is_active: false, can_delete: true })]);
    api.post.mockResolvedValue({ data: { success: true } });

    openAction('partsCatalog.restore');

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/parts-catalog/1/restore'));
    await waitFor(() => expect(api.get).toHaveBeenCalledTimes(2));
  });
});

describe('API edge cases', () => {
  it('names a permission failure instead of blaming the data', async () => {
    await renderWith([part()]);
    api.delete.mockRejectedValue({ response: { status: 403, data: { message: 'Forbidden' } } });

    openAction('partsCatalog.remove');
    confirmAction('partsCatalog.remove');

    await waitFor(() => expect(mockToast.error).toHaveBeenCalledWith('partsCatalog.permissionDenied'));
  });

  it('names a network failure rather than reporting a save problem', async () => {
    await renderWith([part()]);
    api.delete.mockRejectedValue(new Error('Network Error')); // no `response` at all

    openAction('partsCatalog.remove');
    confirmAction('partsCatalog.remove');

    await waitFor(() => expect(mockToast.error).toHaveBeenCalledWith('partsCatalog.networkError'));
  });

  it('surfaces a server message for any other failure', async () => {
    await renderWith([part()]);
    api.delete.mockRejectedValue({ response: { status: 500, data: { message: 'Something broke' } } });

    openAction('partsCatalog.remove');
    confirmAction('partsCatalog.remove');

    await waitFor(() => expect(mockToast.error).toHaveBeenCalledWith('Something broke'));
  });

  it('reports a failed retire without pretending it worked', async () => {
    await renderWith([part({ can_delete: false, usage_count: 2,
      references: { fitted_components: 2, warranties: 0, required_parts: 0 } })]);
    api.post.mockRejectedValue({ response: { status: 500, data: {} } });

    openAction('partsCatalog.retire');
    confirmAction('partsCatalog.retire');

    await waitFor(() => expect(mockToast.error).toHaveBeenCalledWith('partsCatalog.retireError'));
    expect(mockToast.success).not.toHaveBeenCalled();
  });
});
