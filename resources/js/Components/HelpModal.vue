<template>
    <Modal
        :show="show"
        @close="closeModal"
        v-bind="$attrs"
    >
        <div class="select-none relative">

            <div class="p-4 w-full mt-2 mb-4">
                <div
                    class="capitalize w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Help</div>
                <hr>
            </div>

            <FormLoader v-if="loading" class="mx-auto" :show="loading" :text="`getting help`"/>
            <div class="p-4 relative">
                <div v-if="howTos.length" class="text-sm text-center text-gray-600 mb-2">double click/tap a how-to to view steps</div>
                <div class="overflow-hidden overflow-y-auto h-[65vh] px-4 pb-4">
                    <div v-if="howTos.length" class="space-y-3">
                        <HowToComponent
                            v-for="(howTo, idx) in howTos"
                            :key="idx"
                            :howTo="howTo"
                        />
                    </div>

                    <div v-else class="h-96 flex justify-center items-center text-sm text-gray-600">no help yet</div>
                </div>
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

<script setup>
import Modal from '@/Components/Modal.vue'
import FormLoader from '@/Components/FormLoader.vue'
import Alert from '@/Components/Alert.vue'
import { ref, watch } from 'vue'
import HowToComponent from './HowToComponent.vue';
import useAuth from '@/Composables/useAuth';
import useAlert from '@/Composables/useAlert';
import { usePage } from '@inertiajs/vue3';


const { goToLogin } = useAuth()
const { alertData, setFailedAlertData, clearAlertData } = useAlert()
const user = usePage().props.auth.user

const emits = defineEmits(['close'])

const props = defineProps({
    page: {
        type: String,
        required: true
    },
    show: {
        type: Boolean,
        default: false
    }
})

const loading = ref(false)
const howTos = ref([])

watch(() => props.show, () => {
    if (props.show)
        getHowTos()
})

function closeModal(){
    emits('close')
}

async function getHowTos() {
    if (howTos.value.length) return

    loading.value = true

    await axios.get(route('api.how-tos', {
        pageLike: props.page,
    }))
        .then((res) => {
            console.log(res)
            let data = [
                ...res.data.data,
            ]

            if (user)
                data = data.filter((h) => {
                    const name = h.name?.toLowerCase()
                    if (!name) return true
                    return !name.includes('log') && !name.includes('regist')
                })

            howTos.value = data
        })
        .catch((err) => {
            console.log(err)
            setFailedAlertData({
                time: 4000,
                message: "Something unfortunate happened. Please try again in a short while."
            })
            goToLogin(err)
        })
        .finally(() => {
            loading.value = false
        })
}
</script>