<script setup>
import { usePage, router } from '@inertiajs/vue3';
import { computed, ref, watchEffect } from 'vue';
import PrimaryButton from './PrimaryButton.vue';
import DangerButton from './DangerButton.vue';
import useAlert from '@/Composables/useAlert';
import Alert from './Alert.vue';
import FormLoader from './FormLoader.vue';
import useEnums from '@/Composables/useEnums';


const { alertData, clearAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()

const props = defineProps({
    request: {
        default: null
    }
})

const emits = defineEmits(['onData', 'alert'])

const { RequestStatusEnum, RequestTypeEnum } = useEnums()
const userId = usePage().props.auth.user?.id;

const responding = ref(false)
const status = ref(null)

watchEffect(() => {
    if (props.request.status)
        status.value = props.request.status
})

const computedTypeMessage = computed(() => {
    return {
        [RequestTypeEnum.discussion]: computedIsFrom.value ? `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}sent a request inviting counsellor for a discussion in a ${props.request.for.forType}.` : `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}received a request to take part in a discussion for a ${props.request.for.forType}.`,
        [RequestTypeEnum.guardianship]: computedIsFrom.value ? `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}sent a guardianship request.` : `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}received a guardianship request.`,
        [RequestTypeEnum.counsellor]: computedIsFrom.value ? `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}sent a counsellor verification request.` : `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}received a counsellor verification request.`,
        [RequestTypeEnum.administrator]: computedIsFrom.value ? '' : 'You accepted the request.',
        [RequestTypeEnum.therapy]: computedIsFrom.value ? `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}sent an assistance request for therapy with name: ${props.request.for.name}.` 
            : `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}received an assistance request for therapy with name: ${props.request.for.name}.`,
        [RequestTypeEnum.groupTherapy]: computedIsFrom.value ? '' : 'You accepted the request.',
        // SCRUM-175: previously missing entirely (fell through to `undefined`, a blank line) --
        // this is SCRUM-72's join-a-group-therapy request type, distinct from RequestTypeEnum.groupTherapy above.
        [RequestTypeEnum.groupTherapyMembership]: computedIsFrom.value ? `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}requested to join the group therapy "${props.request.for.name}".`
            : `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}received a request to join your group therapy "${props.request.for.name}".`,
        // SCRUM-167: these org-context types previously fell through to `undefined` here (blank
        // text) -- this generic badge is also where a counsellor/user sees them in their own
        // personal Requests modal, alongside the fuller detail on the dedicated my-organizations
        // dashboard (which additionally supports counter-offering the compensation-change type).
        [RequestTypeEnum.organizationCounsellorInvite]: computedIsFrom.value ? `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}invited a counsellor to affiliate with your organization.` : `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}been invited to affiliate as a counsellor with an organization.`,
        [RequestTypeEnum.organizationCounsellorApplication]: computedIsFrom.value ? `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}applied to affiliate with an organization as a counsellor.` : `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}received an application to affiliate as a counsellor with your organization.`,
        [RequestTypeEnum.organizationMemberInvite]: computedIsFrom.value ? `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}invited a member to join your organization.` : `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}been invited to join an organization as a member.`,
        [RequestTypeEnum.organizationMemberApplication]: computedIsFrom.value ? `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}applied to join an organization as a member.` : `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}received an application to join your organization as a member.`,
        [RequestTypeEnum.organizationCounsellorCompensationChange]: 'A compensation negotiation for an organization affiliation is in progress.',
        // TT-4.10e/SCRUM-294: `for` is the User whose dob is changing -- may or may not be the
        // same person as `from` (an admin can initiate this on someone else's behalf), so this
        // deliberately names `for` explicitly rather than assuming "your own" the way most other
        // types above do.
        [RequestTypeEnum.dobChange]: computedIsFrom.value
            ? `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}requested a date-of-birth change for ${partyLabel(props.request.for)}.`
            : `You ${props.request.status == RequestStatusEnum.pending ? 'have ' : ''}received a request to approve a date-of-birth change for ${partyLabel(props.request.for)}.`,
    }[props.request?.type]
})
const computedStatus = computed(() => {
    return {
        [RequestStatusEnum.accepted]: computedIsFrom.value ? 'Your request has been accepted.' : 'You accepted the request.',
        [RequestStatusEnum.rejected]: computedIsFrom.value ? 'Your request has been rejected.' : 'You rejected the request.',
        [RequestStatusEnum.pending]: computedIsFrom.value ? 'Your request is pending.' : 'You have not responded to this request.',
    }[status.value]
})
// SCRUM-298: softer, calmer chip colors -- the previous bg-*-300/text-*-800 pairing was
// jarringly saturated next to the rest of the app's muted palette (e.g. PaymentRequiredBanner's
// bg-blue-50/border-blue-200).
const computedStatusClasses = computed(() => {
    return {
        [RequestStatusEnum.accepted]: 'text-green-700 bg-green-50 border border-green-200',
        [RequestStatusEnum.rejected]: 'text-red-700 bg-red-50 border border-red-200',
        [RequestStatusEnum.pending]: 'text-amber-700 bg-amber-50 border border-amber-200',
    }[props.request?.status]
})
const computedStatusLabel = computed(() => {
    return {
        [RequestStatusEnum.accepted]: 'Accepted',
        [RequestStatusEnum.rejected]: 'Rejected',
        [RequestStatusEnum.pending]: 'Pending',
    }[props.request?.status]
})
const computedIsFrom = computed(() => {
    if (!props.request.from) return

    if (props.request.from.isCounsellor)
        return userId == props.request.from.userId 

    return userId == props.request.from.id
})
const computedIsTo = computed(() => {
    if (!props.request.from) return

    // TT-4.10f/SCRUM-295: dobChange's respondent isn't just `to` -- any one of the target's
    // CURRENT guardians (or any admin) may act, re-verified live server-side rather than by a
    // `to`-identity match here (a ward's second guardian, or `to` after their own guardianship was
    // revoked, would otherwise be wrongly shown as not the recipient, or wrongly shown as still
    // the recipient, respectively). Trust the backend's own answer instead of re-deriving this --
    // duplicating that authorization logic here is exactly what drifted out of sync before.
    if (props.request.type == RequestTypeEnum.dobChange)
        return !!props.request.dobChange?.isRespondent

    // Defensive: dobChange is the only type with a genuinely null `to` today (handled above) --
    // guards against a throw below if that ever changes for some other type.
    if (!props.request.to) return

    if (props.request.to.isCounsellor)
        return userId == props.request.to.userId

    return userId == props.request.to.id
})

// SCRUM-298: collapses the exact three-way OR the template's wrapping v-if and its inner
// v-if/v-else-if/v-if branches would otherwise have to repeat in sync -- a future edit to one
// inner condition without updating the wrapper could silently render empty spacing with no
// content.
const computedShowsPartyLine = computed(() => {
    return (computedIsFrom.value && props.request.to) ||
        (computedIsFrom.value && !props.request.to && props.request.type == RequestTypeEnum.dobChange) ||
        (computedIsTo.value && props.request.from)
})

// SCRUM-168: the from/to party can also be an Organization (org invite/application/compensation
// types) -- falling through to `@${party.username}` for a party with no `username` rendered a
// literal "@undefined" here before this was ever exercised for a plain member. Mirrors
// Organization/Partials/RequestQueueSection.vue's own partyLabel() for the org-scoped queues.
function partyLabel(party) {
    if (!party) return '--'
    if (party.deleted) return 'deleted'
    if (party.isOrganization) return party.name
    if (party.isCounsellor) return party.name
    return `@${party.username}`
}

function visitTherapy() {
    let therapyId
    if (RequestTypeEnum.discussion == props.request.type)
        therapyId = props.request.for.forId
    else
        therapyId = props.request.for.id

    router.get(route('therapies.get', {therapyId}))
}

async function clickedResponse(response) {
    responding.value = true
    await axios.post(route('requests.respond', { requestId: props.request.id }), { response })
        .then((res) => {
            console.log(res)

            emits('onData', res.data.request)
            status.value = res.data.request.status
            if (res.data.request?.status !== status.value && status.value == RequestStatusEnum.accepted) {
                emits('alert', {
                    type: 'success',
                    time: 5000,
                    message: res.data.request.type == RequestTypeEnum.therapy ? 'Your response was successful, but another counsellor may have already accepted to assist.' : ''
                })
                return
            }

            emits('alert', {
                type: 'success',
                time: 5000,
                message: 'You have successful responded to the request.'
            })
        })
        .catch((err) => {
            console.log(err)

            emits('alert', {
                type: 'failed',
                time: 5000,
                message: 'Something unfortunate happened. Please try again shortly.'
            })
        })
    responding.value = false
}
</script>

<template>
    <div v-bind="$attrs" class="bg-white shadow-sm border border-gray-200 rounded-lg w-full max-w-[440px] p-4 relative">
        <FormLoader v-if="responding" class="relative" :show="responding" :text="'responding to request'"/>

        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 text-sm text-gray-900 leading-snug">{{ computedTypeMessage }}<span
                    v-if="[RequestTypeEnum.discussion, RequestTypeEnum.therapy].includes(request.type)"
                    class="ml-1 text-xs text-blue-600 hover:text-blue-800 cursor-pointer whitespace-nowrap"
                    @click="visitTherapy"
                >view therapy</span></div>
            <span
                :class="computedStatusClasses"
                class="shrink-0 px-2.5 py-1 rounded-full text-xs font-medium"
            >{{ computedStatusLabel }}</span>
        </div>

        <div
            v-if="request.type == RequestTypeEnum.dobChange"
            class="text-xs text-gray-500 mt-2"
        >proposed: {{ request.dobChange?.newDob ? new Date(request.dobChange.newDob).toDateString() : '--' }} (currently: {{ request.dobChange?.priorDob ? new Date(request.dobChange.priorDob).toDateString() : '--' }})</div>

        <div
            v-if="computedShowsPartyLine"
            class="flex flex-wrap gap-x-4 text-xs text-gray-500 mt-2"
        >
            <div v-if="computedIsFrom && request.to">to: {{ partyLabel(request.to) }}</div>
            <div v-else-if="computedIsFrom && !request.to && request.type == RequestTypeEnum.dobChange">to: any admin</div>
            <div v-if="computedIsTo && request.from">from: {{ partyLabel(request.from) }}</div>
        </div>

        <div class="text-xs text-gray-500 mt-2">{{ computedStatus }}</div>

        <div v-if="request.status == RequestStatusEnum.pending && computedIsTo" class="flex justify-end gap-2 mt-3">
            <PrimaryButton :disabled="responding" @click="() => clickedResponse('accepted')">accept</PrimaryButton>
            <DangerButton :disabled="responding" @click="() => clickedResponse('rejected')">reject</DangerButton>
        </div>
    </div>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>