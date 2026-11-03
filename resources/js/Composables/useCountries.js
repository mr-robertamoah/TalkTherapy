import { ref } from 'vue'

// SCRUM-377: the country list for the profile, admin-user and licensing-authority forms. It comes
// from VITE_COUNTRIES_API when that's a real URL. In environments where it's a placeholder (".env"
// ships "123"), the old per-form fetch went to a relative "/123/countries/flag/images" and 404'd, then
// the JSON parse threw. Now it isn't called at all, and the built-in list is used. One request per page
// load, shared by every form on it; the last good list is cached in localStorage.

const FALLBACK = ['Ghana', 'Nigeria', 'Uganda', 'Rwanda']
const STORAGE_KEY = 'countries'

let loading = null

function countriesApi() {
    const base = import.meta.env.VITE_COUNTRIES_API ?? ''

    return /^https?:\/\//i.test(base) ? base.replace(/\/+$/, '') : null
}

function cached() {
    try {
        const stored = localStorage.getItem(STORAGE_KEY)

        return stored ? stored.split(',').filter(Boolean) : null
    } catch {
        return null
    }
}

function fetchCountries() {
    const base = countriesApi()
    if (!base) return Promise.resolve(null)

    // No cookies, and no Referer: the app's paths (e.g. an admin user-edit URL) stay private.
    loading ??= fetch(`${base}/countries/flag/images`, { credentials: 'omit', referrerPolicy: 'no-referrer' })
        .then(res => (res.ok ? res.json() : null))
        .then(body => {
            const names = (body?.data ?? []).map(c => c?.name).filter(name => typeof name === 'string' && name)
            if (!names.length) return null

            try {
                localStorage.setItem(STORAGE_KEY, names.toString())
            } catch {
                // storage full or blocked: the list still works for this page
            }

            return names
        })
        .catch(() => null)

    return loading
}

export default function useCountries() {
    const countries = ref(cached() ?? [...FALLBACK])

    async function loadCountries() {
        const names = await fetchCountries()
        if (names) countries.value = names
    }

    return { countries, loadCountries }
}
