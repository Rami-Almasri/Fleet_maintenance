// Render test for the redesigned assign-step recommendation UI: a hero "recommended decision" card + a
// Compare toggle that reveals the secondary sections. Uses the live API payload shape.

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import GarageRecommendations from './GarageRecommendations';
import api from '../../api/client';

jest.mock('../../api/client', () => ({ get: jest.fn() }));
jest.mock('../../i18n/I18nContext', () => ({
  // t returns the key, but interpolates {pct}/{n} so specialization/matches text is assertable.
  useI18n: () => ({ t: (k, v) => (v ? `${k}:${Object.values(v).join(',')}` : k), lang: 'en' }),
}));

const PAYLOAD = {
  has_history: true,
  total_history: 24517,
  criteria: { model: 'YUKON', faults: ['engine', 'interior'], fault_labels: ['Engine', 'Interior'] },
  ticket: {
    model_label: 'GMC Yukon',
    faults_detail: [
      { symptom: 'Engine noise', category_key: 'engine', label: 'Engine' },
      { symptom: 'Dashboard fault', category_key: 'interior', label: 'Interior' },
    ],
  },
  primary: [
    { vendor_id: 223, garage: 'Deals On Wheels auto', rank: 1, is_top: true, match_score: 96, matched: 16, total: 40, concentration: 89, confidence: 'high', warn: false,
      coverage: { covered: 2, total: 2, missing: [] },
      fault_coverage: [
        { category_key: 'engine', label: 'Engine', at_garage: 42, same_model: 15 },
        { category_key: 'interior', label: 'Interior', at_garage: 28, same_model: 9 },
      ],
      reasons: [{ t: '16 previous Yukon repairs', s: '16 jobs' }, { t: 'Current fault coverage: 2/2' }, { t: 'Highest Engine repair volume' }] },
    { vendor_id: 501, garage: '7 CYLINDER', rank: 2, is_top: false, match_score: 62, matched: 8, total: 30, concentration: 40, confidence: 'medium', warn: false,
      coverage: { covered: 1, total: 2, missing: ['Interior'] }, fault_coverage: [], reasons: [{ t: 'Strong Yukon focus' }] },
  ],
  also_consider: [
    { dimension: 'fault', label: 'Engine specialist', vendor_id: 164, garage: 'Alresala', jobs: 58, concentration: 91 },
  ],
};

beforeEach(() => api.get.mockReset());

test('shows one hero recommendation with score, confidence, fault badges and short reasons', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  const onResult = jest.fn();
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={onResult} />);

  // hero decision
  expect(await screen.findByText('Deals On Wheels auto')).toBeInTheDocument();
  expect(screen.getByText('96')).toBeInTheDocument();                 // score /100
  expect(screen.getByText('workflow.garageRec.confidence.high')).toBeInTheDocument();
  // fault categories rendered as separate badges
  expect(screen.getByText('Engine')).toBeInTheDocument();
  expect(screen.getByText('Interior')).toBeInTheDocument();
  // short reasons
  expect(screen.getByText('16 previous Yukon repairs')).toBeInTheDocument();

  // secondary sections are hidden until Compare
  expect(screen.queryByText('7 CYLINDER')).not.toBeInTheDocument();
  expect(screen.queryByText('Alresala')).not.toBeInTheDocument();

  await waitFor(() => expect(onResult).toHaveBeenCalledWith(PAYLOAD));
});

test('Compare reveals other proven garages and specialists', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('Deals On Wheels auto');

  fireEvent.click(screen.getByText('workflow.garageRec.compare'));
  expect(screen.getByText('workflow.garageRec.otherProven')).toBeInTheDocument();
  expect(screen.getByText('7 CYLINDER')).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.specialists')).toBeInTheDocument();
  expect(screen.getByText('Alresala')).toBeInTheDocument();
});

test('Select recommended garage pre-fills the picker with the top vendor', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  const onPick = jest.fn();
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={onPick} onResult={() => {}} />);
  await screen.findByText('Deals On Wheels auto');

  fireEvent.click(screen.getByText('workflow.garageRec.selectRecommended'));
  expect(onPick).toHaveBeenCalledWith(223);
});

test('View details reveals the evidence breakdown', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('Deals On Wheels auto');

  expect(screen.queryByText('workflow.garageRec.details.matches')).not.toBeInTheDocument();
  fireEvent.click(screen.getByText('workflow.garageRec.viewDetails'));
  expect(screen.getByText('workflow.garageRec.details.matches')).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.details.specialization')).toBeInTheDocument();

  // per-fault coverage evidence tied to THIS repair
  expect(screen.getByText('workflow.garageRec.faultCoverageTitle')).toBeInTheDocument();
  expect(screen.getByText('Engine noise')).toBeInTheDocument();
  expect(screen.getByText('Dashboard fault')).toBeInTheDocument();
  // "N label repairs at this garage" is interpolated (mocked t joins the values)
  expect(screen.getByText('workflow.garageRec.repairsHere:42,Engine')).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.repairsModel:15,GMC Yukon,Engine')).toBeInTheDocument();
});

test('once selected, the CTA becomes a recorded-recommendation summary', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={223} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('Deals On Wheels auto');

  expect(screen.getByText('workflow.garageRec.confirmSelected')).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.auditNote')).toBeInTheDocument();
  // the plain "select" CTA is gone once confirmed
  expect(screen.queryByText('workflow.garageRec.selectRecommended')).not.toBeInTheDocument();
});

test('a low-confidence recommendation shows the review-alternatives warning', async () => {
  const low = { ...PAYLOAD, primary: [{ ...PAYLOAD.primary[0], confidence: 'low', match_score: 34 }] };
  api.get.mockResolvedValue({ data: { data: low } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('Deals On Wheels auto');
  expect(screen.getByText('workflow.garageRec.lowWarning')).toBeInTheDocument();
});

test('degrades gracefully when there is no history', async () => {
  api.get.mockResolvedValue({ data: { data: { has_history: false, total_history: 0, primary: [], also_consider: [] } } });
  render(<GarageRecommendations ticketId={2} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  expect(await screen.findByText('workflow.garageRec.none')).toBeInTheDocument();
});

test('no proven primary but specialists exist — explains why and surfaces the specialists directly', async () => {
  // primary empty (no garage has a proven record on this exact vehicle + fault) but a fault specialist
  // exists. The panel must NOT go blank under "Recommended for" — it explains and shows the specialist.
  const noPrimary = { ...PAYLOAD, primary: [], also_consider: PAYLOAD.also_consider };
  api.get.mockResolvedValue({ data: { data: noPrimary } });
  render(<GarageRecommendations ticketId={7} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);

  // the "Recommended for: Engine/Interior" fault labels still render
  expect(await screen.findByText('Engine')).toBeInTheDocument();
  // explanation, not a blank panel — and the specialist is reachable without a Compare click
  expect(screen.getByText('workflow.garageRec.noPrimaryTitle')).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.noPrimary')).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.specialists')).toBeInTheDocument();
  expect(screen.getByText('Alresala')).toBeInTheDocument();
});
