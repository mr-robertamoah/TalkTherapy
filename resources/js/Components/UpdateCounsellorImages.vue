<script setup>
import useAlert from "@/Composables/useAlert";
import useModal from "@/Composables/useModal";
import { useForm } from "@inertiajs/vue3";
import { ref, watch, watchEffect } from "vue";
import Alert from "./Alert.vue";
import FormLoader from "./FormLoader.vue";
import PrimaryButton from "./PrimaryButton.vue";
import Modal from "./Modal.vue";
import { computed } from "vue";
import InputError from "./InputError.vue";
import Avatar from "./Avatar.vue";

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

const data = ref({
    selectedCases: [],
    selectedLanguages: [],
    selectedReligions: [],
    cases: [],
    languages: [],
    religions: [],
})
const loading = ref(false)
const formDataChanged = ref(false)
const avatarUrl = ref('')
const coverUrl = ref('')
const avatar = ref(null)
const cover = ref(null)

watch(
    () => props.show,
    () => {
        modalData.value.show = props.show

        if (props.show) setUpdateData()
    }
)
watch(
    () => avatar.value?.files,
    () => {
        if (avatar.value?.files) updateForm.avatar = avatar.value.files[0]
        else updateForm.avatar = null

        updateForm.clearErrors('avatar')
    }
)
watch(
    () => cover.value,
    () => {
        if (cover.value?.files) updateForm.cover = cover.value.files[0]
        else updateForm.cover = null

        updateForm.clearErrors('cover')
    }
)
watchEffect(() => {
    formDataChanged.value = false
    
    if (updateForm.avatar)
        return formDataChanged.value = true
    
    if (avatarUrl.value !== props.counsellor.avatar)
        return formDataChanged.value = true
    
    if (coverUrl.value !== props.counsellor.cover)
        return formDataChanged.value = true

    if (updateForm.cover)
        return formDataChanged.value = true
})

const computedAvatarUrl = computed(() => {
    return updateForm.avatar ? URL.createObjectURL(updateForm.avatar) : avatarUrl.value
})
const computedCoverUrl = computed(() => {
    return updateForm.cover ? URL.createObjectURL(updateForm.cover) : coverUrl.value
})

function closeThisModal() {
    resetUpdateData()
    emits('closeModal')
    closeModal()
}
 
function updateCounsellor() { 
    
    if (thereIsNoData()) {
        setAlertData({
            show: true,
            type: 'failed',
            message: "Nothing was provided to update your profile."
        })
        return
    }

    updateForm.post(route(`counsellor.update`, { counsellorId: props.counsellor?.id}), {
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

function clickedChangeFile(type) {
    console.log(type);
    if (type == 'avatar')
        return avatar.value.click()

    cover.value.click()
}

function changeAvatar(e) {
    if (e.target.files?.length)
        updateForm.avatar = e.target.files[0]
}

function changeCover(e) {
    if (e.target.files?.length)
        updateForm.cover = e.target.files[0]
}

function deleteAvatar() {
    if (avatarUrl.value) {
        avatarUrl.value = ''
        updateForm.deleteAvatar = true
        return
    }

    updateForm.avatar = null
    avatarUrl.value = props.counsellor.avatar
}

function deleteCover() {
    if (coverUrl.value) {
        coverUrl.value = ''
        updateForm.deleteCover = true
        return
    }

    updateForm.cover = null
    coverUrl.value = props.counsellor.cover
}

function setUpdateData() {
    if (props.counsellor?.avatar)
        avatarUrl.value = props.counsellor.avatar
    if (props.counsellor?.cover)
        coverUrl.value = props.counsellor.cover
}

function resetUpdateData() {
    if (avatar.value?.value) avatar.value.value = ''
    if (cover.value?.value) cover.value.value = ''
    if (updateForm.avatar) updateForm.avatar = null
    if (updateForm.cover) updateForm.cover = null
    updateForm.reset(
        'avatar', 'cover', 'deleteAvatar', 'deleteCover'
    )
}

function thereIsNoData() {
    if (
        updateForm.avatar || updateForm.cover || updateForm.deleteAvatar || updateForm.deleteCover
    ) return false

    return true
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
                    <div class="w-full mx-auto max-w-[700px] bg-gray-200 sm:rounded-lg p-6 pb-20 relative">
                        <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">Profile Images</div>
                        <div class="relative p-1 text-gray-900 text-center bg-gray-300 w-full h-[200px] sm:h-[250px] md:h-[300px]">
                            <div class="absolute p-2 top-2 right-2 flex justify-end items-center">
                                <div
                                    v-if="counsellor.cover"
                                    @click="deleteCover"
                                    class="w-fit p-2 transition duration-75 text-xs sm:text-sm tracking-wide rounded cursor-pointer mr-2"
                                    :class="[!coverUrl ? 'hover:bg-green-600 hover:text-green-200 bg-green-300 text-green-700' : 'hover:bg-red-600 hover:text-red-200 bg-red-300 text-red-700']"
                                >{{ !coverUrl ? 'restore' : 'remove' }} image</div>
                                <div
                                    @click="() => clickedChangeFile('cover')"
                                    class="w-fit p-2 hover:bg-gray-600 hover:text-gray-200 transition duration-75 bg-gray-300 text-gray-700 text-xs sm:text-sm tracking-wide rounded cursor-pointer"
                                >{{ computedCoverUrl ? 'change' : 'add' }} cover image</div>
                            </div>
                            <img 
                                :src="computedCoverUrl ?? ''" 
                                :alt="'counsellor cover'"
                                v-if="computedCoverUrl"
                                class="w-full h-full object-cover rounded-b-lg"
                            >
                            <div v-else class="text-sm w-full h-full flex justify-center items-center text-gray-600 bg-white rounded-b-lg">no cover image</div>
                        </div>
                        <div class="absolute z-10 bottom-[42px] sm:bottom-[32px] left-2 block xs:flex items-center">

                            <div class="flex items-center z-[1] space-x-2">
                                <Avatar :size="80" :src="computedAvatarUrl ?? ''" :alt="'counsellor avatar'"/>
                                <div class="flex justify-center space-y-2 xs:space-y-0 xs:space-x-2 flex-col xs:flex-row">
                                    <div
                                        @click="() => clickedChangeFile('avatar')"
                                        class="w-fit p-2 text-center hover:bg-gray-600 hover:text-gray-200 transition duration-75 bg-gray-300 text-gray-700 text-xs xs:text-sm tracking-wide rounded cursor-pointer z-0 xs:-z-[1]"
                                    >{{ computedAvatarUrl ? 'change' : 'add' }} avatar</div>
                                    <div
                                        v-if="counsellor.avatar"
                                        @click="deleteAvatar"
                                        class="w-fit p-2 text-center transition duration-75 text-xs xs:text-sm tracking-wide rounded cursor-pointer z-0 xs:-z-[2]"
                                        :class="[!avatarUrl ? 'hover:bg-green-600 hover:text-green-200 bg-green-300 text-green-700' : 'hover:bg-red-600 hover:text-red-200 bg-red-300 text-red-700']"
                                    >{{ !avatarUrl ? 'restore' : 'remove' }} avatar</div>
                                </div>
                            </div>

                            <div class="shrink rounded bg-white p-2 text-sm" v-if="updateForm.errors.avatar || updateForm.errors.cover">
                                <InputError class="mt-2" :message="updateForm.errors.avatar" />
                                <InputError class="mt-2" :message="updateForm.errors.cover" />
                            </div>
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
    
    <input ref="avatar" type="file" name="counsellor-avatar" id="counsellor-avatar" class="hidden" accept="image/*" @change="changeAvatar">
    <input ref="cover" type="file" name="cover" id="cover" class="hidden" accept="image/*" @change="changeCover">
</template>