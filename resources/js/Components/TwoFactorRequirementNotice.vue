<script setup>
// SCRUM-34d: for a platform administrator who hasn't set up two-factor sign-in once it's required from a date. Before
// that day: a reminder, which "not now" hides for this browser session. From it: they're kept on the profile until it's
// set up (RequireTwoFactorForAdmins), and this says why -- it can't be dismissed then.
import { computed, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

const page = usePage()
const requirement = computed(() => page.props.twoFactorRequirement)
// page.url: recomputed on every visit, even if the layout stays mounted.
const onProfile = computed(() => page.url && route().current('profile.show'))

const storageKey = computed(() => `two-factor-reminder-dismissed:${page.props.auth?.user?.id}`)

function readDismissed() {
    try {
        return sessionStorage.getItem(storageKey.value) === '1'
    } catch {
        return false
    }
}

const dismissed = ref(readDismissed())

const show = computed(() => !!requirement.value && (requirement.value.required || !dismissed.value))

// A plain date from the server ('2027-01-15'), shown as that day wherever the browser is.
const fromDate = computed(() => {
    const [year, month, day] = (requirement.value?.from ?? '').split('-').map(Number)

    return year ? new Date(year, month - 1, day).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' }) : ''
})

function dismiss() {
    dismissed.value = true
    try {
        sessionStorage.setItem(storageKey.value, '1')
    } catch {
        // Private mode: hidden for this page only.
    }
}

const buttonClass = 'rounded-md bg-indigo-700 px-3 py-1.5 font-medium text-white hover:bg-indigo-800 focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2'
</script>

<template>
    <aside v-if="show" aria-label="Two-factor sign-in for administrators" class="border-b border-indigo-200 bg-indigo-50">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2 text-sm text-indigo-950 sm:px-6 lg:px-8">
            <p v-if="requirement.required" class="w-full min-w-0 sm:w-auto sm:flex-1">
                <span class="font-medium">Two-factor sign-in is now required for administrators.</span>
                Set it up below to get back to the rest of the site — it takes a couple of minutes with an authenticator app.
            </p>
            <p v-else class="w-full min-w-0 sm:w-auto sm:flex-1">
                From <span class="font-medium">{{ fromDate }}</span>, administrators need two-factor sign-in. Set it up
                now on your profile, so nothing stops you that day.
            </p>

            <div class="flex shrink-0 items-center gap-3">
                <a v-if="onProfile" href="#profile-two-factor-id" :class="buttonClass">set it up</a>
                <Link v-else :href="route('profile.show') + '#profile-two-factor-id'" :class="buttonClass">set it up</Link>
                <button
                    v-if="!requirement.required"
                    type="button"
                    class="rounded-md px-2 py-1.5 text-indigo-950 underline hover:text-black focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2"
                    @click="dismiss"
                >not now</button>
            </div>
        </div>
    </aside>
</template>
