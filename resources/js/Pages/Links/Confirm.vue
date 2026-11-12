<script setup>
// SCRUM-418/419: opening a link that would make someone a guardian, a therapy's counsellor, a discussion's or a
// group's counsellor, or (SCRUM-419) a member of an organisation. Nothing has happened yet: it says what they'd
// be agreeing to (only what the server chose to show -- see each Perform*LinkAction::preview()), and they
// accept (api.links.join) or not. The answer is shown here, with the way on. A link that can't be used shows
// only why (`refusal`) -- with its `type` only where the page always said it (PreviewLinkAction).
//
// What differs between link types is data (COPY, over DEFAULTS), not template branches: a new type is one entry.
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

const formatTime = (value) => (value ? new Date(value).toLocaleString() : '')

// Each detail row: { label, value, block?, srOnly? } -- a row without a value isn't shown.
const DEFAULTS = {
    // The tab's title; the kicker when null.
    headTitle: null,
    // { url, shape: 'round' | 'square', initial } -- `initial` shows when there's no picture.
    avatar: () => null,
    details: () => [],
    sending: 'one moment…',
    successTitle: () => 'Done.',
    failureTitle: "That didn't work.",
    // Whether they can press again after it didn't work.
    retry: true,
    // Where to go after answering: the server's redirect once it worked, or a fixed route after any answer.
    back: { label: 'Continue', route: null, afterAny: false },
    refusalHeadTitle: 'Link',
    refusalTitle: "This link can't be used",
    refusalBack: { label: 'Go home', route: 'home' },
}

const COPY = {
    GUARDIANSHIP: {
        kicker: 'Guardian request',
        title: (s) => `${s.name} has asked you to be their guardian`,
        avatar: (s) => (s.avatarUrl ? { url: s.avatarUrl, shape: 'round' } : null),
        details: (s) => [{ label: 'Username', value: s.username }],
        explain: "As their guardian you'll be told about their therapies and the groups they join.",
        accept: 'become their guardian',
    },
    THERAPY_COUNSELLOR: {
        kicker: 'Counsellor invitation',
        title: (s) => s.name,
        details: (s) => [
            { label: 'Client', value: s.client },
            { label: 'Started', value: s.createdAt },
            { label: 'Kind', value: `${s.public ? 'Public' : 'Private'}${s.anonymous ? ', anonymous' : ''}` },
            { label: 'Background', value: s.backgroundStory, block: true },
        ],
        explain: 'You would become the counsellor of this therapy.',
        accept: 'become the counsellor',
    },
    DISCUSSION: {
        kicker: 'Discussion invitation',
        title: (s) => s.name,
        details: (s) => [
            { label: 'Description', value: s.description, block: true, srOnly: true },
            { label: 'Started by', value: s.startedBy },
            { label: 'About', value: s.about ? `a ${s.about}` : null },
            {
                label: 'When',
                value: s.startTime ? `${formatTime(s.startTime)}${s.endTime ? ` – ${formatTime(s.endTime)}` : ''}` : null,
            },
        ],
        explain: 'You would join this discussion between counsellors.',
        accept: 'join the discussion',
    },
    GROUP_THERAPY_COUNSELLOR: {
        kicker: 'Group counsellor invitation',
        title: (s) => s.name,
        details: (s) => [
            { label: 'About', value: s.about, block: true, srOnly: true },
            { label: 'Created by', value: s.createdBy },
            { label: 'Kind', value: s.anonymous ? 'Anonymous group' : null },
        ],
        explain: "You would join this group's team of counsellors.",
        accept: 'join as a counsellor',
    },
    ORGANIZATION_SELF_APPLY: {
        kicker: 'Join as a member',
        title: (s) => s.name,
        headTitle: (s) => `Join ${s.name}`,
        avatar: (s) => ({ url: s.logoUrl, shape: 'square', initial: s.name?.charAt(0).toUpperCase() }),
        details: (s) => [{ label: 'About', value: s.description, block: true, srOnly: true }],
        explain: "Your request goes to the organization's admins. You become a member once one of them accepts it.",
        accept: 'request to join',
        sending: 'sending…',
        successTitle: (s) => `Request sent to ${s.name}.`,
        failureTitle: "Your request couldn't be sent.",
        retry: false,
        back: { label: 'Go to My organizations', route: 'organizations.mine.dashboard', afterAny: true },
        refusalHeadTitle: 'Join link',
        refusalTitle: "This join link can't be used",
        refusalBack: { label: 'Go to My organizations', route: 'organizations.mine.dashboard' },
    },
}

const { goToLogin } = useAuth()

const copy = computed(() => (COPY[props.type] ? { ...DEFAULTS, ...COPY[props.type] } : null))
const refused = computed(() => !!props.refusal || !copy.value || !props.summary)
// A refusal only carries its type where the page always said it; otherwise the defaults.
const refusalCopy = computed(() => copy.value ?? DEFAULTS)

const avatar = computed(() => (refused.value ? null : copy.value.avatar(props.summary)))
const details = computed(() => (refused.value ? [] : copy.value.details(props.summary).filter((row) => row.value)))

const sending = ref(false)
// { ok, message, redirect } once they've answered.
const result = ref(null)

const backHref = computed(() => {
    if (!result.value) return null
    const back = copy.value.back
    if (back.afterAny) return route(back.route)
    return result.value.ok ? result.value.redirect : null
})

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
    <Head :title="refused ? refusalCopy.refusalHeadTitle : (copy.headTitle?.(summary) ?? copy.kicker)" />

    <AuthenticatedLayout>
        <div class="py-12">
            <div class="max-w-xl mx-auto px-4 sm:px-6">
                <section class="bg-white shadow-lg border border-gray-200 rounded-xl p-6 sm:p-8" aria-labelledby="link-confirm-title">
                    <template v-if="refused">
                        <h1 id="link-confirm-title" class="text-xl font-bold text-gray-900">{{ refusalCopy.refusalTitle }}</h1>
                        <p class="mt-3 text-sm text-gray-700">{{ refusal || 'This link is no longer active.' }}</p>
                        <Link :href="route(refusalCopy.refusalBack.route)" class="mt-6 inline-block text-sm font-medium text-blue-700 hover:underline">{{ refusalCopy.refusalBack.label }}</Link>
                    </template>

                    <template v-else>
                        <div class="flex items-center gap-4">
                            <template v-if="avatar">
                                <img
                                    v-if="avatar.url"
                                    :src="avatar.url"
                                    alt=""
                                    class="w-14 h-14 object-cover border border-gray-200 shrink-0"
                                    :class="avatar.shape === 'round' ? 'rounded-full' : 'rounded-lg'"
                                />
                                <div
                                    v-else-if="avatar.initial"
                                    class="w-14 h-14 bg-blue-100 text-blue-700 flex items-center justify-center text-xl font-bold shrink-0"
                                    :class="avatar.shape === 'round' ? 'rounded-full' : 'rounded-lg'"
                                    aria-hidden="true"
                                >{{ avatar.initial }}</div>
                            </template>
                            <div class="min-w-0">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ copy.kicker }}</p>
                                <h1 id="link-confirm-title" class="mt-1 text-xl font-bold text-gray-900 break-words">{{ copy.title(summary) }}</h1>
                            </div>
                        </div>

                        <dl v-if="details.length" class="mt-4 space-y-2 text-sm text-gray-700">
                            <div v-for="row in details" :key="row.label" :class="row.block ? '' : 'flex gap-2'">
                                <dt :class="row.srOnly ? 'sr-only' : 'text-gray-500'">{{ row.label }}</dt>
                                <dd :class="row.block ? ['whitespace-pre-line', row.srOnly ? '' : 'mt-1'] : ''">{{ row.value }}</dd>
                            </div>
                        </dl>

                        <div role="status" aria-live="polite">
                            <div
                                v-if="result"
                                class="mt-6 rounded-lg border p-4 text-sm"
                                :class="result.ok ? 'border-green-200 bg-green-50 text-green-900' : 'border-red-200 bg-red-50 text-red-900'"
                            >
                                <p class="font-medium">{{ result.ok ? copy.successTitle(summary) : copy.failureTitle }}</p>
                                <p class="mt-1">{{ result.message }}</p>
                            </div>
                        </div>

                        <template v-if="!result?.ok">
                            <p v-if="!result" class="mt-6 text-sm text-gray-600">{{ copy.explain }}</p>
                            <div class="mt-6 flex flex-wrap items-center gap-4">
                                <PrimaryButton type="button" :disabled="sending || (!copy.retry && !!result)" @click="accept">
                                    {{ sending ? copy.sending : copy.accept }}
                                </PrimaryButton>
                                <Link :href="route('home')" class="text-sm font-medium text-gray-600 hover:text-gray-900 hover:underline">Not now</Link>
                            </div>
                        </template>

                        <!-- A fixed route is an app page (Inertia); the server's redirect is followed with a full load. -->
                        <component
                            :is="copy.back.afterAny ? Link : 'a'"
                            v-if="backHref"
                            :href="backHref"
                            class="mt-6 inline-block text-sm font-medium text-blue-700 hover:underline"
                        >{{ copy.back.label }}</component>
                    </template>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
