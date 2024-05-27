<template>
    <div v-bind="$attrs" class="rounded bg-stone-200">
        <UserComponent
            :user="isWard ? data.ward : data.guardian"
        />
        <div class="flex justify-end my-1 mr-2" v-if="isWard">
            <PrimaryButton @click="() => showModal('delete')">remove</PrimaryButton>
        </div>
    </div>

    <MiniModal
        :show="modalData.type == 'delete' && modalData.show"
        @close="closeModal"
    >
        <div class="select-none">
            <div class="text-red-700 text-center font-bold tracking-wide">Remove Ward</div>
            <hr class="my-2">
            <FormLoader :danger="true" :show="loading && modalData.type == 'delete'" :text="'removing ward'"/>
            <div class="text-red-700 my-4 w-[90%] mx-auto text-center">Are sure you want to remove this Ward?</div>
            <div class="flex p-4 items-center justify-end mx-auto w-[90%] md:w-[75%]">
                <PrimaryButton @click="closeModal">cancel</PrimaryButton>
                <DangerButton class="ml-2" @click="removeGuardianship">remove</DangerButton>
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
import UserComponent from './UserComponent.vue';
import FormLoader from './FormLoader.vue';
import DangerButton from './DangerButton.vue';
import MiniModal from './MiniModal.vue';
import PrimaryButton from './PrimaryButton.vue';
import useModal from '@/Composables/useModal';
import { ref } from 'vue';
import useAlert from '@/Composables/useAlert';
import Alert from './Alert.vue';


const { modalData, showModal, closeModal } = useModal()
const { alertData, clearAlertData, setSuccessAlertData, setFailedAlertData } = useAlert()

const emits = defineEmits(['removed'])

const props = defineProps({
    data: {
        default: null
    },
    isWard: {
        default: false
    },
})

const loading = ref(false)

async function removeGuardianship() {
    if (!props.data?.id) return

    loading.value = true    

    await axios.delete(route(`api.guardianship.delete`, { guardianshipId: props.data.id }))
        .then((res) => {
            console.log(res)
            
            setSuccessAlertData({
                message: 'The guardianship has been successfully removed.',
                time: 4000
            })

            emits('removed', props.data)
            closeModal()
        })
        .catch((err) => {
            console.log(err)
            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                    time: 5000,
                })
                return
            }

            if (err.alert) {
                setFailedAlertData({
                    message: err.alert,
                    time: 5000,
                })
                return
            }

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
                time: 4000
            })
        })
        .finally(() => {
            loading.value = false
        })
}
</script>