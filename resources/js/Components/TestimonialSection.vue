<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">Testimonial</h2>

            <FormLoader :text="'getting testimonial'" :show="loading && !modalData.type"/>

            <div 
                v-if="testimonial"
                class="flex flex-col justify-center items-start w-[90%] sm:w-[80%] mx-auto"
            >
                <div class="text-base"><span class="bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent font-bold">@{{ testimonial.addedby.username }}</span></div>
                <div class="text-lg text-gray-600">{{ testimonial.addedby.fullName ?? testimonial.addedby.name }} ({{ testimonial.by }})</div>
                <div class="text-gray-600 text-sm tracking-wide mt-4 text-justify p-2 bg-gray-200 rounded">
                    <p>
                        {{ testimonial.content }}
                    </p>
                </div>
            </div>

            <template v-else>
                <div v-if="computedCanAdd" class="text-sm text-gray-600 mt-8 w-[90%] sm:w-[80%] mx-auto">
                    No testimonials to show for now. If you feel this app has had an impact, do share a testimonial.
                </div>

                <div v-else class="text-sm text-gray-600 mt-8 w-[90%] sm:w-[80%] mx-auto">
                    No testimonials to show for now. You may have a look around the app and you may want to testify soon
                </div>

                <div v-if="!loading && computedCanAdd" class="mt-4 flex w-[90%] sm:w-[80%] mx-auto space-x-3 justify-start items-center overflow-hidden overflow-x-auto p-2">
                    <PrimaryButton class="shrink-0" @click="() => showModal('add')">add testimonial</PrimaryButton>
                </div>      
            </template>

            <div v-if="!loading && computedIsByYou" class="mt-4 flex w-[90%] sm:w-[80%] mx-auto space-x-3 justify-start items-center overflow-hidden overflow-x-auto p-2">
                <PrimaryButton class="shrink-0" @click="() => showModal('update')">update</PrimaryButton>
                <DangerButton class="shrink-0" @click="() => showModal('delete')">delete</DangerButton>
            </div>      
        </header>

        <Alert
            :show="alertData.show"
            :type="alertData.type"
            :message="alertData.message"
            :time="alertData.time"
            @close="clearAlertData"
        />

        <MiniModal
            :show="modalData.type == 'delete' && modalData.show"
            @close="closeModal"
        >
            <div>
                <div class="text-red-700 text-center font-bold tracking-wide">Delete Testimonial</div>
                <hr class="my-2">
                <FormLoader :danger="true" :show="loading && modalData.type == 'delete'" :text="'deleting testimonial'"/>
                <div class="text-red-700 my-4 w-[90%] mx-auto text-center">Are sure you want to delete this testimonial?</div>
                <div class="flex p-4 items-center justify-end mx-auto w-[90%] md:w-[75%]">
                    <PrimaryButton @click="closeModal">cancel</PrimaryButton>
                    <DangerButton class="ml-2" @click="deleteTestimonial">delete</DangerButton>
                </div>
            </div>
        </MiniModal>

        <UpdateTestimonialModal
            :show="modalData.show && modalData.type == 'update'"
            :testimonial="testimonial"
            @on-success="(data) => {
                testimonial = data
            }"
            @close-modal="closeModal"
        />
        
        <CreateTestimonialModal
            :show="modalData.show && modalData.type == 'add'"
            @close-modal="closeModal"
            @on-success="(data) => {
                if (byId == data.addedby.id && byType == data.by)
                    testimonial = data
            }"
        />
    </section>
</template>

<script setup>
import useAlert from '@/Composables/useAlert';
import useAuth from '@/Composables/useAuth';
import { onBeforeMount, ref } from 'vue';
import Alert from './Alert.vue';
import FormLoader from './FormLoader.vue';
import UpdateTestimonialModal from './UpdateTestimonialModal.vue';
import DangerButton from './DangerButton.vue';
import useModal from '@/Composables/useModal';
import MiniModal from './MiniModal.vue';
import CreateTestimonialModal from './CreateTestimonialModal.vue';
import { computed } from 'vue';
import PrimaryButton from './PrimaryButton.vue';
import { usePage } from '@inertiajs/vue3';

const { goToLogin } = useAuth()
const { modalData, showModal, closeModal } = useModal()
const { alertData, clearAlertData, setSuccessAlertData, setFailedAlertData } = useAlert ()

const user = usePage().props.auth.user

const props = defineProps({
    addedby: {
        default: null
    },
    byType: {
        default: null
    },
    byId: {
        default: null
    }
})

onBeforeMount(() => {
    getTestimonial()
})

const loading = ref(false)
const testimonial = ref(null)

const computedIsByYou = computed(() => {
    return !!testimonial.value && testimonial.value?.by == props.byType && testimonial.value?.addedby?.id == props.byId
})
const computedCanAdd = computed(() => {
    return 'User' == props.byType && user?.id == props.byId || 'Counsellor' == props.byType && user?.counsellor?.id == props.byId
})

async function getTestimonial() {
    loading.value = true

    await axios.get(route(`api.testimonials`), { addedbyType: props.byType, addedbyId: props.byId })
        .then((res) => {
            console.log(res)
            testimonial.value = res.data.data?.length ? res.data.data[0] : null
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)

            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                    time: 5000
                })
                return
            }

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
                time: 5000
            })
        })

    loading.value = false
}

async function deleteTestimonial() {
    if (!testimonial.value?.id)
    loading.value = true    

    await axios.delete(route(`api.testimonials.delete`, { testimonialId: testimonial.value.id }))
        .then((res) => {
            console.log(res)
            
            setSuccessAlertData({
                message: 'Your testimonial has been successfully deleted.',
                time: 4000
            })

            testimonial.value = null
            closeModal()
        })
        .catch((err) => {
            console.log(err)
            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                    time: 5000,
                })
                return
            }

            if (err.alert) {
                setFailedAlertData({
                    message: err.alert,
                    time: 5000,
                })
                return
            }

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
                time: 4000
            })
        })
        .finally(() => {
            loading.value = false
        })
}

</script>