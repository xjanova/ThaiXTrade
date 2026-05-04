/**
 * TPIX Trade — Sentry Vue init
 *
 * Activate by:
 *   1. npm install @sentry/vue
 *   2. add to .env: VITE_SENTRY_DSN=https://...@sentry.io/...
 *   3. import this from app.js: import { initSentry } from './sentry';
 *      then: initSentry(app, router) before app.mount(...)
 *
 * No-op when VITE_SENTRY_DSN is empty (safe to ship without DSN).
 */

export async function initSentry(app, router) {
    const dsn = import.meta.env.VITE_SENTRY_DSN;
    if (!dsn) return; // disabled — no DSN configured

    try {
        const Sentry = await import('@sentry/vue');

        Sentry.init({
            app,
            dsn,
            environment: import.meta.env.MODE,
            release: import.meta.env.VITE_GIT_SHA || undefined,

            // Performance monitoring
            tracesSampleRate: 0.10,
            replaysSessionSampleRate: 0.0,   // Don't record sessions (PII concern)
            replaysOnErrorSampleRate: 0.10,  // Record session only when error occurs

            integrations: [
                Sentry.browserTracingIntegration({ router }),
                // Sentry.replayIntegration({ maskAllText: true, blockAllMedia: true }),
            ],

            // Strip wallet addresses from URLs / messages before send
            beforeSend(event) {
                if (event.message) {
                    event.message = event.message
                        .replace(/0x[a-fA-F0-9]{40,64}/g, '0x[REDACTED]')
                        .replace(/\b([a-z]+ ){11,23}[a-z]+\b/gi, '[MNEMONIC_REDACTED]');
                }
                if (event.request?.url) {
                    event.request.url = event.request.url.replace(/0x[a-fA-F0-9]{40,64}/g, '0x[REDACTED]');
                }
                event.tags = { ...(event.tags || {}), site: 'tpix-trade', chain_id: '4289' };
                return event;
            },

            // Suppress noise
            ignoreErrors: [
                'Network Error',
                'Failed to fetch',
                /^User rejected/i,                       // wallet user-cancel
                /^Action rejected by user/i,
                /^MetaMask Tx Signature: User denied/i,
                'ResizeObserver loop limit exceeded',
                'Non-Error promise rejection captured',
            ],
            denyUrls: [
                /extensions\//i,
                /^chrome:\/\//i,
                /^moz-extension:\/\//i,
            ],
        });

        // Tag user wallet (anonymized)
        if (window?.tpixUser?.id) {
            Sentry.setUser({ id: String(window.tpixUser.id) });
        }
    } catch (e) {
        // SDK not installed yet — fall through silently
        console.warn('[sentry] SDK not installed, skipping:', e.message);
    }
}
