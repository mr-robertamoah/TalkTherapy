<template>
    <div v-bind="$attrs" class="rounded bg-gray-100 text-sm p-2 relative">
        <div
            class="flex justify-between items-center mb-2"
        >
            <div
                class="text-center font-bold w-8 h-8 rounded-full bg-gray-600 text-gray-200 flex justify-center items-center"
            >{{ howToStep.position }}</div>
            <div class="space-x-3 flex justify-end items-center">
                <PrimaryButton
                    v-if="canRemove"
                    @click.prevent="() => emits('remove', howToStep)"
                >remove</PrimaryButton>
                <PrimaryButton
                    v-if="canRestore"
                    @click.prevent="() => emits('restore', howToStep)"
                >restore</PrimaryButton>
                <PrimaryButton
                    v-if="canEdit"
                    @click.prevent="() => showModal('edit')"
                >edit</PrimaryButton>
                <DangerButton
                    v-if="canEdit"
                    @click.prevent="() => emits('deleted', howToStep)"
                >delete</DangerButton>
            </div>
        </div>
        <div class="text-center font-bold">{{ howToStep.name }}</div>
        <div v-if="howToStep.description" class="text-gray-600 text-xs my-2">{{ howToStep.description }}</div>
        <div>
            <FilePreview
                v-if="howToStep.file"
                :file="howToStep.file"
                class="h-[100px] w-[100px] mx-auto"
                :show-remove="false"
            />
        </div>
    </div>

    <AdminHowToStepUpdateModal
        :show="modalData.show && modalData.type == 'edit'"
        @updated="updateHowToStep"
        :how-to-step="howToStep"
        :file="file"
        @close="closeModal"
    />
</template>

<script setup>
import FilePreview from '@/Components/FilePreview.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AdminHowToStepUpdateModal from '@/Components/AdminHowToStepUpdateModal.vue'
import DangerButton from './DangerButton.vue';
import useModal from '@/Composables/useModal';


const { modalData, closeModal, showModal } = useModal()

const emits = defineEmits(['updated', 'deleted', 'restore', 'remove'])

const props = defineProps({
    howToStep: {
        default: null
    },
    file: {
        default: null
    },
    howTo: {
        default: null
    },
    show: {
        default: false
    },
    canEdit: {
        default: false
    },
    canRestore: {
        default: false
    },
    positions: {
        default: []
    },
    canRemove: {
        default: false
    }
})

function updateHowToStep(data) {
    emits('updated', data)
}
</script>