<script setup>
import { usePage, router } from '@inertiajs/vue3';
import { computed, ref, watchEffect } from 'vue';
import PrimaryButton from './PrimaryButton.vue';
import DangerButton from './DangerButton.vue';
import useAlert from '@/Composables/useAlert';
import Alert from './Alert.vue';
import FormLoader from './FormLoader.vue';
import StyledLink from './StyledLink.vue';
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

const showActions = ref(false)
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
    }[props.request?.type]
})
const computedStatus = computed(() => {
    return {
        [RequestStatusEnum.accepted]: computedIsFrom.value ? 'Your request has been accepted.' : 'You accepted the request.',
        [RequestStatusEnum.rejected]: computedIsFrom.value ? 'Your request has been rejected.' : 'You rejected the request.',
        [RequestStatusEnum.pending]: computedIsFrom.value ? 'Your request is pending.' : 'You have not responded to this request.',
    }[status.value]
})
const computedStatusClasses = computed(() => {
    return {
        [RequestStatusEnum.accepted]: 'text-green-800 bg-green-300',
        [RequestStatusEnum.rejected]: 'text-red-800 bg-red-300',
        [RequestStatusEnum.pending]: 'text-yellow-800 bg-yellow-300',
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

    if (props.request.to.isCounsellor)
        return userId == props.request.to.userId 

    return userId == props.request.to.id
})

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
    <div v-bind="$attrs" class="bg-stone-300 rounded w-full max-w-[400px] select-none p-2 relative">
        <FormLoader v-if="responding" class="relative" :show="responding" :text="'responding to request'"/>
        <div class="text-gray-600 text-sm tracking-wide">{{ computedTypeMessage }}<span 
                v-if="[RequestTypeEnum.discussion, RequestTypeEnum.therapy].includes(request.type)"
                class="ml-2 text-xs text-blue-600 cursor-pointer"
                @click="visitTherapy"
            >view therapy</span></div>
        <div class="flex justify-end items-center w-full text-xs my-2">
            <div v-if="computedIsFrom && request.to" class="flex text-gray-600">to: {{ request.to.isCounsellor ? request.to.name : `@${request.to.username}` }}</div>
            <div v-if="computedIsTo && request.from" class="flex text-gray-600">from: {{ request.from.isCounsellor ? request.from.name : `@${request.from.username}` }}</div>
            <div 
                @dblclick="() => {
                    if (computedIsTo)
                        showActions = !showActions
                }"
                :title="computedIsTo && request.status == 'PENDING' ? 'double click to show/hide actions' : ''"
                :class="computedStatusClasses"
                class="text-center p-2 rounded w-fit ml-auto cursor-pointer"
            >{{ computedStatus }}</div>
        </div>
            
        <template v-if="showActions">
            <div class="flex justify-end items-center space-x-2 p-2 overflow-hidden overflow-x-auto">
                    <StyledLink class="shrink-0" v-if="request.type == RequestTypeEnum.therapy" :href="route('therapies.get', { therapyId: request.for.id })" :text="'visit therapy page'"/>
                    <template v-if="request.status == RequestStatusEnum.pending && computedIsTo">
                        <PrimaryButton :disabled="responding" @click="() => clickedResponse('accepted')" class="shrink-0">accept</PrimaryButton>
                        <DangerButton :disabled="responding" @click="() => clickedResponse('rejected')" class="shrink-0">reject</DangerButton>
                    </template>
            </div>
        </template>
    </div>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>