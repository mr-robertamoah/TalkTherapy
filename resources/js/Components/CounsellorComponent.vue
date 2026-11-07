<template>
    <div
        v-bind="$attrs"
        class="p-4 rounded-xl shadow-lg bg-white border border-gray-100 select-none cursor-pointer hover:shadow-xl transition-all duration-300"
        :class="forRequest ? 'w-64' : 'w-full max-w-[400px]'"
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
            <div class="flex justify-start items-center cursor-pointer space-x-3 p-2 rounded-lg hover:bg-gray-50 transition-colors" :class="forRequest ? 'mb-2' : 'mb-4'">
                <Avatar class="shrink-0" :avatar-text="'...'" :size="forRequest ? 40 : 48" :src="counsellor?.avatar ?? ''"/>
                <div class="text-gray-700 flex min-w-0 flex-col space-y-1">
                    <div class="capitalize font-semibold text-base truncate">{{ counsellor.name }}</div>
                    <div class="text-sm text-gray-500">{{ counsellor.username ? `@${counsellor.username}` : '' }}</div>
                    <!-- SCRUM-30 -->
                    <CounsellorVerificationBadge v-if="counsellor.verification" class="w-fit" :verification="counsellor.verification" />
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
            <!-- SCRUM-393: in the "find a counsellor" picker, what they work with is a few lines of
                 small text (it used to be tabs of large chips, which made each card very tall). -->
            <div v-if="forRequest" class="text-xs text-gray-600">
                <dl class="space-y-1">
                    <div v-for="row in requestSummary" :key="row.label" class="flex gap-1">
                        <dt class="shrink-0 font-medium text-gray-700">{{ row.label }}:</dt>
                        <dd class="min-w-0 truncate" :class="{ capitalize: row.full }" :title="row.full">{{ row.text }}</dd>
                    </div>
                </dl>
                <button
                    type="button"
                    class="mt-3 inline-flex min-h-[36px] w-full items-center justify-center rounded-lg border border-blue-600 px-3 text-sm font-medium text-blue-700 hover:bg-blue-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                    @click.stop="$emit('select')"
                >Select</button>
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
import { computed, ref } from 'vue';
import Avatar from './Avatar.vue';
import CounsellorVerificationBadge from './CounsellorVerificationBadge.vue';
import CounsellorModal from './CounsellorModal.vue';
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
    // SCRUM-134: only the admin counsellors listing (Admin/People/Counsellors.vue) passes this true -- every other
    // context this component is used in (public listings, request views, etc.) leaves it off.
    canDelete: {
        type: Boolean,
        default: false
    }
})

const emits = defineEmits(['onResponse', 'dblclick', 'select', 'deleted'])

const { alertData, setFailedAlertData, setSuccessAlertData, clearAlertData } = useAlert()
const { modalData, showModal, closeModal } = useModal()

const view = ref(false)

// SCRUM-393: the picker's summary -- up to three names per kind, the rest counted.
const requestSummary = computed(() => {
    if (!props.forRequest || !props.counsellor) return []
    const names = (list) => (list ?? []).map((item) => item.name).filter(Boolean)
    const line = (list) => {
        const all = names(list)
        if (!all.length) return { text: 'Not set', full: '' }
        const shown = all.slice(0, 3).join(', ')
        return { text: all.length > 3 ? `${shown} +${all.length - 3} more` : shown, full: all.join(', ') }
    }
    const therapies = props.counsellor.allTherapiesCount ?? 0

    return [
        { label: 'Therapies', text: String(therapies), full: '' },
        { label: 'Profession', ...line(props.counsellor.profession ? [props.counsellor.profession] : []) },
        { label: 'Cases', ...line(props.counsellor.cases) },
        { label: 'Languages', ...line(props.counsellor.languages) },
        { label: 'Religions', ...line(props.counsellor.religions) },
    ]
})
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