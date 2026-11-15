<template>
    <Modal :show="show" max-width="lg" :labelledby="titleId" @close="emits('close')">
        <!-- QA: Modal's panel has no padding of its own (MiniModal's has p-6). -->
        <div class="relative p-6">
            <h2 :id="titleId" ref="heading" tabindex="-1" class="text-lg font-semibold text-gray-800 focus:outline-none">Attendance</h2>
            <p class="text-sm text-gray-600">{{ session?.name }}</p>

            <hr class="my-3">

            <FormLoader class="mx-auto" :show="loading" text="getting the register" />

            <p v-if="error" role="alert" class="mb-3 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ error }}</p>
            <div role="status">
                <p v-if="success" class="mb-3 rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-900">{{ success }}</p>
            </div>

            <template v-if="data?.register">
                <p class="mb-3 text-sm text-gray-700">{{ windowText }}</p>

                <p v-if="!data.register.length" class="text-sm text-gray-600">There is nobody on this session's register.</p>

                <!-- Editable: a Present/Absent choice per person (radio buttons, so arrow keys move between them). -->
                <form v-else-if="data.canRecord" @submit.prevent="save">
                    <ul class="divide-y divide-gray-200 rounded-lg border border-gray-200">
                        <li v-for="person in data.register" :key="person.key" class="p-3">
                            <fieldset class="flex flex-wrap items-center justify-between gap-2">
                                <legend class="float-left text-sm font-medium text-gray-800">{{ person.name }}</legend>
                                <div class="inline-flex overflow-hidden rounded-md border border-gray-300">
                                    <label v-for="option in OPTIONS" :key="option.value" class="cursor-pointer">
                                        <input
                                            v-model="marks[person.key]"
                                            type="radio"
                                            class="peer sr-only"
                                            :name="`attendance-${session.id}-${person.key}`"
                                            :value="option.value"
                                            :disabled="saving"
                                        />
                                        <span
                                            class="block px-3 py-1.5 text-sm text-gray-700 peer-checked:font-semibold peer-focus-visible:ring-2 peer-focus-visible:ring-inset peer-focus-visible:ring-indigo-500"
                                            :class="option.value === 'PRESENT' ? 'peer-checked:bg-green-700 peer-checked:text-white' : 'border-l border-gray-300 peer-checked:bg-red-700 peer-checked:text-white'"
                                        >{{ option.label }}</span>
                                    </label>
                                </div>
                            </fieldset>
                            <p v-if="person.markedAt" class="mt-1 text-xs text-gray-500">Last marked {{ formatDateTime(person.markedAt) }}</p>
                        </li>
                    </ul>
                    <p v-if="unmarkedCount" class="mt-2 text-xs text-gray-600">
                        {{ unmarkedCount === 1 ? 'One person has' : `${unmarkedCount} people have` }} no mark yet. Saving leaves them unmarked.
                    </p>

                    <div class="mt-4 flex flex-wrap justify-end gap-2">
                        <SecondaryButton type="button" :disabled="saving" @click="emits('close')">close</SecondaryButton>
                        <PrimaryButton type="submit" :disabled="saving || !dirty">{{ saving ? 'saving...' : 'save attendance' }}</PrimaryButton>
                    </div>
                </form>

                <!-- Read-only: the window is closed, the session is final, or the viewer can't mark it. -->
                <template v-else>
                    <ul class="divide-y divide-gray-200 rounded-lg border border-gray-200">
                        <li v-for="person in data.register" :key="person.key" class="flex flex-wrap items-center justify-between gap-2 p-3">
                            <span class="text-sm font-medium text-gray-800">{{ person.name }}</span>
                            <span class="text-sm" :class="statusClass(person.status)">{{ statusLabel(person.status) }}</span>
                        </li>
                    </ul>
                    <div class="mt-4 flex justify-end">
                        <SecondaryButton type="button" @click="emits('close')">close</SecondaryButton>
                    </div>
                </template>

                <!-- SCRUM-445: the server checks the rest (nobody on the client side asked to start it). -->
                <div v-if="canOfferNoShow" class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-3">
                    <p class="text-sm text-gray-700">Everyone is marked absent. If nobody came, you can close this session as a no-show.</p>
                    <DangerButton type="button" class="mt-2" :disabled="closing" @click="confirmingNoShow = true">close as a no-show</DangerButton>
                </div>
            </template>
        </div>

        <MiniModal :show="confirmingNoShow" max-width="md" :labelledby="`${titleId}-no-show`" @close="confirmingNoShow = false">
            <div class="relative">
                <FormLoader class="mx-auto" :danger="true" :show="closing" text="closing the session" />
                <h3 :id="`${titleId}-no-show`" class="text-center font-bold tracking-wide text-red-700">Close as a no-show?</h3>
                <hr class="my-2">
                <p class="my-3 text-sm text-gray-700">
                    This marks "{{ session?.name }}" as failed: it did not take place, and nobody is charged for it.
                    You can't undo this, and its attendance can't be changed afterwards.
                </p>
                <div class="flex justify-end gap-2">
                    <SecondaryButton type="button" :disabled="closing" @click="confirmingNoShow = false">cancel</SecondaryButton>
                    <DangerButton type="button" :disabled="closing" @click="closeAsNoShow">close as a no-show</DangerButton>
                </div>
            </div>
        </MiniModal>
    </Modal>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import Modal from './Modal.vue';
import MiniModal from './MiniModal.vue';
import FormLoader from './FormLoader.vue';
import PrimaryButton from './PrimaryButton.vue';
import SecondaryButton from './SecondaryButton.vue';
import DangerButton from './DangerButton.vue';
import useLocalDateTimed from '@/Composables/useLocalDateTime';

// SCRUM-446 (SCRUM-115): a counsellor marks who came to an in-person session, from its start until 48 hours after its
// end (the server's window, recordableUntil); read-only once that closes, the session is final, or for an
// administrator (canRecord). People are named as the server gives them -- masked ones stay masked.

const OPTIONS = [
    { value: 'PRESENT', label: 'Present' },
    { value: 'ABSENT', label: 'Absent' },
]
// A no-show close is only for a session that never started (the server also checks who asked to start it).
const NEVER_STARTED = ['PENDING', 'IN_SESSION_CONFIRMATION']
const FINAL = ['FAILED', 'ABANDONED']

const props = defineProps({
    show: { type: Boolean, default: false },
    session: { default: null },
})

const emits = defineEmits(['close', 'saved', 'updated'])

const { toLocalDate } = useLocalDateTimed()

const heading = ref(null)
const titleId = computed(() => `session-attendance-${props.session?.id}`)
const data = ref(null)
const marks = ref({})
const loading = ref(false)
const saving = ref(false)
const closing = ref(false)
const confirmingNoShow = ref(false)
const error = ref('')
const success = ref('')
// The status after a no-show close here, before the parent's copy of the session catches up.
const closedStatus = ref(null)
const now = ref(new Date())

const status = computed(() => closedStatus.value ?? props.session?.status)

// Each source on its own: a live refresh replaces the session object (same id) and must not reset the panel.
watch([() => props.show, () => props.session?.id], ([show]) => {
    if (!show) return
    error.value = ''
    success.value = ''
    closedStatus.value = null
    confirmingNoShow.value = false
    load()
}, { immediate: true })

async function load() {
    loading.value = true
    await axios.get(route('api.session.attendance.index', { sessionId: props.session.id }))
        .then((res) => setData(res.data))
        .catch((err) => { error.value = messageOf(err, 'The register could not be loaded. Please try again.') })
        .finally(() => { loading.value = false })
}

function setData(value) {
    data.value = value
    now.value = new Date()
    marks.value = Object.fromEntries((value?.register ?? []).map((person) => [person.key, person.status]))
}

const dirty = computed(() => (data.value?.register ?? []).some((person) => marks.value[person.key] && marks.value[person.key] !== person.status))
const unmarkedCount = computed(() => (data.value?.register ?? []).filter((person) => !marks.value[person.key]).length)

const windowText = computed(() => {
    const until = data.value?.recordableUntil ? formatDateTime(data.value.recordableUntil) : null

    if (data.value?.canRecord) return `You can mark attendance until ${until}.`
    if (FINAL.includes(status.value)) return 'This session was closed, so its attendance can no longer be changed.'
    if (until && now.value > toLocalDate(data.value.recordableUntil)) return `Attendance could be marked until ${until}. It can no longer be changed.`
    if (data.value?.recordableFrom && now.value < toLocalDate(data.value.recordableFrom)) return 'You can mark attendance once the session has started.'

    return 'You can view this attendance, but not change it.'
})

const canOfferNoShow = computed(() => {
    const register = data.value?.register ?? []

    return !!data.value?.canRecord && !dirty.value && register.length > 0
        && register.every((person) => person.status === 'ABSENT')
        && NEVER_STARTED.includes(status.value)
        && !!props.session?.endTime && now.value > toLocalDate(props.session.endTime)
})

async function save() {
    if (saving.value || !dirty.value) return
    saving.value = true
    error.value = ''
    success.value = ''

    // Everyone with a choice -- the server keeps what the others had.
    const attendance = (data.value?.register ?? [])
        .filter((person) => marks.value[person.key])
        .map((person) => ({ key: person.key, status: marks.value[person.key] }))

    await axios.post(route('api.session.attendance.store', { sessionId: props.session.id }), { attendance })
        .then((res) => {
            setData(res.data)
            success.value = 'Attendance saved.'
            emits('saved', res.data)
        })
        .catch((err) => { error.value = messageOf(err, 'Attendance could not be saved. Please try again.') })
        .finally(() => { saving.value = false })
}

async function closeAsNoShow() {
    if (closing.value) return
    closing.value = true
    error.value = ''
    success.value = ''

    await axios.post(route('api.session.attendance.close_no_show', { sessionId: props.session.id }))
        .then((res) => {
            closedStatus.value = res.data.session?.status ?? 'FAILED'
            success.value = 'The session was closed as a no-show. Nobody is charged for it.'
            emits('updated', res.data.session)
            return load()
        })
        // QA: the button that was clicked is gone (the register is read-only now) -- focus goes to the heading, with
        // the status message right under it.
        .then(() => nextTick(() => heading.value?.focus()))
        .catch((err) => { error.value = messageOf(err, 'The session could not be closed. Please try again.') })
        .finally(() => {
            closing.value = false
            confirmingNoShow.value = false
        })
}

function messageOf(err, fallback) {
    return err.response?.data?.message || fallback
}

function formatDateTime(value) {
    return toLocalDate(value)?.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}

function statusLabel(value) {
    return value === 'PRESENT' ? 'Present' : (value === 'ABSENT' ? 'Absent' : 'Not marked')
}

function statusClass(value) {
    return value === 'PRESENT' ? 'font-semibold text-green-800' : (value === 'ABSENT' ? 'font-semibold text-red-800' : 'text-gray-500')
}
</script>
