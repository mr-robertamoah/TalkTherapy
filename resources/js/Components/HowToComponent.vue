<template>
    <div v-bind="$attrs" class="p-4 bg-slate-200 rounded-lg select-none cursor-pointer" @dblclick="() => showModal('how-to')">
        <div class="mb-4 text-gray-800 text-center text-sm font-bold tracking-wide">{{ howTo.name }}</div>
        <div class="text-sm text-justify text-gray-600 mx-auto w-[90%]">{{ howTo.description }}</div>
        <div class="my-4 p-2 flex justify-start items-center overflow-hidden overflow-x-auto space-x-3">
            <div class="text-sm">{{ howTo.howToSteps.length }} steps</div>
        </div>
        <div class="text-xs text-gray-600 text-end mt-4 mb-2">{{ toDiffForHumans(howTo.createdAt) }}</div>
    </div>

    <HowToModal
        :show="modalData.show"
        :howTo="howTo"
        @close="closeModal"
    />
</template>

<script setup>
import useAlert from "@/Composables/useAlert"
import useModal from "@/Composables/useModal"
import HowToModal from "./HowToModal.vue"
import useLocalDateTimed from "@/Composables/useLocalDateTime"


const { toDiffForHumans } = useLocalDateTimed()
const { alertData, setFailedAlertData, clearAlertData, setSuccessAlertData } = useAlert()
const { modalData, closeModal, showModal } = useModal()

const props = defineProps({
    howTo: {
        default: null
    }
})
</script>