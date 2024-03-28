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
const { alertData, clearAlertData, setAlertData} = useAlert()

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
const professionData = ref({
    name: '', description: ''
})
const professionErrors = ref({
    name: '', description: ''
})

watch(() => professionData.value.name, () => {
    if (
        professionData.value.name?.length &&
        professionErrors.value.name?.length
    ) clearError(professionErrors, 'name')
})

function closeModal() {
    clearProfessionData()
    emits('closeModal')
}
 
function clearProfessionData() {
    professionData.value.name = ''
    professionData.value.description = ''
}
  
async function createProfession() {
    loading.value = true

    const addedbyData = {}

    if (props.addedbyId) {
        addedbyData['addedbyType'] = props.addedbyType
        addedbyData['addedbyId'] = props.addedbyId
    }
    
    await axios
    .post(route(`professions.create`), {
        ...addedbyData,
        ...professionData.value
    })
    .then((res) => {
        console.log(res)
        emits('afterCreating', res.data.profession)
        closeModal()
    })
    .catch((err) => {
        console.log(err)
        if (err.response?.status == 422) {
            setErrorData(professionErrors, err.response.data.errors, ['name', 'description'])
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
                >Create Profession</div>
                <hr>
            </div>
            
            <div>
                <FormLoader class="mx-auto" :show="loading" :text="'creating profession...'"/>
                <form 
                    @submit.prevent="createProfession"
                >

                    <div class="mt-4 mx-auto max-w-[400px]">
                        <InputLabel for="name" value="Name" />

                        <TextInput
                            id="name"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="professionData.name"
                            required
                        />

                        <InputError class="mt-2" :message="professionErrors.name" />
                    </div>

                    <div class="mt-4 mx-auto max-w-[400px]">
                        <InputLabel for="description" value="Description" />

                        <TextBox
                            id="description"
                            class="mt-1 block w-full"
                            v-model="professionData.description"
                        />

                        <InputError class="mt-2" :message="professionErrors.description" />
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