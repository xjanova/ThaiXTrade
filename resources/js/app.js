/**
 * TPIX TRADE - Main Application Entry
 * Developed by Xman Studio
 */

import { createApp, h, ref } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { createPinia } from 'pinia';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { initAudio } from '@/Composables/useSounds';
import SplashScreen from '@/Components/SplashScreen.vue';

import '../css/app.css';

// Create Pinia store
const pinia = createPinia();

// App name
const appName = import.meta.env.VITE_APP_NAME || 'TPIX TRADE';

// Splash screen state (shared)
const splashDone = ref(false);

// Initialize audio on first interaction
initAudio();

createInertiaApp({
    title: (title) => title ? `${title} - ${appName}` : appName,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue')
        ),
    setup({ el, App, props, plugin }) {
        const app = createApp({
            render() {
                return h('div', { id: 'tpix-root' }, [
                    // Splash Screen — แสดงครั้งเดียวเมื่อเปิดแอพ
                    !splashDone.value
                        ? h(SplashScreen, {
                            onDone: () => { splashDone.value = true; },
                        })
                        : null,
                    // Main App
                    h('div', {
                        style: {
                            opacity: splashDone.value ? '1' : '0',
                            transition: 'opacity 0.5s ease',
                            minHeight: '100vh',
                            display: 'flex',
                            flexDirection: 'column',
                        },
                    }, [h(App, props)]),
                ]);
            },
        });

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
