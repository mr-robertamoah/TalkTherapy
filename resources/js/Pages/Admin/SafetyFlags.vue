<script setup>
// SCRUM-32: the administrators' safety alerts -- counsellors' flags that someone may need specialist or
// inpatient care. The list never names anyone or gives the reason: an administrator acknowledges one
// and becomes the one handling it, and only they can see who it's about and why (each look is
// recorded). Flags an administrator is involved in aren't shown to them at all. A super administrator
// can hand one on. Kept live: a ping (no id, only to administrators not involved) refetches the list.
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import axios from 'axios'
import { Head, router, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Alert from '@/Components/Alert.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { acquireUserChannel } from '@/Composables/sharedChannel'
import useAlert from '@/Composables/useAlert'

// Shared Inertia props aren't page attributes, and the page has several root nodes.
defineOptions({ layout: AdminLayout, inheritAttrs: false })

const props = defineProps({
    flags: { type: Array, default: () => [] },
    admins: { type: Array, default: () => [] },
    status: { type: String, default: '' },
    label: { type: String, default: 'Safety flag' },
    // TT-4.3b: the lists for recording an action and resolving.
    outcomes: { type: Array, default: () => [] },
    actions: { type: Array, default: () => [] },
})

const { alertData, clearAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()

const rows = ref([...props.flags])
const who = ref({}) // id -> what RevealSafetyFlagAction::who returned
const why = ref({}) // id -> what RevealSafetyFlagAction::reason returned
const handTo = ref({}) // id -> admin id
// TT-4.3b: the handling administrator's forms, per flag.
const noteText = ref({})
const actionType = ref({})
const actionText = ref({})
const outcome = ref({})
const outcomeNote = ref({})
const resolving = ref(null)
const showHistory = ref({})
const busy = ref(null)
const filter = ref(props.status)
let channel = null
let reloadTimer = null
let latest = 0

const filters = [
    { value: '', label: 'Needing someone (open or being handled)' },
    { value: 'OPEN', label: 'Open' },
    { value: 'ACKNOWLEDGED', label: 'Being handled' },
    { value: 'WITHDRAWN', label: 'Withdrawn' },
    { value: 'RESOLVED', label: 'Resolved' },
]
const statusLabel = { OPEN: 'Open -- nobody is handling it yet', ACKNOWLEDGED: 'Being handled', WITHDRAWN: 'Withdrawn by the counsellor', RESOLVED: 'Resolved' }
// Being handled: acknowledged, or withdrawn after someone took it on (it still needs resolving).
const beingHandled = (row) => !!row.handler && ['ACKNOWLEDGED', 'WITHDRAWN'].includes(row.status)
const handled = (row) => row.isMine && beingHandled(row)
// Never by colour alone: each urgency has its own word and mark.
const urgencyMark = { IMMINENT: '!!!', URGENT: '!!', NON_URGENT: '!' }
const discussedLabel = { YES: 'Yes', NO: 'No', NOT_POSSIBLE: 'It wasn\'t possible' }

watch(() => props.flags, (flags) => { rows.value = [...flags] })

function formatWhen(value) {
    return value ? new Date(value).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''
}

const refusal = (err) => err.response?.data?.message ?? 'Something went wrong. Please try again.'

function changeFilter() {
    router.get(route('administrator.safety_flags'), filter.value ? { status: filter.value } : {}, { preserveScroll: true, preserveState: true })
}

// Refetched on each ping -- bursts are gathered (300 ms), and only the latest answer is shown.
function reload() {
    clearTimeout(reloadTimer)
    reloadTimer = setTimeout(async () => {
        const ticket = ++latest
        await axios.get(route('admin.safety_flags.list', filter.value ? { status: filter.value } : {}))
            .then((res) => {
                if (ticket !== latest) return
                rows.value = res.data.flags
                // What was shown stays only on flags they still handle (one handed on, or closed, doesn't).
                const mine = new Set(rows.value.filter((row) => row.isMine).map((row) => row.id))
                who.value = Object.fromEntries(Object.entries(who.value).filter(([id]) => mine.has(id)))
                why.value = Object.fromEntries(Object.entries(why.value).filter(([id]) => mine.has(id)))
            })
            .catch(() => {})
    }, 300)
}

async function act(row, name, action, success) {
    busy.value = `${name}-${row.id}`
    await action()
        .then((res) => { if (success) setSuccessAlertData({ message: success }); return res })
        .catch((err) => setFailedAlertData({ message: refusal(err) }))
        .finally(() => { busy.value = null })
}

function acknowledge(row) {
    act(row, 'ack', () => axios.post(route('admin.safety_flags.acknowledge', { flag: row.id })).then(reload),
        'You are handling it now. You can see who it is about and why.')
}

function showWho(row) {
    act(row, 'who', () => axios.post(route('admin.safety_flags.who', { flag: row.id })).then((res) => { who.value = { ...who.value, [row.id]: res.data } }))
}

function showWhy(row) {
    act(row, 'why', () => axios.post(route('admin.safety_flags.reason', { flag: row.id })).then((res) => { why.value = { ...why.value, [row.id]: res.data } }))
}

// TT-4.3b: a new note or action is added to what's shown -- only if the reason is already open: adding
// one never reads (and logs) the reason on the handler's behalf (reviews).
function appendNote(row, note) {
    if (!why.value[row.id]) return
    why.value = { ...why.value, [row.id]: { ...why.value[row.id], notes: [...(why.value[row.id].notes ?? []), note] } }
}

function addNote(row) {
    act(row, 'note', () => axios.post(route('admin.safety_flags.note', { flag: row.id }), { content: noteText.value[row.id] })
        .then((res) => { noteText.value = { ...noteText.value, [row.id]: '' }; appendNote(row, res.data.note); reload() }), 'The note has been added.')
}

function recordAction(row) {
    act(row, 'action', () => axios.post(route('admin.safety_flags.action', { flag: row.id }), { action: actionType.value[row.id], content: actionText.value[row.id] || null })
        .then((res) => { actionType.value = { ...actionType.value, [row.id]: undefined }; actionText.value = { ...actionText.value, [row.id]: '' }; appendNote(row, res.data.note); reload() }), 'The action has been recorded.')
}

// TT-4.3c: send, hold or withhold the person's or the guardians' notice.
const noticeReason = ref({}) // `${id}-${which}` -> text
const noticeAction = ref(null) // `${id}-${which}-${decision}` while asking for a reason

function decideNotice(row, which, decision) {
    const key = `${row.id}-${which}`
    act(row, `notice-${which}`, () => axios.post(route('admin.safety_flags.notice', { flag: row.id }), { which, decision, reason: noticeReason.value[key] || null })
        .then((res) => { noticeAction.value = null; noticeReason.value = { ...noticeReason.value, [key]: '' }; if (res.data.note) appendNote(row, res.data.note); reload() }),
    decision === 'send' ? 'The notice has been sent.' : (decision === 'hold' ? 'The notice is held.' : 'The notice is withheld.'))
}

function resolve(row) {
    act(row, 'resolve', () => axios.post(route('admin.safety_flags.resolve', { flag: row.id }), { outcome: outcome.value[row.id], note: outcomeNote.value[row.id] })
        .then(() => { resolving.value = null; reload() }), 'It has been resolved.')
}

function reassign(row) {
    act(row, 'hand', () => axios.post(route('admin.safety_flags.reassign', { flag: row.id }), { adminId: handTo.value[row.id] })
        // Cleared once handed on (QA): the choice is now the handler, no longer in the list.
        .then(() => { handTo.value = { ...handTo.value, [row.id]: undefined }; reload() }),
        'It has been handed on.')
}

onMounted(() => {
    // Pinged on the administrator's own channel, only for flags they aren't involved in.
    channel = acquireUserChannel(usePage().props.auth?.user?.id)
    channel?.listen('.safety.changed', () => reload())
})

onBeforeUnmount(() => {
    channel?.release()
    clearTimeout(reloadTimer)
})
</script>

<template>
    <Head title="Safety alerts" />

    <div class="mx-auto my-8 w-full max-w-5xl space-y-6 px-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Safety alerts</h1>
            <div class="mt-2 h-1 w-16 bg-blue-600"></div>
            <p class="mt-2 text-sm text-gray-600">
                {{ label }}. A counsellor thinks someone may need more care than therapy here can give. Acknowledge one to
                handle it: only the administrator handling it sees who it is about and the counsellor's reason, and every look
                is recorded. We aren't an emergency service -- if someone is in danger now, emergency services come first.
            </p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <div>
                <label for="safety-flag-filter" class="block text-xs font-medium text-gray-700">Show</label>
                <select id="safety-flag-filter" v-model="filter" class="mt-1 rounded-md border-gray-300 text-sm shadow-sm" @change="changeFilter">
                    <option v-for="option in filters" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
            </div>
        </div>

        <p v-if="!rows.length" class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-600 shadow-sm">No safety alerts here.</p>

        <ul v-else class="space-y-3">
            <li v-for="row in rows" :key="row.id" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-semibold text-gray-900">
                            <span aria-hidden="true" class="mr-1 font-mono">{{ urgencyMark[row.urgency] }}</span>{{ row.urgencyLabel }}
                            <span class="font-normal text-gray-600">· {{ row.kind }}</span>
                        </p>
                        <p class="mt-0.5 text-sm text-gray-700">
                            {{ row.wasMinor ? `Under 18 · ${row.hadGuardian ? 'has a guardian' : 'no guardian on record'}` : 'Adult' }}
                            <template v-if="row.anonymous"> · anonymous to others</template>
                            <template v-if="row.country"> · {{ row.country }}</template>
                        </p>
                        <p class="mt-0.5 text-xs text-gray-600">
                            Raised {{ formatWhen(row.createdAt) }} · {{ statusLabel[row.status] ?? row.status }}<template v-if="row.handler"> · handled by {{ row.isMine ? 'you' : row.handler.name }}</template>
                            <template v-if="row.outcome"> · {{ row.outcome }}</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <PrimaryButton v-if="row.status === 'OPEN'" type="button" :disabled="busy === `ack-${row.id}`" @click="acknowledge(row)">Acknowledge</PrimaryButton>
                        <template v-if="row.isMine">
                            <SecondaryButton v-if="!who[row.id]" type="button" :disabled="busy === `who-${row.id}`" @click="showWho(row)">Show who</SecondaryButton>
                            <SecondaryButton v-if="!why[row.id]" type="button" :disabled="busy === `why-${row.id}`" @click="showWhy(row)">Show the reason</SecondaryButton>
                        </template>
                    </div>
                </div>

                <dl v-if="who[row.id]" class="mt-3 grid gap-1 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm sm:grid-cols-[10rem_1fr]">
                    <dt class="text-gray-600">About</dt>
                    <dd class="text-gray-900">{{ who[row.id].client.name }} (@{{ who[row.id].client.username }}) · {{ who[row.id].client.email }}<template v-if="who[row.id].client.leftGroup"> · has left the group</template></dd>
                    <dt class="text-gray-600">Where</dt>
                    <dd class="text-gray-900">{{ who[row.id].where.kind }}: {{ who[row.id].where.name }}</dd>
                    <dt class="text-gray-600">Raised by</dt>
                    <dd class="text-gray-900">{{ who[row.id].counsellor.name }} (@{{ who[row.id].counsellor.username }})</dd>
                </dl>

                <dl v-if="why[row.id]" class="mt-3 grid gap-1 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm sm:grid-cols-[10rem_1fr]">
                    <dt class="text-gray-600">Reason</dt>
                    <dd class="whitespace-pre-wrap break-words text-gray-900">{{ why[row.id].reason }}</dd>
                    <dt class="text-gray-600">Discussed with them</dt>
                    <dd class="text-gray-900">{{ discussedLabel[why[row.id].discussedWithClient] }}</dd>
                    <dt class="text-gray-600">Emergency services</dt>
                    <dd class="text-gray-900">{{ why[row.id].emergencyServicesContacted ? 'Contacted' : 'Not contacted' }}<template v-if="why[row.id].emergencyServicesNote"> -- {{ why[row.id].emergencyServicesNote }}</template></dd>
                    <template v-if="why[row.id].withdrawnReason">
                        <dt class="text-gray-600">Withdrawn because</dt>
                        <dd class="whitespace-pre-wrap break-words text-gray-900">{{ why[row.id].withdrawnReason }}</dd>
                    </template>
                    <template v-if="why[row.id].outcome">
                        <dt class="text-gray-600">Outcome</dt>
                        <dd class="whitespace-pre-wrap break-words text-gray-900">{{ why[row.id].outcome }}<template v-if="why[row.id].outcomeNote"> -- {{ why[row.id].outcomeNote }}</template></dd>
                    </template>
                    <template v-if="why[row.id].notes?.length">
                        <dt class="text-gray-600">Notes and actions</dt>
                        <dd>
                            <ul class="space-y-1">
                                <li v-for="(note, index) in why[row.id].notes" :key="index" class="text-gray-900">
                                    <span class="text-xs text-gray-600">{{ formatWhen(note.at) }} · {{ note.by }}:</span>
                                    <strong v-if="note.action" class="ml-1">{{ note.action }}</strong>
                                    <span v-if="note.content" class="ml-1 whitespace-pre-wrap break-words">{{ note.content }}</span>
                                </li>
                            </ul>
                        </dd>
                    </template>
                </dl>

                <!-- TT-4.3c (QA): where its notices stand, for every administrator who sees it (no reasons). -->
                <p v-if="!handled(row) && (row.notices.client !== 'NOT_APPLICABLE' || row.notices.guardians !== 'NOT_APPLICABLE')" class="mt-1 text-xs text-gray-600">
                    Notices -- to the person: {{ row.notices.clientLabel }}<template v-if="row.notices.guardians !== 'NOT_APPLICABLE'">; to their guardians: {{ row.notices.guardiansLabel }}</template>
                </p>

                <!-- TT-4.3b: the handling administrator's tools (an acknowledged flag, or one withdrawn since). -->
                <div v-if="handled(row)" class="mt-3 grid gap-3 sm:grid-cols-2">
                    <form class="space-y-1" @submit.prevent="addNote(row)">
                        <label :for="`safety-note-${row.id}`" class="block text-xs font-medium text-gray-700">Add a note</label>
                        <textarea :id="`safety-note-${row.id}`" v-model="noteText[row.id]" rows="2" maxlength="2000" class="block w-full rounded-md border-gray-300 text-sm shadow-sm"></textarea>
                        <SecondaryButton type="submit" :disabled="!noteText[row.id]?.trim() || busy === `note-${row.id}`">Add note</SecondaryButton>
                    </form>
                    <form class="space-y-1" @submit.prevent="recordAction(row)">
                        <label :for="`safety-action-${row.id}`" class="block text-xs font-medium text-gray-700">Record an action</label>
                        <select :id="`safety-action-${row.id}`" v-model="actionType[row.id]" class="block w-full rounded-md border-gray-300 text-sm shadow-sm">
                            <option :value="undefined" disabled>Choose what you did</option>
                            <option v-for="option in actions" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                        <input v-model="actionText[row.id]" type="text" maxlength="2000" :aria-label="actionType[row.id] === 'OTHER' ? 'What you did (needed)' : 'Details (optional)'" :placeholder="actionType[row.id] === 'OTHER' ? 'What you did (needed)' : 'Details (optional)'" class="block w-full rounded-md border-gray-300 text-sm shadow-sm" />
                        <SecondaryButton type="submit" :disabled="!actionType[row.id] || (actionType[row.id] === 'OTHER' && !actionText[row.id]?.trim()) || busy === `action-${row.id}`">Record</SecondaryButton>
                    </form>
                    <!-- TT-4.3c: the notices -- sent automatically at the time shown unless you send or hold them. -->
                    <div class="space-y-2 rounded-md border border-gray-200 p-3 sm:col-span-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-600">Notices</p>
                        <p v-if="row.notices.dueAt" class="text-xs text-gray-600">Waiting notices go out on their own at {{ formatWhen(row.notices.dueAt) }}.</p>
                        <div v-for="which in ['client', 'guardians']" :key="which" class="text-sm">
                            <template v-if="row.notices[which] !== 'NOT_APPLICABLE'">
                                <p class="text-gray-900">{{ which === 'client' ? 'To the person' : 'To their guardians' }}: <span class="text-gray-700">{{ row.notices[`${which}Label`] }}</span></p>
                                <div v-if="['PENDING', 'HELD'].includes(row.notices[which])" class="mt-1 flex flex-wrap items-end gap-2">
                                    <SecondaryButton type="button" :disabled="busy === `notice-${which}-${row.id}`" @click="decideNotice(row, which, 'send')">Send now</SecondaryButton>
                                    <template v-if="noticeAction?.startsWith(`${row.id}-${which}-`)">
                                        <input v-model="noticeReason[`${row.id}-${which}`]" type="text" maxlength="1000" :aria-label="noticeAction.endsWith('-hold') ? 'Safety reason for holding it' : 'Reason for withholding it'" :placeholder="noticeAction.endsWith('-hold') ? 'Safety reason for holding it' : 'Reason for withholding it'" class="rounded-md border-gray-300 text-sm shadow-sm" />
                                        <SecondaryButton type="button" :disabled="!noticeReason[`${row.id}-${which}`]?.trim() || busy === `notice-${which}-${row.id}`" @click="decideNotice(row, which, noticeAction.endsWith('-hold') ? 'hold' : 'withhold')">Confirm</SecondaryButton>
                                        <SecondaryButton type="button" :disabled="busy === `notice-${which}-${row.id}`" @click="noticeAction = null">Cancel</SecondaryButton>
                                    </template>
                                    <template v-else>
                                        <SecondaryButton v-if="row.notices[which] === 'PENDING'" type="button" :disabled="busy === `notice-${which}-${row.id}`" @click="noticeAction = `${row.id}-${which}-hold`">Hold…</SecondaryButton>
                                        <SecondaryButton type="button" :disabled="busy === `notice-${which}-${row.id}`" @click="noticeAction = `${row.id}-${which}-withhold`">Withhold…</SecondaryButton>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                    <div class="sm:col-span-2">
                        <PrimaryButton v-if="resolving !== row.id" type="button" @click="resolving = row.id">Resolve…</PrimaryButton>
                        <form v-else class="space-y-2 rounded-md border border-gray-200 p-3" @submit.prevent="resolve(row)">
                            <label :for="`safety-outcome-${row.id}`" class="block text-xs font-medium text-gray-700">Outcome</label>
                            <select :id="`safety-outcome-${row.id}`" v-model="outcome[row.id]" class="block w-full rounded-md border-gray-300 text-sm shadow-sm">
                                <option :value="undefined" disabled>Choose the outcome</option>
                                <option v-for="option in outcomes" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                            <label :for="`safety-outcome-note-${row.id}`" class="block text-xs font-medium text-gray-700">What happened (needed)</label>
                            <textarea :id="`safety-outcome-note-${row.id}`" v-model="outcomeNote[row.id]" rows="2" maxlength="2000" class="block w-full rounded-md border-gray-300 text-sm shadow-sm"></textarea>
                            <div class="flex gap-2">
                                <SecondaryButton type="button" @click="resolving = null">Cancel</SecondaryButton>
                                <PrimaryButton :disabled="!outcome[row.id] || !outcomeNote[row.id]?.trim() || busy === `resolve-${row.id}`">Resolve</PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- TT-4.3b: what happened and when (never what was written), for every administrator who sees it. -->
                <div class="mt-3">
                    <button type="button" class="text-xs font-medium text-gray-700 underline" :aria-expanded="!!showHistory[row.id]" @click="showHistory = { ...showHistory, [row.id]: !showHistory[row.id] }">
                        {{ showHistory[row.id] ? 'Hide history' : 'Show history' }}
                    </button>
                    <ol v-if="showHistory[row.id]" class="mt-2 space-y-1 border-l border-gray-200 pl-3 text-xs text-gray-700">
                        <li v-for="(step, index) in row.history" :key="index">{{ formatWhen(step.at) }} -- {{ step.label }}<template v-if="step.by"> ({{ step.by }})</template></li>
                    </ol>
                </div>

                <!-- A super administrator hands one being handled to someone else. -->
                <div v-if="admins.length && beingHandled(row)" class="mt-3 flex flex-wrap items-end gap-2">
                    <div>
                        <label :for="`safety-flag-hand-${row.id}`" class="block text-xs font-medium text-gray-700">Hand to</label>
                        <select :id="`safety-flag-hand-${row.id}`" v-model="handTo[row.id]" class="mt-1 rounded-md border-gray-300 text-sm shadow-sm">
                            <option :value="undefined" disabled>Choose an administrator</option>
                            <option v-for="admin in admins.filter((admin) => admin.id !== row.handler?.id)" :key="admin.id" :value="admin.id">{{ admin.name }}</option>
                        </select>
                    </div>
                    <SecondaryButton type="button" :disabled="!handTo[row.id] || busy === `hand-${row.id}`" @click="reassign(row)">Hand on</SecondaryButton>
                </div>
            </li>
        </ul>
    </div>

    <Alert :show="alertData.show" :type="alertData.type" :message="alertData.message" :time="alertData.time" @close="clearAlertData" />
</template>
