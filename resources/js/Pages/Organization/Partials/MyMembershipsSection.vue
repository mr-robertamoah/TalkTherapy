<script setup>
import { ref } from 'vue';

const props = defineProps({
    initialMemberships: {
        type: Object,
        required: true,
    },
})

defineEmits(['alert'])

const memberships = ref([...props.initialMemberships.data])
const nextPageUrl = ref(props.initialMemberships.links?.next ?? null)
const loadingMore = ref(false)

// OrganizationMemberStatusEnum only has ACTIVE/ENDED -- a membership is active immediately on
// creation (CreateOrganizationMemberAction), there's no PENDING affiliation state to distinguish
// here (unlike the counsellor-compensation case, membership itself is never gated on billing
// config being set).
function statusLabel(membership) {
    return membership.status === 'ACTIVE' ? 'Active' : 'Ended'
}

function billingSummary(membership) {
    const billingConfig = membership.billingConfig
    if (!billingConfig) return 'No billing config set'
    if (billingConfig.mode === 'RETAINER') return 'Retainer'
    return `Pay per use (${billingConfig.per === 'PER_THERAPY' ? 'per therapy' : 'per session'})`
}

async function loadMore() {
    if (!nextPageUrl.value) return

    loadingMore.value = true
    await axios.get(nextPageUrl.value)
        .then((res) => {
            memberships.value = [...memberships.value, ...res.data.data]
            nextPageUrl.value = res.data.links?.next ?? null
        })
        .finally(() => {
            loadingMore.value = false
        })
}
</script>

<template>
    <div class="bg-white overflow-hidden shadow-xl border border-gray-100 sm:rounded-xl">
        <div class="p-6 sm:p-8">
            <div class="mb-4">
                <div class="text-xl font-bold text-gray-900">My Memberships</div>
                <div class="w-12 h-1 bg-blue-600 mt-2"></div>
                <div class="text-sm text-gray-600 mt-2">
                    Organizations you belong to as a member. To respond to a pending invite, use the "Requests" menu.
                </div>
            </div>

            <div v-if="!memberships.length" class="text-center py-8 text-gray-500">You're not a member of any organization yet.</div>

            <div v-else class="overflow-x-auto">
                <table class="min-w-full text-sm text-left">
                    <thead>
                        <tr class="text-gray-500 uppercase text-xs">
                            <th class="py-2 pr-4">Organization</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 pr-4">Billing</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="membership in memberships" :key="membership.id" class="border-t border-gray-100">
                            <td class="py-3 pr-4">{{ membership.organization?.deleted ? 'Deleted organization' : membership.organization?.name }}</td>
                            <td class="py-3 pr-4">{{ statusLabel(membership) }}</td>
                            <td class="py-3 pr-4">{{ billingSummary(membership) }}</td>
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
