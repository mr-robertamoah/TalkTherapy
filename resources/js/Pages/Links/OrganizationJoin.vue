<script setup>
// SCRUM-415: opening an organisation join link. Nothing has happened yet -- the person reads who they're
// asking to join and confirms (api.links.join), and the answer is shown right here: the request was sent,
// or why it couldn't be. When the link can't be used at all, the server sends only the reason (`refusal`),
// nothing about the organisation.
import { ref } from 'vue'
import axios from 'axios'
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { refusalMessage } from '@/Composables/useOfferToHelp'
import useAuth from '@/Composables/useAuth'

const props = defineProps({
    uuid: { type: String, default: null },
    organization: { type: Object, default: null },
    refusal: { type: String, default: null },
})

const { goToLogin } = useAuth()

const sending = ref(false)
// { ok: boolean, message: string } once they've confirmed.
const result = ref(null)

async function requestToJoin() {
    sending.value = true
    await axios.post(route('api.links.join', { uuid: props.uuid }))
        .then((res) => {
            result.value = { ok: true, message: res.data.message }
        })
        .catch((err) => {
            goToLogin(err)
            result.value = { ok: false, message: refusalMessage(err) }
        })
        .finally(() => { sending.value = false })
}
</script>

<template>
    <Head :title="organization ? `Join ${organization.name}` : 'Join link'" />

    <AuthenticatedLayout>
        <div class="py-12">
            <div class="max-w-xl mx-auto px-4 sm:px-6">
                <section class="bg-white shadow-lg border border-gray-200 rounded-xl p-6 sm:p-8" aria-labelledby="org-join-title">
                    <template v-if="refusal">
                        <h1 id="org-join-title" class="text-xl font-bold text-gray-900">This join link can't be used</h1>
                        <p class="mt-3 text-sm text-gray-700">{{ refusal }}</p>
                        <Link :href="route('organizations.mine.dashboard')" class="mt-6 inline-block text-sm font-medium text-blue-700 hover:underline">Go to My organizations</Link>
                    </template>

                    <template v-else>
                        <div class="flex items-center gap-4">
                            <img
                                v-if="organization.logoUrl"
                                :src="organization.logoUrl"
                                alt=""
                                class="w-14 h-14 rounded-lg object-cover border border-gray-200 shrink-0"
                            />
                            <div v-else class="w-14 h-14 rounded-lg bg-blue-100 text-blue-700 flex items-center justify-center text-xl font-bold shrink-0" aria-hidden="true">
                                {{ organization.name.charAt(0).toUpperCase() }}
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Join as a member</p>
                                <h1 id="org-join-title" class="text-xl font-bold text-gray-900 break-words">{{ organization.name }}</h1>
                            </div>
                        </div>

                        <p v-if="organization.description" class="mt-4 text-sm text-gray-700 whitespace-pre-line">{{ organization.description }}</p>

                        <div role="status" aria-live="polite">
                            <div
                                v-if="result"
                                class="mt-6 rounded-lg border p-4 text-sm"
                                :class="result.ok ? 'border-green-200 bg-green-50 text-green-900' : 'border-red-200 bg-red-50 text-red-900'"
                            >
                                <p class="font-medium">{{ result.ok ? `Request sent to ${organization.name}.` : "Your request couldn't be sent." }}</p>
                                <p class="mt-1">{{ result.message }}</p>
                            </div>
                        </div>

                        <template v-if="!result?.ok">
                            <p v-if="!result" class="mt-6 text-sm text-gray-600">
                                Your request goes to the organization's admins. You become a member once one of them accepts it.
                            </p>
                            <div class="mt-6 flex flex-wrap items-center gap-4">
                                <PrimaryButton type="button" :disabled="sending || !!result" @click="requestToJoin">
                                    {{ sending ? 'sending…' : 'request to join' }}
                                </PrimaryButton>
                                <Link :href="route('home')" class="text-sm font-medium text-gray-600 hover:text-gray-900 hover:underline">Not now</Link>
                            </div>
                        </template>

                        <Link
                            v-if="result"
                            :href="route('organizations.mine.dashboard')"
                            class="mt-6 inline-block text-sm font-medium text-blue-700 hover:underline"
                        >Go to My organizations</Link>
                    </template>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
