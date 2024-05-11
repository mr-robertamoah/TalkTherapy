<template>
    <div
        @dblclick="clickedItem"
        v-bind="$attrs"
        class="capitalize mr-3 relative rounded text-sm p-2 w-fit text-gray-700 bg-gray-300 select-none transition duration-75 cursor-pointer hover:bg-gray-600 hover:text-white text-center"
    >
        <div>{{ item.name }}</div>
        <div class="text-center mt-2 p-1 text-xs cursor-pointer rounded text-gray-600 bg-white" v-if="showDetails">
            <div @click="clickedAction" class="p-1 mb-2 hover:bg-gray-600 hover:text-gray-200">{{ action }}</div>
            <div @click="clickedShowDetails" class="p-1 hover:bg-gray-600 hover:text-gray-200 text-nowrap">show details</div>
        </div>
    </div>

    <MiniModal
        :show="modalData.show"
        @close="closeModal"
    >
        <div>
            <div class="text-center font-bold">{{ item.name }}</div>
            <hr class="my-2">
            <div class="text-justify text-sm">
                {{ computedDetails ?? 'no detail/description for now' }}
            </div>
        </div>
    </MiniModal>
</template>

<script setup>
import useModal from '@/Composables/useModal';
import { computed, ref, watch } from 'vue';
import MiniModal from './MiniModal.vue';

const { modalData, closeModal, showModal } = useModal()

const emits = defineEmits(['selectItem'])

const props = defineProps({
    item: {
        default: null
    },
    showActions: {
        default: true
    },
    action: {
        default: 'select'
    }
})

const showDetails = ref(false)

watch(() => showDetails.value, () => {
    if (showDetails.value)
        setInterval(() => {
            showDetails.value = false
        }, 20000)
})

const computedDetails = computed(() => {
    return props.item?.description ?? props.item?.about
})

function clickedShowDetails() {
    showDetails.value = false
    showModal('details')
}

function clickedAction() {
    showDetails.value = false
    selectItem()
}

function clickedItem() {
    if (!props.showActions) {
        showModal('details')
        return
    }
    showDetails.value = true
}

function selectItem() {
    emits('selectItem', props.item)
}
</script>