import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { i18n, setI18nLocale } from './i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
const chunkReloadKey = 'kusumavision-nms:chunk-reload-at';

window.addEventListener('vite:preloadError', (event) => {
    event.preventDefault();

    const now = Date.now();

    try {
        const lastReloadAt = Number(sessionStorage.getItem(chunkReloadKey) || 0);

        if (now - lastReloadAt <= 10000) {
            return;
        }

        sessionStorage.setItem(chunkReloadKey, String(now));
    } catch {
        // Reload anyway if storage is unavailable; the fresh HTML has the new asset map.
    }

    window.location.reload();
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        // Locale awal dari prop Inertia (di-set backend SetLocale), sebelum mount.
        setI18nLocale(props.initialPage.props.locale || 'id');

        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(i18n)
            .mount(el);
    },
    progress: {
        color: '#06b6d4',
    },
});
