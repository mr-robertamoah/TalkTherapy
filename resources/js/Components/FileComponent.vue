<script setup>
import PrimaryButton from './PrimaryButton.vue';
import FileModal from './FileModal.vue';
import { ref } from 'vue';

const props = defineProps({
    file: {
        default: null,
    },
    hasDownload: {
        default: false,
        type: Boolean,
    }
})

const showModal = ref(false)
const link = ref(null)

function downloadFile() {
    link.value.click()
}
</script>

<template>
    <div v-bind="$attrs">
        <div 
            @dblclick="() => showModal = true"
            v-if="file.mime.includes('image')" class="w-full h-full p-2 bg-stone-100 flex justify-center items-center">
            <img class="h-full cursor-pointer w-full object-cover" :src="file.url" :alt="file.name">
        </div>
        <div 
            @dblclick="() => showModal = true"
            v-else-if="file.mime.includes('video')" class="w-full h-full p-2 bg-stone-100 flex justify-center items-center">
            <video class="h-full cursor-pointer w-full object-cover" autoplay :controls="false" :src="file.url" :alt="file.name"></video>
        </div>
        <div 
            v-else
            @dblclick="downloadFile"
            class="w-6 h-6 bg-white cursor-pointer text-sm text-center flex justify-center items-center text-gray-600 text-ellipsis p-1"
        >{{ file.name }}</div>

        <div v-if="hasDownload" class="flex justify-end mt-4 mb-2">
            <PrimaryButton @click="downloadFile">download</PrimaryButton>
        </div>
    </div>

    <FileModal
        :show="showModal"
        :file="file"
        @close-modal="() => showModal = false"
    />
    <a :href="file.url" download="" class="hidden" ref="link"></a>
</template>