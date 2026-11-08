<script setup>
// SCRUM-33: a quick help chat between the person who asked and the counsellor who answered. Plain
// text; an adult can also start an audio call (audio only -- the server enforces it, and a minor needs
// a guardian's consent). Either side can end it; afterwards it stays here, read-only, for both.
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import axios from 'axios'
import { Head } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import MiniModal from '@/Components/MiniModal.vue'
import VideoCallPanel from '@/Components/VideoCallPanel.vue'
import { acquireChannel } from '@/Composables/sharedChannel'
import useHelpNow, { helpRefusal } from '@/Composables/useHelpNow'

const props = defineProps({
    helpRequest: { type: Object, required: true },
    messages: { type: Array, default: () => [] },
})

const { show: showHelp } = useHelpNow()

const help = ref(props.helpRequest)
const thread = ref([...props.messages])
const draft = ref('')
const sending = ref(false)
const error = ref('')
const confirmingEnd = ref(false)
const ending = ref(false)
const inCall = ref(false)
const list = ref(null)
let channel = null

const connected = computed(() => help.value.status === 'CONNECTED')
const otherName = computed(() => help.value.isPerson ? (help.value.counsellorName ?? 'Your counsellor') : (help.value.person?.name ?? 'The person'))

async function scrollDown() {
    await nextTick()
    if (list.value) list.value.scrollTop = list.value.scrollHeight
}

async function loadMessages() {
    await axios.get(route('help.requests.messages', { helpRequest: help.value.id }))
        .then((res) => { thread.value = res.data.messages; scrollDown() })
        .catch(() => {})
}

async function loadRequest() {
    await axios.get(route('help.requests.show', { helpRequest: help.value.id }))
        .then((res) => {
            help.value = res.data.helpRequest
            if (help.value.status !== 'CONNECTED') inCall.value = false
        })
        .catch(() => {})
}

async function send() {
    const content = draft.value.trim()
    if (!content || sending.value) return
    sending.value = true
    error.value = ''
    await axios.post(route('help.requests.messages.store', { helpRequest: help.value.id }), { content })
        .then((res) => {
            thread.value.push(res.data.message)
            draft.value = ''
            scrollDown()
        })
        .catch((err) => {
            error.value = helpRefusal(err, 'Your message could not be sent.')
            loadRequest()
        })
        .finally(() => { sending.value = false })
}

async function end() {
    ending.value = true
    await axios.post(route('help.requests.end', { helpRequest: help.value.id }))
        .then((res) => {
            help.value = res.data.helpRequest
            inCall.value = false
            confirmingEnd.value = false
        })
        .catch((err) => { error.value = helpRefusal(err, 'The chat could not be ended.') })
        .finally(() => { ending.value = false })
}

function formatTime(value) {
    return value ? new Date(value).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) : ''
}

onMounted(() => {
    scrollDown()
    channel = acquireChannel(`help-requests.${help.value.id}`)
    channel?.listen('.help.message', () => loadMessages())
    channel?.listen('.help.updated', () => loadRequest())
})

onBeforeUnmount(() => channel?.release())
</script>

<template>
    <Head title="Urgent help chat" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Urgent help chat</h2>
        </template>

        <div class="mx-auto w-full max-w-3xl space-y-4 px-4 py-6">
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="min-w-0">
                    <p class="font-semibold text-gray-900">{{ otherName }}</p>
                    <p class="text-sm text-gray-600">
                        <template v-if="connected">Chat open{{ help.isPerson ? '' : (help.person?.isMinor ? ' · under 18' : '') }}</template>
                        <template v-else>This chat has ended. You can read it here.</template>
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <SecondaryButton type="button" @click="showHelp">Emergency numbers</SecondaryButton>
                    <SecondaryButton v-if="connected && help.audioPossible && !inCall" type="button" @click="inCall = true">Start audio call</SecondaryButton>
                    <DangerButton v-if="connected" type="button" @click="confirmingEnd = true">End chat</DangerButton>
                </div>
            </div>

            <p v-if="help.isPerson" class="text-xs text-gray-600">If you are in danger, call your emergency number now -- TalkTherapy isn't an emergency service.</p>
            <p v-if="connected && !help.audioPossible" class="text-xs text-gray-600">
                {{ help.audioUnavailable === 'minor' ? 'Audio calls in this chat need a parent or guardian\'s consent, so it\'s text only.' : 'This chat is text only.' }}
            </p>

            <VideoCallPanel v-if="inCall && help.sessionId" :session="{ id: help.sessionId }" @close="inCall = false" />

            <section class="rounded-lg border border-gray-200 bg-white shadow-sm" aria-label="Messages">
                <ol ref="list" class="max-h-[55vh] min-h-[240px] space-y-3 overflow-y-auto p-4" aria-live="polite">
                    <li v-if="!thread.length" class="text-sm text-gray-600">No messages yet. Say hello.</li>
                    <li v-for="message in thread" :key="message.id" class="flex" :class="message.mine ? 'justify-end' : 'justify-start'">
                        <div class="max-w-[80%] rounded-lg px-3 py-2 text-sm" :class="message.mine ? 'bg-blue-600 text-white' : 'border border-gray-200 bg-gray-50 text-gray-900'">
                            <p class="text-xs font-medium" :class="message.mine ? 'text-blue-100' : 'text-gray-600'">{{ message.sender }} · {{ formatTime(message.createdAt) }}</p>
                            <p class="mt-0.5 whitespace-pre-wrap break-words">{{ message.content }}</p>
                        </div>
                    </li>
                </ol>

                <form v-if="connected" class="flex items-end gap-2 border-t border-gray-100 p-3" @submit.prevent="send">
                    <label for="help-chat-message" class="sr-only">Message</label>
                    <textarea
                        id="help-chat-message"
                        v-model="draft"
                        rows="2"
                        maxlength="2000"
                        class="block w-full resize-none rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        placeholder="Write a message"
                        @keydown.enter.exact.prevent="send"
                    ></textarea>
                    <PrimaryButton :disabled="sending || !draft.trim()">Send</PrimaryButton>
                </form>
                <p v-if="error" class="px-4 pb-3 text-sm text-red-700" role="alert">{{ error }}</p>
            </section>
        </div>

        <MiniModal :show="confirmingEnd" labelledby="help-end-title" @close="confirmingEnd = false">
            <div class="space-y-4">
                <h2 id="help-end-title" class="text-lg font-semibold text-gray-900">End this chat?</h2>
                <p class="text-sm text-gray-600">You'll both still be able to read it. {{ help.isPerson ? 'You can ask for help again any time.' : '' }}</p>
                <div class="flex justify-end gap-2">
                    <SecondaryButton type="button" :disabled="ending" @click="confirmingEnd = false">Keep chatting</SecondaryButton>
                    <DangerButton type="button" :disabled="ending" @click="end">End chat</DangerButton>
                </div>
            </div>
        </MiniModal>
    </AuthenticatedLayout>
</template>
