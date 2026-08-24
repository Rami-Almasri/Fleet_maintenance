/**
 * Copying text, in the browsers this app is actually opened in.
 *
 * `navigator.clipboard` only exists in a SECURE CONTEXT — https, or localhost. The team opens this app
 * over the office LAN (http://<ip>), where the API is simply undefined: `navigator.clipboard.writeText`
 * throws, `navigator.clipboard?.writeText` quietly does nothing, and either way the button looks broken
 * because nothing lands on the clipboard. That is the whole bug behind "the copy link button does not
 * work".
 *
 * So: try the modern API, fall back to the old textarea + execCommand('copy') trick, and — this is the
 * part that matters — RETURN WHETHER IT WORKED, so the caller can say "copied" only when it did and can
 * show the URL for manual copying when it didn't.
 */
export async function copyText(text) {
  const value = String(text ?? '');
  if (!value) return false;

  if (navigator.clipboard && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(value);
      return true;
    } catch { /* fall through to the legacy path below */ }
  }

  // Legacy path — works over plain http, which is where the modern API isn't there at all.
  try {
    const ta = document.createElement('textarea');
    ta.value = value;
    ta.setAttribute('readonly', '');
    // Off-screen but still focusable/selectable; `fixed` keeps the page from scrolling to it.
    ta.style.cssText = 'position:fixed;top:0;left:-9999px;opacity:0;';
    document.body.appendChild(ta);
    ta.select();
    ta.setSelectionRange(0, value.length);
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    return !!ok;
  } catch {
    return false;
  }
}

export default copyText;
