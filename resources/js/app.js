import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy/dist/vue.m';
import VueSelect from 'vue-select';
import HelpNowRoot from './Components/HelpNow/HelpNowRoot.vue';
import { forgetThisDevice } from './Composables/useWebPush';
import { announceSignOut, watchSession } from './Support/sessionEnded';

// SCRUM-399: every sign-out link (whichever layout) first stops urgent help alerts on this browser -- the session
// it was turned on in may be long gone (a remember-me sign-in), so the server can't tell which device this is.
let signingOut = false;
router.on('before', (event) => {
    const visit = event.detail.visit;
    if (signingOut || visit.method !== 'post' || visit.url.pathname !== new URL(route('logout')).pathname) return;
    signingOut = true;
    // SCRUM-423: this browser's other tabs go to sign-in too.
    forgetThisDevice().finally(() => router.post(route('logout'), {}, { onSuccess: announceSignOut, onFinish: () => { signingOut = false; } }));
    return false;
});

const appName = import.meta.env.VITE_APP_NAME || 'TalkTherapy';

createInertiaApp({
    title: (title) => title ? `${appName} - ${title}` : appName,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        // SCRUM-423: a page whose session ends leaves for sign-in and closes its live connection.
        watchSession(router, props.initialPage);
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
