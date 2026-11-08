<script setup>
// SCRUM-33: "I need help now", mounted once at the app root (resources/js/app.js) so it's on every
// page -- signed in or not, suspended, in a chat, in the admin area -- and survives page visits.
// - The dialog always leads with the emergency number for the person's country (verified numbers
//   only; none means "contact your local emergency services", never a guess), with a country picker.
// - Signed in: ask for a counsellor now (optionally anonymously, with a one-line note), wait, and
//   go to the chat when someone answers. Guests are asked to sign in.
// - A counsellor on call is rung here when someone asks (HelpRingModal).
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import MiniModal from '@/Components/MiniModal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import HelpRingModal from './HelpRingModal.vue'
import EmergencyNumbersPanel from './EmergencyNumbersPanel.vue'
import useHelpNow from '@/Composables/useHelpNow'

const page = usePage()
const user = computed(() => page.props.auth?.user ?? null)
const { open, request, starting, error, show, resume, start, cancel, startAgain, forget } = useHelpNow()

const anonymous = ref(false)
const note = ref('')
// Bumped on every open, so the country picker starts closed.
const panelKey = ref(0)
const now = ref(Date.now())
let ticker = null

const status = computed(() => request.value?.status ?? null)
// The help chat has its own "Emergency numbers" button, and on a phone this one would sit over the
// message box (QA) -- so it isn't shown there (the dialog itself still is).
const onHelpChat = computed(() => page.component === 'Help/Chat')
const waitedFor = computed(() => {
    if (!request.value?.createdAt) return ''
    const seconds = Math.max(0, Math.floor((now.value - new Date(request.value.createdAt).getTime()) / 1000))
    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
})

watch(open, (isOpen) => {
    if (!isOpen) return
    anonymous.value = !!user.value?.settings?.anonymous
    panelKey.value++
})

onMounted(() => {
    if (user.value) resume()
    ticker = setInterval(() => { now.value = Date.now() }, 1000)
})

onBeforeUnmount(() => clearInterval(ticker))

// Signing in or out while the app stays open -- signing out forgets the last person's request.
watch(() => user.value?.id, (id, previous) => {
    if (id && id !== previous) resume()
    if (!id && previous) forget()
})

// Asking again starts afresh: no old note, and their saved anonymity choice.
function askAgain() {
    note.value = ''
    anonymous.value = !!user.value?.settings?.anonymous
    startAgain()
}

function submit() {
    start({ anonymous: anonymous.value, note: note.value })
}
</script>

<template>
    <div>
        <!-- On a phone a round button raised above the bottom left of the screen, clear of chat
             message boxes (QA); from sm up, the full pill in the bottom right corner, clear of the
             admin sidebar. -->
        <button
            v-if="!onHelpChat"
            type="button"
            class="fixed bottom-24 left-3 z-40 inline-flex h-12 w-12 items-center justify-center gap-2 rounded-full bg-red-700 text-sm font-semibold text-white shadow-lg hover:bg-red-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-red-300 sm:bottom-4 sm:left-auto sm:right-4 sm:h-auto sm:min-h-[44px] sm:w-auto sm:px-4"
            :aria-label="status === 'WAITING' ? 'I need help now -- looking for a counsellor' : 'I need help now'"
            @click="show"
        >
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm0-4a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-5.66-1.17 2.12-2.12a3.97 3.97 0 0 1 0-1.42L4.34 7.17a6 6 0 0 0 0 5.66Zm11.32 0a6 6 0 0 0 0-5.66l-2.12 2.12c.1.47.1.95 0 1.42l2.12 2.12ZM7.17 4.34l2.12 2.12c.47-.1.95-.1 1.42 0l2.12-2.12a6 6 0 0 0-5.66 0Zm0 11.32a6 6 0 0 0 5.66 0l-2.12-2.12c-.47.1-.95.1-1.42 0l-2.12 2.12Z" clip-rule="evenodd" />
            </svg>
            <span class="sr-only sm:not-sr-only">I need help now</span>
            <span v-if="status === 'WAITING'" class="h-2 w-2 rounded-full bg-white" aria-hidden="true"></span>
        </button>

        <MiniModal :show="open" labelledby="help-now-title" @close="open = false">
            <div class="space-y-5 text-gray-900">
                <h2 id="help-now-title" class="text-lg font-semibold">Get help now</h2>

                <!-- The emergency number, always first. -->
                <EmergencyNumbersPanel :key="panelKey" input-id="help-now-country" />

                <p class="text-xs text-gray-600">TalkTherapy isn't an emergency service, and a counsellor may not be free straight away.</p>

                <!-- Talk to a counsellor now. -->
                <section aria-labelledby="help-now-talk" class="space-y-3">
                    <h3 id="help-now-talk" class="text-base font-semibold">Talk to a counsellor now</h3>

                    <p v-if="!user" class="text-sm text-gray-700">
                        <Link :href="route('login')" class="font-medium text-blue-700 underline" @click="open = false">Sign in</Link>
                        to chat with a counsellor who is available right now.
                    </p>

                    <template v-else-if="status === 'WAITING'">
                        <div class="rounded-lg border border-gray-200 bg-white p-4" role="status" aria-live="polite">
                            <p class="font-medium">Looking for a counsellor… <span class="tabular-nums text-gray-600">{{ waitedFor }}</span></p>
                            <p class="mt-1 text-sm text-gray-600">Counsellors available for urgent help are asked, including any who become available while you wait. You'll be taken to the chat as soon as one answers. If you're in danger, call the number above now.</p>
                        </div>
                        <SecondaryButton type="button" @click="cancel">Stop waiting</SecondaryButton>
                    </template>

                    <div v-else-if="status === 'CONNECTED'" class="space-y-2">
                        <p class="text-sm text-gray-700">{{ request.counsellorName ?? 'A counsellor' }} is with you.</p>
                        <Link :href="route('help.chat', { helpRequest: request.id })" class="inline-flex min-h-[44px] items-center rounded-md bg-gray-800 px-4 text-sm font-semibold text-white hover:bg-gray-700" @click="open = false">Open your chat</Link>
                    </div>

                    <template v-else-if="status === 'EXPIRED'">
                        <p class="text-sm text-gray-700" role="status">No counsellor could answer in time. If you're in danger, call the number above now. You can also ask again.</p>
                        <PrimaryButton type="button" @click="askAgain">Ask again</PrimaryButton>
                    </template>

                    <template v-else-if="status === 'CLOSED'">
                        <p class="text-sm text-gray-700" role="status">Our team closed your request. If you're in danger, call the number above now. You can ask again whenever you need to.</p>
                        <PrimaryButton type="button" @click="askAgain">Ask again</PrimaryButton>
                    </template>

                    <template v-else-if="status === 'CANCELLED' || status === 'ENDED'">
                        <p class="text-sm text-gray-700" role="status">{{ status === 'ENDED' ? 'Your chat has ended.' : 'You stopped waiting.' }} You can ask again whenever you need to.</p>
                        <PrimaryButton type="button" @click="askAgain">Ask again</PrimaryButton>
                    </template>

                    <form v-else class="space-y-3" @submit.prevent="submit">
                        <div>
                            <label for="help-now-note" class="block text-sm font-medium text-gray-800">What's happening? (optional)</label>
                            <input id="help-now-note" v-model="note" type="text" maxlength="200" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="One line, if you'd like" />
                        </div>
                        <label class="flex items-center gap-2 text-sm text-gray-800">
                            <input v-model="anonymous" type="checkbox" class="rounded border-gray-300" />
                            Don't show my name to the counsellor
                        </label>
                        <PrimaryButton :disabled="starting">{{ starting ? 'Asking…' : 'Ask for a counsellor now' }}</PrimaryButton>
                    </form>

                    <p v-if="error" class="text-sm text-red-700" role="alert">{{ error }}</p>
                </section>

                <div class="flex justify-end">
                    <SecondaryButton type="button" @click="open = false">Close</SecondaryButton>
                </div>
            </div>
        </MiniModal>

        <HelpRingModal v-if="user?.counsellor?.id" :counsellor-id="user.counsellor.id" />
    </div>
</template>
