<script setup>
import { ref, watch } from 'vue';
import FormLoader from './FormLoader.vue';
import InputError from './InputError.vue';
import InputLabel from './InputLabel.vue';
import Modal from './Modal.vue';
import PrimaryButton from './PrimaryButton.vue';
import TextBox from './TextBox.vue';
import TextInput from './TextInput.vue';
import useErrorHandler from '@/Composables/useErrorHandler';
import useAlert from '@/Composables/useAlert';
import Alert from './Alert.vue';

const { setErrorData } = useErrorHandler()
const { alertData, clearAlertData, setAlertData, setFailedAlertData } = useAlert()

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    addedbyType: {
        type: String,
        default: 'Counsellor'
    },
    addedbyId: {
        type: Number,
        default: 0
    },
})

const emits = defineEmits(['closeModal', 'afterCreating'])

const loading = ref(false)
const religionData = ref({
    name: '', about: ''
})
const religionErrors = ref({
    name: '', about: ''
})

watch(() => religionData.value.name, () => {
    if (
        religionData.value.name?.length &&
        religionErrors.value.name?.length
    ) clearError(religionErrors, 'name')
})

function closeModal() {
    clearReligionData()
    emits('closeModal')
}
 
function clearReligionData() {
    religionData.value.name = ''
    religionData.value.about = ''
}
  
async function createReligion() {
    loading.value = true

    const addedbyData = {}

    if (props.addedbyId) {
        addedbyData['addedbyType'] = props.addedbyType
        addedbyData['addedbyId'] = props.addedbyId
    }
    
    await axios
    .post(route(`religions.create`), {
        ...addedbyData,
        ...religionData.value
    })
    .then((res) => {
        console.log(res)
        emits('afterCreating', res.data.religion)
        closeModal()
    })
    .catch((err) => {
        console.log(err)
        if (err.response?.status == 422) {
            setErrorData(religionErrors, err.response.data.errors, ['name', 'about'])
            return
        }
        if (err.response?.data?.message) {
            setFailedAlertData({
                message: err.response.data.message,
                time: 4000,
            })
            return
        }

        setAlertData({
            message: 'Something unfortunate happened. Please try again later.',
            type: 'failed',
            show: true,
        })
    })
    .finally(() => {
        loading.value = false
    })
}
</script>

<template>
    
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="p-4">

            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Create Religion</div>
                <hr>
            </div>
            
            <div>
                <FormLoader class="mx-auto" :show="loading" :text="'creating religion'"/>
                <form 
                    @submit.prevent="createReligion"
                >

                    <div class="mt-4 mx-auto max-w-[400px]">
                        <InputLabel for="name" value="Name" />

                        <TextInput
                            id="name"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="religionData.name"
                            required
                        />

                        <InputError class="mt-2" :message="religionErrors.name" />
                    </div>

                    <div class="mt-4 mx-auto max-w-[400px]">
                        <InputLabel for="about" value="About" />

                        <TextBox
                            id="about"
                            class="mt-1 block w-full"
                            v-model="religionData.about"
                        />

                        <InputError class="mt-2" :message="religionErrors.about" />
                    </div>

                    <div class="flex items-center justify-end mt-4">

                        <PrimaryButton class="ms-4" :class="{ 'opacity-25': loading }" :disabled="loading">
                            create
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