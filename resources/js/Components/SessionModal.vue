<template>
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="p-4">

            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >{{ session.name }}</div>
                <hr>
            </div>

            <div class="overflow-hidden overflow-y-auto h-[70vh] w-[90%] mx-auto md:w-[70%]">
                <div v-if="session.about" class="p-4 rounded bg-gray-200 shadow-sm min-h-[100px]">
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">about</div>
                    <div class="mt-2 text-sm text-gray-600 text-justify">{{ session.about }}</div>
                </div>

                <div class="p-4 rounded bg-gray-200 my-4 shadow-sm min-h-[100px]">
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">details</div>
                    <NameAndValue :name="'Type'" :value="session.type"/>
                    <NameAndValue :name="'Payment Type'" :value="session.paymentType"/>
                    <NameAndValue :name="'Status'" :value="session.status"/>
                </div>

                <div class="p-4 rounded bg-gray-200 my-4 shadow-sm min-h-[100px]" v-if="session.lng && session.lat">
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">location</div>
                    <div :id="`session_${session.id}`" class="w-full h-[200px]">

                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import Modal from './Modal.vue';
import NameAndValue from './NameAndValue.vue';

const emits = defineEmits(['close'])

const props = defineProps({
    show: {
        default: false,
        type: Boolean
    },
    session: {
        default: null
    }
})

function closeModal() {
    emits('close')
}
</script>