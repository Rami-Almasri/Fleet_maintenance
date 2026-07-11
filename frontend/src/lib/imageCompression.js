// Client-side image compression — runs entirely in the browser before any upload,
// so a 6–12 MP phone photo becomes a ~200–600 KB JPEG on-device. This keeps S3
// storage and upload time low (the "non-negotiable" performance requirement) and
// means the raw multi-megabyte original never touches the network or the server.
//
// Pure canvas + toBlob — no third-party library. Returns the compressed Blob plus
// an object URL for preview and before/after size stats for the UI.

/**
 * @param {File|Blob} file  The original image (e.g. from <input capture="environment">).
 * @param {object} [opts]
 * @param {number} [opts.maxDimension=1600]  Longest edge in px after downscaling.
 * @param {number} [opts.quality=0.72]       JPEG quality 0–1.
 * @param {string} [opts.mimeType='image/jpeg']
 * @returns {Promise<{blob: Blob, url: string, width: number, height: number,
 *                     originalSize: number, compressedSize: number, ratio: number}>}
 */
export async function compressImage(file, opts = {}) {
  const { maxDimension = 1600, quality = 0.72, mimeType = 'image/jpeg' } = opts;

  const { source, width: srcW, height: srcH } = await loadBitmap(file);
  const { width, height } = fitWithin(srcW, srcH, maxDimension);

  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext('2d');
  ctx.imageSmoothingQuality = 'high';
  ctx.drawImage(source, 0, 0, width, height);
  if (source.close) source.close(); // release ImageBitmap memory where supported

  const blob = await canvasToBlob(canvas, mimeType, quality);
  const originalSize = file.size || 0;
  const compressedSize = blob.size;

  return {
    blob,
    url: URL.createObjectURL(blob),
    width,
    height,
    originalSize,
    compressedSize,
    ratio: originalSize ? compressedSize / originalSize : 1,
  };
}

/**
 * Decode a file into a drawable source plus its intrinsic dimensions, preferring
 * the fast createImageBitmap path and falling back to an HTMLImageElement.
 * @returns {Promise<{source: CanvasImageSource, width: number, height: number}>}
 */
async function loadBitmap(file) {
  if (typeof createImageBitmap === 'function') {
    try {
      const bmp = await createImageBitmap(file);
      return { source: bmp, width: bmp.width, height: bmp.height };
    } catch {
      /* fall through to HTMLImageElement */
    }
  }
  const url = URL.createObjectURL(file);
  try {
    const img = await new Promise((resolve, reject) => {
      const el = new Image();
      el.onload = () => resolve(el);
      el.onerror = reject;
      el.src = url;
    });
    return { source: img, width: img.naturalWidth, height: img.naturalHeight };
  } finally {
    URL.revokeObjectURL(url);
  }
}

function fitWithin(w, h, max) {
  if (w <= max && h <= max) return { width: w, height: h };
  const scale = max / Math.max(w, h);
  return { width: Math.round(w * scale), height: Math.round(h * scale) };
}

function canvasToBlob(canvas, type, quality) {
  return new Promise((resolve) => {
    if (canvas.toBlob) {
      canvas.toBlob((b) => resolve(b), type, quality);
    } else {
      // Safari/old fallback via data URL.
      const dataUrl = canvas.toDataURL(type, quality);
      const bin = atob(dataUrl.split(',')[1]);
      const arr = new Uint8Array(bin.length);
      for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
      resolve(new Blob([arr], { type }));
    }
  });
}

/** Human-readable byte size for the UI ("2.4 MB", "318 KB"). */
export function formatBytes(bytes) {
  if (!bytes) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
  const val = bytes / 1024 ** i;
  return `${val >= 10 || i === 0 ? Math.round(val) : val.toFixed(1)} ${units[i]}`;
}
