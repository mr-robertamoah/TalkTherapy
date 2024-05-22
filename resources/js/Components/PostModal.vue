<script setup>
import { ref, watch } from 'vue';
import PostComponent from './PostComponent.vue';
import Modal from './Modal.vue';


const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    post: {
        default: null
    },
})

const emits = defineEmits(['closeModal', 'updated', 'deleted'])

function closeModal() {
    emits('closeModal')
}

</script>

<template>
    
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="p-4">

            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Post</div>
                <hr>
            </div>
            
            <div class="h-[80vh] overflow-hidden overflow-y-auto">
                <PostComponent
                    :post="post"
                    @updated="(post) => emits('updated', post)"
                    @deleted="() => emits('deleted', post)"
                    class="w-[300px] md:w-[350px] lg:w-[350px] shrink-0 mx-auto mb-8"
                    :show-share="false"
                />
            </div>
        </div>
    </Modal>
</template>