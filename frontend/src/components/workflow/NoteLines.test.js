import { noteFacts } from './NoteLines';

/**
 * The splitter is the only thing standing between a machine-composed ticket note and a wall of text
 * on a board card. These pin the two boundaries it recognises — a sentence end, and the " · " seam
 * the backend writes when a second person adds to an open request.
 */
describe('noteFacts', () => {
  it('is empty for an empty note', () => {
    expect(noteFacts('')).toEqual([]);
    expect(noteFacts(null)).toEqual([]);
  });

  it('leaves a single statement whole', () => {
    expect(noteFacts('Routine check due — go check: Battery Status.'))
      .toEqual(['Routine check due — go check: Battery Status.']);
  });

  it('splits a composed agenda into one statement per fact', () => {
    expect(noteFacts(
      'Routine check due — go check: Battery Status. Routine check overdue — 21 days since last '
      + 'maintenance completion — please check: Battery, Fluids, and Brakes.',
    )).toEqual([
      'Routine check due — go check: Battery Status.',
      'Routine check overdue — 21 days since last maintenance completion — please check: Battery, Fluids, and Brakes.',
    ]);
  });

  it('splits the " · " thread seam, including an Arabic addition', () => {
    expect(noteFacts('Routine check due — go check: Battery Status. · Omar added: فحص دوري'))
      .toEqual(['Routine check due — go check: Battery Status.', 'Omar added: فحص دوري']);
  });

  it('does not read a decimal as a sentence end', () => {
    expect(noteFacts('Topped up 1.5 L oil on arrival.')).toEqual(['Topped up 1.5 L oil on arrival.']);
  });
});
