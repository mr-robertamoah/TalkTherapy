<script setup>
// SCRUM-33: an on-call counsellor's ring. The ring itself carries only an id; the short summary
// (first name or "Anonymous", adult or minor, country, languages, the person's note) is fetched.
// The first counsellor to accept is connected; the others see that someone else answered.
import { onBeforeUnmount, onMounted, ref } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import MiniModal from '@/Components/MiniModal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import ReportHelpRequestModal from '@/Components/HelpNow/ReportHelpRequestModal.vue'
import { acquireCounsellorChannel } from '@/Composables/sharedChannel'
import { helpRefusal } from '@/Composables/useHelpNow'

const props = defineProps({
    counsellorId: { type: Number, required: true },
})

const ring = ref(null) // HelpRequestResource
const notice = ref('')
const accepting = ref(false)
// SCRUM-398: reporting a ring closes it for this counsellor only -- it goes on ringing everyone else.
const reportingId = ref(null)

function report() {
    reportingId.value = ring.value.id
    ring.value = null
}

function reportClosed() {
    reportingId.value = null
    next()
}
let channel = null
let noticeTimer = null

// Rings that came while one was showing wait their turn (oldest first).
const queue = ref([])

async function rang({ helpRequestId }) {
    if (ring.value) {
        if (ring.value.id !== helpRequestId && !queue.value.includes(helpRequestId)) queue.value.push(helpRequestId)
        return
    }
    await axios.get(route('help.requests.show', { helpRequest: helpRequestId }))
        .then((res) => {
            if (res.data.helpRequest?.status === 'WAITING') {
                notice.value = ''
                ring.value = res.data.helpRequest
            } else {
                next()
            }
        })
        .catch(() => next())
}

function next() {
    const id = queue.value.shift()
    if (id) rang({ helpRequestId: id })
}

// Anyone already waiting that they could answer -- on load, and when they switch on (useHelpNow).
async function catchUp() {
    await axios.get(route('help.requests.ringing'))
        .then((res) => (res.data.helpRequests ?? []).forEach((help) => rang({ helpRequestId: help.id })))
        .catch(() => {})
}

defineExpose({ catchUp })

function closedElsewhere({ helpRequestId, status }) {
    queue.value = queue.value.filter((id) => id !== helpRequestId)
    if (ring.value?.id !== helpRequestId || accepting.value) return
    ring.value = null
    flash(status === 'CONNECTED' ? 'Someone else answered.' : 'They are no longer waiting.')
    next()
}

function flash(message) {
    notice.value = message
    clearTimeout(noticeTimer)
    noticeTimer = setTimeout(() => { notice.value = '' }, 5000)
}

async function accept() {
    accepting.value = true
    await axios.post(route('help.requests.accept', { helpRequest: ring.value.id }))
        .then((res) => {
            ring.value = null
            router.visit(route('help.chat', { helpRequest: res.data.helpRequest.id }))
        })
        .catch((err) => {
            ring.value = null
            flash(helpRefusal(err, 'It could not be answered.'))
        })
        .finally(() => { accepting.value = false })
}

onMounted(() => {
    channel = acquireCounsellorChannel(props.counsellorId)
    channel?.listen('.help.ringing', rang)
    channel?.listen('.help.updated', closedElsewhere)
    catchUp()
    window.addEventListener('help:available', catchUp)
    // SCRUM-399: an alert tapped while the app is already open -- look again for anyone waiting.
    navigator.serviceWorker?.addEventListener('message', fromServiceWorker)
})

function fromServiceWorker(event) {
    if (event.data?.type === 'help-push-opened') catchUp()
}

onBeforeUnmount(() => {
    window.removeEventListener('help:available', catchUp)
    navigator.serviceWorker?.removeEventListener('message', fromServiceWorker)
    channel?.release()
    clearTimeout(noticeTimer)
})
</script>

<template>
    <MiniModal :show="!!ring" labelledby="help-ring-title" :closeable="!accepting" @close="ring = null">
        <div v-if="ring" class="space-y-4 text-gray-900">
            <h2 id="help-ring-title" class="text-lg font-semibold">Someone needs help now</h2>
            <dl class="grid grid-cols-[auto,1fr] gap-x-4 gap-y-1 text-sm">
                <dt class="text-gray-600">Name</dt><dd>{{ ring.person.name }}</dd>
                <dt class="text-gray-600">Age</dt><dd>{{ ring.person.isMinor ? 'Under 18' : 'Adult' }}</dd>
                <template v-if="ring.person.country"><dt class="text-gray-600">Country</dt><dd>{{ ring.person.country }}</dd></template>
                <template v-if="ring.person.languages.length"><dt class="text-gray-600">Languages</dt><dd>{{ ring.person.languages.join(', ') }}</dd></template>
            </dl>
            <p v-if="ring.person.note" class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-800">"{{ ring.person.note }}"</p>
            <p class="text-xs text-gray-600">If you accept, a private chat opens with them straight away.</p>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <button v-if="ring.canReport" type="button" class="mr-auto text-sm text-gray-600 underline hover:text-gray-900" :disabled="accepting" @click="report">Report this request</button>
                <SecondaryButton type="button" :disabled="accepting" @click="() => { ring = null; next() }">Not now</SecondaryButton>
                <PrimaryButton type="button" :disabled="accepting" @click="accept">{{ accepting ? 'Connecting…' : 'Accept' }}</PrimaryButton>
            </div>
        </div>
    </MiniModal>

    <ReportHelpRequestModal :show="reportingId !== null" :help-request-id="reportingId" @close="reportClosed" />

    <div v-if="notice" class="fixed bottom-20 left-4 z-40 rounded-md border border-gray-200 bg-white px-4 py-2 text-sm text-gray-800 shadow-lg" role="status">{{ notice }}</div>
</template>
