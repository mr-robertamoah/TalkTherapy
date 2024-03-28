<script setup>
import { ref } from 'vue';
import Modal from './Modal.vue';

const props = defineProps({
    file: {
        default: null,
    },
    hasDownload: {
        default: false,
        type: Boolean,
    },
    show: {
        default: false,
        type: Boolean,
    },
})

const emits = defineEmits(['closeModal'])

const link = ref(null)

function closeModal() {
    emits('closeModal')
}

function clickedSave() {
    link.value.click()
}
</script>

<template>
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="w-full p-2 h-[80vh] overflow-hidden relative">
            <div
                @click="clickedSave"
                class="absolute top-2 right-2 p-2 bg-green-700 text-green-200 cursor-pointer tracking-wide rounded min-w-[80px] text-center hover:bg-green-400 hover:text-green-700 transition duration-75"
            >save</div>
            <div 
                v-if="file.mime.includes('image')" class="w-full h-[70vh] my-auto p-2 bg-stone-100 flex justify-center items-center">
                <img class="w-full h-full object-scale-down" :src="file.url" :alt="file.name">
            </div>
            <div 
                v-else-if="file.mime.includes('video')" class="w-full max-h-[70vh] my-auto p-2 bg-stone-100 flex justify-center items-center">
                <video class="w-full h-full object-scale-down" autoplay :controls="false" :src="file.url" :alt="file.name"></video>
            </div>
        </div>
    </Modal>

    <a :href="file.url" download="" class="hidden" ref="link"></a>
</template>