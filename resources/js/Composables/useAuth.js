import { router } from "@inertiajs/vue3";

export default function useAuth() {
    
    const goToLogin = (err) => {
        if (err.response.status == 401)
            router.get('login')
    }

    return { goToLogin }
}