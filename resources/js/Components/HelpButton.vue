<template>
    <div
        v-bind="$attrs"
        @click="() => showModal('ok')"
        class="w-8 h-8 rounded-full bg-blue-600 text-blue-200 justify-center items-center flex cursor-pointer p-2"
    >?</div>

    <GuidedTour
        :start="startHowToTour"
        :tours="howToTour"
        @endTour="endTour"
    />
    <HelpModal
        :show="modalData.show"
        :page="page"
        @close="closeModal"
        @startTour="startTour"
    />
</template>

<script setup>
import useModal from "@/Composables/useModal"
import HelpModal from "./HelpModal.vue";
import { ref } from "vue";
import GuidedTour from "./GuidedTour.vue";


const { modalData, closeModal, showModal } = useModal()

const startHowToTour = ref(false)
const howToTour = ref(null)

let props = defineProps({
    page: {
        type: String,
        required: true
    }
})

function startTour(howTo) {
    howToTour.value = howTo
    startHowToTour.value = true
}

function endTour() {
    howToTour.value = null
    startHowToTour.value = false
}
</script>