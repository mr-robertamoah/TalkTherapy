// SCRUM-33: the "I need help now" state, shared by the app-root HelpNowRoot (button, dialog, a
// counsellor's ring) -- module-level, so it survives page visits. Live updates come over the help
// request's own channel; while waiting it also checks every 15 seconds, in case the socket is down.
import { ref } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import { acquireChannel } from '@/Composables/sharedChannel'

const open = ref(false)
const numbers = ref({ country: null, numbers: [] })
const numbersLoading = ref(false)
const numbersFailed = ref(false)
const request = ref(null) // HelpRequestResource, the person's own
const starting = ref(false)
const error = ref('')

let channel = null
let channelFor = null
let poll = null

export function helpRefusal(err, fallback = 'Something went wrong. If you are in danger, call your emergency number now.') {
    if ([403, 404, 409, 422, 429].includes(err?.response?.status)) return err.response.data?.message ?? fallback
    return fallback
}

async function loadNumbers(countryCode = null) {
    numbersLoading.value = true
    numbersFailed.value = false
    await axios.get(route('help.emergency_numbers', countryCode ? { country: countryCode } : {}))
        .then((res) => { numbers.value = res.data })
        .catch(() => { numbersFailed.value = true })
        .finally(() => { numbersLoading.value = false })
}

function stopWatching() {
    channel?.release()
    channel = null
    channelFor = null
    clearInterval(poll)
    poll = null
}

function watchRequest() {
    const id = request.value?.id
    if (!id || !['WAITING', 'CONNECTED'].includes(request.value.status)) return stopWatching()
    if (channelFor === id) return

    stopWatching()
    channelFor = id
    channel = acquireChannel(`help-requests.${id}`)
    channel?.listen('.help.updated', () => refresh())
    poll = setInterval(() => { if (request.value?.status === 'WAITING') refresh() }, 15000)
}

function applied(next) {
    const wasWaiting = request.value?.status === 'WAITING'
    request.value = next
    watchRequest()

    // Answered: go to the chat.
    if (wasWaiting && next?.status === 'CONNECTED') {
        open.value = false
        router.visit(route('help.chat', { helpRequest: next.id }))
    }
}

async function refresh() {
    if (!request.value?.id) return
    await axios.get(route('help.requests.show', { helpRequest: request.value.id }))
        .then((res) => applied(res.data.helpRequest))
        .catch(() => {})
}

// On load (signed in): pick up a request still waiting or connected.
async function resume() {
    await axios.get(route('help.requests.current'))
        .then((res) => applied(res.data.helpRequest))
        .catch(() => {})
}

async function start({ anonymous, note }) {
    starting.value = true
    error.value = ''
    await axios.post(route('help.requests.store'), { anonymous, note })
        .then((res) => applied(res.data.helpRequest))
        .catch((err) => { error.value = helpRefusal(err) })
        .finally(() => { starting.value = false })
}

async function cancel() {
    if (!request.value?.id) return
    error.value = ''
    await axios.post(route('help.requests.cancel', { helpRequest: request.value.id }))
        .then((res) => applied(res.data.helpRequest))
        .catch((err) => { error.value = helpRefusal(err) })
}

function startAgain() {
    stopWatching()
    request.value = null
    error.value = ''
}

// Signed out: nothing of the last person's request stays on screen.
function forget() {
    startAgain()
    open.value = false
    numbers.value = { country: null, numbers: [] }
}

function show() {
    open.value = true
    if (!numbers.value.country && !numbersLoading.value) loadNumbers()
}

export default function useHelpNow() {
    return { open, numbers, numbersLoading, numbersFailed, request, starting, error, show, loadNumbers, resume, start, cancel, startAgain, refresh, forget }
}
