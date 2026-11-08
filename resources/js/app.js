import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy/dist/vue.m';
import VueSelect from 'vue-select';
import HelpNowRoot from './Components/HelpNow/HelpNowRoot.vue';

const appName = import.meta.env.VITE_APP_NAME || 'TalkTherapy';

createInertiaApp({
    title: (title) => title ? `${appName} - ${title}` : appName,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        // SCRUM-33: "I need help now" sits beside every page, so it's everywhere and survives visits.
        return createApp({ render: () => [h(App, props), h(HelpNowRoot)] })
            .component('v-select', VueSelect)
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
