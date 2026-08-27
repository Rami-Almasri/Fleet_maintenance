// Vehicle documents — the car's paperwork scan, today the Mulkiya (UAE Vehicle Licence).
//
// Uploading a replacement does NOT overwrite the old scan: the API supersedes it and the previous
// card stays as history (a renewal is a genuinely new card). Every call returns the SAME payload
// shape — { vehicle, kind, current, history } — so a caller can just replace its state after any
// write instead of re-fetching.
//
// Images are compressed in-browser before upload (a phone photo of a Mulkiya is 4–8 MB and the
// card is perfectly legible at 2000px). PDFs are sent through untouched — compressImage only
// understands raster images.

import api from '../api/client';
import { compressImage } from './imageCompression';

const base = (vehicleId) => `/Vehicle/${vehicleId}/documents`;

export const MULKIYA = 'mulkiya';

/** The car's document trail for a kind: the card in force + every superseded version. */
export async function getVehicleDocuments(vehicleId, kind = MULKIYA) {
  const res = await api.get(base(vehicleId), { params: { kind } });
  return res.data.data; // { vehicle, kind, current, history: [...] }
}

/**
 * Add or change the car's Mulkiya. Whatever was current becomes history.
 * Returns the fresh payload.
 */
export async function uploadVehicleDocument(vehicleId, file, { kind = MULKIYA, note = '' } = {}) {
  const isPdf = file.type === 'application/pdf';
  const fd = new FormData();

  if (isPdf) {
    fd.append('file', file, file.name || 'mulkiya.pdf');
  } else {
    // 2000px keeps the plate, chassis no. and expiry dates readable; 0.8 keeps the card legible
    // where a harsher setting starts to smear the small Arabic text.
    const compressed = await compressImage(file, { maxDimension: 2000, quality: 0.8 });
    fd.append('file', compressed.blob, `${kind}.jpg`);
  }

  fd.append('kind', kind);
  if (note) fd.append('note', note);

  const res = await api.post(base(vehicleId), fd);
  return res.data.data;
}

/**
 * Remove one version — for a wrong upload, not for a renewal. If it was the current card, the
 * newest remaining version is promoted back to current. Returns the fresh payload.
 */
export async function deleteVehicleDocument(vehicleId, documentId) {
  const res = await api.delete(`${base(vehicleId)}/${documentId}`);
  return res.data.data;
}
