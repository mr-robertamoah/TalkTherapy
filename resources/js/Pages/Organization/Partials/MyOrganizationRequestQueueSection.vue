<script setup>
import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import CompensationCounterOfferModal from '@/Components/CompensationCounterOfferModal.vue';
import useEnums from '@/Composables/useEnums';

const props = defineProps({
    initialRequests: {
        type: Object,
        required: true,
    },
})

// useAlert() is deliberately non-singleton -- calling it locally here would update state
// nothing renders, since only the parent MyOrganizations.vue mounts an <Alert>. Emit up instead.
const emit = defineEmits(['alert', 'compensation-accepted'])
const { RequestTypeEnum } = useEnums()
const currentUserId = usePage().props.auth.user?.id

const TYPE_LABELS = {
    [RequestTypeEnum.organizationCounsellorInvite]: 'Invite from an organization',
    [RequestTypeEnum.organizationCounsellorApplication]: 'Your application',
    [RequestTypeEnum.organizationCounsellorCompensationChange]: 'Compensation negotiation',
}

const requests = ref([...props.initialRequests.data])
const nextPageUrl = ref(props.initialRequests.links?.next ?? null)
const loadingMore = ref(false)
const respondingId = ref(null)
const counterOfferRequest = ref(null)

function partyLabel(party) {
    if (!party) return '--'
    if (party.deleted) return 'deleted'
    if (party.isOrganization) return party.name
    if (party.isCounsellor) return party.name
    return party.fullName ?? party.username
}

function proposedTermsSummary(request) {
    const terms = request.proposedTerms
    if (!terms) return '--'
    if (terms.type === 'FREE') return 'Free'
    if (terms.type === 'FIXED') return `${terms.currency} ${terms.amount}`
    if (terms.type === 'PERCENTAGE') return `${terms.percentage}% of ${terms.basis === 'COUNSELLOR_RATE' ? "counsellor's rate" : 'negotiated rate'}`
    return '--'
}

// The counsellor can act on a request exactly when it's currently awaiting THEIR decision --
// mirrors EnsureUserCanRespondToRequestAction's own "to" check server-side, and
// Organization/Partials/RequestQueueSection.vue's equivalent isActionable() for the org-admin side.
function isActionable(request) {
    return request.to?.isCounsellor && request.to?.userId === currentUserId
}

function isCompensationChange(request) {
    return request.type === RequestTypeEnum.organizationCounsellorCompensationChange
}

async function loadMore() {
    if (!nextPageUrl.value) return

    loadingMore.value = true
    await axios.get(nextPageUrl.value)
        .then((res) => {
            requests.value = [...requests.value, ...res.data.data]
            nextPageUrl.value = res.data.links?.next ?? null
        })
        .finally(() => {
            loadingMore.value = false
        })
}

// Re-fetches the first page from scratch -- used by the parent after the browse-and-apply
// section sends a new application, since this list has no other way to learn about it.
async function reload() {
    await axios.get(route('organizations.mine.requests'))
        .then((res) => {
            requests.value = [...res.data.data]
            nextPageUrl.value = res.data.links?.next ?? null
        })
}

defineExpose({ reload })

function removeFromQueue(requestId) {
    requests.value = requests.value.filter((r) => r.id !== requestId)
}

async function respond(request, response) {
    respondingId.value = request.id
    await axios.post(route('requests.respond', { requestId: request.id }), { response })
        .then(() => {
            emit('alert', { type: 'success', message: `Request ${response}.` })
            removeFromQueue(request.id)
            // Accepting a compensation-change Request updates the affiliation's current
            // compensation -- the sibling MyAffiliationsSection has no other way to learn its
            // "current compensation" cell just went stale (QA-caught, SCRUM-167).
            if (response === 'accepted' && isCompensationChange(request)) {
                emit('compensation-accepted')
            }
        })
        .catch((err) => {
            // requests.respond's failure shape uses an "error" key, not "message" (see
            // RequestController::respond()).
            emit('alert', { type: 'failed', message: err.response?.data?.error ?? 'Could not respond to the request.' })
        })
    respondingId.value = null
}

function openCounterOffer(request) {
    counterOfferRequest.value = request
}

function counterOfferSent() {
    removeFromQueue(counterOfferRequest.value.id)
    counterOfferRequest.value = null
}
</script>

<template>
    <div class="bg-white overflow-hidden shadow-xl border border-gray-100 sm:rounded-xl">
        <div class="p-6 sm:p-8">
            <div class="mb-4">
                <div class="text-xl font-bold text-gray-900">Pending Applications &amp; Invites</div>
                <div class="w-12 h-1 bg-blue-600 mt-2"></div>
                <div class="text-sm text-gray-600 mt-2">
                    Requests awaiting a decision -- distinct from an already-affiliated organization still awaiting compensation terms above.
                </div>
            </div>

            <div v-if="!requests.length" class="text-center py-8 text-gray-500">No pending requests.</div>

            <div v-else class="space-y-3">
                <div v-for="request in requests" :key="request.id" class="border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm font-semibold text-gray-900">{{ TYPE_LABELS[request.type] ?? request.type }}</div>
                            <div class="text-xs text-gray-500 mt-1">from {{ partyLabel(request.from) }} to {{ partyLabel(request.to) }}</div>
                            <template v-if="isCompensationChange(request)">
                                <div class="text-xs text-gray-700 mt-2">Proposed: {{ proposedTermsSummary(request) }}</div>
                                <div v-if="request.round" class="text-xs text-gray-500">Round {{ request.round }}</div>
                                <div v-if="request.expiresAt" class="text-xs text-gray-500">Expires {{ request.expiresAt }}</div>
                            </template>
                        </div>
                        <div v-if="!isActionable(request)" class="text-xs text-gray-500 italic">awaiting their response</div>
                    </div>

                    <div v-if="isActionable(request)" class="flex items-center justify-end space-x-2 mt-3">
                        <PrimaryButton :disabled="respondingId === request.id" @click="() => respond(request, 'accepted')">accept</PrimaryButton>
                        <DangerButton :disabled="respondingId === request.id" @click="() => respond(request, 'rejected')">reject</DangerButton>
                        <SecondaryButton v-if="isCompensationChange(request)" @click="() => openCounterOffer(request)">counter-offer</SecondaryButton>
                    </div>
                </div>

                <div v-if="nextPageUrl" class="text-center mt-4">
                    <button @click="loadMore" :disabled="loadingMore" class="text-sm text-blue-600 hover:underline">
                        {{ loadingMore ? 'loading...' : 'load more' }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <CompensationCounterOfferModal
        :request="counterOfferRequest"
        @close="() => counterOfferRequest = null"
        @sent="counterOfferSent"
        @alert="(alert) => emit('alert', alert)"
    />
</template>
