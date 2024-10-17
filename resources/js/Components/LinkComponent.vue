<template>
    <div class="rounded p-2" v-bind="$attrs">
        <div
            class="w-full flex justify-between items-center mr-2 text-xs mb-2"
        >
            <div class="text-xs text-gray-400 text-end my-2">{{ link.createdAt }}</div>
            <div class="flex justify-center items-center gap-2">
                <div
                    v-if="status"
                    class="p-1 rounded w-full ml-auto text-xs text-center"
                    :class="[!['deleting', 'deactivating'].includes(status) ? 'bg-green-200 text-green-600' : 'bg-red-200 text-red-600']"
                >{{ status }}</div>
                <div
                    class="p-1 rounded w-fit ml-auto text-xs"
                    :class="{'bg-blue-200 text-blue-600': link.state == 'ACTIVE', 'bg-red-200 text-red-600': link.state == 'IN_ACTIVE'}"
                >{{ computedState }}</div>
            </div>
        </div>
        <div v-if="displayDetails" class="py-2">
            <div class="text-gray-600 font-bold mb-1">Details</div>
            <div class="flex gap-2 ml-2">
                <div
                    class="flex justify-center items-center p-2 rounded bg-gray-800"
                >
                    to: <span class="ml-2" 
                            :class="{'capitalize': link.to.isCounsellor}"
                        >
                            {{ link.to.isCounsellor ? link.to.name : link.to.username }}
                        </span>
                </div>
                <div
                    class="flex justify-center items-center p-2 rounded bg-gray-800"
                >
                    for: <span class="capitalize ml-2">{{ link.forType }}</span>
                </div>
            </div>
            <div class="text-xs text-gray-400 text-center my-2">only this {{ link.to.isCounsellor ? 'counsellor' : 'user' }} can use this link.</div>
        </div>
        <div class="flex justify-start items-center space-x-2 text-sm text-gray-600 overflow-hidden overflow-x-auto">
            <div class="flex justify-end my-2">
                <CopyIcon
                    @click="copyLink"
                    title="copy"
                    class="cursor-pointer w-5 h-5 text-green-600"/>
            </div>
            <div class="underline text-xs text-blue-600 text-ellipsis">www.talktherapy.tech/links/{{ link.uuid }}</div>
        </div>
        <div class="flex flex-wrap justify-end space-x-2 items-center" v-if="!loading">
            <div 
                v-if="link.to || link.forType"
                @click="() => displayDetails= !displayDetails"
                class="ml-2 text-xs underline text-gray-600 cursor-pointer hover:text-blue-600"
            >{{ displayDetails ? 'hide' : 'show' }} details</div>
            <div 
                v-if="link.state == 'IN_ACTIVE'"
                @click="changeStatus"
                class="ml-2 text-xs underline text-gray-600 cursor-pointer hover:text-blue-600"
            >activate</div>
            <div 
                v-if="link.state == 'ACTIVE'"
                @click="changeStatus"
                class="ml-2 text-xs underline text-gray-600 cursor-pointer hover:text-red-600"
            >deactivate</div>
            <div
                @click="() => showModal('delete')"
                class="ml-2 text-xs underline text-gray-600 cursor-pointer hover:text-red-600"
            >delete</div>
        </div>
    </div>

    <MiniModal
        :show="modalData.type == 'delete' && modalData.show"
        @close="closeModal"
    >
        <div>
            <div class="text-red-700 text-center font-bold tracking-wide">Remove Link</div>
            <hr class="my-2">
            <FormLoader :danger="true" :show="loading && modalData.type == 'delete'" :text="'deleting link'"/>
            <div class="text-red-700 my-4 w-[90%] mx-auto text-center">Are sure you want to delete this Link?</div>
            <div class="flex p-4 items-center justify-end mx-auto w-[90%] md:w-[75%]">
                <PrimaryButton @click="closeModal">cancel</PrimaryButton>
                <DangerButton class="ml-2" @click="deleteTheLink">delete</DangerButton>
            </div>
        </div>
    </MiniModal>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>

<script setup>
import { ref } from 'vue';
import CopyIcon from '../Icons/CopyIcon.vue'
import PrimaryButton from './PrimaryButton.vue';
import DangerButton from './DangerButton.vue';
import MiniModal from './MiniModal.vue';
import useModal from '@/Composables/useModal';
import FormLoader from './FormLoader.vue';
import Alert from './Alert.vue';
import useAlert from '@/Composables/useAlert';
import useAppLink from '@/Composables/useAppLink';
import { computed } from 'vue';


const { modalData, closeModal, showModal } = useModal()
const { alertData, clearAlertData, setFailedAlertData } = useAlert()
const { deleteLink, changeLinkStatus } = useAppLink()

const emits = defineEmits(['updated', 'deleted'])

const props = defineProps({
    link: {
        default: null
    }
})

const displayDetails = ref(false)
const loading = ref(false)
const status = ref('')

const computedState = computed(() => {
    return props.link?.state == 'ACTIVE' ? 'active' : 'inactive'
})

function showDetails() {
    displayDetails.value = true
}

function hideDetails() {
    displayDetails.value = false
}

async function deleteTheLink() {
    loading.value = true
    status.value = 'deleting'
    
    const link = await deleteLink({linkId: props.link.id})

    loading.value = false
    status.value = ''

    if (!link) return

    emits('deleted', link)
}

function copyLink() {
    status.value = 'copying'
    setTimeout(() => {
        status.value = 'copied.'
    }, 1000)
    navigator.clipboard.writeText(`www.talktherapy.tech/links/${props.link.uuid}`)
    setTimeout(() => {
        status.value = ''
    }, 2000)
}

async function changeStatus() {
    loading.value = true
    status.value = props.link.state == 'ACTIVE' ? 'deactivating' : 'activating'

    const link = await changeLinkStatus({linkId: props.link.id})

    loading.value = false
    status.value = ''

    if (!link) return

    emits('updated', link)
}

</script>