/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */

import { default as _ } from 'lodash';
window._ = _;

import axios from 'axios';
window.axios = axios;

window.axios.defaults.baseURL = `${import.meta.env.VITE_APP_URL}/api`
window.axios.defaults.withCredentials = true
window.axios.defaults.withXSRFToken = true
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// SCRUM-359: once an account is suspended every API call is refused with this code -- send the
// page to the one place a suspended account can go instead of failing call by call. The path is
// route('suspended.show') (routes/web.php); Ziggy isn't loaded this early, so keep the two in step.
window.axios.interceptors.response.use(undefined, (error) => {
    if (error?.response?.status === 403 && error.response.data?.code === 'account_suspended' && window.location.pathname !== '/suspended') {
        window.location.assign('/suspended');
    }

    return Promise.reject(error);
});

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
