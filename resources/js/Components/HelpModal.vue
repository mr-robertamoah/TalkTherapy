<template>
    <Modal
        :show="show"
        @close="closeModal"
        v-bind="$attrs"
        classes="bg-transparent shadow-none"
        maxWidth="md"
    >
        <div class="select-none relative">

            <div class="p-4 w-full mt-2">
                <div
                    class="capitalize w-fit mx-auto text-2xl font-bold text-neutral-800
                        mb-2 bg-white rounded p-2 shadow-sm"
                >Guided Tours</div>
            </div>

            <FormLoader v-if="loading" class="mx-auto" :show="loading" :text="`getting help`"/>
            <div class="p-2 pt-0 max-w-96 mx-auto relative">
                <div v-if="howTos.length" class="text-sm text-center text-gray-800 mb-2">double click/tap a Guided Tour to view steps</div>
                <div class="overflow-hidden overflow-y-auto h-[65vh] px-4 pb-4">
                    <div v-if="howTos.length" class="space-y-3">
                        <HowToComponent
                            v-for="(howTo, idx) in howTos"
                            :key="idx"
                            :howTo="howTo"
                            @startTour="startTour"
                            :useModal="false"
                        />
                    </div>

                    <div v-else class="h-96 flex justify-center items-center text-sm text-gray-600">no guided tours</div>
                </div>
            </div>
                    <div
                        class="w-fit py-1 font-bold absolute top-5 right-0
                            p-2 px-4 rounded bg-red-600 text-red-200 
                            shadow-red-300 cursor-pointer"
                        v-on:click="() => closeModal()"
                    >x</div>

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

<script setup>
import Modal from '@/Components/Modal.vue'
import FormLoader from '@/Components/FormLoader.vue'
import Alert from '@/Components/Alert.vue'
import { ref, watch } from 'vue'
import HowToComponent from './HowToComponent.vue';
import useAuth from '@/Composables/useAuth';
import useAlert from '@/Composables/useAlert';
import { usePage } from '@inertiajs/vue3';
import GuidedTour from './GuidedTour.vue';


const { goToLogin } = useAuth()
const { alertData, setFailedAlertData, clearAlertData } = useAlert()

const emits = defineEmits(['close', 'startTour'])

const props = defineProps({
    page: {
        type: String,
        required: true
    },
    show: {
        type: Boolean,
        default: false
    },
})

const loading = ref(false)
const howTos = ref([])

watch(() => props.show, () => {
    if (props.show && !howTos.value?.length)
        getHowTos()
})

function startTour(howTo) {
    emits('startTour', howTo)
    closeModal()
}

function closeModal(){
    emits('close')
}

async function getHowTos() {
    if (howTos.value.length) return

    const user = usePage().props.auth.user

    loading.value = true

    await axios.get(route('api.how-tos', {
        pageLike: props.page,
    }))
        .then((res) => {
            console.log(res)
            if (!user) {
                howTos.value = [
                    ...res.data.data,
                ]
                return
            }

            let data = [
                ...res.data.data,
            ]

            data = data.filter((h) => {
                const name = h.name?.toLowerCase()
                if (!name) return true
                return (name.includes('log') || name.includes('regist')) ? false : true
            })

            howTos.value = data
        })
        .catch((err) => {
            console.log(err)
            setFailedAlertData({
                message: "Something unfortunate happened. Please try again in a short while."
            })
            goToLogin(err)
        })
        .finally(() => {
            loading.value = false
        })
}
</script>