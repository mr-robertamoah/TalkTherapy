import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

// SCRUM-455 (Google sign-in, SCRUM-112): whether the signed-in account has a password (one made with Google may not) --
// the forms that ask for it point to "Set a password" when it doesn't. True unless the server says otherwise.
export default function usePasswordSet() {
    const page = usePage()

    return computed(() => page.props.auth?.user?.passwordSet !== false)
}
