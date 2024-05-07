<template>
    <div
        v-bind="$attrs"
        @click="() => showModal('ok')"
        class="w-8 h-8 rounded-full bg-blue-600 text-blue-200 justify-center items-center flex cursor-pointer p-2"
    >?</div>

    <HelpModal
        :show="modalData.show"
        :page="page"
        @close="closeModal"
    />
</template>

<script setup>
import useModal from "@/Composables/useModal"
import HelpModal from "./HelpModal.vue";


const { modalData, closeModal, showModal } = useModal()
defineProps({
    page: {
        type: String,
        required: true
    }
})
</script>