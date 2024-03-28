<script setup>
import { computed } from 'vue';


const props = defineProps({
    request: {
        default: null
    }
})

const RequestTypes = {
    counsellor: 'COUNSELLOR_VERIFICATION_REQUEST',
    administrator: 'ADMINISTRATION_REQUEST',
    therapy: 'THERAPY_ASSISTANCE_REQUEST',
    groupTherapy: 'GROUP_THERAPY_ASSISTANCE_REQUEST',
}
const RequestStatuses = {
    accepted: 'ACCEPTED',
    pending: 'PENDING',
    rejected: 'REJECTED',
}

const computedTypeMessage = computed(() => {
    return {
        [RequestTypes.counsellor]: 'You have sent a counsellor verification request.',
        [RequestTypes.administrator]: '',
        [RequestTypes.therapy]: '',
        [RequestTypes.groupTherapy]: '',
    }[props.request?.type]
})
const computedStatus = computed(() => {
    return {
        [RequestStatuses.accepted]: 'your request has been accepted.',
        [RequestStatuses.rejected]: 'your request has been rejected.',
        [RequestStatuses.pending]: 'your request is pending.',
    }[props.request?.status]
})
const computedStatusClasses = computed(() => {
    return {
        [RequestStatuses.accepted]: 'text-green-800 bg-green-300',
        [RequestStatuses.rejected]: 'text-red-800 bg-red-300',
        [RequestStatuses.pending]: 'text-yellow-800 bg-yellow-300',
    }[props.request?.status]
})
</script>

<template>
    <div class="bg-stone-300 rounded w-full max-w-[400px] select-none p-2">
        <div class="">{{ computedTypeMessage }}</div>
        <div :class="computedStatusClasses" class="text-sm my-2 text-end p-2 rounded w-fit ml-auto">{{ computedStatus }}</div>
    </div>
</template>