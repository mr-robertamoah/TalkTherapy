<template>
    <div
        v-bind="$attrs"
        class="w-full max-w-[400px] p-4 rounded-xl shadow-lg bg-white border border-gray-100 select-none cursor-pointer hover:shadow-xl transition-all duration-300"
        @dblclick="() => {
            $emit('dblclick')
            if (useMinimal) return
            goToPage()
        }"
    >
        <div
            v-if="tag.length"
            class="bg-blue-600 text-blue-100 px-2 py-1 text-xs w-fit rounded ml-auto"
        >{{ tag }}</div>
        <div 
            v-if="counsellor.deleted" 
            class="p-2 text-red-700 text-center text-sm"
        >counsellor account has been deleted</div>
        <div 
            v-else-if="useMinimal" 
            :class="[whiteText ? 'text-white' : 'text-gray-600']"
        >
            <!-- TODO: add section that allows viewing specialisation of counsellor -->
            <div class="flex items-center gap-2 mx-auto">
                <div class="capitalize text-sm align-middle">{{ counsellor.name }}</div>
                <div class="text-xs align-middle">{{ counsellor.username ? `@${counsellor.username}` : '' }}</div>
            </div>
            <slot></slot>
        </div>
        <div v-else>
            <div class="flex justify-start items-center mb-4 cursor-pointer space-x-3 p-2 rounded-lg hover:bg-gray-50 transition-colors">
                <Avatar class="shrink-0" :avatar-text="'...'" :size="48" :src="counsellor?.avatar ?? ''"/>
                <div class="text-gray-700 flex flex-col space-y-1">
                    <div class="capitalize font-semibold text-base">{{ counsellor.name }}</div>
                    <div class="text-sm text-gray-500">{{ counsellor.username ? `@${counsellor.username}` : '' }}</div>
                </div>
            </div>
            <slot></slot>
            
            <div class="mt-3 flex justify-end items-center space-x-2" v-if="(hasView && !counsellor.anonymous) || canDelete">
                <div
                    v-if="hasView && !counsellor.anonymous"
                    @click="() => view = true"
                    class="p-2 bg-gray-700 text-gray-200 cursor-pointer tracking-wide rounded min-w-[80px] text-center hover:bg-gray-600 hover:text-white transition duration-75">view</div>
                <DangerButton v-if="canDelete" @click="() => showModal('delete')">delete</DangerButton>
            </div>
            <div class="" v-if="forRequest">
                <div class="my-2">
                    <ActivityBadge
                        :name="'number of therapies'"
                        :value="counsellor.allTherapiesCount ?? 0"
                    />
                </div>
                <div 
                    class="flex flex-col justify-center items-center w-full"
                >
                    <div class="my-2 flex justify-start w-full overflow-hidden overflow-x-auto p-2 space-x-2">
                        <div
                            v-for="(item, idx) in ['profession', 'cases', 'languages', 'religions']"
                            :key="idx"
                            @click="() => {
                                selectedItem = item
                            }"
                            class="px-2 py-1 cursor-pointer rounded transition duration-100"
                            :class="[selectedItem == item ? 'bg-slate-300 text-slate-800' : 'bg-slate-200 text-slate-600']"
                        >{{ item }}</div>
                    </div>
                    <div
                        v-if="selectedItem"
                    >
                        <div v-if="selectedItem == 'profession'" class="p-2">
                            <div
                                v-if="counsellor[selectedItem]"
                                :title="counsellor[selectedItem].about ?? ''"
                                class="capitalize mr-3 rounded text-sm p-2 min-w-[100px] text-gray-700 bg-gray-300 select-none transition duration-75 cursor-pointer hover:bg-gray-600 hover:text-white text-center"
                            >{{ counsellor[selectedItem].name }}</div>

                            <div v-else class="text-gray-600 w-full my-2 text-center text-sm">has no {{ selectedItem }} set</div>
                        </div>
                        <div v-else class="p-2 flex justify-start items-center overflow-hidden overflow-x-auto">
                            <template v-if="counsellor[selectedItem]?.length">
                                <div
                                    v-for="(item, idx) in counsellor[selectedItem]"
                                    :title="item.about ?? ''"
                                    :key="idx"
                                    class="capitalize mr-3 rounded shrink-0 text-sm p-2 min-w-[100px] text-gray-700 bg-gray-300 select-none transition duration-75 cursor-pointer hover:bg-gray-600 hover:text-white text-center"
                                >{{ item.name }}</div>

                            </template>

                            <div v-else class="text-gray-600 w-full my-2 text-center text-sm">has no {{ selectedItem }} set</div>
                        </div>
                    </div>
                    <!-- <div v-else class="text-gray-600 w-full my-2 text-center text-sm">nothing selected yet</div> -->
                </div>
            </div>
            <div class="flex justify-end" v-if="online">
                <div 
                    class="mx-2 w-4 h-4 p-1 rounded-full flex justify-center items-center mr-2 bg-green-700"
                >
                    <div 
                        class="w-full h-full rounded-full bg-green-300"
                    ></div>
                </div>
            </div>
        </div>
    </div>

    <CounsellorModal
        :show="view"
        @close="() => view = false"
        :counsellor="counsellor"
    />

    <MiniModal
        v-if="canDelete"
        :show="modalData.show && modalData.type == 'delete'"
        @close="closeModal"
    >
        <div>
            <FormLoader :danger="true" class="mx-auto" :show="deleting" :text="'deleting counsellor account'"/>
            <div class="text-gray-600 text-center font-bold tracking-wide">
                Delete {{ counsellor.name }}'s Counsellor Account
            </div>

            <hr class="my-2">

            <div class="my-4 text-sm text-red-700 text-center w-[90%] mx-auto font-bold tracking-wide">
                Are you sure you want to delete this counsellor account? This is the same eligibility-gated
                deletion the counsellor themselves would trigger -- it will fail if they have pending sessions,
                an in-session therapy, an active group therapy or organization affiliation, or pending requests
                awaiting their decision.
            </div>

            <div class="flex space-x-2 justify-end items-center w-full p-2">
                <PrimaryButton @click="() => closeModal()" class="shrink-0">cancel</PrimaryButton>
                <DangerButton @click="deleteCounsellor" class="shrink-0">delete</DangerButton>
            </div>
        </div>
    </MiniModal>

    <Alert
        v-if="canDelete"
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>

<script setup>
import { ref } from 'vue';
import Avatar from './Avatar.vue';
import CounsellorModal from './CounsellorModal.vue';
import ActivityBadge from './ActivityBadge.vue';
import DangerButton from './DangerButton.vue';
import PrimaryButton from './PrimaryButton.vue';
import FormLoader from './FormLoader.vue';
import MiniModal from './MiniModal.vue';
import Alert from './Alert.vue';
import useAlert from '@/Composables/useAlert';
import useModal from '@/Composables/useModal';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    counsellor: {
        default: null
    },
    hasView: {
        type: Boolean,
        default: true
    },
    tag: {
        type: String,
        default: ''
    },
    useMinimal: {
        type: Boolean,
        default: false
    },
    whiteText: {
        type: Boolean,
        default: false
    },
    visitPage: {
        type: Boolean,
        default: true
    },
    online: {
        type: Boolean,
        default: false
    },
    forRequest: {
        type: Boolean,
        default: false
    },
    // SCRUM-134: only the admin counsellors listing (Admin.vue) passes this true -- every other
    // context this component is used in (public listings, request views, etc.) leaves it off.
    canDelete: {
        type: Boolean,
        default: false
    }
})

const emits = defineEmits(['onResponse', 'dblclick', 'deleted'])

const { alertData, setFailedAlertData, setSuccessAlertData, clearAlertData } = useAlert()
const { modalData, showModal, closeModal } = useModal()

const view = ref(false)
const selectedItem = ref(null)
const deleting = ref(false)

function clickedResponse(response) {
    emits('onResponse', response)
}

async function deleteCounsellor() {
    deleting.value = true

    await axios
        .delete(route('admin.counsellors.delete', { counsellorId: props.counsellor.id }))
        .then((res) => {
            setSuccessAlertData({
                message: `${props.counsellor.name}'s counsellor account has successfully been deleted.`,
            })
            emits('deleted', res.data.counsellor)
            closeModal()
        })
        .catch((err) => {
            setFailedAlertData({
                message: err.response?.data?.message ?? 'Something unfortunate happened. Please try again later.',
            })
        })
        .finally(() => {
            deleting.value = false
        })
}

function goToPage() {
    if (props.visitPage && props.counsellor)
        router.get(route('counsellor.show', { counsellorId: props.counsellor.id}))
}
</script>