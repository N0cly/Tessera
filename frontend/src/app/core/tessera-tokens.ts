/**
 * Read tessera design tokens from the live document. Used by code that
 * renders pixels in JS (canvas QR, Chart.js) so we never hardcode a hex —
 * see tessera-design.md.
 *
 * Reads are cheap (the browser caches computed styles) and we only call
 * them at render time, not in tight loops.
 */
export function token(name: string, fallback = ''): string {
  if (typeof document === 'undefined') return fallback;
  const value = getComputedStyle(document.documentElement).getPropertyValue(`--${name}`).trim();
  return value || fallback;
}
