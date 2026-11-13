<script setup>
// SCRUM-41: a slim notice across the app for an account whose email isn't verified -- "add an email" when there is
// none (sign-up doesn't ask for one), or "verify <email>" with a resend. It blocks nothing. Dismissed, it stays hidden
// for this browser session (per account). Not on the pages that already say it (profile, verify page) or where it
// would get in the way of help or a conversation (help chat, therapy and discussion chats).
import { computed, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'

const page = usePage()
const user = computed(() => page.props.auth?.user)

const HIDDEN_ON = ['profile.show', 'help.chat', 'therapies.chat', 'group.therapies.chat', 'discussions.chat']

const storageKey = computed(() => `email-notice-dismissed:${user.value?.id}`)

function readDismissed() {
    try {
        return sessionStorage.getItem(storageKey.value) === '1'
    } catch {
        return false
    }
}

const dismissed = ref(readDismissed())
const sending = ref(false)

const show = computed(() => {
    if (!user.value || user.value.emailVerifiedAt || dismissed.value) return false

    return !HIDDEN_ON.some((name) => route().current(name))
})

const hasEmail = computed(() => !!user.value?.email)
const status = computed(() => page.props.verificationStatus)

function dismiss() {
    dismissed.value = true
    try {
        sessionStorage.setItem(storageKey.value, '1')
    } catch {
        // Private mode: hidden for this page only.
    }
}

function resend() {
    if (sending.value) return
    sending.value = true
    router.post(route('verification.send'), {}, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { sending.value = false },
    })
}
</script>

<template>
    <aside v-if="show" aria-label="Email verification" class="border-b border-amber-200 bg-amber-50">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2 text-sm text-amber-900 sm:px-6 lg:px-8">
            <p v-if="!hasEmail" class="w-full min-w-0 sm:w-auto sm:flex-1">
                Add an email to your account so you don't miss session reminders, can reset your password if you forget it,
                and can start a therapy.
            </p>
            <p v-else class="w-full min-w-0 sm:w-auto sm:flex-1">
                Please verify <span class="font-medium break-all">{{ user.email }}</span> so you don't miss session
                reminders and can start a therapy.
                <!-- Only the outcome is announced (the banner itself is on every page). -->
                <span role="status" class="font-medium" :class="status === 'verification-link-sent' ? 'text-green-800' : ''">
                    {{ status === 'verification-link-sent' ? 'We\'ve sent you a new link.' : status === 'verification-throttled' ? 'Please wait a minute before asking for another link.' : '' }}
                </span>
            </p>

            <div class="flex shrink-0 items-center gap-3">
                <Link
                    v-if="!hasEmail"
                    :href="route('profile.show')"
                    class="rounded-md bg-amber-800 px-3 py-1.5 font-medium text-white hover:bg-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-600 focus:ring-offset-2"
                >add an email</Link>
                <button
                    v-else
                    type="button"
                    :disabled="sending"
                    class="rounded-md bg-amber-800 px-3 py-1.5 font-medium text-white hover:bg-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-600 focus:ring-offset-2 disabled:opacity-60"
                    @click="resend"
                >{{ sending ? 'sending…' : 'send the link' }}</button>
                <button
                    type="button"
                    class="rounded-md px-2 py-1.5 text-amber-900 underline hover:text-amber-950 focus:outline-none focus:ring-2 focus:ring-amber-600 focus:ring-offset-2"
                    @click="dismiss"
                >not now</button>
            </div>
        </div>
    </aside>
</template>
