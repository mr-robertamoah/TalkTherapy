<script setup>
import { computed, ref, watchEffect } from "vue"


const emits = defineEmits(['removeFile'])

const props = defineProps({
    file: {default: null,},
    showRemove: {default: true,},
    type: {default: 'normal',},
})

const message = ref('')
const imgSrc = ref('')
const audioSrc = ref('')
const videoSrc = ref('')

watchEffect(() => {
    if (!props.file) {
        return
    }

    if (props.file?.url) return showFile(props.file)

    readFile(props.file)
})

const computedIsVideoOrAudio = computed(() => {
    if (
        props.file?.mime?.includes('audio') ||
        props.file?.type?.includes('audio') ||
        props.file?.mime?.includes('video') ||
        props.file?.type?.includes('video')
    ) return true

    return false
})
function clickedRemove() {
    emits('removeFile')
}

function showFile(f) {
    if (f.mime.includes('image')) {
        imgSrc.value = f.url
    } else if (f.mime.includes('video')) {
        videoSrc.value = f.url
    } else if (f.mime.includes('audio')) {
        audioSrc.value = f.url
    } else if (!f.mime.includes('application')) {
        message.value = `${f.name} is not a valid file`
    }
}

function readFile(f) {
    const url = URL.createObjectURL(f)

    if (f.type.includes('image')) {
        imgSrc.value = url
    } else if (f.type.includes('video')) {
        videoSrc.value = url
    } else if (f.type.includes('audio')) {
        audioSrc.value = url
    }
}
</script>

<template>
    <div class="p-2 bg-gray-300 rounded-md"
        :class="{'min-w-[120px]': computedIsVideoOrAudio}"
    >
        <div class="text-sm absolute "
            @click="clickedRemove"
            v-if="(showRemove ? (file ? true : false) : false)"
        >
            <div 
                class="w-6 h-6 p-1 bg-gray-700 text-cyan-100 rounded-full flex justify-center items-center text-xs border-cyan-100 border-2 mt-1 mr-1 z-[1] cursor-pointer absolute">
                <div>x</div>
            </div>
        </div>
        <div 
            v-if="(type === 'normal')"
            :class="` w-full h-full text-center`"
        >
            <img v-if="imgSrc" alt="cannot load" :src="imgSrc" class="object-scale-down w-full h-full">
            <audio v-else-if="audioSrc" alt="cannot load" class="min-w-[100px] mx-auto w-full" :src="audioSrc" controls></audio>
            <video v-else-if="videoSrc" alt="cannot load" class="min-w-[100px] mx-auto w-full" :src="videoSrc" controls></video>
            <div v-else class="text-xs text-gray-600 mt-4">{{ file.name }}</div>
        </div>
    </div>
</template>