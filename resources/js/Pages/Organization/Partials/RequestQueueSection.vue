<script setup>
import { ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import CompensationCounterOfferModal from '@/Components/CompensationCounterOfferModal.vue';
import Pagination from '@/Components/Pagination.vue';
import useEnums from '@/Composables/useEnums';

const props = defineProps({
    organizationId: {
        type: [Number, String],
        required: true,
    },
    initialRequests: {
        type: Object,
        required: true,
    },
})

// useAlert() is deliberately non-singleton -- calling it locally here would update state
// nothing renders, since only the parent Show.vue mounts an <Alert>. Emit up instead.
const emit = defineEmits(['alert'])
const { RequestTypeEnum } = useEnums()

const TYPE_LABELS = {
    [RequestTypeEnum.organizationCounsellorInvite]: 'Counsellor invite',
    [RequestTypeEnum.organizationCounsellorApplication]: 'Counsellor application',
    [RequestTypeEnum.organizationMemberInvite]: 'Member invite',
    [RequestTypeEnum.organizationMemberApplication]: 'Member application',
    [RequestTypeEnum.organizationCounsellorCompensationChange]: 'Compensation negotiation',
}

const requests = ref([...props.initialRequests.data])
const meta = ref(props.initialRequests.meta)
const loadingPage = ref(false)
const respondingId = ref(null)
const counterOfferRequest = ref(null)

function partyLabel(party) {
    if (!party) return '--'
    if (party.deleted) return 'deleted'
    if (party.isOrganization) return party.name
    if (party.isCounsellor) return party.name
    return party.fullName ?? party.username
}

// The org can act on a request exactly when it's currently awaiting THIS org's decision --
// mirrors EnsureUserCanRespondToRequestAction's own "to" check server-side.
function isActionable(request) {
    return request.to?.isOrganization && request.to?.id === Number(props.organizationId)
}

function isCompensationChange(request) {
    return request.type === RequestTypeEnum.organizationCounsellorCompensationChange
}

async function goToPage(url) {
    if (!url) return

    loadingPage.value = true
    await axios.get(url)
        .then((res) => {
            requests.value = [...res.data.data]
            meta.value = res.data.meta
        })
        .finally(() => {
            loadingPage.value = false
        })
}

// Re-fetches the first page from scratch -- used by the parent after a sibling section sends
// a new invite/application, since this list has no other way to learn about it.
async function reload() {
    await axios.get(route('organizations.requests.index', { organizationId: props.organizationId }))
        .then((res) => {
            requests.value = [...res.data.data]
            meta.value = res.data.meta
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
        })
        .catch((err) => {
            // requests.respond's failure shape uses an "error" key, not "message" (see
            // RequestController::respond()) -- unlike compensation_counter_offer below, which
            // does use "message".
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
                    Requests awaiting a decision -- distinct from an already-affiliated counsellor/member still awaiting compensation/billing terms above.
                </div>
            </div>

            <div v-if="!requests.length" class="text-center py-8 text-gray-500">No pending requests.</div>

            <div v-else class="space-y-3">
                <div v-for="request in requests" :key="request.id" class="border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm font-semibold text-gray-900">{{ TYPE_LABELS[request.type] ?? request.type }}</div>
                            <div class="text-xs text-gray-500 mt-1">from {{ partyLabel(request.from) }} to {{ partyLabel(request.to) }}</div>
                        </div>
                        <div v-if="!isActionable(request)" class="text-xs text-gray-500 italic">awaiting their response</div>
                    </div>

                    <div v-if="isActionable(request)" class="flex items-center justify-end space-x-2 mt-3">
                        <PrimaryButton :disabled="respondingId === request.id" @click="() => respond(request, 'accepted')">accept</PrimaryButton>
                        <DangerButton :disabled="respondingId === request.id" @click="() => respond(request, 'rejected')">reject</DangerButton>
                        <SecondaryButton v-if="isCompensationChange(request)" @click="() => openCounterOffer(request)">counter-offer</SecondaryButton>
                    </div>
                </div>

                <div v-if="loadingPage" class="text-center mt-4 text-sm text-gray-500">loading...</div>
                <Pagination v-else :meta="meta" @navigate="goToPage" />
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
