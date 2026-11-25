<script setup>
import AdminTable from '@/Components/Admin/AdminTable.vue'
import AdminRow from '@/Components/Admin/AdminRow.vue'
import AdminCell from '@/Components/Admin/AdminCell.vue'
import AdminRecognitionRow from '@/Components/Admin/AdminRecognitionRow.vue'
import AdminRecognitionWaiverOption from '@/Components/Admin/AdminRecognitionWaiverOption.vue'
import Alert from '@/Components/Alert.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import TextInput from '@/Components/TextInput.vue'
import useAlert from '@/Composables/useAlert'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { formatUtc } from '@/Support/commissionFree.js'
import { recognitionAlerts, recognitionEventLabel, STAR_CAP_FIELDS, waiverOptionErrors, waiverPrefill } from '@/Support/recognition.js'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { default as _ } from 'lodash'

// TT-7.11d2/SCRUM-474: counsellor recognition for a month (Admin/Recognition). Every administrator sees the
// suggestions, the month's audit and the star limits; only a super administrator (canManage) approves, skips,
// adds or changes a limit -- the server refuses anyone else (403) whatever this page shows.
//
// TT-7.11e2/SCRUM-477: approving (or adding) can also give the counsellor a commission-free period for the next month,
// linked to the recognition; the "Fee waiver" column shows the month's linked periods (feeWaivers, cut down by the
// server for an ordinary administrator and for the viewer's own row).
defineOptions({ layout: AdminLayout })

const props = defineProps({
    month: { type: String, required: true },
    months: { type: Array, required: true },
    // GetRecognitionSuggestionsAction's payload.
    recognition: { type: Object, required: true },
    events: { type: Array, default: () => [] },
    caps: { type: Object, required: true },
    // Each cap's name => the most it may be set to.
    capsMax: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
    ownCounsellorId: { type: Number, default: null },
    // AdminRecognitionFeeWaiverResource: the periods linked to this month's recognitions.
    feeWaivers: { type: Array, default: () => [] },
    // PlatformFeeWaiver::REASON_MAX.
    waiverReasonMax: { type: Number, required: true },
})

const { alertData, clearAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()
const page = usePage()

// A month in the URL that can't be decided comes back here (the default month) with the error.
const monthError = computed(() => page.props.errors?.month ?? null)

const monthLabel = computed(() => props.months.find((option) => option.value === props.month)?.label ?? props.month)
const atCap = computed(() => props.recognition.approvedCount >= props.recognition.perMonth)

// ---- Approve / skip / add ----

// The row waiting for its second click: { counsellorId, action }.
const confirming = ref(null)
const busyId = ref(null)

function isOwn(row) {
    return props.ownCounsellorId !== null && row.counsellorId === props.ownCounsellorId
}

function hasAlerts(row) {
    return recognitionAlerts(row.flags).length > 0
}

// A suggestion: approve unless already approved, skip unless already skipped.
function suggestionActions(row) {
    if (!props.canManage || isOwn(row)) return { approve: false, skip: false }

    return { approve: row.decision !== 'approved' && !atCap.value, skip: row.decision !== 'skipped' }
}

// A row no longer suggested: skip an approval; approve a skipped one again only while it is eligible (it goes through
// "add", as it isn't suggested).
function otherActions(row) {
    if (!props.canManage || isOwn(row)) return { approve: false, skip: false }

    return { approve: row.decision === 'skipped' && !hasAlerts(row) && !atCap.value, skip: row.decision === 'approved' }
}

function confirmingFor(row) {
    return confirming.value?.counsellorId === row.counsellorId ? confirming.value.action : null
}

// ---- The optional fee waiver when approving (TT-7.11e2/SCRUM-477) ----

// Only one approval is confirmed at a time (a row's, or an "add"), so one set of fields; reset on every ask.
const waiverOption = ref(newWaiverOption())
// A refused period after a saved approval: { heading, reason }, shown until dismissed, as the approval went through.
const waiverError = ref(null)
// The counsellor whose period is being posted after their approval: no other decision goes until it settles.
const waiverSaving = ref(null)
// Checked before the approval is posted (the confirm buttons stay disabled until it passes); the server checks again.
const waiverErrors = computed(() => waiverOptionErrors(waiverOption.value, props.waiverReasonMax))
const waiverInvalid = computed(() => Object.keys(waiverErrors.value).length > 0)

function newWaiverOption() {
    return { enabled: false, ...waiverPrefill(monthLabel.value) }
}

function ask(row, action) {
    if (action === 'approve') waiverOption.value = newWaiverOption()
    confirming.value = { counsellorId: row.counsellorId, action }
}

// The recognition just approved, from the reloaded props (the redirect back carries no body).
function recognitionIdFrom(page, counsellorId) {
    const recognition = page?.props?.recognition ?? props.recognition
    const row = [...recognition.suggestions, ...recognition.others].find((item) => item.counsellorId === counsellorId)

    return row?.decision === 'approved' ? row.recognitionId : null
}

// The first message of a refusal: a field error (422), or the service's message (403/404).
function waiverRefusal(error) {
    const data = error.response?.data
    const fieldMessage = data?.errors ? Object.values(data.errors).flat()[0] : null

    return fieldMessage ?? data?.message ?? 'The commission-free period could not be saved.'
}

// After the approval is saved: the period, linked to it. A refusal leaves the approval as it is, and is shown once, in
// the notice above the suggestions.
async function createWaiver(counsellorId, recognitionId, label, refusedHeading, option) {
    if (!recognitionId) {
        waiverError.value = { heading: refusedHeading, reason: 'The approved recognition could not be found. Reload the page and try again.' }
        return
    }

    waiverSaving.value = counsellorId

    await axios.post(route('admin.counsellors.fee-waivers.store', { counsellor: counsellorId }), {
        starts_at: option.starts_at,
        ends_at: option.ends_at,
        reason: option.reason,
        counsellor_recognition_id: recognitionId,
    })
        .then(() => {
            setSuccessAlertData({ message: `${label}, and the platform fee waived.` })
            router.reload({ only: ['feeWaivers'], preserveScroll: true })
        })
        .catch((error) => {
            waiverError.value = { heading: refusedHeading, reason: waiverRefusal(error) }
        })
        .finally(() => {
            waiverSaving.value = null
        })
}

// `refusedHeading`: the notice's heading if the period is then refused ("Ama approved without a fee waiver.").
function decide(name, counsellorId, label, { onSuccess, refusedHeading = 'Approved without a fee waiver.' } = {}) {
    // Read now: the option is reset by the next ask.
    const option = name !== 'skip' && waiverOption.value.enabled ? { ...waiverOption.value } : null
    // Never post an approval with a period that can only be refused, nor while the last period is being saved.
    if ((option && waiverInvalid.value) || waiverSaving.value !== null) return
    busyId.value = counsellorId
    waiverError.value = null

    router.post(route(`admin.recognition.${name}`), { month: props.month, counsellor_id: counsellorId }, {
        preserveScroll: true,
        onSuccess: (page) => {
            onSuccess?.()
            if (option) {
                createWaiver(counsellorId, recognitionIdFrom(page, counsellorId), label, refusedHeading, option)
            } else {
                setSuccessAlertData({ message: `${label}.` })
            }
        },
        onError: (errors) => setFailedAlertData({
            message: errors.counsellor_id ?? errors.month ?? errors.alert ?? 'Could not save this decision.',
        }),
        onFinish: () => {
            busyId.value = null
            confirming.value = null
        },
    })
}

// ---- The fee waiver column: end now / delete (super admin) ----

const waiverByRecognition = computed(() => new Map(props.feeWaivers.map((waiver) => [waiver.recognitionId, waiver])))
// The waiver waiting for its second click: { id, kind }.
const confirmingWaiver = ref(null)
const waiverBusyId = ref(null)

function waiverFor(row) {
    return row.recognitionId ? (waiverByRecognition.value.get(row.recognitionId) ?? null) : null
}

function waiverConfirmingFor(row) {
    const waiver = waiverFor(row)

    return waiver && confirmingWaiver.value?.id === waiver.id ? confirmingWaiver.value.kind : null
}

function actOnWaiver(row, kind) {
    const waiver = waiverFor(row)
    if (!waiver) return
    const name = kind === 'end' ? 'admin.counsellors.fee-waivers.end' : 'admin.counsellors.fee-waivers.delete'
    const who = row.name ?? 'the counsellor'
    waiverBusyId.value = waiver.id

    router.post(route(name, { counsellor: waiver.counsellorId, waiver: waiver.id }), {}, {
        preserveScroll: true,
        onSuccess: () => setSuccessAlertData({ message: kind === 'end' ? `The fee waiver for ${who} ended now.` : `The fee waiver for ${who} deleted.` }),
        onError: (errors) => setFailedAlertData({ message: errors.waiver ?? errors.alert ?? 'Could not change this fee waiver.' }),
        onFinish: () => {
            waiverBusyId.value = null
            confirmingWaiver.value = null
        },
    })
}

function confirmAction(row, action, isSuggestion) {
    const name = action === 'skip' ? 'skip' : (isSuggestion ? 'approve' : 'add')
    const who = row.name ?? 'The counsellor'

    decide(name, row.counsellorId, action === 'skip' ? `${who} skipped for ${monthLabel.value}` : `${who} approved for ${monthLabel.value}`, {
        refusedHeading: `${who} approved without a fee waiver.`,
    })
}

// ---- Add a counsellor (super admin) ----

const search = ref('')
const searchResults = ref([])
const searching = ref(false)
const searchError = ref('')
const adding = ref(null)
let latestSearch = 0

const debouncedSearch = _.debounce(() => runSearch(), 400)

// Below 2 characters: drop any search still waiting or on its way, so a late answer can't fill the list again.
function stopSearch() {
    debouncedSearch.cancel()
    latestSearch++
    searching.value = false
}

watch(search, () => {
    searchError.value = ''
    if (search.value.trim().length < 2) {
        stopSearch()
        searchResults.value = []
        return
    }
    debouncedSearch()
})

onBeforeUnmount(() => stopSearch())

async function runSearch() {
    const ticket = ++latestSearch
    searching.value = true

    await axios.get(route('admin.recognition.counsellors', { month: props.month, search: search.value.trim() }))
        .then((res) => {
            if (ticket === latestSearch) searchResults.value = res.data.counsellors
        })
        .catch((error) => {
            if (ticket !== latestSearch) return
            searchResults.value = []
            searchError.value = error.response?.data?.message ?? 'Could not search counsellors right now.'
        })
        .finally(() => {
            if (ticket === latestSearch) searching.value = false
        })
}

// The add panel's waiver option (one at a time), to move focus into it when it opens.
let addOption = null

async function askToAdd(counsellor) {
    waiverOption.value = newWaiverOption()
    adding.value = counsellor
    await nextTick()
    addOption?.focus()
}

function confirmAdd() {
    const counsellor = adding.value

    decide('add', counsellor.id, `${counsellor.name} added for ${monthLabel.value}`, {
        refusedHeading: `${counsellor.name} added without a fee waiver.`,
        onSuccess: () => {
            adding.value = null
            search.value = ''
            searchResults.value = []
        },
    })
}

// ---- Star limits ----

const capsForm = useForm({ caps: { ...props.caps } })

function saveCaps() {
    capsForm.post(route('admin.settings.recognition-caps.update'), {
        preserveScroll: true,
        onSuccess: () => setSuccessAlertData({ message: 'Star limits saved.' }),
        onError: (errors) => {
            if (errors.alert) setFailedAlertData({ message: errors.alert })
        },
    })
}

function capError(key) {
    return capsForm.errors[`caps.${key}`] ?? null
}

// ---- Tables ----

const rowColumns = [
    { label: 'Rank' },
    { label: 'Counsellor' },
    { label: 'Stars' },
    { label: 'First star (GMT)' },
    { label: 'Alerts' },
    { label: 'Decision and consent' },
    { label: 'Fee waiver' },
    { label: 'Actions', srOnly: true },
]

const eventColumns = [
    { label: 'When (GMT)' },
    { label: 'Counsellor' },
    { label: 'What' },
    { label: 'By' },
]
</script>

<template>
    <Head title="Counsellor recognition" />

    <div>
        <div class="my-8 w-full max-w-7xl mx-auto space-y-8 px-4">

            <!-- Month and summary -->
            <section aria-labelledby="recognition-heading" class="bg-white border border-gray-200 shadow-sm rounded-lg p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 id="recognition-heading" class="text-lg font-medium text-gray-900">Counsellor recognition</h1>
                        <p class="mt-1 text-sm text-gray-600 max-w-2xl">
                            Counsellors ranked by the stars they earned in the month (UTC), among those who can be
                            recognised now: verified, not suspended, and not the subject of an open report.
                            <template v-if="!canManage">Only a super administrator can approve, skip or add a counsellor.</template>
                        </p>
                    </div>

                    <nav aria-label="Month" class="inline-flex rounded-md border border-gray-300 overflow-hidden">
                        <Link
                            v-for="option in months"
                            :key="option.value"
                            :href="route('administrator.recognition', { month: option.value })"
                            :aria-current="option.value === month ? 'page' : undefined"
                            preserve-scroll
                            class="px-4 py-2 min-h-[44px] inline-flex items-center text-sm border-r last:border-r-0 border-gray-300"
                            :class="option.value === month ? 'bg-gray-800 text-white font-medium' : 'bg-white text-gray-700 hover:bg-gray-50'"
                        >{{ option.label }}</Link>
                    </nav>
                </div>

                <p v-if="monthError" class="mt-3 text-sm text-red-700" role="alert">{{ monthError }} Showing {{ monthLabel }}.</p>

                <div class="mt-4 flex flex-wrap items-center gap-3 text-sm">
                    <span class="inline-flex items-center rounded-md border px-3 py-1.5 font-medium"
                        :class="atCap ? 'bg-green-50 border-green-200 text-green-800' : 'bg-gray-50 border-gray-200 text-gray-800'">
                        {{ recognition.approvedCount }} of {{ recognition.perMonth }} approved for {{ monthLabel }}
                    </span>
                    <span v-if="atCap && canManage" class="text-gray-600">The month is full: skip an approval to make room.</span>
                </div>

                <div v-if="recognition.unattributedOpenReports > 0" class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    <span class="font-medium">{{ recognition.unattributedOpenReports }} open {{ recognition.unattributedOpenReports === 1 ? 'report' : 'reports' }} not counted against anyone.</span>
                    {{ recognition.unattributedOpenReportsNote }}
                </div>
            </section>

            <!-- A period refused after its approval was saved (TT-7.11e2/SCRUM-477) -->
            <div v-if="waiverError" role="alert" class="flex items-start justify-between gap-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                <p><span class="font-medium">{{ waiverError.heading }}</span> {{ waiverError.reason }}</p>
                <button type="button" class="shrink-0 min-h-[44px] md:min-h-0 rounded-md border border-red-300 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500" @click="waiverError = null">dismiss</button>
            </div>

            <!-- Suggestions -->
            <section aria-labelledby="suggestions-heading" class="space-y-3">
                <h2 id="suggestions-heading" class="text-lg font-medium text-gray-900">Suggestions for {{ monthLabel }}</h2>
                <AdminTable :columns="rowColumns" :caption="`Suggestions for ${monthLabel}`" :empty="!recognition.suggestions.length" empty-text="Nobody eligible earned a star this month.">
                    <AdminRecognitionRow
                        v-for="row in recognition.suggestions"
                        :key="row.counsellorId"
                        :row="row"
                        :can-approve="suggestionActions(row).approve"
                        :can-skip="suggestionActions(row).skip"
                        :confirming="confirmingFor(row)"
                        :busy="busyId === row.counsellorId || waiverSaving !== null"
                        :confirm-disabled="waiverInvalid"
                        :waiver="waiverFor(row)"
                        :waiver-confirming="waiverConfirmingFor(row)"
                        :waiver-busy="waiverBusyId !== null && waiverBusyId === waiverFor(row)?.id"
                        :column-count="rowColumns.length"
                        @ask="(action) => ask(row, action)"
                        @confirm="(action) => confirmAction(row, action, true)"
                        @cancel="confirming = null"
                        @waiver-ask="(kind) => confirmingWaiver = { id: waiverFor(row).id, kind }"
                        @waiver-confirm="(kind) => actOnWaiver(row, kind)"
                        @waiver-cancel="confirmingWaiver = null"
                    >
                        <template v-if="canManage && !waiverFor(row)" #approve-options>
                            <AdminRecognitionWaiverOption v-model="waiverOption" :id-prefix="`waiver-suggestions-${row.counsellorId}`" :disabled="busyId === row.counsellorId" :errors="waiverErrors" :reason-max="waiverReasonMax" />
                        </template>
                    </AdminRecognitionRow>
                </AdminTable>
            </section>

            <!-- Others: decided this month, no longer suggested -->
            <section v-if="recognition.others.length" aria-labelledby="others-heading" class="space-y-3">
                <div>
                    <h2 id="others-heading" class="text-lg font-medium text-gray-900">Also decided for {{ monthLabel }}</h2>
                    <p class="mt-1 text-sm text-gray-600">Added by hand, or no longer suggested -- for example, suspended since they were approved.</p>
                </div>
                <AdminTable :columns="rowColumns" :caption="`Other counsellors decided for ${monthLabel}`">
                    <AdminRecognitionRow
                        v-for="row in recognition.others"
                        :key="row.counsellorId"
                        :row="row"
                        :can-approve="otherActions(row).approve"
                        :can-skip="otherActions(row).skip"
                        :confirming="confirmingFor(row)"
                        :busy="busyId === row.counsellorId || waiverSaving !== null"
                        :confirm-disabled="waiverInvalid"
                        :waiver="waiverFor(row)"
                        :waiver-confirming="waiverConfirmingFor(row)"
                        :waiver-busy="waiverBusyId !== null && waiverBusyId === waiverFor(row)?.id"
                        :column-count="rowColumns.length"
                        @ask="(action) => ask(row, action)"
                        @confirm="(action) => confirmAction(row, action, false)"
                        @cancel="confirming = null"
                        @waiver-ask="(kind) => confirmingWaiver = { id: waiverFor(row).id, kind }"
                        @waiver-confirm="(kind) => actOnWaiver(row, kind)"
                        @waiver-cancel="confirmingWaiver = null"
                    >
                        <template v-if="canManage && !waiverFor(row)" #approve-options>
                            <AdminRecognitionWaiverOption v-model="waiverOption" :id-prefix="`waiver-others-${row.counsellorId}`" :disabled="busyId === row.counsellorId" :errors="waiverErrors" :reason-max="waiverReasonMax" />
                        </template>
                    </AdminRecognitionRow>
                </AdminTable>
            </section>

            <!-- Add a counsellor (super admin) -->
            <section v-if="canManage" aria-labelledby="add-heading" class="bg-white border border-gray-200 shadow-sm rounded-lg p-6">
                <h2 id="add-heading" class="text-lg font-medium text-gray-900">Add a counsellor</h2>
                <p class="mt-1 text-sm text-gray-600">Recognise someone who isn't suggested for {{ monthLabel }}. Only counsellors who can be recognised now, and aren't already listed above, are found.</p>

                <div class="mt-4 max-w-xl">
                    <InputLabel for="recognitionSearch" value="Name or username" />
                    <TextInput id="recognitionSearch" v-model="search" type="search" autocomplete="off" maxlength="100" class="mt-1 block w-full" placeholder="at least 2 characters" />
                    <p v-if="searching" class="mt-1 text-xs text-gray-500">searching...</p>
                    <InputError class="mt-2" :message="searchError" />

                    <ul v-if="searchResults.length" class="mt-2 divide-y divide-gray-100 border border-gray-200 rounded-md bg-white">
                        <li v-for="counsellor in searchResults" :key="counsellor.id" class="flex flex-wrap items-center justify-between gap-3 px-3 py-2 text-sm">
                            <span><span class="font-medium text-gray-900">{{ counsellor.name }}</span> <span class="text-gray-500">@{{ counsellor.username }}</span></span>
                            <SecondaryButton v-if="adding?.id !== counsellor.id" :disabled="atCap" @click="askToAdd(counsellor)">add</SecondaryButton>
                            <div v-else class="basis-full rounded-md border border-gray-200 bg-gray-50 p-3">
                                <p class="font-medium text-gray-900">Add {{ counsellor.name }} for {{ monthLabel }}</p>
                                <AdminRecognitionWaiverOption :ref="(el) => addOption = el" v-model="waiverOption" class="mt-2" :id-prefix="`waiver-add-${counsellor.id}`" :disabled="busyId === counsellor.id" :errors="waiverErrors" :reason-max="waiverReasonMax" />
                                <span class="mt-3 flex gap-2">
                                    <PrimaryButton :disabled="busyId === counsellor.id || waiverSaving !== null || waiverInvalid" @click="confirmAdd">confirm add</PrimaryButton>
                                    <SecondaryButton :disabled="busyId === counsellor.id" @click="adding = null">cancel</SecondaryButton>
                                </span>
                            </div>
                        </li>
                    </ul>
                    <p v-else-if="search.trim().length >= 2 && !searching && !searchError" class="mt-2 text-sm text-gray-500">No counsellor who can be recognised matches.</p>
                </div>
            </section>

            <!-- Audit -->
            <section aria-labelledby="events-heading" class="space-y-3">
                <div>
                    <h2 id="events-heading" class="text-lg font-medium text-gray-900">What happened in {{ monthLabel }}</h2>
                    <p class="mt-1 text-sm text-gray-600">Every decision and consent change on this month's recognitions, most recent first.</p>
                </div>
                <AdminTable :columns="eventColumns" :caption="`Recognition history for ${monthLabel}`" :empty="!events.length" empty-text="Nothing decided for this month yet.">
                    <AdminRow v-for="event in events" :key="event.id">
                        <AdminCell label="When (GMT)" half><span class="whitespace-nowrap">{{ formatUtc(event.at) }}</span></AdminCell>
                        <AdminCell label="Counsellor" half>{{ event.counsellorName ?? 'Deleted counsellor' }}</AdminCell>
                        <AdminCell label="What" half>{{ recognitionEventLabel(event) }}</AdminCell>
                        <AdminCell label="By" half>{{ event.actorName ?? '--' }}</AdminCell>
                    </AdminRow>
                </AdminTable>
            </section>

            <!-- Star limits -->
            <section aria-labelledby="caps-heading" class="bg-white border border-gray-200 shadow-sm rounded-lg p-6">
                <h2 id="caps-heading" class="text-lg font-medium text-gray-900">Star limits</h2>
                <p class="mt-1 text-sm text-gray-600">
                    The most stars a counsellor can earn in a UTC day from each source, and from any one post's likes and comments.
                    <template v-if="!canManage">Only a super administrator can change them.</template>
                </p>

                <form v-if="canManage" class="mt-6" @submit.prevent="saveCaps">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="field in STAR_CAP_FIELDS" :key="field.key">
                            <InputLabel :for="`cap-${field.key}`" :value="field.label" />
                            <TextInput
                                :id="`cap-${field.key}`"
                                v-model="capsForm.caps[field.key]"
                                type="number"
                                min="0"
                                :max="capsMax[field.key]"
                                step="1"
                                required
                                class="mt-1 block w-full"
                                :aria-describedby="`cap-${field.key}-hint`"
                            />
                            <p :id="`cap-${field.key}-hint`" class="mt-1 text-xs text-gray-500">{{ field.hint }}</p>
                            <InputError class="mt-1" :message="capError(field.key)" />
                        </div>
                    </div>
                    <div class="mt-4 flex items-center justify-between gap-4">
                        <p class="text-xs text-gray-500">Whole numbers from 0 to {{ capsMax.session }} ({{ capsMax.session_per_client }} for the per-client and per-group limits, likes and comments per client included). 0 stops that source earning stars.</p>
                        <PrimaryButton :class="{ 'opacity-25': capsForm.processing }" :disabled="capsForm.processing">save limits</PrimaryButton>
                    </div>
                </form>

                <dl v-else class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="field in STAR_CAP_FIELDS" :key="field.key" class="rounded-md border border-gray-200 p-3">
                        <dt class="text-sm font-medium text-gray-700">{{ field.label }}</dt>
                        <dd class="mt-1 text-2xl font-semibold text-gray-900">{{ caps[field.key] }}</dd>
                        <dd class="text-xs text-gray-500">{{ field.hint }}</dd>
                    </div>
                </dl>
            </section>
        </div>

        <Alert
            :show="alertData.show"
            :type="alertData.type"
            :message="alertData.message"
            :time="alertData.time"
            @close="clearAlertData"
        />
    </div>
</template>
