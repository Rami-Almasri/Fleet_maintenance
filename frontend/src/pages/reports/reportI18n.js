import { useCallback } from 'react';
import { useI18n } from '../../i18n/I18nContext';

/**
 * Renders the translatable nodes the report services emit (see VehicleSystemDashboardService::tr).
 *
 * These pages state counts — "3 recorded events", "144 days apart" — and a sentence with a number
 * baked into it can never be matched by a phrase catalog. So the backend sends a catalog key and its
 * params instead of English, and this resolves the three shapes it can send:
 *
 *   { code, params, plural }   a catalog key. `plural` routes through tp(), because Arabic has six
 *                              plural categories to English's two and a bare interpolation gets 2,
 *                              3–10 and 11+ wrong.
 *   { text }                   recorded data — a garage's name, a fault as the fitter wrote it.
 *                              Returned untouched: translating the record would be inventing evidence.
 *   { parts, sep }             several nodes in order.
 *
 * A param may itself be a node, so a sentence can embed a translated system name without being built
 * from concatenated fragments — which would fix English word order onto Arabic.
 *
 * `fallback` is the English the service still sends alongside every node, so a key that has not been
 * added to the catalog yet renders the old English rather than a raw dot-path.
 */
export function useTx() {
  const { t, tp } = useI18n();

  return useCallback(
    function tx(node, fallback = '') {
      if (node == null) return fallback;
      if (typeof node === 'string') return node;

      if (Array.isArray(node.parts)) {
        const rendered = node.parts.map((p) => tx(p)).filter((s) => s !== '' && s != null);
        return rendered.join(node.sep ?? ' · ');
      }

      if (node.text != null) return node.text;
      if (!node.code) return fallback;

      const params = {};
      for (const [key, value] of Object.entries(node.params || {})) {
        params[key] = value && typeof value === 'object' ? tx(value) : value;
      }

      // tp() falls back through `other`, so a catalog needs only the forms its language uses.
      return node.plural ? tp(node.code, Number(params.n) || 0, params) : t(node.code, params);
    },
    [t, tp],
  );
}
