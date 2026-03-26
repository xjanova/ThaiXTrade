/**
 * TPIX TRADE - Main Application Entry
 * Developed by Xman Studio
 */

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { createPinia } from 'pinia';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { initAudio } from '@/Composables/useSounds';

import '../css/app.css';

// Create Pinia store
const pinia = createPinia();

// App name
const appName = import.meta.env.VITE_APP_NAME || 'TPIX TRADE';

// Initialize audio on first user interaction
initAudio();

createInertiaApp({
    title: (title) => title ? `${title} - ${appName}` : appName,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue')
        ),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) });

        app.use(plugin);
        app.use(pinia);

        // Global properties
        app.config.globalProperties.$appName = appName;

        // Global error handler
        app.config.errorHandler = (err, instance, info) => {
            console.error('[TPIX] Vue Error:', err, info);
        };

        // Mount app
        app.mount(el);
    },
    progress: {
        color: '#06b6d4',
        showSpinner: true,
    },
});
