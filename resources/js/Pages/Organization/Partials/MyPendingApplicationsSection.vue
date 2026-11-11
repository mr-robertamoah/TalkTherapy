<script setup>
// SCRUM-415: the person's own applications to join organisations as a member, still waiting for an
// admin -- from a join link or from the directory below. SCRUM-417: one can be withdrawn, after
// confirming; the answer is shown in the row's place (no flash).
import { ref } from 'vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { refusalMessage } from '@/Composables/useOfferToHelp';

const props = defineProps({
    initialApplications: {
        type: Object,
        required: true,
    },
})

const applications = ref([...props.initialApplications.data])
const nextPageUrl = ref(props.initialApplications.links?.next ?? null)
const loadingMore = ref(false)
const loadError = ref('')
const confirmingId = ref(null)
const withdrawingId = ref(null)
const rowError = ref({})
const withdrawnNotice = ref('')

async function withdraw(application) {
    withdrawingId.value = application.id
    rowError.value = { ...rowError.value, [application.id]: '' }
    withdrawnNotice.value = ''
    await axios.post(route('organizations.members.applications.withdraw', { requestId: application.id }))
        .then(() => {
            applications.value = applications.value.filter((item) => item.id !== application.id)
            confirmingId.value = null
            withdrawnNotice.value = `Your application to ${orgName(application)} was withdrawn.`
        })
        .catch((err) => {
            // Answered (409) or gone (404) since the page loaded: say so and drop it -- it's no longer waiting.
            if ([404, 409].includes(err?.response?.status)) {
                applications.value = applications.value.filter((item) => item.id !== application.id)
                confirmingId.value = null
                withdrawnNotice.value = err.response.data?.message ?? 'This application is no longer waiting.'
                return
            }
            rowError.value = { ...rowError.value, [application.id]: refusalMessage(err, "Couldn't withdraw this application.") }
        })
        .finally(() => { withdrawingId.value = null })
}

function orgName(application) {
    return application.organization?.deleted ? 'Deleted organization' : application.organization?.name
}

async function loadMore() {
    if (!nextPageUrl.value) return

    loadingMore.value = true
    loadError.value = ''
    await axios.get(nextPageUrl.value)
        .then((res) => {
            applications.value = [...applications.value, ...res.data.data]
            nextPageUrl.value = res.data.links?.next ?? null
        })
        .catch(() => { loadError.value = "Couldn't load more applications. Try again." })
        .finally(() => {
            loadingMore.value = false
        })
}

// After applying from the directory on this page.
async function reload() {
    loadError.value = ''
    await axios.get(route('organizations.mine.applications'))
        .then((res) => {
            applications.value = res.data.data
            nextPageUrl.value = res.data.links?.next ?? null
        })
        .catch(() => { loadError.value = "Couldn't refresh your applications. Reload the page to see them." })
}

defineExpose({ reload })
</script>

<template>
    <section class="bg-white overflow-hidden shadow-xl border border-gray-100 sm:rounded-xl" aria-labelledby="pending-applications-title">
        <div class="p-6 sm:p-8">
            <div class="mb-4">
                <h3 id="pending-applications-title" class="text-xl font-bold text-gray-900">Pending applications</h3>
                <div class="w-12 h-1 bg-blue-600 mt-2"></div>
                <p class="text-sm text-gray-600 mt-2">
                    Organizations you've asked to join. You'll be notified when an admin responds.
                </p>
            </div>

            <p class="text-sm text-green-800" role="status" aria-live="polite">{{ withdrawnNotice }}</p>

            <p v-if="!applications.length" class="text-center py-6 text-gray-500">You have no applications waiting.</p>

            <ul v-else class="divide-y divide-gray-100">
                <li v-for="application in applications" :key="application.id" class="py-3 text-sm space-y-2">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <span class="font-medium text-gray-900">{{ orgName(application) }}</span>
                            <span class="ml-2 text-gray-500">Requested {{ application.createdAt }}</span>
                        </div>
                        <SecondaryButton v-if="confirmingId !== application.id" type="button" @click="confirmingId = application.id">withdraw</SecondaryButton>
                    </div>
                    <div v-if="confirmingId === application.id" class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 p-3">
                        <span class="text-gray-700">Withdraw your application to {{ orgName(application) }}? You can apply again later if they're still accepting members.</span>
                        <DangerButton type="button" :disabled="withdrawingId === application.id" @click="withdraw(application)">
                            {{ withdrawingId === application.id ? 'withdrawing…' : 'withdraw' }}
                        </DangerButton>
                        <SecondaryButton type="button" @click="confirmingId = null">keep it</SecondaryButton>
                    </div>
                    <p v-if="rowError[application.id]" class="text-red-700" role="alert">{{ rowError[application.id] }}</p>
                </li>
            </ul>

            <p v-if="loadError" class="mt-3 text-sm text-red-700" role="alert">{{ loadError }}</p>

            <div v-if="nextPageUrl" class="text-center mt-4">
                <button type="button" @click="loadMore" :disabled="loadingMore" class="text-sm text-blue-600 hover:underline">
                    {{ loadingMore ? 'loading...' : 'load more' }}
                </button>
            </div>
        </div>
    </section>
</template>
