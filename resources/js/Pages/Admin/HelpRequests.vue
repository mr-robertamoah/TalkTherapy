<script setup>
// SCRUM-33 (TT-4.4b): the urgent help queue -- people waiting for a counsellor, chats going on, and the
// last day's closed ones, kept live. Administrators coordinate: ring the counsellors on call again, ring
// one counsellor directly (who still accepts), or close a request. They never chat, and never read a
// chat from here. Who asked is shown only on request, and every look is recorded.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import axios from 'axios'
import { Head } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminSearchBox from '@/Components/Admin/AdminSearchBox.vue'
import Alert from '@/Components/Alert.vue'
import MiniModal from '@/Components/MiniModal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import TextInput from '@/Components/TextInput.vue'
import { acquireChannel } from '@/Composables/sharedChannel'
import { searchPage } from '@/Composables/useAdminSearch'
import useAlert from '@/Composables/useAlert'

// Shared Inertia props aren't page attributes, and the page has several root nodes (QA).
defineOptions({ layout: AdminLayout, inheritAttrs: false })

const props = defineProps({
    requests: { type: Array, default: () => [] },
    onCall: { type: Number, default: 0 },
    search: { type: String, default: '' },
    searchTooMany: { type: Boolean, default: false },
    expireAfterMinutes: { type: Number, default: 10 },
})

const { alertData, clearAlertData, setAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()

const rows = ref([...props.requests])
const onCallCount = ref(props.onCall)
const revealed = ref({}) // id -> { name, username, email }
const now = ref(Date.now())
const busy = ref(null)
let channel = null
let ticker = null

const waiting = computed(() => rows.value.filter((row) => row.status === 'WAITING'))
const connected = computed(() => rows.value.filter((row) => row.status === 'CONNECTED'))
const closed = computed(() => rows.value.filter((row) => !['WAITING', 'CONNECTED'].includes(row.status)))

const ending = { person: 'the person', counsellor: 'the counsellor', time: 'time ran out', suspension: 'the counsellor\'s suspension', revoked: 'the counsellor\'s verification ending' }
const statusLabel = { ENDED: 'Chat ended', EXPIRED: 'Nobody answered', CANCELLED: 'Stopped waiting', CLOSED: 'Closed by an administrator' }

function waitedFor(row) {
    const seconds = Math.max(0, Math.floor((now.value - new Date(row.createdAt).getTime()) / 1000))
    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

function formatTime(value) {
    return value ? new Date(value).toLocaleString(undefined, { weekday: 'short', hour: '2-digit', minute: '2-digit' }) : ''
}

const refusal = (err) => err.response?.data?.message ?? 'Something went wrong. Please try again.'

// A search (or any visit back to this page) brings new props.
watch(() => props.requests, (requests) => { rows.value = [...requests] })
watch(() => props.onCall, (count) => { onCallCount.value = count })

// Refetched on each queue event -- bursts are gathered (300 ms), and only the latest answer is shown.
// (AdminLayout refreshes the badge on the same events.)
let reloadTimer = null
let latest = 0

function reload() {
    clearTimeout(reloadTimer)
    reloadTimer = setTimeout(async () => {
        const ticket = ++latest
        await axios.get(route('admin.help_requests.queue', props.search ? { search: props.search } : {}))
            .then((res) => {
                if (ticket !== latest) return
                rows.value = res.data.requests
                onCallCount.value = res.data.onCall
            })
            .catch(() => {})
    }, 300)
}

async function ringAgain(row) {
    busy.value = `ring-${row.id}`
    await axios.post(route('admin.help_requests.ring', { helpRequest: row.id }))
        .then((res) => res.data.rung
            ? setSuccessAlertData({ message: `Rang ${res.data.rung} counsellor${res.data.rung === 1 ? '' : 's'} on call.` })
            : setAlertData({ type: 'info', message: 'No counsellor is on call right now. Try ringing one directly.' }))
        .catch((err) => setFailedAlertData({ message: refusal(err) }))
        .finally(() => { busy.value = null })
}

async function reveal(row) {
    busy.value = `reveal-${row.id}`
    await axios.post(route('admin.help_requests.reveal', { helpRequest: row.id }))
        .then((res) => { revealed.value = { ...revealed.value, [row.id]: res.data } })
        .catch((err) => setFailedAlertData({ message: refusal(err) }))
        .finally(() => { busy.value = null })
}

// Ringing one counsellor.
const picking = ref(null)
const counsellorSearch = ref('')
const counsellors = ref([])
let searchTimer = null

async function loadCounsellors() {
    if (!picking.value) return
    await axios.get(route('admin.help_requests.counsellors', { helpRequest: picking.value.id, search: counsellorSearch.value || undefined }))
        .then((res) => { counsellors.value = res.data.counsellors })
        .catch((err) => { setFailedAlertData({ message: refusal(err) }); picking.value = null })
}

function startPicking(row) {
    picking.value = row
    counsellorSearch.value = ''
    counsellors.value = []
    loadCounsellors()
}

function searchCounsellors() {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(loadCounsellors, 300)
}

async function ringCounsellor(counsellor) {
    busy.value = `pick-${counsellor.id}`
    await axios.post(route('admin.help_requests.ring_counsellor', { helpRequest: picking.value.id }), { counsellorId: counsellor.id })
        .then(() => {
            setSuccessAlertData({ message: `${counsellor.name} has been rung (if they have TalkTherapy open). They still need to accept.` })
            picking.value = null
        })
        .catch((err) => setFailedAlertData({ message: refusal(err) }))
        .finally(() => { busy.value = null })
}

// Closing a request.
const closing = ref(null)
const closeNote = ref('')

async function close() {
    busy.value = 'close'
    await axios.post(route('admin.help_requests.close', { helpRequest: closing.value.id }), { note: closeNote.value })
        .then(() => {
            setSuccessAlertData({ message: 'Request closed. The person has been told, with the emergency number.' })
            closing.value = null
            closeNote.value = ''
        })
        .catch((err) => setFailedAlertData({ message: refusal(err) }))
        .finally(() => { busy.value = null })
}

onMounted(() => {
    channel = acquireChannel('admin.help-requests')
    channel?.listen('.help.queue', () => reload())
    ticker = setInterval(() => { now.value = Date.now() }, 1000)
})

onBeforeUnmount(() => {
    channel?.release()
    clearInterval(ticker)
    clearTimeout(searchTimer)
    clearTimeout(reloadTimer)
})
</script>

<template>
    <Head title="Urgent help" />

    <div class="mx-auto my-8 w-full max-w-5xl space-y-6 px-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Urgent help</h1>
            <div class="mt-2 h-1 w-16 bg-blue-600"></div>
            <p class="mt-2 text-sm text-gray-600">
                People who tapped "I need help now". Counsellors on call are rung straight away; if nobody answers you're alerted, and
                after {{ expireAfterMinutes }} minutes the request closes and the person is shown the emergency number again. You can ring
                the counsellors on call again, ring one counsellor directly (they still accept), or close a request. Who asked is shown
                only when you ask, and every look is recorded.
            </p>
            <p class="mt-2 text-sm font-medium" :class="onCallCount ? 'text-gray-800' : 'text-amber-800'">
                {{ onCallCount ? `${onCallCount} counsellor${onCallCount === 1 ? '' : 's'} on call now.` : 'No counsellor is on call right now.' }}
            </p>
        </div>

        <AdminSearchBox :model-value="search" label="Search by the counsellor's name" :too-many="searchTooMany" @search="(term) => searchPage('administrator.help_requests', term, {})" />

        <!-- Waiting -->
        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-6" aria-labelledby="help-waiting">
            <h2 id="help-waiting" class="text-lg font-semibold text-gray-900">Waiting for a counsellor <span class="text-sm font-normal text-gray-600">({{ waiting.length }})</span></h2>
            <p v-if="!waiting.length" class="mt-2 text-sm text-gray-600">Nobody is waiting.</p>
            <ul v-else class="mt-3 space-y-3">
                <li v-for="row in waiting" :key="row.id" class="rounded-md border border-amber-200 bg-amber-50 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 text-sm text-gray-800">
                                <p class="font-semibold text-gray-900">
                                    {{ row.name }} <span class="font-normal text-gray-600">· {{ row.isMinor ? 'under 18' : 'adult' }}<template v-if="row.country"> · {{ row.country }}</template></span>
                                </p>
                                <p class="tabular-nums">Waiting {{ waitedFor(row) }}</p>
                                <p v-if="row.note" class="mt-1 text-gray-700">"{{ row.note }}"</p>
                                <p class="mt-1 flex flex-wrap gap-2 text-xs">
                                    <span v-if="row.suspendedAccount" class="rounded-full border border-gray-300 bg-white px-2 py-0.5 text-gray-700">Suspended account</span>
                                    <span v-if="row.escalatedAgainAt" class="rounded-full border border-red-200 bg-white px-2 py-0.5 text-red-800">Alerted twice</span>
                                    <span v-else-if="row.escalatedAt" class="rounded-full border border-amber-300 bg-white px-2 py-0.5 text-amber-800">Administrators alerted</span>
                                    <span v-if="row.pulledCounsellor" class="rounded-full border border-blue-200 bg-white px-2 py-0.5 text-blue-800">Rang {{ row.pulledCounsellor }}</span>
                                </p>
                                <p v-if="revealed[row.id]" class="mt-2 rounded border border-gray-200 bg-white p-2 text-xs text-gray-800">
                                    {{ revealed[row.id].name }} (@{{ revealed[row.id].username }}) · {{ revealed[row.id].email }}
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <PrimaryButton type="button" :disabled="busy === `ring-${row.id}`" @click="ringAgain(row)">Ring on call again</PrimaryButton>
                                <SecondaryButton type="button" @click="startPicking(row)">Ring a counsellor</SecondaryButton>
                                <SecondaryButton v-if="!revealed[row.id]" type="button" :disabled="busy === `reveal-${row.id}`" @click="reveal(row)">Show who asked</SecondaryButton>
                                <DangerButton type="button" @click="() => { closing = row; closeNote = '' }">Close</DangerButton>
                            </div>
                        </div>
                </li>
            </ul>
        </section>

        <!-- In a chat -->
        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-6" aria-labelledby="help-connected">
            <h2 id="help-connected" class="text-lg font-semibold text-gray-900">In a chat <span class="text-sm font-normal text-gray-600">({{ connected.length }})</span></h2>
            <p v-if="!connected.length" class="mt-2 text-sm text-gray-600">No help chats right now.</p>
            <ul v-else class="mt-3 divide-y divide-gray-100">
                <li v-for="row in connected" :key="row.id" class="flex flex-wrap items-baseline justify-between gap-2 py-2 text-sm">
                        <span class="text-gray-900">{{ row.name }}<span class="text-gray-600"> with {{ row.counsellor }}</span></span>
                        <span class="text-xs text-gray-600">since {{ formatTime(row.acceptedAt) }}</span>
                </li>
            </ul>
        </section>

        <!-- The last day -->
        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-6" aria-labelledby="help-closed">
            <h2 id="help-closed" class="text-lg font-semibold text-gray-900">The last day</h2>
            <p v-if="!closed.length" class="mt-2 text-sm text-gray-600">Nothing else in the last day.</p>
            <ul v-else class="mt-3 divide-y divide-gray-100">
                <li v-for="row in closed" :key="row.id" class="py-2 text-sm">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="text-gray-900">{{ row.name }} · {{ statusLabel[row.status] ?? row.status }}<template v-if="row.endedBy && row.status === 'ENDED'"> by {{ ending[row.endedBy] ?? row.endedBy }}</template></span>
                            <span class="text-xs text-gray-600">{{ formatTime(row.endedAt ?? row.createdAt) }}</span>
                        </div>
                        <p v-if="row.counsellor" class="text-xs text-gray-600">With {{ row.counsellor }}</p>
                        <p v-if="row.closedNote" class="text-xs text-gray-600">Note: {{ row.closedNote }}</p>
                </li>
            </ul>
        </section>
    </div>

    <MiniModal :show="!!picking" labelledby="help-pick-title" @close="picking = null">
        <div v-if="picking" class="space-y-4">
            <h2 id="help-pick-title" class="text-lg font-semibold text-gray-900">Ring a counsellor</h2>
            <p class="text-sm text-gray-600">They're rung now, if they have TalkTherapy open, and can accept -- even if they aren't on call. Counsellors on call are listed first.</p>
            <div>
                <label for="help-pick-search" class="block text-sm font-medium text-gray-700">Find a counsellor</label>
                <TextInput id="help-pick-search" v-model="counsellorSearch" type="search" maxlength="100" class="mt-1 block w-full" @input="searchCounsellors" />
            </div>
            <p v-if="!counsellors.length" class="text-sm text-gray-600">No counsellor who could take it right now.</p>
            <ul v-else class="max-h-72 divide-y divide-gray-100 overflow-y-auto">
                <li v-for="counsellor in counsellors" :key="counsellor.id" class="flex items-center justify-between gap-2 py-2 text-sm">
                    <span class="text-gray-900">{{ counsellor.name }} <span v-if="counsellor.onCall" class="ml-1 rounded-full border border-green-200 bg-green-50 px-2 py-0.5 text-xs text-green-800">On call</span></span>
                    <PrimaryButton type="button" :disabled="busy === `pick-${counsellor.id}`" @click="ringCounsellor(counsellor)">Ring</PrimaryButton>
                </li>
            </ul>
            <div class="flex justify-end"><SecondaryButton type="button" @click="picking = null">Cancel</SecondaryButton></div>
        </div>
    </MiniModal>

    <MiniModal :show="!!closing" labelledby="help-close-title" @close="closing = null">
        <form v-if="closing" class="space-y-4" @submit.prevent="close">
            <h2 id="help-close-title" class="text-lg font-semibold text-gray-900">Close this request?</h2>
            <p class="text-sm text-gray-600">The person is told it was closed and shown the emergency number. Only close it if they're being helped another way.</p>
            <div>
                <label for="help-close-note" class="block text-sm font-medium text-gray-700">Note for the record (optional)</label>
                <TextInput id="help-close-note" v-model="closeNote" maxlength="300" class="mt-1 block w-full" />
            </div>
            <div class="flex justify-end gap-2">
                <SecondaryButton type="button" :disabled="busy === 'close'" @click="closing = null">Keep it open</SecondaryButton>
                <DangerButton :disabled="busy === 'close'">Close request</DangerButton>
            </div>
        </form>
    </MiniModal>

    <Alert :show="alertData.show" :type="alertData.type" :message="alertData.message" :time="alertData.time" @close="clearAlertData" />
</template>
