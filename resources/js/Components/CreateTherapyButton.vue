<script setup>
import { onMounted } from "vue"
import useModal from "@/Composables/useModal"
import Alert from "./Alert.vue";
import PrimaryButton from "./PrimaryButton.vue";
import MiniModal from "./MiniModal.vue";
import IndividualTherapyFormModal from "./IndividualTherapyFormModal.vue";
import GroupTherapyFormModal from "./GroupTherapyFormModal.vue";

const { modalData, closeModal, showModal } = useModal()

const props = defineProps({
    counsellor: {
        default: null
    },
    // SCRUM-33 (TT-4.4c): open the individual therapy form straight away (an invitation's link).
    openIndividualForm: {
        type: Boolean,
        default: false
    }
})

onMounted(() => {
    if (props.openIndividualForm && props.counsellor) showModal('individual')
})

const emits = defineEmits(['successful'])


function clickedCreate() {
    // showModal('individual')
    showModal('mini')
}
</script>

<template>
    <div>
        <PrimaryButton @click="clickedCreate" class="capitalize text-center text-nowrap">create therapy</PrimaryButton>
        
        <IndividualTherapyFormModal
            :show="modalData.show && modalData.type == 'individual'"
            @close-modal="closeModal"
            :counsellor="counsellor"
        />
        
        <GroupTherapyFormModal
            :show="modalData.show && modalData.type == 'group'"
            @close-modal="closeModal"
        />

        <MiniModal
            :show="modalData.show && modalData.type == 'mini'"
            @close="closeModal"
        >
            <div class="text-center text-sm text-gray-600 mb-4 mt-2">
                Which kind of therapy are you looking for?
            </div>
            <div class="flex flex-col justify-center items-center">
                <PrimaryButton @click="() => {
                    closeModal()
                    showModal('individual')
                    }" class="mb-4"
                >individual therapy</PrimaryButton>
                <PrimaryButton @click="() => {
                    closeModal()
                    showModal('group')
                    }"
                >group therapy</PrimaryButton>
            </div>
        </MiniModal>
    </div>
</template>