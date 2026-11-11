<script setup>
// SCRUM-418: opening a link that would make someone a guardian, a therapy's counsellor, a discussion's or a
// group's counsellor. Nothing has happened yet: it says what they'd be agreeing to (only what the server
// chose to show -- see each Perform*LinkAction::preview()), and they accept (api.links.join) or not. The
// answer is shown here, with the way on. A link that can't be used shows only why (`refusal`).
import { computed, ref } from 'vue'
import axios from 'axios'
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { refusalMessage } from '@/Composables/useOfferToHelp'
import useAuth from '@/Composables/useAuth'

const props = defineProps({
    uuid: { type: String, default: null },
    type: { type: String, default: null },
    summary: { type: Object, default: null },
    refusal: { type: String, default: null },
})

const COPY = {
    GUARDIANSHIP: {
        kicker: 'Guardian request',
        title: (s) => `${s.name} has asked you to be their guardian`,
        explain: "As their guardian you'll be told about their therapies and the groups they join.",
        accept: 'become their guardian',
    },
    THERAPY_COUNSELLOR: {
        kicker: 'Counsellor invitation',
        title: (s) => s.name,
        explain: 'You would become the counsellor of this therapy.',
        accept: 'become the counsellor',
    },
    DISCUSSION: {
        kicker: 'Discussion invitation',
        title: (s) => s.name,
        explain: 'You would join this discussion between counsellors.',
        accept: 'join the discussion',
    },
    GROUP_THERAPY_COUNSELLOR: {
        kicker: 'Group counsellor invitation',
        title: (s) => s.name,
        explain: "You would join this group's team of counsellors.",
        accept: 'join as a counsellor',
    },
}

const { goToLogin } = useAuth()

const copy = computed(() => COPY[props.type] ?? null)
const sending = ref(false)
// { ok, message, redirect } once they've answered.
const result = ref(null)

const formatTime = (value) => (value ? new Date(value).toLocaleString() : '')

async function accept() {
    sending.value = true
    result.value = null
    await axios.post(route('api.links.join', { uuid: props.uuid }))
        .then((res) => {
            result.value = { ok: true, message: res.data.message, redirect: res.data.redirect }
        })
        .catch((err) => {
            goToLogin(err)
            result.value = { ok: false, message: refusalMessage(err) }
        })
        .finally(() => { sending.value = false })
}
</script>

<template>
    <Head :title="copy && summary ? copy.kicker : 'Link'" />

    <AuthenticatedLayout>
        <div class="py-12">
            <div class="max-w-xl mx-auto px-4 sm:px-6">
                <section class="bg-white shadow-lg border border-gray-200 rounded-xl p-6 sm:p-8" aria-labelledby="link-confirm-title">
                    <template v-if="refusal || !copy || !summary">
                        <h1 id="link-confirm-title" class="text-xl font-bold text-gray-900">This link can't be used</h1>
                        <p class="mt-3 text-sm text-gray-700">{{ refusal || 'This link is no longer active.' }}</p>
                        <Link :href="route('home')" class="mt-6 inline-block text-sm font-medium text-blue-700 hover:underline">Go home</Link>
                    </template>

                    <template v-else>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ copy.kicker }}</p>

                        <div class="mt-1 flex items-center gap-4">
                            <img
                                v-if="type === 'GUARDIANSHIP' && summary.avatarUrl"
                                :src="summary.avatarUrl"
                                alt=""
                                class="w-14 h-14 rounded-full object-cover border border-gray-200 shrink-0"
                            />
                            <h1 id="link-confirm-title" class="text-xl font-bold text-gray-900 break-words">{{ copy.title(summary) }}</h1>
                        </div>

                        <dl class="mt-4 space-y-2 text-sm text-gray-700">
                            <template v-if="type === 'GUARDIANSHIP' && summary.username">
                                <div class="flex gap-2"><dt class="text-gray-500">Username</dt><dd>{{ summary.username }}</dd></div>
                            </template>

                            <template v-if="type === 'THERAPY_COUNSELLOR'">
                                <div v-if="summary.client" class="flex gap-2"><dt class="text-gray-500">Client</dt><dd>{{ summary.client }}</dd></div>
                                <div class="flex gap-2"><dt class="text-gray-500">Started</dt><dd>{{ summary.createdAt }}</dd></div>
                                <div class="flex gap-2"><dt class="text-gray-500">Kind</dt><dd>{{ summary.public ? 'Public' : 'Private' }}{{ summary.anonymous ? ', anonymous' : '' }}</dd></div>
                                <div v-if="summary.backgroundStory">
                                    <dt class="text-gray-500">Background</dt>
                                    <dd class="mt-1 whitespace-pre-line">{{ summary.backgroundStory }}</dd>
                                </div>
                            </template>

                            <template v-if="type === 'DISCUSSION'">
                                <div v-if="summary.description"><dt class="sr-only">Description</dt><dd class="whitespace-pre-line">{{ summary.description }}</dd></div>
                                <div v-if="summary.startedBy" class="flex gap-2"><dt class="text-gray-500">Started by</dt><dd>{{ summary.startedBy }}</dd></div>
                                <div class="flex gap-2"><dt class="text-gray-500">About</dt><dd>a {{ summary.about }}</dd></div>
                                <div v-if="summary.startTime" class="flex gap-2"><dt class="text-gray-500">When</dt><dd>{{ formatTime(summary.startTime) }}<template v-if="summary.endTime"> – {{ formatTime(summary.endTime) }}</template></dd></div>
                            </template>

                            <template v-if="type === 'GROUP_THERAPY_COUNSELLOR'">
                                <div v-if="summary.about"><dt class="sr-only">About</dt><dd class="whitespace-pre-line">{{ summary.about }}</dd></div>
                                <div v-if="summary.createdBy" class="flex gap-2"><dt class="text-gray-500">Created by</dt><dd>{{ summary.createdBy }}</dd></div>
                                <div v-if="summary.anonymous" class="flex gap-2"><dt class="text-gray-500">Kind</dt><dd>Anonymous group</dd></div>
                            </template>
                        </dl>

                        <div role="status" aria-live="polite">
                            <div
                                v-if="result"
                                class="mt-6 rounded-lg border p-4 text-sm"
                                :class="result.ok ? 'border-green-200 bg-green-50 text-green-900' : 'border-red-200 bg-red-50 text-red-900'"
                            >
                                <p class="font-medium">{{ result.ok ? 'Done.' : "That didn't work." }}</p>
                                <p class="mt-1">{{ result.message }}</p>
                            </div>
                        </div>

                        <template v-if="!result?.ok">
                            <p v-if="!result" class="mt-6 text-sm text-gray-600">{{ copy.explain }}</p>
                            <div class="mt-6 flex flex-wrap items-center gap-4">
                                <PrimaryButton type="button" :disabled="sending" @click="accept">
                                    {{ sending ? 'one moment…' : copy.accept }}
                                </PrimaryButton>
                                <Link :href="route('home')" class="text-sm font-medium text-gray-600 hover:text-gray-900 hover:underline">Not now</Link>
                            </div>
                        </template>

                        <a
                            v-if="result?.ok && result.redirect"
                            :href="result.redirect"
                            class="mt-6 inline-block text-sm font-medium text-blue-700 hover:underline"
                        >Continue</a>
                    </template>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
