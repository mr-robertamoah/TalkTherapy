<template>
    <div
        v-bind="$attrs"
        class="p-4 bg-slate-200 rounded-lg select-none cursor-pointer transition-all duration-100
            hover:shadow-lg hover:bg-white"
        @dblclick="() => showHowTo()"
    >
        <div class="mb-3 text-gray-800 text-center text-sm font-bold tracking-wide">{{ howTo.name }}</div>
        <div class="text-sm text-justify text-gray-700 mx-auto w-[90%]">{{ howTo.description }}</div>
        <div class="text-xs text-gray-600 mt-2">{{ howTo.howToSteps.length }} steps</div>
    </div>

    <HowToModal
        :show="modalData.show"
        :howTo="howTo"
        @close="closeModal"
    />
</template>

<script setup>
import useModal from "@/Composables/useModal"
import HowToModal from "./HowToModal.vue"
import useLocalDateTimed from "@/Composables/useLocalDateTime"


let emits = defineEmits(['startTour'])

const { toDiffForHumans } = useLocalDateTimed()
const { modalData, closeModal, showModal } = useModal()

const props = defineProps({
    howTo: {
        default: null
    },
    useModal: {
        type: Boolean,
        default: true
    },
})

function showHowTo() {
    if (props.useModal)
        return showModal()

    emits('startTour', props.howTo)
}
</script>