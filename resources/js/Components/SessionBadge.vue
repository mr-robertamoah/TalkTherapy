<template>
    <div v-bind="$attrs" class="w-full rounded bg-white shadow-sm p-2 select-none cursor-pointer">
        <div class="text-xs my-2 w-fit ml-auto mr-2 text-gray-600">{{ session.createdAt }}</div>
        <div class="capitalize text-gray-600 font-bold tracking-wide px-2">
            {{ session.name }}
        </div>
        <div class="text-gray-600 text-sm my-2 p-2 px-4 text-center" v-if="computedAbout">
            {{ computedAbout }}
        </div>
        <div class="flex justify-end items-center my-2">
            <div 
                v-if="computedCanPerformActions"
                @click="() => showModal('actions')"
                class="ml-2 text-xs underline text-gray-600 cursor-pointer hover:text-blue-600"
            >show actions</div>
            <div 
                @click="() => showModal('details')"
                class="ml-2 text-xs underline text-gray-600 cursor-pointer hover:text-blue-600"
            >show details</div>
        </div>
    </div>

    <SessionModal
        :session="session"
        :show="modalData.type == 'details' && modalData.show"
        @close="closeModal"
        v-if="session"
    />
    <UpdateSessionFormModal
        :session="session"
        :show="modalData.type == 'update' && modalData.show"
        @close="closeModal"
        v-if="session"
    />
    <MiniModal
        :show="['actions', 'delete'].includes(modalData.type) && modalData.show"
        @close="closeModal"
        v-if="session"
    >
        <div v-if="modalData.type == 'actions'">
            <div class="text-gray-600 text-center font-bold tracking-wide">Actions</div>
            <hr class="my-2">

            <div class="flex p-4 flex-col items-center justify-center mx-auto w-[90%] md:w-[75%]">
                <PrimaryButton @click="() => showModal('update')">update</PrimaryButton>
                <PrimaryButton @click="() => showModal('delete')">delete</PrimaryButton>
            </div>
        </div>
        <div v-if="modalData.type == 'delete'" class="relative">
            <div class="text-gray-600 text-center font-bold tracking-wide">Actions</div>
            <hr class="my-2">
            <FormLoader :danger="true" :show="loading" :text="'deleting session...'"/>
            <div class="text-red-700 my-4">Are sure you want to delete this session.</div>
            <div class="flex p-4 items-center justify-end mx-auto w-[90%] md:w-[75%]">
                <PrimaryButton @click="closeModal">cancel</PrimaryButton>
                <PrimaryButton @click="deleteSession">delete</PrimaryButton>
            </div>
        </div>
    </MiniModal>
</template>

<script setup>
import useModal from '@/Composables/useModal';
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import SessionModal from './SessionModal.vue';
import MiniModal from './MiniModal.vue';
import PrimaryButton from './PrimaryButton.vue';
import UpdateSessionFormModal from './UpdateSessionFormModal.vue';
import FormLoader from './FormLoader.vue';

const { modalData, closeModal, showModal } = useModal()

const props = defineProps({
    session: {
        default: null
    }
})

const mainSession = ref(null)

watch(() => props.session?.id, () => {
    if (props.session?.id) mainSession.value = {...props.session}
})

const computedAbout = computed(() => {
    return props.session?.about?.length > 100 ? props.session?.about?.slice(0, 100) + '...' : props.session?.about
})
const computedCanPerformActions = computed(() => {
    return props.session?.userId == usePage().props.auth.user?.id
})

function deleteSession() {
    
}
</script>