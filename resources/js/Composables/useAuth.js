import { router } from "@inertiajs/vue3";

// For a guest page: an action that needs an account sends them to sign in. (A signed-in page whose session ended is
// handled for every request in Support/sessionEnded.js -- SCRUM-423.) route(): a bare 'login' was relative to the page.

export default function useAuth() {
    
    const goToLogin = (err) => {
        if (err?.response?.status == 401)
            router.get(route('login'))
    }

    return { goToLogin }
}