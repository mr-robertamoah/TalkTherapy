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
    next: {
        default: false,
        type: Boolean,
    },
    previous: {
        default: false,
        type: Boolean,
    },
})

const emits = defineEmits(['closeModal', 'clickedPrevious', 'clickedNext'])

const link = ref(null)

function closeModal() {
    emits('closeModal')
}

function clickedSave() {
    link.value.click()
}

function clickedNext() {
    emits('clickedNext')
}

function clickedPrevious() {
    emits('clickedPrevious')
}
</script>

<template>
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="w-full p-2 h-[80vh] overflow-hidden relative text-sm text-gray-600 flex justify-center items-center">
            <div v-if="previous" @click="clickedPrevious" class="shrink-0 text-3xl font-bold mx-2 p-2 cursor-pointer" title="check next image">
                {{"<"}}
            </div>
            <div class="shrink">
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
                <div 
                    v-else class="w-full max-h-[70vh] my-auto p-2 bg-stone-100 flex justify-center items-center">
                    <div class="w-fit text-center">{{ file.name }}</div>
                </div>
            </div>
            <div v-if="next" @click="clickedNext" class="shrink-0 text-3xl font-bold mx-2 p-2 cursor-pointer" title="check next image">
                {{">"}}
            </div>
        </div>
    </Modal>

    <a :href="file.url" download="" class="hidden" ref="link"></a>
</template>