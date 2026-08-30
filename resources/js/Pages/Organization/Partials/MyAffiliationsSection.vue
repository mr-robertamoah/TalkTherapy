<script setup>
import { ref } from 'vue';

const props = defineProps({
    initialAffiliations: {
        type: Object,
        required: true,
    },
})

defineEmits(['alert'])

const affiliations = ref([...props.initialAffiliations.data])
const nextPageUrl = ref(props.initialAffiliations.links?.next ?? null)
const loadingMore = ref(false)

// AC4: an affiliation that exists but is PENDING (compensation not yet finalized) is a
// different state from a pending Request in the queue below -- worded distinctly here, mirroring
// Organization/Partials/CounsellorsSection.vue's own statusLabel() for the org-admin side.
function statusLabel(affiliation) {
    if (affiliation.status === 'PENDING') return 'Affiliated -- awaiting compensation agreement'
    if (affiliation.status === 'ACTIVE') return 'Active'
    return 'Ended'
}

function compensationSummary(affiliation) {
    const compensation = affiliation.compensation
    if (!compensation) return 'No compensation terms set'
    if (compensation.type === 'FREE') return 'Free'
    if (compensation.type === 'FIXED') return `${compensation.currency} ${compensation.amount}`
    if (compensation.type === 'PERCENTAGE') return `${compensation.percentage}% of ${compensation.basis === 'COUNSELLOR_RATE' ? "counsellor's rate" : 'negotiated rate'}`
    return '--'
}

async function loadMore() {
    if (!nextPageUrl.value) return

    loadingMore.value = true
    await axios.get(nextPageUrl.value)
        .then((res) => {
            affiliations.value = [...affiliations.value, ...res.data.data]
            nextPageUrl.value = res.data.links?.next ?? null
        })
        .finally(() => {
            loadingMore.value = false
        })
}

// Re-fetches the first page from scratch -- used by the parent after accepting a compensation
// change in the request queue below, since this list has no other way to learn its "current
// compensation" cell just went stale.
async function reload() {
    await axios.get(route('organizations.mine.counsellor_affiliations'))
        .then((res) => {
            affiliations.value = [...res.data.data]
            nextPageUrl.value = res.data.links?.next ?? null
        })
}

defineExpose({ reload })
</script>

<template>
    <div class="bg-white overflow-hidden shadow-xl border border-gray-100 sm:rounded-xl">
        <div class="p-6 sm:p-8">
            <div class="mb-4">
                <div class="text-xl font-bold text-gray-900">My Organizations</div>
                <div class="w-12 h-1 bg-blue-600 mt-2"></div>
            </div>

            <div v-if="!affiliations.length" class="text-center py-8 text-gray-500">You're not affiliated with any organization yet.</div>

            <div v-else class="overflow-x-auto">
                <table class="min-w-full text-sm text-left">
                    <thead>
                        <tr class="text-gray-500 uppercase text-xs">
                            <th class="py-2 pr-4">Organization</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 pr-4">Compensation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="affiliation in affiliations" :key="affiliation.id" class="border-t border-gray-100">
                            <td class="py-3 pr-4">{{ affiliation.organization?.deleted ? 'Deleted organization' : affiliation.organization?.name }}</td>
                            <td class="py-3 pr-4">{{ statusLabel(affiliation) }}</td>
                            <td class="py-3 pr-4">{{ compensationSummary(affiliation) }}</td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="nextPageUrl" class="text-center mt-4">
                    <button @click="loadMore" :disabled="loadingMore" class="text-sm text-blue-600 hover:underline">
                        {{ loadingMore ? 'loading...' : 'load more' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
