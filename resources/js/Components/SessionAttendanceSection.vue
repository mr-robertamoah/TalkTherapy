<template>
    <!-- A counsellor of the therapy or group (or an uninvolved administrator): the register, in its own dialog. -->
    <div v-if="registerViewer" v-bind="$attrs" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
        <h3 class="text-sm font-semibold text-gray-800">Attendance</h3>
        <p class="mt-1 text-sm text-gray-600">{{ summary }}</p>
        <PrimaryButton type="button" class="mt-3" @click="showRegister = true">{{ canRecord ? 'mark attendance' : 'view attendance' }}</PrimaryButton>
        <SessionAttendanceModal
            :show="showRegister"
            :session="session"
            @close="showRegister = false"
            @saved="onSaved"
            @updated="(updated) => emits('updated', updated)"
        />
    </div>
    <!-- A client or member: only their own mark, and nothing while they have none. -->
    <p v-else-if="ownMark" v-bind="$attrs" class="rounded-lg border border-gray-200 bg-white p-3 text-sm text-gray-700 shadow-sm">
        Your counsellor marked you <span class="font-semibold">{{ ownMark.status === 'PRESENT' ? 'present' : 'absent' }}</span>
        (on {{ formatDate(ownMark.markedAt) }}).
    </p>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PrimaryButton from './PrimaryButton.vue';
import SessionAttendanceModal from './SessionAttendanceModal.vue';
import useLocalDateTimed from '@/Composables/useLocalDateTime';

// SCRUM-446 (SCRUM-115): attendance on an in-person session that has started. A session fetched on its own
// (api.sessions.show -- the live refresh after SessionUpdatedEvent too) already carries the viewer's own mark; a list's
// copy doesn't, so it is asked for. A viewer who may not read it gets nothing shown.

defineOptions({ inheritAttrs: false })

const props = defineProps({
    session: { default: null },
})

const emits = defineEmits(['updated'])

const { toLocalDate } = useLocalDateTimed()

const data = ref(null)
const showRegister = ref(false)

const applies = computed(() => !!usePage().props.auth?.user && props.session?.type === 'IN_PERSON'
    && !!props.session?.startTime && toLocalDate(props.session.startTime) <= new Date())

// The session's own copy is enough for a client or member: it has viewerAttendance and no register.
const carriesOwnMark = computed(() => !!props.session && 'viewerAttendance' in props.session && !('attendance' in props.session))

const registerViewer = computed(() => Array.isArray(data.value?.register))
const canRecord = computed(() => !!data.value?.canRecord)
const ownMark = computed(() => carriesOwnMark.value ? props.session.viewerAttendance : data.value?.viewerAttendance)

const summary = computed(() => {
    const register = data.value?.register ?? []
    const marked = register.filter((person) => person.status).length

    if (!register.length) return 'Nobody is on the register.'

    return `${marked} of ${register.length} marked.`
})

// Asked again whenever the session is refreshed (a new object), so a mark made elsewhere shows.
watch(() => props.session, (session, previous) => {
    // Another session: what was shown for the last one never stays.
    if (session?.id !== previous?.id) data.value = null
    if (!applies.value || carriesOwnMark.value) {
        data.value = null
        return
    }
    load()
}, { immediate: true })

// The save's own response is the fresh register -- no second request.
function onSaved(payload) {
    if (payload) data.value = payload
}

async function load() {
    const sessionId = props.session?.id
    await axios.get(route('api.session.attendance.index', { sessionId }))
        .then((res) => { if (props.session?.id === sessionId) data.value = res.data })
        // Not theirs to read (an outsider, a guest of a public therapy -- 403/422), or nothing loaded yet: nothing is
        // shown. A later reload that fails for another reason (a network blip) keeps what was shown.
        .catch((err) => {
            if (props.session?.id !== sessionId) return
            if (!data.value || [403, 422].includes(err.response?.status)) data.value = null
        })
}

function formatDate(value) {
    return toLocalDate(value)?.toLocaleDateString(undefined, { dateStyle: 'medium' })
}
</script>
