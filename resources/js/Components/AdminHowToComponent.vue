<template>
    <div v-bind="$attrs" class="p-4 bg-slate-200 rounded-lg select-none">
        <div class="mb-4 text-gray-800 text-center text-sm font-bold tracking-wide">{{ howTo.name }}</div>
        <div class="text-sm text-justify text-gray-600 mx-auto w-[90%]">{{ howTo.description }}</div>
        <div class="my-4 p-2 flex justify-start items-center overflow-hidden overflow-x-auto space-x-3">
            <AdminHowToStepComponent
                v-for="(howToStep, idx) in howTo.howToSteps.sort((a, b) => a.position - b.position)"
                :key="idx"
                :howToStep="howToStep"
                class="w-[250px] sm:w-[400px] shrink-0"
            />
        </div>
        <div class="text-xs text-gray-600 text-end mt-4 mb-2">{{ toDiffForHumans(howTo.createdAt) }}</div>
        <div class="flex flex-col justify-start items-center mt-3">
            
            <div
                @click="() => showActions = !showActions"
                class="text-gray-500 text-xs cursor-pointer hover:bg-gray-300 hover:text-gray-600 p-2 rounded">
                {{ showActions ? 'hide actions' : 'show actions' }}
            </div>
        </div>
        <div class="flex overflow-hidden overflow-x-auto space-x-3 justify-start items-center mt-3" v-if="showActions">
            <PrimaryButton @click="() => showModal('update')">update</PrimaryButton>        
            <DangerButton @click="() => showModal('delete')">delete</DangerButton>      
        </div>
    </div>

    <MiniModal
        :show="modalData.show && modalData.type == 'delete'"
        @close="closeModal"
    >
        <div>
            <FormLoader :danger="true" class="mx-auto" :show="loading" :text="'deleting how-to'"/>
            <div class="text-gray-600 text-center font-bold tracking-wide">
                Delete '{{ howTo.name }}' How-to
            </div>

            <hr class="my-2">

            <div class="relative">
                <div class="my-4 text-sm text-red-700 text-center w-[90%] mx-auto font-bold tracking-wide">
                    Are you sure you want to delete this how-to?
                </div>
            </div>

            <div class="flex space-x-2 justify-end items-center w-full p-2">
                <PrimaryButton @click="() => closeModal()" class="shrink-0">cancel</PrimaryButton>
                <DangerButton @click="deleteUser" class="shrink-0">delete</DangerButton>
            </div>
        </div>

    </MiniModal>

    <AdminHowToUpdateModal
        :show="modalData.show && modalData.type == 'update'"
        @close="closeModal"
        @updated="updateHowTo"
        :howTo="howTo"
    />

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>

<script setup>
import useAlert from "@/Composables/useAlert"
import useModal from "@/Composables/useModal"
import Alert from "@/Components/Alert.vue"
import AdminHowToUpdateModal from "@/Components/AdminHowToUpdateModal.vue"
import { ref } from "vue"
import DangerButton from "./DangerButton.vue"
import PrimaryButton from "./PrimaryButton.vue"
import FormLoader from "./FormLoader.vue"
import MiniModal from "./MiniModal.vue"
import AdminHowToStepComponent from "./AdminHowToStepComponent.vue"
import useLocalDateTimed from "@/Composables/useLocalDateTime"


const { toDiffForHumans } = useLocalDateTimed()
const { alertData, setFailedAlertData, clearAlertData, setSuccessAlertData } = useAlert()
const { modalData, closeModal, showModal } = useModal()

const emits = defineEmits(['deleted', 'updated'])

const props = defineProps({
    howTo: {
        default: null
    }
})

const loading = ref(false)
const showActions = ref(false)

function updateHowTo(howTo) {
    emits('updated', howTo)
}
  
async function deleteUser() {
    loading.value = true
    
    await axios
    .delete(route(`admin.how-tos.delete`, { howToId: props.howTo.id}))
    .then((res) => {
        console.log(res)
        setSuccessAlertData({
            message: `'${props.howTo.name}' how-to has successfully been deleted.`,
            time: 5000
        })
        emits('deleted', res.data.howTo)
        closeModal()
    })
    .catch((err) => {
        console.log(err)
        if (err.response?.data?.message) {
            setFailedAlertData({
                message: err.response.data.message,
                time: 4000,
            })
            return
        }

        setFailedAlertData({
            message: 'Something unfortunate happened. Please try again later.',
            time: 4000,
        })
    })
    .finally(() => {
        loading.value = false
    })
}

</script>