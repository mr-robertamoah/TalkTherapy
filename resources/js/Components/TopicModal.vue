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

            <div class="overflow-hidden overflow-y-auto h-[70vh] w-[95%] mx-auto px-4 py-6 space-y-6">
                <!-- Description Section -->
                <div v-if="topic.description" class="bg-gradient-to-br from-gray-50 to-gray-100 p-6 rounded-xl shadow-md border border-gray-200">
                    <div class="flex items-center mb-4">
                        <div class="w-2 h-8 bg-gray-600 rounded-full mr-3"></div>
                        <h3 class="text-xl font-semibold text-gray-800 capitalize">Description</h3>
                    </div>
                    <p class="text-gray-700 leading-relaxed text-justify">{{ topic.description }}</p>
                </div>

                <!-- Sessions Section -->
                <div class="bg-gradient-to-br from-stone-50 to-stone-100 p-6 rounded-xl shadow-md border border-stone-200">
                    <div class="flex items-center mb-4">
                        <div class="w-2 h-8 bg-stone-600 rounded-full mr-3"></div>
                        <h3 class="text-xl font-semibold text-gray-800 capitalize">Related Sessions</h3>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <template v-if="topic.sessions?.length">
                            <div v-for="c in topic.sessions" :key="c.id" 
                                 class="bg-white px-4 py-3 rounded-lg shadow-sm border border-stone-200 hover:shadow-md transition-shadow">
                                <span class="text-gray-700 font-medium capitalize">{{ c.name }}</span>
                                <p v-if="c.about" class="text-sm text-gray-500 mt-1">{{ c.about }}</p>
                                <div v-if="c.startTime" class="text-xs text-gray-400 mt-2">
                                    {{ new Date(c.startTime).toLocaleDateString() }}
                                </div>
                            </div>
                        </template>
                        <div v-else class="w-full text-center py-8 text-gray-500">No sessions linked to this topic</div>
                    </div>
                </div>

                <!-- Metadata Section -->
                <div class="bg-gradient-to-br from-slate-50 to-slate-100 p-6 rounded-xl shadow-md border border-slate-200">
                    <div class="flex items-center mb-4">
                        <div class="w-2 h-8 bg-slate-600 rounded-full mr-3"></div>
                        <h3 class="text-xl font-semibold text-gray-800 capitalize">Information</h3>
                    </div>
                    <div class="bg-white p-4 rounded-lg shadow-sm">
                        <span class="text-sm font-medium text-gray-500">Created</span>
                        <p class="text-lg font-semibold text-gray-800">{{ new Date(topic.createdAt).toLocaleString() }}</p>
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