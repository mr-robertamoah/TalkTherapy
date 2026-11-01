<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import ApplicationLogo from '@/Components/ApplicationLogo.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

// SCRUM-359: the one page a suspended account can reach. Deliberately not AuthenticatedLayout --
// that layout calls the API and joins broadcast channels, which a suspended account is refused.

const props = defineProps({
    suspension: { type: Object, required: true },
})

const isAccount = computed(() => props.suspension.scope === 'account')
const appealForm = useForm({ appeal: '' })

function formatDate(value) {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'long', timeStyle: 'short' }) : null
}

function submitAppeal() {
    appealForm.post(route('suspended.appeal', { suspension: props.suspension.id }), {
        preserveScroll: true,
        onSuccess: () => appealForm.reset(),
    })
}
</script>

<template>
    <Head title="Suspended" />

    <div class="min-h-screen bg-gray-100 py-10 px-4">
        <div class="mx-auto w-full max-w-xl">
            <div class="flex justify-center">
                <ApplicationLogo class="w-20 h-20 fill-current text-gray-500" />
            </div>

            <div class="mt-6 bg-white shadow-sm border border-gray-200 rounded-lg p-6 space-y-5">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">
                        {{ isAccount ? 'Your account is suspended' : 'Your counsellor role is suspended' }}
                    </h1>
                    <div class="w-16 h-1 bg-amber-500 mt-2"></div>
                </div>

                <p class="text-sm text-gray-700">
                    <template v-if="isAccount">
                        Following a review, you can't use TalkTherapy while this suspension lasts.
                    </template>
                    <template v-else>
                        Following a review, you can't work as a counsellor while this suspension lasts. You can
                        still use TalkTherapy as a client.
                    </template>
                </p>

                <dl class="text-sm divide-y divide-gray-100 border border-gray-200 rounded-md">
                    <div class="flex flex-col sm:flex-row sm:gap-4 p-3">
                        <dt class="w-28 shrink-0 text-gray-500">Ends</dt>
                        <dd class="text-gray-900">{{ formatDate(suspension.endsAt) ?? 'When an administrator lifts it' }}</dd>
                    </div>
                    <div class="flex flex-col sm:flex-row sm:gap-4 p-3">
                        <dt class="w-28 shrink-0 text-gray-500">Reason</dt>
                        <dd class="text-gray-900 whitespace-pre-line">{{ suspension.reason }}</dd>
                    </div>
                </dl>

                <section v-if="suspension.canAppeal" class="space-y-2">
                    <h2 class="font-semibold text-gray-900">Appeal</h2>
                    <p class="text-sm text-gray-600">
                        You can appeal once. An administrator who did not apply the suspension will read it, and you will
                        be emailed the decision.
                    </p>
                    <InputLabel for="appeal" value="Why should the suspension be lifted?" />
                    <textarea
                        id="appeal"
                        v-model="appealForm.appeal"
                        rows="5"
                        maxlength="3000"
                        class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                    ></textarea>
                    <InputError :message="appealForm.errors.appeal" />
                    <PrimaryButton :disabled="appealForm.processing || appealForm.appeal.trim().length < 20" @click="submitAppeal">send appeal</PrimaryButton>
                </section>
                <p v-else-if="suspension.appealedAt && !suspension.appealDecision" class="text-sm text-gray-600">
                    Your appeal was sent on {{ formatDate(suspension.appealedAt) }}. You will be emailed the decision.
                </p>
                <p v-else-if="suspension.appealDecision === 'upheld'" class="text-sm text-gray-600">
                    Your appeal was reviewed and the suspension stays in place.
                </p>

                <p class="text-sm text-gray-600">
                    If you need support urgently, please contact your local emergency services or a crisis line.
                </p>

                <div class="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-gray-100">
                    <Link v-if="!isAccount" :href="route('home')" class="text-sm text-gray-700 underline hover:text-gray-900">Continue to TalkTherapy</Link>
                    <Link :href="route('logout')" method="post" as="button" class="text-sm text-gray-700 underline hover:text-gray-900">Sign out</Link>
                </div>
            </div>
        </div>
    </div>
</template>
