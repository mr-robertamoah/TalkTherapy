<script setup>
import { computed, ref, unref, watchEffect } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextBox from '@/Components/TextBox.vue';
import FormLoader from '@/Components/FormLoader.vue';
import Modal from '@/Components/Modal.vue';
import useAlert from "@/Composables/useAlert";
import Alert from "./Alert.vue";
import PrimaryButton from "./PrimaryButton.vue";
import { usePage } from '@inertiajs/vue3';
import useAuth from '@/Composables/useAuth';
import useErrorHandler from '@/Composables/useErrorHandler';

const { goToLogin } = useAuth()
const { clearErrorData } = useErrorHandler()
const { alertData, clearAlertData, setSuccessAlertData, setFailedAlertData } = useAlert()

const user = usePage().props.auth.user

const props = defineProps({
    show: {
        default: false,
        type: Boolean
    },
    testimonial: {
        default: null,
    },
})

const emits = defineEmits(['closeModal', 'onSuccess'])

watchEffect(() => {
    if (props.testimonial?.id)
        testimonialData.value.content = props.testimonial.content
})

const loading = ref(false)
const testimonialData = ref({
    'content': '',
})
const testimonialErrors = ref({
    'content': '',
})

const computedIsCounsellor = computed(() => {
    return !!user?.counsellor
})

async function createTestimonial() {
    if (!props.testimonial?.id) return

    if (!testimonialData.value.content) {
        setFailedAlertData({
            message: "Content is required for a testimonial.",
        });
        return
    }

    clearErrorData(testimonialErrors, [
        'content', 
    ])

    loading.value = true

    await axios.post(route(`api.testimonials.update`, { testimonialId: props.testimonial?.id }), {...unref(testimonialData)})
        .then((res) => {
            console.log(res)
            
            setSuccessAlertData({
                message: 'Your testimonial has been updated successfully.',
            })

            if (res.data.testimonial)
                emits('onSuccess', res.data.testimonial)

            closeModal()
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)

            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                })
                return
            }

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
            })
        
        })

    loading.value = false
}

function clearData() {
    testimonialData.value.content = ''
}

function closeModal() {
    clearData()
    emits('closeModal')
}
</script>

<template>
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="select-none relative">

            <div class="p-4 w-full mt-2 mb-4">
                <div
                    class="capitalize w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Update Testimonial</div>
                <hr>
            </div>

            <FormLoader class="mx-auto" :show="loading" :text="`updating testimonial`"/>
            <div class="p-4 relative">
                <form 
                    @submit.prevent="createTestimonial"
                >
                    <div class="overflow-hidden overflow-y-auto h-[65vh] px-4 pb-4">
                        <div class="mb-4 text-sm text-gray-600">
                            <div class="p-4 rounded bg-gray-200 shadow-sm">
                                Updating as {{ testimonial.by }}
                            </div>
                        </div>
                        
                        <div class="p-4 mb-4 rounded bg-gray-200 shadow-sm">
                            <div class="text-left text-sm font-bold mb-2">Details</div>
                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="content" value="Content" />

                                <TextBox
                                    id="content"
                                    class="mt-1 block w-full"
                                    v-model="testimonialData.content"
                                    rows="8"
                                />

                                <div class="mt-2 text-xs text-gray-500">What do you really think of #TalkTherapy app.</div>
                                <InputError class="mt-2" :message="testimonialErrors.content" />
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end mt-4">

                        <PrimaryButton class="ms-4" :class="{ 'opacity-25': loading }" :disabled="loading">
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