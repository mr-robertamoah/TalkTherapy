<script setup>
import { computed, ref, watch } from 'vue'
import axios from 'axios'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Alert from '@/Components/Alert.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import SuspensionCard from '@/Components/SuspensionCard.vue'
import TextBox from '@/Components/TextBox.vue'
import ReportStatusBadge from '@/Components/ReportStatusBadge.vue'
import useAlert from '@/Composables/useAlert'

// SCRUM-387: the admin area's shell (sidebar inside AuthenticatedLayout), kept across admin pages.
defineOptions({ layout: AdminLayout })

// SCRUM-340: reviewing one report. Status and reported-person changes are Inertia form posts. The
// reported conversation is fetched over axios on demand (with a reason) and only kept in this
// component's memory -- never in page props, so it isn't saved into browser history state.

const props = defineProps({
    report: { type: Object, required: true },
    outcomes: { type: Array, required: true },
})

const { alertData, clearAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()

const isActive = computed(() => ['open', 'under_review'].includes(props.report.status))

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : '--'
}

// SCRUM-359
const historyLabels = {
    suspension_applied: 'applied a suspension',
    suspension_lifted: 'lifted the suspension',
    suspension_ended: 'recorded that the suspension ended',
    appeal_submitted: 'appealed the suspension',
    appeal_decided: 'decided the appeal',
}

function readable(value) {
    return (value ?? '').replaceAll('_', ' ')
}

function postOptions(message, afterSuccess = () => {}) {
    return {
        preserveScroll: true,
        onSuccess: () => {
            afterSuccess()
            setSuccessAlertData({ message })
        },
        onError: (errors) => setFailedAlertData({ message: errors.alert ?? Object.values(errors)[0] ?? 'Something went wrong.' }),
    }
}

// --- Status -----------------------------------------------------------------------------------
const closing = ref(null) // 'resolved' | 'dismissed' | null
const statusForm = useForm({ status: '', outcome: '', note: '' })

function startClosing(status) {
    closing.value = status
    statusForm.reset()
    statusForm.clearErrors()
}

function submitStatus(status) {
    statusForm.status = status
    if (status !== 'resolved') statusForm.outcome = ''

    statusForm.post(route('admin.reports.status', { report: props.report.id }), postOptions('Report updated.', () => {
        closing.value = null
        statusForm.reset()
    }))
}

// --- Reported person --------------------------------------------------------------------------
const personKey = (person) => (person ? `${person.type}:${person.id}` : '')
const partyKey = ref(personKey(props.report.reportedPerson))
watch(() => props.report.reportedPerson, (person) => {
    partyKey.value = personKey(person)
})
const partyForm = useForm({ type: '', id: null, reason: '' })
// SCRUM-362: once confidential messages were read, only a super admin may re-point the report,
// with a reason that goes into the history.
const partyLocked = computed(() => props.report.partyLocked)
const partyChanged = computed(() => partyKey.value !== personKey(props.report.reportedPerson))
const needsPartyReason = computed(() => partyLocked.value && partyChanged.value)

function recordParty() {
    const [type, id] = partyKey.value.split(':')
    if (!type || !id) return

    partyForm.type = type
    partyForm.id = Number(id)
    partyForm.post(route('admin.reports.party', { report: props.report.id }), postOptions('Reported person recorded.', () => partyForm.reset('reason')))
}

// --- Suspension (SCRUM-359) -------------------------------------------------------------------
// SCRUM-398: a report on an urgent help request -- who asked is masked until revealed, and every reveal is
// recorded (the read log). Needed before suspending them.
const revealed = ref(null)
const revealing = ref(false)
const revealError = ref('')
async function revealHelpRequester() {
    revealing.value = true
    revealError.value = ''
    await axios.post(props.report.helpRequest.revealUrl)
        .then((res) => {
            revealed.value = res.data.person
            router.reload({ only: ['report'], preserveScroll: true })
        })
        .catch((err) => { revealError.value = err.response?.data?.message ?? 'That could not be shown.' })
        .finally(() => { revealing.value = false })
}

function formatWhen(value) {
    return value ? new Date(value).toLocaleString() : null
}

const suspendForm = useForm({ scope: props.report.suspend.scopes[0] ?? '', duration: '30', public_reason: '', internal_note: '' })
watch(() => props.report.suspend.scopes, (scopes) => {
    if (!scopes.includes(suspendForm.scope)) suspendForm.scope = scopes[0] ?? ''
})

function applySuspension() {
    suspendForm.post(route('admin.suspensions.apply', { report: props.report.id }), postOptions('Suspension applied.', () => suspendForm.reset('public_reason', 'internal_note')))
}

// --- Conversation -----------------------------------------------------------------------------
const sessionId = ref(props.report.unlock.sessions[0]?.id ?? null)
const reason = ref('')
const reasonError = ref('')
const loadingConversation = ref(false)
const conversation = ref(null)
const conversationSessionId = ref(null)

// A conversation fetched for one session is never left on screen under another.
watch(sessionId, () => {
    conversation.value = null
})

// The sessions change when the report is reopened, closed or gets a reported person -- keep a valid
// selection, and drop a conversation that can no longer be read.
watch(() => props.report.unlock.sessions, (sessions) => {
    if (!sessions.some((s) => s.id === sessionId.value)) sessionId.value = sessions[0]?.id ?? null
    if (!props.report.unlock.available) conversation.value = null
})

async function readConversation() {
    reasonError.value = ''
    if (reason.value.trim().length < 10) {
        reasonError.value = 'Say why you need to read this conversation (at least 10 characters). It is recorded.'
        return
    }

    loadingConversation.value = true
    try {
        const res = await axios.post(
            route('admin.reports.conversation', { report: props.report.id, session: sessionId.value }),
            { reason: reason.value },
        )
        conversation.value = res.data.messages
        conversationSessionId.value = sessionId.value
        reason.value = ''
        // The first read moves the report to under review and adds to the access log.
        router.reload({ only: ['report'], preserveScroll: true })
    } catch (err) {
        setFailedAlertData({ message: err.response?.data?.error ?? err.response?.data?.message ?? 'Could not load the conversation.' })
    } finally {
        loadingConversation.value = false
    }
}

const visibleConfidentialCount = computed(() =>
    (conversation.value ?? []).filter((m) => m.confidential && !m.hidden).length,
)
</script>

<template>
    <Head :title="`Report #${report.id}`" />

    <div>
        <div class="my-8 w-full max-w-6xl mx-auto space-y-6 px-4">
            <div>
                <Link :href="route('administrator.reports')" class="text-sm text-gray-600 hover:underline">&larr; all reports</Link>
                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <div class="text-2xl font-bold text-gray-900">Report #{{ report.id }}</div>
                    <ReportStatusBadge :status="report.status" />
                </div>
                <div class="w-16 h-1 bg-blue-600 mt-2"></div>
            </div>

            <!-- Details -->
            <section class="bg-white shadow-sm border border-gray-200 sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-900">What was reported</h3>
                <dl class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div>
                        <dt class="text-gray-500">About</dt>
                        <dd class="text-gray-900 capitalize">{{ report.subject.type }}<span v-if="report.subject.name" class="normal-case"> &middot; {{ report.subject.name }}</span></dd>
                    </div>
                    <div v-if="report.therapy">
                        <dt class="text-gray-500">Therapy</dt>
                        <dd class="text-gray-900">{{ report.therapy.name }} <span class="text-gray-500">({{ report.therapy.type }})</span></dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Reported by</dt>
                        <dd class="text-gray-900">{{ report.reporter?.name ?? '--' }} <span v-if="report.reporter" class="text-gray-500">({{ report.reporter.type }})</span></dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Submitted</dt>
                        <dd class="text-gray-900">{{ formatDate(report.createdAt) }}</dd>
                    </div>
                </dl>
                <div class="mt-4 text-sm">
                    <div class="text-gray-500">Description</div>
                    <p class="mt-1 text-gray-900 whitespace-pre-line">{{ report.description }}</p>
                </div>
                <div v-if="report.files.length" class="mt-4 text-sm">
                    <div class="text-gray-500">Evidence</div>
                    <ul class="mt-1 space-y-1">
                        <li v-for="file in report.files" :key="file.id">
                            <a :href="file.url" target="_blank" rel="noopener" class="text-blue-700 hover:underline">{{ file.name }}</a>
                        </li>
                    </ul>
                </div>
                <div v-if="!isActive" class="mt-4 text-sm border-t border-gray-100 pt-4">
                    <div class="text-gray-500">Closed {{ formatDate(report.resolvedAt) }}</div>
                    <div v-if="report.outcome" class="mt-1 text-gray-900 capitalize">Outcome: {{ readable(report.outcome) }}</div>
                    <p v-if="report.resolutionNote" class="mt-1 text-gray-900 whitespace-pre-line">{{ report.resolutionNote }}</p>
                </div>
            </section>

            <!-- SCRUM-398: an urgent help request -- what the reviewer needs, never who asked until revealed (recorded). -->
            <section v-if="report.helpRequest" class="bg-white shadow-sm border border-gray-200 sm:rounded-lg p-6 space-y-3">
                <h3 class="font-semibold text-gray-900">The urgent help request</h3>
                <dl class="grid grid-cols-[auto,1fr] gap-x-4 gap-y-1 text-sm">
                    <dt class="text-gray-600">Who</dt>
                    <dd class="text-gray-900">{{ report.helpRequest.person }} · {{ report.helpRequest.isMinor ? 'under 18' : 'adult' }}<template v-if="report.helpRequest.country"> · {{ report.helpRequest.country }}</template></dd>
                    <dt class="text-gray-600">Asked</dt><dd class="text-gray-900">{{ formatWhen(report.helpRequest.askedAt) }}</dd>
                    <template v-if="report.helpRequest.acceptedAt"><dt class="text-gray-600">Answered</dt><dd class="text-gray-900">{{ formatWhen(report.helpRequest.acceptedAt) }}</dd></template>
                    <template v-if="report.helpRequest.endedAt"><dt class="text-gray-600">Ended</dt><dd class="text-gray-900">{{ formatWhen(report.helpRequest.endedAt) }} <span class="text-gray-500 lowercase">({{ report.helpRequest.status }})</span></dd></template>
                </dl>
                <p v-if="report.helpRequest.note" class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-800">"{{ report.helpRequest.note }}"</p>
                <p v-if="report.helpRequest.transcriptDeleted" class="text-sm text-gray-600">The chat's messages have been deleted.</p>
                <div v-if="revealed" class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="status">
                    {{ revealed.name }} (@{{ revealed.username }})<template v-if="revealed.email"> · {{ revealed.email }}</template>
                    <span class="block text-xs text-amber-800">This look has been recorded in the read log.</span>
                </div>
                <div v-else class="flex flex-wrap items-center gap-3">
                    <SecondaryButton type="button" :disabled="revealing" @click="revealHelpRequester">{{ revealing ? 'Showing…' : 'Show who asked' }}</SecondaryButton>
                    <span class="text-xs text-gray-600">Every time you do, it is recorded in the read log.</span>
                </div>
                <p v-if="revealError" class="text-sm text-red-700" role="alert">{{ revealError }}</p>
            </section>

            <!-- Reported person -->
            <section class="bg-white shadow-sm border border-gray-200 sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-900">Reported person</h3>
                <p class="mt-1 text-sm text-gray-600">
                    Only messages this person sent or received can be read below.
                </p>
                <div class="mt-3 text-sm">
                    <span v-if="report.reportedPerson" class="text-gray-900">
                        {{ report.reportedPerson.name }}
                        <span class="text-gray-500">({{ report.reportedPerson.type }}<template v-if="report.reportedPerson.username">, @{{ report.reportedPerson.username }}</template>)</span>
                    </span>
                    <span v-else class="text-amber-700">Not recorded yet.</span>
                </div>

                <p v-if="isActive && partyLocked && !report.canChangeLockedParty" class="mt-4 text-sm text-gray-600">
                    Confidential messages have been read on this report, so only a super administrator can change who it is about.
                </p>

                <div v-else-if="isActive && report.participants.length" class="mt-4 flex flex-col sm:flex-row sm:items-end gap-3">
                    <div class="flex-1">
                        <InputLabel for="reported_person" value="Record who this report is about" />
                        <select
                            id="reported_person"
                            v-model="partyKey"
                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                        >
                            <option value="" disabled>choose a participant</option>
                            <option
                                v-for="person in report.participants"
                                :key="`${person.type}:${person.id}`"
                                :value="`${person.type}:${person.id}`"
                            >{{ person.name }} ({{ person.role }})</option>
                        </select>
                    </div>
                    <PrimaryButton :disabled="partyForm.processing || !partyKey || (needsPartyReason && partyForm.reason.trim().length < 10)" @click="recordParty">record</PrimaryButton>
                </div>
                <div v-if="isActive && partyLocked && report.canChangeLockedParty && needsPartyReason" class="mt-3">
                    <InputLabel for="party_reason" value="Why are you changing who this report is about? (kept in the history)" />
                    <textarea
                        id="party_reason"
                        v-model="partyForm.reason"
                        rows="2"
                        maxlength="1000"
                        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                    ></textarea>
                </div>
            </section>

            <!-- Review -->
            <section class="bg-white shadow-sm border border-gray-200 sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-900">Review</h3>

                <div v-if="!closing" class="mt-4 flex flex-wrap gap-2">
                    <PrimaryButton v-if="report.status === 'open'" :disabled="statusForm.processing" @click="() => submitStatus('under_review')">start review</PrimaryButton>
                    <PrimaryButton v-if="isActive" :disabled="statusForm.processing" @click="() => startClosing('resolved')">resolve</PrimaryButton>
                    <SecondaryButton v-if="isActive" :disabled="statusForm.processing" @click="() => startClosing('dismissed')">dismiss</SecondaryButton>
                    <SecondaryButton v-if="!isActive" :disabled="statusForm.processing" @click="() => submitStatus('under_review')">reopen</SecondaryButton>
                </div>

                <div v-else class="mt-4 space-y-3 max-w-xl">
                    <div v-if="closing === 'resolved'">
                        <InputLabel for="outcome" value="Outcome" />
                        <select
                            id="outcome"
                            v-model="statusForm.outcome"
                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm capitalize"
                        >
                            <option value="" disabled>choose an outcome</option>
                            <option v-for="outcome in outcomes" :key="outcome" :value="outcome">{{ readable(outcome) }}</option>
                        </select>
                        <InputError :message="statusForm.errors.outcome" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel for="note" :value="closing === 'resolved' ? 'What was decided' : 'Why it is being dismissed'" />
                        <TextBox id="note" v-model="statusForm.note" rows="3" class="mt-1 block w-full text-sm" />
                        <InputError :message="statusForm.errors.note" class="mt-1" />
                    </div>
                    <p class="text-xs text-gray-500">
                        The reporter and the reported person are told the report was closed. They are not told who
                        reviewed it, and the reported person is not told who reported them.
                    </p>
                    <div class="flex gap-2">
                        <DangerButton v-if="closing === 'dismissed'" :disabled="statusForm.processing" @click="() => submitStatus('dismissed')">dismiss report</DangerButton>
                        <PrimaryButton v-else :disabled="statusForm.processing" @click="() => submitStatus('resolved')">resolve report</PrimaryButton>
                        <SecondaryButton :disabled="statusForm.processing" @click="() => (closing = null)">cancel</SecondaryButton>
                    </div>
                </div>
            </section>

            <!-- SCRUM-359: Suspension -->
            <section v-if="report.suspensions.length || report.suspend.available || report.outcome === 'suspension_recommended'" class="bg-white shadow-sm border border-gray-200 sm:rounded-lg p-6 space-y-4">
                <h3 class="font-semibold text-gray-900">Suspension</h3>

                <SuspensionCard
                    v-for="suspension in report.suspensions"
                    :key="suspension.id"
                    :suspension="suspension"
                    :show-report-link="false"
                    @done="(message) => setSuccessAlertData({ message })"
                    @failed="(message) => setFailedAlertData({ message })"
                />

                <p v-if="report.suspend.revealFirst && report.outcome === 'suspension_recommended'" class="text-sm text-gray-600">
                    See who asked for help (above, recorded) before suspending them.
                </p>
                <p v-if="report.suspend.superAdminOnly && report.outcome === 'suspension_recommended'" class="text-sm text-gray-600">
                    Only a super administrator can suspend someone.
                </p>

                <div v-if="report.suspend.available && report.suspend.scopes.length" class="space-y-3">
                    <p class="text-sm text-gray-600">
                        Suspend {{ revealed?.name ?? report.reportedPerson?.name }}. They are told the dates and the reason you write for them,
                        never who reported them or who you are. Their clients are told they are unavailable, not why.
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <InputLabel for="suspend_scope" value="What it covers" />
                            <select id="suspend_scope" v-model="suspendForm.scope" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                                <option v-for="scope in report.suspend.scopes" :key="scope" :value="scope">
                                    {{ scope === 'account' ? 'Whole account (no access at all)' : 'Counsellor role only (can still be a client)' }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <InputLabel for="suspend_duration" value="How long" />
                            <select id="suspend_duration" v-model="suspendForm.duration" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                                <option v-for="days in report.suspend.durations" :key="days" :value="String(days)">{{ days }} days</option>
                                <option value="until_lifted">Until lifted</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <InputLabel for="suspend_reason" value="Reason the person will see" />
                        <textarea id="suspend_reason" v-model="suspendForm.public_reason" rows="3" maxlength="2000" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"></textarea>
                    </div>
                    <div>
                        <InputLabel for="suspend_note" value="Internal note (admins only, optional)" />
                        <textarea id="suspend_note" v-model="suspendForm.internal_note" rows="2" maxlength="2000" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"></textarea>
                    </div>
                    <DangerButton :disabled="suspendForm.processing || !suspendForm.scope || suspendForm.public_reason.trim().length < 10" @click="applySuspension">suspend</DangerButton>
                </div>
            </section>

            <!-- Conversation -->
            <section class="bg-white shadow-sm border border-gray-200 sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-900">Reported conversation</h3>

                <p v-if="!report.unlock.available" class="mt-2 text-sm text-gray-600">{{ report.unlock.reason }}</p>

                <template v-else>
                    <p class="mt-2 text-sm text-gray-600">
                        Reading the conversation shows confidential and deleted messages the reported person sent or
                        received. Your name, your reason and every such message shown are recorded, and both people
                        are told confidential messages were read when the report is closed. Other people's
                        confidential messages stay hidden.
                    </p>

                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <InputLabel for="session" value="Session" />
                            <select
                                id="session"
                                v-model="sessionId"
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                            >
                                <option v-for="session in report.unlock.sessions" :key="session.id" :value="session.id">
                                    {{ session.name }} &middot; {{ formatDate(session.startTime) }}
                                </option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel for="reason" value="Why do you need to read it?" />
                            <TextBox id="reason" v-model="reason" rows="2" class="mt-1 block w-full text-sm" />
                            <InputError :message="reasonError" class="mt-1" />
                        </div>
                    </div>
                    <div class="mt-3">
                        <PrimaryButton :disabled="loadingConversation || !sessionId" @click="readConversation">
                            {{ loadingConversation ? 'loading...' : 'read conversation' }}
                        </PrimaryButton>
                    </div>

                    <div v-if="conversation && conversationSessionId === sessionId" class="mt-6 border-t border-gray-100 pt-4">
                        <div class="text-xs text-gray-500 mb-3">
                            {{ conversation.length }} message(s) &middot; {{ visibleConfidentialCount }} confidential message(s) shown
                        </div>
                        <div v-if="conversation.length === 0" class="text-sm text-gray-500 italic">No messages in this session.</div>
                        <ol v-else class="space-y-3">
                            <li
                                v-for="message in conversation"
                                :key="message.id"
                                class="rounded-md border p-3 text-sm"
                                :class="message.fromReportedPerson ? 'border-blue-200 bg-blue-50' : 'border-gray-200 bg-white'"
                            >
                                <div class="flex flex-wrap items-center gap-2 text-xs text-gray-600">
                                    <span class="font-medium text-gray-900">{{ message.sender }}</span>
                                    <span v-if="message.recipient">&rarr; {{ message.recipient }}</span>
                                    <span>&middot; {{ formatDate(message.createdAt) }}</span>
                                    <span v-if="message.confidential" class="px-1.5 py-0.5 rounded border border-purple-200 bg-purple-50 text-purple-800">confidential</span>
                                    <span v-if="message.deletedForEveryone" class="px-1.5 py-0.5 rounded border border-red-200 bg-red-50 text-red-800">deleted for everyone</span>
                                </div>
                                <p v-if="message.hidden" class="mt-2 text-gray-500 italic">
                                    {{ message.confidential ? 'Confidential message' : 'Deleted message' }} between other people. Not part of this report.
                                </p>
                                <template v-else>
                                    <p class="mt-2 text-gray-900 whitespace-pre-line">{{ message.content }}</p>
                                    <ul v-if="message.files.length" class="mt-2 space-y-1">
                                        <li v-for="file in message.files" :key="file.id">
                                            <a :href="file.url" target="_blank" rel="noopener" class="text-blue-700 hover:underline">{{ file.name }}</a>
                                        </li>
                                    </ul>
                                </template>
                            </li>
                        </ol>
                    </div>
                </template>
            </section>

            <!-- History and access log -->
            <section class="bg-white shadow-sm border border-gray-200 sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-900">History</h3>
                <div v-if="report.history.length === 0" class="mt-2 text-sm text-gray-500 italic">Nothing has happened on this report yet.</div>
                <ol v-else class="mt-3 space-y-2 text-sm">
                    <li v-for="entry in report.history" :key="entry.id" class="border-l-2 border-gray-200 pl-3">
                        <div class="text-gray-900">
                            <template v-if="entry.event === 'status_change'">
                                {{ entry.by }} moved it from <span class="capitalize">{{ readable(entry.fromStatus) }}</span>
                                to <span class="capitalize">{{ readable(entry.toStatus) }}</span>
                                <span v-if="entry.outcome" class="capitalize"> ({{ readable(entry.outcome) }})</span>
                            </template>
                            <template v-else-if="entry.event === 'party_recorded'">{{ entry.by }} recorded the reported person</template>
                            <template v-else>{{ entry.by }} {{ historyLabels[entry.event] ?? readable(entry.event) }}</template>
                        </div>
                        <div class="text-xs text-gray-500">{{ formatDate(entry.at) }}</div>
                        <p v-if="entry.note" class="mt-1 text-gray-700 whitespace-pre-line">{{ entry.note }}</p>
                    </li>
                </ol>

                <h3 class="font-semibold text-gray-900 mt-6">Conversation access log</h3>
                <p class="mt-1 text-xs text-gray-500">
                    {{ report.confidentialMessagesRead }} confidential message read(s) recorded for this report.
                </p>
                <div v-if="report.accessLog.length === 0" class="mt-2 text-sm text-gray-500 italic">No one has read the conversation.</div>
                <ol v-else class="mt-3 space-y-2 text-sm">
                    <li v-for="access in report.accessLog" :key="access.id" class="border-l-2 border-purple-200 pl-3">
                        <div class="text-gray-900">{{ access.admin }} read {{ access.sessionName ? `the session '${access.sessionName}'` : 'a session' }}</div>
                        <div class="text-xs text-gray-500">{{ formatDate(access.at) }}</div>
                        <p class="mt-1 text-gray-700">{{ access.reason }}</p>
                    </li>
                </ol>
            </section>
        </div>
    </div>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>
