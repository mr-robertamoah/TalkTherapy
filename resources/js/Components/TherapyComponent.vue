<template>
    <div class="min-h-[500px] relative flex flex-col items-center justify-center">
        <div class="rounded-lg bg-stone-100 w-full shrink mb-2">
            <template>
                <TherapyFilterItem
                    v-for="(item, idx) in filterItems"
                    :key="idx"
                    :item="item"
                />
            </template>
        </div>
        <div class="rounded-lg min-h-[400px] bg-stone-200 h-full w-full shrink mb-2">

        </div>
        <div class="rounded-lg bg-stone-100 w-full p-2">
            <div class="w-[90%] mx-auto min-h-10 flex justify-center items-start space-x-2">
                <TextBox
                    rows="3"
                    class="w-full shrink"
                    v-model="message"
                />
                <div class="flex justify-end space-x-2 items-start">
                    <PaperplaneIcon class="w-8 cursor-pointer p-1 h-8 rotate-45" v-if="computedHasMessage" />
                    <PaperclipIcon class="w-8 cursor-pointer p-1 h-8"
                        @click="() => showAttachmentIcons = true"
                    />
                </div>
            </div>
        </div>
        <div
            @click.self="() => showAttachmentIcons = false"
            :class="[showAttachmentIcons ? 'opacity-100 visible z-[1]' : 'opacity-0 invisible -z-[1]']" 
            class="w-full top-0 absolute transition-all duration-100 right-0 h-full bg-gray-600 bg-opacity-30 flex justify-center items-center">
            <div class="w-[80%] bg-white min-h-32 rounded flex justify-center items-center space-x-2 flex-wrap">
                <CameraIcon @click="clickedIcon('camera')" class="w-8 cursor-pointer p-1 h-8" />
                <MicrophoneIcon @click="clickedIcon('microphone')" class="w-8 cursor-pointer p-1 h-8" />
                <FileIcon @click="clickedIcon('file')" class="w-8 cursor-pointer p-1 h-8" />
            </div>
        </div>

        <input type="file" name="messageFiles" ref="messageFilesInput" class="hidden" id="messageFiles" multiple accept="image/*">
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import TextBox from './TextBox.vue';
import PaperplaneIcon from '@/Icons/PaperplaneIcon.vue';
import PaperclipIcon from '@/Icons/PaperclipIcon.vue';
import TherapyFilterItem from './TherapyFilterItem.vue';
import CameraIcon from '@/Icons/CameraIcon.vue';
import FileIcon from '@/Icons/FileIcon.vue';
import MicrophoneIcon from '@/Icons/MicrophoneIcon.vue';


const props = defineProps({
    therapy: {
        default: null
    },
    filterBy: {
        default: '',
        type: String
    }
})

const showAttachmentIcons = ref(false)
const loading = ref(false)
const page = ref(1)
const message = ref('')
const files = ref(null)
const filterItems = ref([])

const computedHasMessage = computed(() => {
    return true
})

async function getMessages() {
    loading.value = true

    await axios
        .get(route('therapies.messages.get', {
            therapyId: props.therapy?.id,
            page: page.value
        }))
        
}
</script>