<script setup>
import useAlert from "@/Composables/useAlert";
import useModal from "@/Composables/useModal";
import { useForm } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import Alert from "./Alert.vue";
import FormLoader from "./FormLoader.vue";
import PrimaryButton from "./PrimaryButton.vue";
import Modal from "./Modal.vue";
import ImageUploadField from "./ImageUploadField.vue";

const { modalData, closeModal } = useModal()
const { alertData, setAlertData, clearAlertData } = useAlert()

const updateForm = useForm({
    avatar: null,
    cover: null,
    deleteAvatar: false,
    deleteCover: false,
})

const emits = defineEmits(['closeModal'])

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    counsellor: {
        default: null,
    },
})

const loading = ref(false)

const formDataChanged = computed(() => !!(
    updateForm.avatar || updateForm.cover || updateForm.deleteAvatar || updateForm.deleteCover
))

watch(
    () => props.show,
    () => modalData.value.show = props.show
)

function closeThisModal() {
    resetUpdateData()
    emits('closeModal')
    closeModal()
}

function updateCounsellor() {
    if (!formDataChanged.value) {
        setAlertData({
            show: true,
            type: 'failed',
            message: "Nothing was provided to update your profile."
        })
        return
    }

    updateForm.post(route(`counsellor.update`, { counsellorId: props.counsellor?.id }), {
        onSuccess: () => {
            closeThisModal()
        },
        onBefore: () => {
            loading.value = true
        },
        onFinish: () => {
            loading.value = false
        },
    })
}

function resetUpdateData() {
    updateForm.reset(
        'avatar', 'cover', 'deleteAvatar', 'deleteCover'
    )
}
</script>

<template>
    <Modal
        :show="modalData.show"
        @close="closeThisModal"
    >
        <div class="p-4">
            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Update Counsellor Account</div>
                <hr>
            </div>

            <FormLoader class="top-14 mx-auto" :show="loading" :text="'updating images'"/>
            <div class="max-h-[80vh] overflow-hidden p-2 overflow-y-auto">
                <form
                    @submit.prevent="updateCounsellor"
                >
                    <div class="w-full mx-auto max-w-[700px] bg-gray-200 sm:rounded-lg p-6 space-y-6">
                        <div>
                            <div class="w-full text-justify capitalize mb-2 text-lg font-medium text-gray-900">Cover Image</div>
                            <div class="w-full h-[200px] sm:h-[250px] md:h-[300px]">
                                <ImageUploadField
                                    v-model="updateForm.cover"
                                    v-model:removed="updateForm.deleteCover"
                                    :existing-url="counsellor?.cover ?? ''"
                                    shape="rect"
                                    label="cover image"
                                    input-id="counsellor-cover"
                                    empty-text="no cover image"
                                    :error="updateForm.errors.cover"
                                />
                            </div>
                        </div>

                        <div>
                            <div class="w-full text-justify capitalize mb-2 text-lg font-medium text-gray-900">Avatar</div>
                            <ImageUploadField
                                v-model="updateForm.avatar"
                                v-model:removed="updateForm.deleteAvatar"
                                :existing-url="counsellor?.avatar ?? ''"
                                shape="circle"
                                :size="80"
                                label="avatar"
                                input-id="counsellor-avatar"
                                :error="updateForm.errors.avatar"
                            />
                        </div>
                    </div>

                    <div class="w-full flex items-center justify-end mt-4">
                        <PrimaryButton class="ms-4" :class="{ 'opacity-25': loading }" :disabled="!formDataChanged || loading">
                            update
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </Modal>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>
