// Video Evidence helpers for the Maintenance Workflow — the garage's repair videos, uploaded to a
// ticket by a supervisor (Waleed/Abdullah) as the permanent record the supervisor's video review is based on.
//
// Upload path (local storage): POST the raw file multipart to the app server, which stores it on the
// local disk and persists the metadata row. Capped at 256 MB (see backend storeVideo + docker/uploads.ini).

import api from '../api/client';

const base = (ticketId) => `/maintenance-tickets/${ticketId}`;

export async function listTicketMedia(ticketId) {
  const res = await api.get(`${base(ticketId)}/media`);
  return res.data.data; // [{ id, kind, note, url, uploaded_by_name, created_at, ... }]
}

export async function deleteTicketMedia(ticketId, mediaId) {
  await api.delete(`${base(ticketId)}/media/${mediaId}`);
  return true;
}

/**
 * Upload one repair video (or still photo) to a ticket via a plain multipart POST to the app server.
 * Returns the saved media row. `onStage` is an optional progress callback.
 *
 * Pass `taskId` to pin the video to ONE fault (the "Mark fixed" evidence); omit it for a plain
 * ticket-scoped video (the video-review behaviour).
 */
export async function uploadRepairVideo(ticketId, file, { note = '', taskId = null, onStage } = {}) {
  onStage?.('uploading');
  const fd = new FormData();
  fd.append('file', file, file.name || 'repair-video.mp4');
  if (note) fd.append('note', note);
  if (taskId) fd.append('maintenance_task_id', taskId);

  onStage?.('saving');
  const res = await api.post(`${base(ticketId)}/video`, fd);
  return res.data.data;
}
