// Video Evidence helpers for the Maintenance Workflow — the garage's repair videos, uploaded to a
// ticket by a supervisor (Waleed/Abdullah) as the permanent record the supervisor's video review is based on.
//
// Two upload paths, tried in order so it works with or without S3:
//   1) Presign → PUT the file DIRECTLY to S3 (no size limit; bytes never touch the app server), then
//      POST the resulting key back to persist the metadata row.
//   2) Fallback: POST the raw file multipart to the app server (small clips / no S3 configured).
// The S3 PUT uses raw fetch (not the api client) so the Sanctum bearer token is never sent to storage.

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

async function presignVideo(ticketId, { contentType, extension }) {
  const res = await api.post(`${base(ticketId)}/video/presign`, {
    content_type: contentType,
    extension,
  });
  return res.data.data; // { disk, key, upload_url, headers, expires_in }
}

async function putToS3(uploadUrl, file, headers = {}) {
  const res = await fetch(uploadUrl, { method: 'PUT', body: file, headers });
  if (!res.ok) throw new Error(`S3 upload failed (${res.status})`);
  return true;
}

async function saveVideoRecord(ticketId, body) {
  const res = await api.post(`${base(ticketId)}/video`, body);
  return res.data.data;
}

async function uploadMultipartFallback(ticketId, file, note, taskId) {
  const fd = new FormData();
  fd.append('file', file, file.name || 'repair-video.mp4');
  if (note) fd.append('note', note);
  if (taskId) fd.append('maintenance_task_id', taskId);
  const res = await api.post(`${base(ticketId)}/video`, fd);
  return res.data.data;
}

/**
 * Upload one repair video to a ticket. Tries the browser-direct S3 path first; on any presign/PUT
 * failure (e.g. S3 not configured on this machine) it falls back to a plain multipart upload so the
 * feature still works. Returns the saved media row. `onStage` is an optional progress callback.
 *
 * Pass `taskId` to pin the video to ONE fault (the "Mark fixed" evidence); omit it for a plain
 * ticket-scoped video (the video-review behaviour).
 */
export async function uploadRepairVideo(ticketId, file, { note = '', taskId = null, onStage } = {}) {
  const contentType = file.type || 'video/mp4';
  const extension = (file.name?.split('.').pop() || (contentType.split('/')[1] || 'mp4')).toLowerCase();

  try {
    onStage?.('presigning');
    const { disk, key, upload_url, headers } = await presignVideo(ticketId, { contentType, extension });
    onStage?.('uploading');
    await putToS3(upload_url, file, headers);
    onStage?.('saving');
    return await saveVideoRecord(ticketId, {
      disk,
      s3_key: key,
      content_type: contentType,
      original_name: file.name || null,
      file_size: file.size ?? null,
      note: note || null,
      maintenance_task_id: taskId || null,
    });
  } catch (e) {
    // Presign/S3 path unavailable — fall back to a server-side multipart ingest (small clips).
    onStage?.('uploading');
    return uploadMultipartFallback(ticketId, file, note, taskId);
  }
}
