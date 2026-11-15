<script setup>
// SCRUM-37 (TT-5.2): a 1:1 therapy's outcome check-ins (PHQ-9, GAD-7). Its counsellor sends one, withdraws one still
// waiting, sees every answer and the scores over time, and acknowledges an answer that needs attention. Its client
// answers (every question, once) and sees their own. A self-harm answer (PHQ-9 item 9 above "Not at all") shows the
// client urgent help at once -- as they choose it, before anything is sent -- and again after. Fetched as JSON, never
// page props. Questionnaire wording is the published text, word for word.
import { computed, nextTick, onMounted, ref } from 'vue'
import axios from 'axios'
import MiniModal from '@/Components/MiniModal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import InputError from '@/Components/InputError.vue'
import EmergencyNumbersPanel from '@/Components/HelpNow/EmergencyNumbersPanel.vue'
import OutcomeScoreChart from '@/Components/CheckIns/OutcomeScoreChart.vue'
import useHelpNow from '@/Composables/useHelpNow'

const props = defineProps({
    therapyId: { type: [Number, String], required: true },
})

const { show: showHelp } = useHelpNow()

const loaded = ref(false)
const loadError = ref('')
const checkIns = ref([])
const measures = ref([])
const canSend = ref(false)
const viewerIs = ref('client')
const status = ref('')
const actionError = ref('')
const resultText = ref(null)
const highlighted = ref(null)

const isCounsellor = computed(() => viewerIs.value === 'counsellor')
const measureOf = (key) => measures.value.find((m) => m.key === key)

async function load() {
    loadError.value = ''
    try {
        const { data } = await axios.get(route('api.check_ins.index', { therapyId: props.therapyId }))
        checkIns.value = data.checkIns
        measures.value = data.measures
        canSend.value = data.canSend
        viewerIs.value = data.viewerIs
        loaded.value = true
        openFromLink()
    } catch {
        loadError.value = 'Check-ins could not be loaded. Please try again.'
    }
}

onMounted(load)

function errorOf(err, fallback) {
    return err.response?.data?.message ?? fallback
}

// --- the counsellor ---------------------------------------------------------------------------------------------
const sendMeasure = ref('phq9')
const sending = ref(false)
const sendError = ref('')

async function send() {
    sending.value = true
    sendError.value = ''
    status.value = ''
    try {
        const { data } = await axios.post(route('api.check_ins.store', { therapyId: props.therapyId }), { measure: sendMeasure.value })
        checkIns.value = [data.checkIn, ...checkIns.value]
        status.value = `Sent. Your client has ${days(data.checkIn.expiresAt)} to answer.`
    } catch (err) {
        sendError.value = errorOf(err, 'It could not be sent. Please try again.')
    } finally {
        sending.value = false
    }
}

async function act(checkIn, what) {
    actionError.value = ''
    status.value = ''
    try {
        const { data } = await axios.post(route(`api.check_ins.${what}`, { checkIn: checkIn.id }))
        checkIns.value = checkIns.value.map((c) => (c.id === checkIn.id ? data.checkIn : c))
        status.value = what === 'cancel' ? 'Check-in withdrawn.' : 'Marked as seen.'
        // Its button is gone: focus stays with the check-in (QA).
        nextTick(() => document.getElementById(`check-in-${checkIn.id}`)?.focus())
    } catch (err) {
        actionError.value = errorOf(err, 'That didn\'t work. Please try again.')
        if (err.response?.status === 409) load()
    }
}

// --- the client: answering --------------------------------------------------------------------------------------
const answering = ref(null) // the check-in being answered
const answers = ref([])
const answerError = ref('')
const submitting = ref(false)
const result = ref(null) // { checkIn, showCrisisSupport }

const answeringMeasure = computed(() => (answering.value ? measureOf(answering.value.measure) : null))
// Shown the moment a self-harm answer is chosen -- not only after sending.
const crisisNow = computed(() => {
    const item = answeringMeasure.value?.attentionItem
    return item !== null && item !== undefined && (answers.value[item] ?? 0) > 0
})

function startAnswer(checkIn) {
    answering.value = checkIn
    answers.value = Array(measureOf(checkIn.measure).items.length).fill(null)
    answerError.value = ''
    result.value = null
}

const heading = ref(null)

function closeAnswer() {
    if (!submitting.value) {
        answering.value = null
        result.value = null
        nextTick(() => heading.value?.focus())
    }
}

async function submit() {
    if (answers.value.some((a) => a === null)) {
        answerError.value = 'Please answer every question.'
        return
    }
    submitting.value = true
    answerError.value = ''
    try {
        const { data } = await axios.post(route('api.check_ins.answer', { checkIn: answering.value.id }), { answers: answers.value })
        checkIns.value = checkIns.value.map((c) => (c.id === data.checkIn.id ? data.checkIn : c))
        result.value = data
        // The form (and its button) is gone: focus the result, so it's read out (review).
        nextTick(() => resultText.value?.focus())
    } catch (err) {
        answerError.value = errorOf(err, 'Your answers could not be sent. Please try again.')
        if (err.response?.status === 409) load()
    } finally {
        submitting.value = false
    }
}

// A notification's link (?checkIn=): the client's opens it to answer; the counsellor's shows it, answers open and
// highlighted (the attention notice's link matters most). Anything no longer there says so.
function openFromLink() {
    const id = Number(new URLSearchParams(window.location.search).get('checkIn'))
    if (!id) return
    const checkIn = checkIns.value.find((c) => c.id === id)

    if (!checkIn) {
        actionError.value = 'That check-in is no longer here.'
    } else if (!isCounsellor.value && checkIn.state === 'requested') {
        nextTick(() => startAnswer(checkIn))
    } else {
        if (checkIn.state === 'completed') expanded.value = checkIn.id
        highlighted.value = checkIn.id
        nextTick(() => document.getElementById(`check-in-${checkIn.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }))
        if (!isCounsellor.value && checkIn.state !== 'completed') actionError.value = 'That check-in can no longer be answered.'
    }
}

// --- history ------------------------------------------------------------------------------------------------------
const open = computed(() => checkIns.value.filter((c) => c.state === 'requested'))
const done = computed(() => checkIns.value.filter((c) => c.state === 'completed'))
const others = computed(() => checkIns.value.filter((c) => ['expired', 'cancelled', 'erased'].includes(c.state)))
const charts = computed(() => measures.value
    .map((m) => ({ measure: m, points: done.value.filter((c) => c.measure === m.key).map((c) => ({ date: c.completedAt, score: c.score, level: c.level })).sort((a, b) => new Date(a.date) - new Date(b.date)) }))
    .filter((chart) => chart.points.length))
const expanded = ref(null)

function date(iso) {
    return iso ? new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : ''
}

function days(iso) {
    const n = Math.max(1, Math.round((new Date(iso) - new Date()) / 86400000))
    return `${n} day${n === 1 ? '' : 's'}`
}
</script>

<template>
    <section aria-labelledby="check-ins-title" class="bg-white shadow-sm border border-gray-200 rounded-lg p-6 text-sm text-gray-700">
        <h2 id="check-ins-title" ref="heading" tabindex="-1" class="text-lg font-semibold text-gray-900 focus:outline-none">Check-ins</h2>
        <p class="mt-1 text-gray-600">
            Short, standard questionnaires about the last two weeks. They help track how things change -- they are not a
            diagnosis.
        </p>

        <p v-if="!loaded && !loadError" class="mt-4" role="status">Loading check-ins…</p>
        <InputError class="mt-4" role="alert" :message="loadError" />

        <template v-if="loaded">
            <p class="mt-3 text-green-800" role="status">{{ status }}</p>
            <InputError role="alert" :message="actionError" />

            <!-- The counsellor sends one. -->
            <form v-if="isCounsellor && canSend" class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="send">
                <div>
                    <label for="check-in-measure" class="block text-xs font-medium text-gray-700">Questionnaire</label>
                    <select id="check-in-measure" v-model="sendMeasure" class="mt-1 rounded-md border-gray-300 text-sm">
                        <option v-for="m in measures" :key="m.key" :value="m.key">{{ m.title }}</option>
                    </select>
                </div>
                <PrimaryButton :disabled="sending" :class="{ 'opacity-25': sending }">{{ sending ? 'Sending…' : 'Send to your client' }}</PrimaryButton>
                <InputError class="w-full" role="alert" :message="sendError" />
            </form>

            <!-- Waiting for an answer. -->
            <div v-if="open.length" class="mt-4">
                <h3 class="font-semibold text-gray-900">{{ isCounsellor ? 'Waiting for your client' : 'To answer' }}</h3>
                <ul class="mt-2 space-y-2">
                    <li v-for="c in open" :key="c.id" class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-gray-200 p-3">
                        <span>{{ measureOf(c.measure)?.title }} -- sent {{ date(c.requestedAt) }}, {{ days(c.expiresAt) }} left</span>
                        <SecondaryButton v-if="isCounsellor" type="button" @click="act(c, 'cancel')">Withdraw</SecondaryButton>
                        <PrimaryButton v-else type="button" :aria-label="`Answer the ${measureOf(c.measure)?.title}`" @click="startAnswer(c)">Answer</PrimaryButton>
                    </li>
                </ul>
            </div>

            <!-- Over time. -->
            <div v-if="charts.length" class="mt-6 grid gap-6 sm:grid-cols-2">
                <div v-for="chart in charts" :key="chart.measure.key">
                    <h3 class="font-semibold text-gray-900">{{ chart.measure.title }} over time</h3>
                    <OutcomeScoreChart class="mt-2" :measure="chart.measure" :points="chart.points" />
                </div>
            </div>

            <div v-if="done.length" class="mt-6">
                <h3 class="font-semibold text-gray-900">Answered</h3>
                <ul class="mt-2 space-y-2">
                    <li v-for="c in done" :id="`check-in-${c.id}`" :key="c.id" tabindex="-1" class="rounded-md border p-3 focus:outline-none" :class="[c.needsAttention && !c.acknowledgedAt ? 'border-amber-300 bg-amber-50' : 'border-gray-200', highlighted === c.id ? 'ring-2 ring-indigo-500' : '']">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span>
                                {{ measureOf(c.measure)?.title }}, {{ date(c.completedAt) }}: <strong>{{ c.score }}</strong> of {{ measureOf(c.measure)?.maxScore }} ({{ c.level }})
                            </span>
                            <span class="flex flex-wrap gap-2">
                                <button type="button" class="min-h-[44px] px-2 text-indigo-700 underline" :aria-expanded="expanded === c.id ? 'true' : 'false'" @click="expanded = expanded === c.id ? null : c.id">
                                    {{ expanded === c.id ? 'Hide answers' : 'Show answers' }}
                                </button>
                                <SecondaryButton v-if="isCounsellor && c.needsAttention && !c.acknowledgedAt" type="button" @click="act(c, 'acknowledge')">Mark as seen</SecondaryButton>
                            </span>
                        </div>
                        <p v-if="isCounsellor && c.needsAttention" class="mt-2 text-amber-900">
                            The answer to the question about thoughts of being better off dead or of self-harm was above "Not at all".
                            Please follow up, and consider raising a safety flag. {{ c.acknowledgedAt ? `Seen ${date(c.acknowledgedAt)}.` : '' }}
                        </p>
                        <ol v-if="expanded === c.id && c.answers" class="mt-3 list-decimal pl-5 space-y-1">
                            <li v-for="(item, i) in measureOf(c.measure)?.items" :key="i">
                                {{ item }}: <span class="font-medium">{{ measureOf(c.measure)?.options[c.answers[i]]?.label }}</span>
                            </li>
                        </ol>
                    </li>
                </ul>
            </div>

            <p v-if="!open.length && !done.length" class="mt-4 text-gray-600">
                {{ isCounsellor ? 'No check-ins yet.' : 'No check-ins yet. Your counsellor can send you one.' }}
            </p>
            <p v-if="others.length" class="mt-3 text-xs text-gray-500">
                {{ others.length }} withdrawn, expired or no longer kept (an erased account's answers).
            </p>
            <p class="mt-4 text-xs text-gray-500">{{ measures[0]?.attribution }}</p>
        </template>

        <!-- Answering: every question, then the result. -->
        <MiniModal :show="!!answering" max-width="2xl" labelledby="check-in-answer-title" @close="closeAnswer">
            <div v-if="answering && answeringMeasure" class="p-6 text-sm text-gray-700">
                <h3 id="check-in-answer-title" class="text-lg font-semibold text-gray-900">{{ answeringMeasure.title }}</h3>

                <template v-if="!result">
                    <p class="mt-1">{{ answeringMeasure.preamble }}</p>
                    <p class="mt-1 text-xs text-gray-500">
                        Your counsellor will see your answers, but not straight away -- this isn't watched live.
                        If you need help now, <button type="button" class="underline text-indigo-700" @click="showHelp">get urgent help</button>.
                    </p>
                    <form class="mt-4 space-y-4" @submit.prevent="submit">
                        <fieldset v-for="(item, i) in answeringMeasure.items" :key="i" class="rounded-md border border-gray-200 p-3">
                            <legend class="px-1 font-medium text-gray-900">{{ i + 1 }}. {{ item }}</legend>
                            <div class="mt-2 grid gap-1 sm:grid-cols-2">
                                <label v-for="option in answeringMeasure.options" :key="option.value" class="flex items-center gap-2 min-h-[44px]">
                                    <input v-model="answers[i]" type="radio" :name="`check-in-item-${i}`" :value="option.value" class="text-indigo-600" required />
                                    {{ option.label }}
                                </label>
                            </div>
                            <!-- The moment a self-harm answer is chosen; only the sentence is announced (review). -->
                            <div v-if="i === answeringMeasure.attentionItem && crisisNow" class="mt-3 space-y-2">
                                <p class="font-medium text-gray-900" role="alert">
                                    Thank you for telling us. You don't have to wait for your counsellor -- if you might act on
                                    these thoughts, get help now.
                                </p>
                                <EmergencyNumbersPanel input-id="check-in-crisis-country" />
                                <SecondaryButton type="button" @click="showHelp">I need help now</SecondaryButton>
                            </div>
                        </fieldset>
                        <InputError role="alert" :message="answerError" />
                        <div class="flex justify-end gap-3">
                            <SecondaryButton type="button" :disabled="submitting" @click="closeAnswer">Cancel</SecondaryButton>
                            <PrimaryButton :disabled="submitting" :class="{ 'opacity-25': submitting }">{{ submitting ? 'Sending…' : 'Send answers' }}</PrimaryButton>
                        </div>
                    </form>
                </template>

                <template v-else>
                    <p ref="resultText" tabindex="-1" class="mt-2 focus:outline-none" role="status">
                        Thank you -- your answers were sent. Your score was {{ result.checkIn.score }} of {{ answeringMeasure.maxScore }}
                        ({{ result.checkIn.level }}). This is not a diagnosis; your counsellor will talk it through with you.
                    </p>
                    <div v-if="result.showCrisisSupport" class="mt-4 space-y-2">
                        <p class="font-medium text-gray-900" role="alert">
                            Your counsellor has been told one of your answers needs their attention, but they may not see it
                            straight away. If you might act on thoughts of hurting yourself, please get help now.
                        </p>
                        <EmergencyNumbersPanel input-id="check-in-result-country" />
                        <SecondaryButton type="button" @click="showHelp">I need help now</SecondaryButton>
                    </div>
                    <div class="mt-6 flex justify-end">
                        <PrimaryButton type="button" @click="closeAnswer">Done</PrimaryButton>
                    </div>
                </template>
            </div>
        </MiniModal>
    </section>
</template>
