import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { ZiggyVue } from 'ziggy-js';
import { applyTheme } from './composables/useTheme';

window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    const theme = localStorage.getItem('theme') || 'auto';
    if (theme === 'auto') {
        applyTheme('auto');
    }
});

createInertiaApp({
    title: (title) => title ? `${title} - Domain Checker` : 'Domain Checker',
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        const theme = localStorage.getItem('theme') || 'auto';
        applyTheme(theme);

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#6366f1',
    },
});
