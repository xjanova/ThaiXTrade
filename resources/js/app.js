/**
 * ThaiXTrade - Main Application Entry
 * Developed by Xman Studio
 */

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { createPinia } from 'pinia';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

import '../css/app.css';

// Create Pinia store
const pinia = createPinia();

// App name
const appName = import.meta.env.VITE_APP_NAME || 'ThaiXTrade';

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

        // Mount app
        app.mount(el);
    },
    progress: {
        color: '#0ea5e9',
        showSpinner: true,
    },
});
