<template>
    <div
        v-bind="$attrs"
        :title="`Start Guilded Tour on ${page} page`"
        @click="() => clickedHelp()"
        class="w-8 h-8 rounded-full bg-blue-600 text-blue-200 justify-center items-center flex cursor-pointer p-2"
    >?</div>

    <GuidedTour
        :start="startHowToTour"
        :tours="howToTour"
        @endTour="endTour"
        :user="user"
    />
    <HelpModal
        :show="modalData.show"
        :page="page"
        @close="closeModal"
        @startTour="startTour"
    />
</template>

<script>
import useGuidedTours from "@/Composables/useGuidedTours";

const { tours, PAGES } = useGuidedTours()
</script>

<script setup>
import useModal from "@/Composables/useModal"
import HelpModal from "./HelpModal.vue";
import { ref } from "vue";
import GuidedTour from "./GuidedTour.vue";


const { modalData, closeModal, showModal } = useModal()

const startHowToTour = ref(false)
const howToTour = ref(null)

let props = defineProps({
    useLocalTours: {
        type: Boolean,
        default: true
    },
    page: {
        type: String,
        required: true,
        validator: (value) => Object.values(PAGES).includes(value)
    },
    user: {
        type: Object,
        required: null
    }
})

function startTour(howTo) {
    if (!howTo) return
    howToTour.value = howTo
    startHowToTour.value = true
}

function endTour() {
    howToTour.value = null
    startHowToTour.value = false
}

function clickedHelp() {
    if (props.useLocalTours) {
        startTour(tours.value.find((tour) => tour.page == props.page ))
        return
    }

    showModal('tour')
}
</script>