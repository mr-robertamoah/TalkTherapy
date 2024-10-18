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
const caseData = ref({
    name: '', description: ''
})
const caseErrors = ref({
    name: '', description: ''
})

watch(() => caseData.value.name, () => {
    if (
        caseData.value.name?.length &&
        caseErrors.value.name?.length
    ) clearError(caseErrors, 'name')
})

function closeModal() {
    clearCaseData()
    emits('closeModal')
}
 
function clearCaseData() {
    caseData.value.name = ''
    caseData.value.description = ''
}
  
async function createCase() {
    loading.value = true

    const addedbyData = {}

    if (props.addedbyId) {
        addedbyData['addedbyType'] = props.addedbyType
        addedbyData['addedbyId'] = props.addedbyId
    }
    
    await axios
    .post(route(`therapy-cases.create`), {
        ...addedbyData,
        ...caseData.value
    })
    .then((res) => {
        console.log(res)
        emits('afterCreating', res.data.case)
        closeModal()
    })
    .catch((err) => {
        console.log(err)
        if (err.response?.status == 422) {
            setErrorData(caseErrors, err.response.data.errors, ['name', 'description'])
            return
        }
        if (err.response?.data?.message) {
            setFailedAlertData({
                message: err.response.data.message,
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
                >Create Case</div>
                <hr>
            </div>
            
            <div>
                <FormLoader class="mx-auto" :show="loading" :text="'creating case'"/>
                <form 
                    @submit.prevent="createCase"
                >

                    <div class="mt-4 mx-auto max-w-[400px]">
                        <InputLabel for="name" value="Name" />

                        <TextInput
                            id="name"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="caseData.name"
                            required
                        />

                        <InputError class="mt-2" :message="caseErrors.name" />
                    </div>

                    <div class="mt-4 mx-auto max-w-[400px]">
                        <InputLabel for="description" value="Description" />

                        <TextBox
                            id="description"
                            class="mt-1 block w-full"
                            v-model="caseData.description"
                        />

                        <InputError class="mt-2" :message="caseErrors.description" />
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