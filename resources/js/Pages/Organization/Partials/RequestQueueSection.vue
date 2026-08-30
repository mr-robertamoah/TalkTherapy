<script setup>
import { ref, computed } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import FormLoader from '@/Components/FormLoader.vue';
import useEnums from '@/Composables/useEnums';
import useErrorHandler from '@/Composables/useErrorHandler';

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
const { setErrorData } = useErrorHandler()

const TYPE_LABELS = {
    [RequestTypeEnum.organizationCounsellorInvite]: 'Counsellor invite',
    [RequestTypeEnum.organizationCounsellorApplication]: 'Counsellor application',
    [RequestTypeEnum.organizationMemberInvite]: 'Member invite',
    [RequestTypeEnum.organizationMemberApplication]: 'Member application',
    [RequestTypeEnum.organizationCounsellorCompensationChange]: 'Compensation negotiation',
}

const requests = ref([...props.initialRequests.data])
const nextPageUrl = ref(props.initialRequests.links?.next ?? null)
const loadingMore = ref(false)
const respondingId = ref(null)
const counterOfferRequest = ref(null)
const counterOfferForm = ref({ type: 'FIXED', amount: '', currency: 'USD', percentage: '', basis: 'COUNSELLOR_RATE', expiryDays: '' })
const counterOfferErrors = ref({})
const sendingCounterOffer = ref(false)

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

// Re-fetches the first page from scratch -- used by the parent after a sibling section sends
// a new invite/application, since this list has no other way to learn about it.
async function reload() {
    await axios.get(route('organizations.requests.index', { organizationId: props.organizationId }))
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
    counterOfferForm.value = { type: 'FIXED', amount: '', currency: 'USD', percentage: '', basis: 'COUNSELLOR_RATE', expiryDays: '' }
    counterOfferErrors.value = {}
}

// Only send the fields relevant to the selected type -- CreateOrganizationCounsellorCompensationRequest
// (reused by the counter-offer endpoint) cross-validates e.g. "a fixed compensation cannot carry
// a percentage or basis", so leftover defaults from a previously-selected type (or empty strings
// that the DTO layer would coerce to 0, not null) reliably tripped that check for every type.
function buildCounterOfferPayload() {
    const payload = { type: counterOfferForm.value.type }

    if (counterOfferForm.value.type === 'FIXED') {
        payload.amount = counterOfferForm.value.amount
        payload.currency = counterOfferForm.value.currency
    }

    if (counterOfferForm.value.type === 'PERCENTAGE') {
        payload.percentage = counterOfferForm.value.percentage
        payload.basis = counterOfferForm.value.basis
    }

    if (counterOfferForm.value.expiryDays) {
        payload.expiryDays = counterOfferForm.value.expiryDays
    }

    return payload
}

async function sendCounterOffer() {
    sendingCounterOffer.value = true
    counterOfferErrors.value = {}

    await axios.post(route('requests.compensation_counter_offer', { requestId: counterOfferRequest.value.id }), buildCounterOfferPayload())
        .then(() => {
            emit('alert', { type: 'success', message: 'Counter-offer sent.' })
            removeFromQueue(counterOfferRequest.value.id)
            counterOfferRequest.value = null
        })
        .catch((err) => {
            // Laravel's validation shape is {field: [messages]} -- setErrorData unwraps each to
            // a plain string InputError can render, rather than displaying the raw array.
            if (err.response?.data?.errors) {
                setErrorData(counterOfferErrors, err.response.data.errors, ['type', 'amount', 'currency', 'percentage', 'basis', 'expiryDays'])
            }
            emit('alert', { type: 'failed', message: err.response?.data?.message ?? 'Could not send the counter-offer.' })
        })

    sendingCounterOffer.value = false
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
                        <button v-if="isCompensationChange(request)" @click="() => openCounterOffer(request)" class="text-sm text-blue-600 hover:underline">
                            counter-offer
                        </button>
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

    <Modal :show="!!counterOfferRequest" @close="() => counterOfferRequest = null">
        <div class="p-6" v-if="counterOfferRequest">
            <div class="text-lg font-bold text-gray-900 mb-4">Counter-Offer Compensation Terms</div>
            <FormLoader :show="sendingCounterOffer" text="sending" />

            <form @submit.prevent="sendCounterOffer" class="space-y-4">
                <div>
                    <InputLabel for="type" value="Type" />
                    <select id="type" v-model="counterOfferForm.type" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <option value="FIXED">Fixed</option>
                        <option value="PERCENTAGE">Percentage</option>
                        <option value="FREE">Free</option>
                    </select>
                    <InputError class="mt-2" :message="counterOfferErrors.type" />
                </div>

                <template v-if="counterOfferForm.type === 'FIXED'">
                    <div>
                        <InputLabel for="amount" value="Amount" />
                        <TextInput id="amount" type="number" class="mt-1 block w-full" v-model="counterOfferForm.amount" />
                        <InputError class="mt-2" :message="counterOfferErrors.amount" />
                    </div>
                    <div>
                        <InputLabel for="currency" value="Currency" />
                        <TextInput id="currency" type="text" class="mt-1 block w-full" v-model="counterOfferForm.currency" />
                        <InputError class="mt-2" :message="counterOfferErrors.currency" />
                    </div>
                </template>

                <template v-if="counterOfferForm.type === 'PERCENTAGE'">
                    <div>
                        <InputLabel for="percentage" value="Percentage" />
                        <TextInput id="percentage" type="number" class="mt-1 block w-full" v-model="counterOfferForm.percentage" />
                        <InputError class="mt-2" :message="counterOfferErrors.percentage" />
                    </div>
                    <div>
                        <InputLabel for="basis" value="Basis" />
                        <select id="basis" v-model="counterOfferForm.basis" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="COUNSELLOR_RATE">Counsellor's rate</option>
                            <option value="NEGOTIATED_RATE">Negotiated rate</option>
                        </select>
                        <InputError class="mt-2" :message="counterOfferErrors.basis" />
                    </div>
                </template>

                <div>
                    <InputLabel for="expiryDays" value="Expiry (days, optional)" />
                    <TextInput id="expiryDays" type="number" class="mt-1 block w-full" v-model="counterOfferForm.expiryDays" />
                    <InputError class="mt-2" :message="counterOfferErrors.expiryDays" />
                </div>

                <div class="flex items-center justify-end">
                    <PrimaryButton :disabled="sendingCounterOffer">send counter-offer</PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>
