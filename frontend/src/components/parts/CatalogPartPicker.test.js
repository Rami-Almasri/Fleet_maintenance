// The picker's contract: a part is CHOSEN from the catalog, never typed.
//
// The free-text box it replaced is why "Brake pads", "Front brake pads (set)" and "break" were three
// unrelated strings for one part. So the rules under test are: search finds the part however a
// person refers to it, selecting stores the reference and not the words, and a line written before
// the catalog existed is shown for review rather than silently kept or silently dropped.

import { render, screen, fireEvent } from '@testing-library/react';
import CatalogPartPicker from './CatalogPartPicker';

jest.mock('../../i18n/I18nContext', () => ({
  useI18n: () => ({ t: (k) => k, lang: 'en' }),
}));

const CATALOG = [
  {
    id: 5, name: 'Alternator', name_ar: 'دينمو', default_part_number: 'ALT-9931',
    aliases: ['dynamo', 'battery not charging', 'ما يشحن'], is_active: true,
  },
  {
    id: 9, name: 'Brake Pads (set)', name_ar: 'فحمات فرامل', default_part_number: null,
    aliases: ['pads', 'brake noise'], is_active: true,
  },
  {
    id: 12, name: 'AC Compressor', name_ar: 'كمبروسر مكيف', default_part_number: 'AC-100',
    aliases: ['ac not cooling'], is_active: true,
  },
];

const setup = (props = {}) => {
  const onChange = jest.fn();
  render(<CatalogPartPicker catalog={CATALOG} onChange={onChange} value={null} {...props} />);
  return onChange;
};

const search = (text) => {
  const input = screen.getByRole('combobox');
  fireEvent.focus(input);
  fireEvent.change(input, { target: { value: text } });
  return input;
};

const optionNames = () => screen.getAllByRole('option').map((o) => o.textContent);

describe('search', () => {
  it('finds a part by its English name', () => {
    setup();
    search('altern');
    expect(optionNames().join()).toContain('Alternator');
  });

  it('finds a part by its Arabic name', () => {
    setup();
    search('دينمو');
    expect(optionNames().join()).toContain('Alternator');
  });

  it('finds a part by SKU', () => {
    setup();
    search('ALT-9931');
    expect(optionNames().join()).toContain('Alternator');
  });

  /** Aliases carry symptom wording. Generous HERE is safe: a human picks from the results. */
  it('finds a part by the problem people describe', () => {
    setup();
    search('battery not charging');
    expect(optionNames().join()).toContain('Alternator');
  });

  it('ranks an exact name above a mere alias match', () => {
    setup();
    search('brake');
    // "Brake Pads (set)" matches by name; the Alternator does not match at all here.
    expect(optionNames()[0]).toContain('Brake Pads');
  });

  it('says so when nothing matches, and points at the catalog', () => {
    setup();
    search('nothing like this exists');
    expect(screen.getByText('workflow.requiredParts.noMatch')).toBeInTheDocument();
    expect(screen.queryAllByRole('option')).toHaveLength(0);
  });
});

describe('selection', () => {
  it('stores the reference, not the typed words', () => {
    const onChange = setup();
    search('altern');
    fireEvent.click(screen.getByRole('button', { name: /Alternator/ }));

    expect(onChange).toHaveBeenCalledWith({
      component_catalog_id: 5,
      part_name: 'Alternator',
      part_number: 'ALT-9931',
    });
  });

  it('selects with the keyboard', () => {
    const onChange = setup();
    const input = search('a');
    fireEvent.keyDown(input, { key: 'ArrowDown' });
    fireEvent.keyDown(input, { key: 'Enter' });

    expect(onChange).toHaveBeenCalled();
    expect(onChange.mock.calls[0][0]).toHaveProperty('component_catalog_id');
  });

  it('shows the chosen part with its SKU, and no search box', () => {
    setup({ value: { component_catalog_id: 5, part_name: 'Alternator', part_number: 'ALT-9931' } });

    expect(screen.getByText(/Alternator/)).toBeInTheDocument();
    expect(screen.getByText('ALT-9931')).toBeInTheDocument();
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
  });

  it('can be changed back to a search', () => {
    const onChange = setup({ value: { component_catalog_id: 5, part_name: 'Alternator', part_number: null } });

    fireEvent.click(screen.getByText('workflow.requiredParts.change'));

    expect(onChange).toHaveBeenCalledWith(null);
  });
});

describe('legacy free text', () => {
  /** The inspector's wording is evidence: shown for review, never silently dropped or accepted. */
  it('shows the old text and asks for a part to be picked', () => {
    setup({ legacyText: 'Front brake disc' });

    expect(screen.getByText(/Front brake disc/)).toBeInTheDocument();
    expect(screen.getByText('workflow.requiredParts.unlinkedHint')).toBeInTheDocument();
    // Still a picker, because the line is not yet a real reference.
    expect(screen.getByRole('combobox')).toBeInTheDocument();
  });

  it('drops the warning once a real part is chosen', () => {
    const { rerender } = render(
      <CatalogPartPicker catalog={CATALOG} onChange={jest.fn()} value={null} legacyText="Front brake disc" />
    );
    expect(screen.getByText('workflow.requiredParts.unlinkedHint')).toBeInTheDocument();

    rerender(
      <CatalogPartPicker
        catalog={CATALOG}
        onChange={jest.fn()}
        value={{ component_catalog_id: 9, part_name: 'Brake Pads (set)', part_number: null }}
        legacyText="Front brake disc"
      />
    );
    expect(screen.queryByText('workflow.requiredParts.unlinkedHint')).not.toBeInTheDocument();
  });
});

describe('loading', () => {
  it('disables the box until the catalog arrives', () => {
    setup({ catalog: [], loading: true });

    expect(screen.getByRole('combobox')).toBeDisabled();
  });
});
