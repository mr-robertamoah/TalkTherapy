<template>
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="select-none relative">

            <div class="p-4 w-full mt-2 mb-4">
                <div
                    class="capitalize w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >{{ howTo.name }}</div>
                <div class="mt-2 text-xs text-gray-600 text-justify w-[95%] mx-auto mb-2">{{ howTo.description }}</div>
                <hr>
            </div>

            <div class="relative pb-4">
                
                <div class="w-full p-2 h-[70vh] overflow-hidden relative text-sm text-gray-600 flex justify-center items-center">
                    <div 
                        v-if="computedPrevious" 
                        @click="clickedPrevious" 
                        class="shrink-0 text-3xl font-bold mx-2 p-2 cursor-pointer" 
                        title="check previous step"
                    >
                        {{"<"}}
                    </div>
                    <div class="h-[450px] relative">
                        <div class="text-base text-center font-bold">{{ howTo.howToSteps[index].name }}</div>
                        <div class="flex space-x-3 justify-start items-center">
                            <div>step</div>
                            <div class="text-center font-bold w-8 h-8 rounded-full mb-1 bg-gray-600 text-gray-200 flex justify-center items-center">{{ howTo.howToSteps[index].position }}</div>
                        </div>
                        <FilePreview
                            :file="howTo.howToSteps[index].file"
                            :showRemove="false"
                            class="w-fit mx-auto h-[380px] mb-4"
                        />
                        <div 
                            v-if="computedHasDescription"
                            @click="() => showMoreDescription = !showMoreDescription" 
                            :class="[showMoreDescription ? 'bottom-0' : '-bottom-3' ]"
                            class="cursor-pointer absolute w-full text-justify bg-gray-600 text-gray-200 p-2 rounded">
                            <div :class="{'mb-2': showMoreDescription}" class="text-center font-bold">click to toggle description</div>
                            <div v-if="showMoreDescription">{{ howTo.howToSteps[index].description }}</div>
                        </div>
                    </div>
                    <div 
                        v-if="computedNext" 
                        @click="clickedNext" 
                        class="shrink-0 text-3xl font-bold mx-2 p-2 cursor-pointer" 
                        title="check next step"
                    >
                        {{">"}}
                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import Modal from '@/Components/Modal.vue'
import FilePreview from '@/Components/FilePreview.vue'
import { computed, ref } from 'vue'

const emits = defineEmits(['close', 'clickedNext', 'clickedPrevious'])

const props = defineProps({
    howTo: {
        required: true,
    },
    show: {
        default: false,
        type: Boolean,
    },
})

const index = ref(0)
const showMoreDescription = ref(false)

const computedPrevious = computed(() => {
    return index.value == 0 ? false : true
})
const computedNext = computed(() => {
    return index.value + 1 == props.howTo.howToSteps.length ? false : true
})
const computedDescription = computed(() => {
    if (!props.howTo.howToSteps[index.value].description) return ''

    return showMoreDescription.value ? props.howTo.howToSteps[index.value].description : 
        'toggle to '
})
const computedHasDescription = computed(() => {
    return props.howTo.howToSteps[index.value]?.description ? true : false
})

function closeModal(){
    emits('close')
}

function clickedNext() {
    index.value += 1
}

function clickedPrevious() {
    index.value -= 1
}
</script>