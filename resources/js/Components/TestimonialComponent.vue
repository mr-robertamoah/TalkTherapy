<template>
    <div v-bind="$attrs">
        <FormLoader :text="(localTestimonial.use ? 'unmarking' : 'marking') + ' testimonial'" :show="loading && !modalData.type"/>

        <div
            class="flex flex-col justify-center items-start w-[90%] sm:w-[80%] mx-auto"
        >
            <div class="text-base"><span class="bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent font-bold">@{{ localTestimonial.addedby.username }}</span></div>
            <div class="text-lg text-gray-600">{{ localTestimonial.addedby.fullName ?? localTestimonial.addedby.name }} ({{ localTestimonial.by }})</div>
            <div class="text-gray-600 text-sm tracking-wide mt-4 text-justify p-2 bg-gray-200 rounded">
                <p>
                    {{ localTestimonial.content }}
                </p>
            </div>
        </div>

        <div v-if="message" class="text-end text-sm text-green-600">{{ message }}</div>

        <div v-if="!loading && user?.isAdmin" class="mt-4 flex w-[90%] sm:w-[80%] mx-auto space-x-3 justify-start items-center overflow-hidden overflow-x-auto p-2">
            <PrimaryButton class="shrink-0" @click="markTestimonial">{{ localTestimonial.use ? 'unmark' : 'mark'}}</PrimaryButton>
        </div>      
    </div>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>

<script setup>
import useAlert from '@/Composables/useAlert';
import useAuth from '@/Composables/useAuth';
import { onBeforeMount, ref, watch, watchEffect } from 'vue';
import Alert from './Alert.vue';
import FormLoader from './FormLoader.vue';
import useModal from '@/Composables/useModal';
import { computed } from 'vue';
import PrimaryButton from './PrimaryButton.vue';
import { usePage } from '@inertiajs/vue3';

const { goToLogin } = useAuth()
const { modalData, showModal, closeModal } = useModal()
const { alertData, clearAlertData, setSuccessAlertData, setFailedAlertData } = useAlert ()

const user = usePage().props.auth.user

const props = defineProps({
    testimonial: {
        default: null
    },
})

const message = ref('')
const localTestimonial = ref(null)
const loading = ref(false)

watchEffect(() => {
    if (props.testimonial?.id)
        localTestimonial.value = props.testimonial
})
watch(() => message.value?.length, () => {
    if (message.value?.length)
        setTimeout(() => {
            message.value = ''
        }, 2000)
})

async function markTestimonial() {
    if (!localTestimonial.value?.id)

    loading.value = true    

    await axios.post(route(`api.testimonials.mark`, { testimonialId: localTestimonial.value.id }), {
        use: localTestimonial.value.use ? 0 : 1
    })
        .then((res) => {
            console.log(res)
            
            message.value = `Your testimonial has been successfully ${res.data.testimonial.use ? 'marked' : 'unmarked'}.`

            localTestimonial.value = res.data.testimonial
        })
        .catch((err) => {
            console.log(err)
            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                })
                return
            }

            if (err.alert) {
                setFailedAlertData({
                    message: err.alert,
                })
                return
            }

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
            })
        })
        .finally(() => {
            loading.value = false
        })
}

</script>