// Cleaning capture helpers — the before/after photo flow behind the Booking Readiness "Cleaning" point.
//
// Photos are compressed in-browser (lib/imageCompression) then POSTed as multipart to the app server,
// which stores them to S3 (when configured) or the local `public` disk. Kept deliberately simple —
// unlike the inspection/video flows there is no presigned-S3 path here; the compressed JPEGs are small.

import api from '../api/client';
import { compressImage } from './imageCompression';

const base = (vehicleId) => `/cleaning/vehicle/${vehicleId}`;

/** The car's cleaning state + its before/after photo lists. */
export async function getCleaning(vehicleId) {
  const res = await api.get(base(vehicleId));
  return res.data.data; // { vehicle:{...,cleaning_status}, before:[...], after:[...] }
}

/**
 * Compress and upload one cleaning photo for a car, tagged 'before' or 'after'.
 * Returns the fresh cleaning payload (so the caller can just replace its state).
 */
export async function uploadCleaningPhoto(vehicleId, file, phase, note = '') {
  const compressed = await compressImage(file, { maxDimension: 1600, quality: 0.72 });
  const fd = new FormData();
  fd.append('phase', phase);
  fd.append('photo', compressed.blob, `cleaning-${phase}.jpg`);
  if (note) fd.append('note', note);
  const res = await api.post(`${base(vehicleId)}/photo`, fd);
  return res.data.data;
}

/** Delete one cleaning photo. Returns the fresh cleaning payload. */
export async function deleteCleaningPhoto(vehicleId, photoId) {
  const res = await api.delete(`${base(vehicleId)}/photo/${photoId}`);
  return res.data.data;
}

/** Flip the car's cleaning flag (clean | dirty | pending). Returns the fresh cleaning payload. */
export async function setCleaningStatus(vehicleId, status) {
  const res = await api.post(`${base(vehicleId)}/status`, { status });
  return res.data.data;
}
