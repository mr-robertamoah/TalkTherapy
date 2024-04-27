<template>
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="p-4">

            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit capitalize mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >{{ topic.name }}</div>
                <hr>
            </div>

            <div class="overflow-hidden overflow-y-auto h-[70vh] w-[90%] mx-auto md:w-[70%]">
                <div v-if="topic.description" class="p-4 rounded bg-gray-200 shadow-sm min-h-[100px]">
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">description</div>
                    <div class="mt-2 text-sm text-gray-600 text-justify">{{ topic.description }}</div>
                </div>

                <div class="p-4 rounded bg-gray-200 my-4 shadow-sm min-h-[100px]">
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">sessions</div>
                    
                    <div class="p-2 flex justify-start items-center overflow-hidden overflow-x-auto my-2">
                                
                        <template v-if="topic.sessions?.length">
                            <PreferenceItem
                                v-for="c in topic.sessions"
                                :key="c.id"
                                :item="c"
                                :show-actions="false"
                            />
                        </template>
                        <div v-else class="w-full text-center text-sm text-gray-600">no sessions</div>
                    </div>
                </div>

                <div class="p-4 rounded bg-gray-200 my-4 shadow-sm min-h-[100px]">
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">dates</div>
                    <div>
                        <div class="text-end text-gray-600 text-sm my-2">created {{ topic.createdAt }}</div>
                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import Modal from './Modal.vue';
import NameAndValue from './NameAndValue.vue';
import PreferenceItem from './PreferenceItem.vue';

const emits = defineEmits(['close'])

const props = defineProps({
    show: {
        default: false,
        type: Boolean
    },
    topic: {
        default: null
    }
})

function closeModal() {
    emits('close')
}
</script>