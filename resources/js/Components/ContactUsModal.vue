<script setup>
import { computed, onBeforeMount, ref, unref, watch, watchEffect } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextBox from '@/Components/TextBox.vue';
import TextInput from '@/Components/TextInput.vue';
import FormLoader from '@/Components/FormLoader.vue';
import Modal from '@/Components/Modal.vue';
import useAlert from "@/Composables/useAlert";
import Alert from "./Alert.vue";
import PrimaryButton from "./PrimaryButton.vue";
import Select from "./Select.vue";
import { usePage } from '@inertiajs/vue3';
import useAuth from '@/Composables/useAuth';
import useErrorHandler from '@/Composables/useErrorHandler';

const { goToLogin } = useAuth()
const { clearErrorData, setErrorData } = useErrorHandler()
const { alertData, clearAlertData, setSuccessAlertData, setFailedAlertData } = useAlert()

const user = usePage().props.auth.user

const props = defineProps({
    show: {
        default: false,
        type: Boolean
    },
})

const emits = defineEmits(['closeModal', 'onSuccess'])

const loading = ref(false)
const getting = ref({
    show: false,
    type: '',
})
const contactData = ref({
    'content': '',
    'type': 'GENERAL',
    'name': '',
    'organisation': '',
    'email': '',
    'addedbyType': 'User',
    'addedbyId': user ? user.id : '',
})
const contactErrors = ref({
    'content': '',
    'type': '',
    'name': '',
    'organisation': '',
    'email': '',
    'addedbyType': '',
    'addedbyId': '',
})

const computedIsCounsellor = computed(() => {
    return !!user?.counsellor
})
const computedType = computed(() => {
    return {
        SUGGESTION: "Have any suggestion for us?",
        GENERAL: "Have any general information for us?",
        SPONSORSHIP: "Interested in supporting our noble cause of improving the mental health of individuals.",
        HELP: "Finding trouble doing something on this app? Let us know about it.",
    }[contactData.value.type]
})

async function createContact() {
    if (!contactData.value.content) {
        setFailedAlertData({
            message: "Content is required, Please tell us something.",
        });
        return
    }

    if (!contactData.value.type) {
        setFailedAlertData({
            message: "Type is required. Select from the select box.",
        });
        return
    }
    
    if (
        (!contactData.value.addedbyType || !contactData.value.addedbyId) && user
    ) {
        contactData.value.addedbyId = user.id
        contactData.value.addedbyId = 'User'
    }

    if (!user && !contactData.value.name) {
        setFailedAlertData({
            message: "Name is required since you are not a user of the app or have not logged in.",
        });
        return
    }

    if (!user && !contactData.value.email) {
        setFailedAlertData({
            message: "Email is required since you are not a user of the app or have not logged in.",
        });
        return
    }

    clearErrorData(contactErrors, [
        'content', 'name', 'email', 'organisation'
    ])

    loading.value = true
    await axios.post(route(`api.contacts.create`), {...unref(contactData)})
        .then((res) => {
            console.log(res)
            
            setSuccessAlertData({
                message: 'Your message to us has successfully been sent.',
            })

            if (res.data.contact)
                emits('onSuccess', res.data.contact)

            closeModal()
        })
        .catch((err) => {
            console.log(err)

            if (err.response?.status == 422 && err.response?.data?.errors) {
                setErrorData(contactErrors, err.response.data.errors, ['name', 'organisation', 'email', 'type'])
                return
            }

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
    contactData.value.content = ''
    contactData.value.name = ''
    contactData.value.email = ''
    contactData.value.type = ''
    contactData.value.addedbyId = user ? user.id : ''
    contactData.value.addedbyType = 'User'
    contactData.value.organisation = ''
}

function setGetting(type) {
    getting.value.type = type
    getting.value.show = true
}

function clearGetting() {
    getting.value.type = ''
    getting.value.show = false
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
                >Contact Us</div>
                <hr>
            </div>

            <FormLoader class="mx-auto" :show="loading" :text="`contacting us`"/>
            <div class="p-4 relative">
                <form 
                    @submit.prevent="createContact"
                >
                    <div class="overflow-hidden overflow-y-auto h-[65vh] px-4 pb-4">
                        <div class="mb-4 text-sm text-gray-600" v-if="user">
                            <div class="p-4 rounded bg-gray-200 shadow-sm">
                                Contacting as {{ contactData.addedbyType }}
                            </div>
                            <div v-if="computedIsCounsellor" class="p-4 rounded bg-gray-200 shadow-sm mt-4">
                                <div class="text-left text-sm font-bold mb-2">You may contact as</div>
                                <div
                                    class="bg-white rounded p-2 mx-auto w-[80%] cursor-pointer my-2"
                                    v-if="contactData.addedbyType !== 'User'"
                                    @dblclick="() => {
                                        contactData.addedbyType = 'User'
                                        contactData.addedbyId = user.id
                                    }"
                                >
                                    Contacting as User
                                </div>
                                <div
                                    class="bg-white rounded p-2 mx-auto w-[80%] cursor-pointer my-2"
                                    v-if="contactData.addedbyType !== 'Counsellor'"
                                    @dblclick="() => {
                                        contactData.addedbyType = 'Counsellor'
                                        contactData.addedbyId = user.counsellor?.id
                                    }"
                                >
                                    Contacting as Counsellor
                                </div>
                            </div>

                            <InputError class="mt-2" :message="contactErrors.addedbyId ?? contactErrors.addedbyType" />
                        </div>
                        
                        <div class="p-4 mb-4 rounded bg-gray-200 shadow-sm">
                            <div class="text-left text-sm font-bold mb-2">What are you contacting us about</div>
                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="type" value="Type" />

                                <Select
                                    id="type"
                                    class="mt-1 block w-full"
                                    v-model="contactData.type"
                                    autocomplete="type"
                                    :options="['GENERAL', 'SPONSORSHIP', 'HELP', 'SUGGESTION']"
                                    :default-option="'select type'"
                                    required
                                />

                                <div class="mt-2 text-xs text-gray-500" v-if="computedType">{{ computedType }}</div>
                                <InputError class="mt-2" :message="contactErrors.type" />
                            </div>
                        </div>
                        
                        <div class="p-4 mb-4 rounded bg-gray-200 shadow-sm">
                            <div class="text-left text-sm font-bold mb-2">Details</div>
                            <template v-if="!user">
                                <div class="mt-4 mx-auto max-w-[400px]">
                                    <InputLabel for="name" value="Name" />

                                    <TextInput
                                        id="name"
                                        class="mt-1 block w-full"
                                        v-model="contactData.name"
                                    />

                                    <InputError class="mt-2" :message="contactErrors.name" />
                                </div>
                                
                                <div class="mt-4 mx-auto max-w-[400px]">
                                    <InputLabel for="email" value="Email" />

                                    <TextInput
                                        id="email"
                                        type="email"
                                        class="mt-1 block w-full"
                                        v-model="contactData.email"
                                    />

                                    <InputError class="mt-2" :message="contactErrors.email" />
                                </div>
                            </template>

                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="organisation" value="Organisation" />

                                <TextInput
                                    id="organisation"
                                    class="mt-1 block w-full"
                                    v-model="contactData.organisation"
                                />

                                <InputError class="mt-2" :message="contactErrors.organisation" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="content" value="Content" />

                                <TextBox
                                    id="content"
                                    class="mt-1 block w-full"
                                    v-model="contactData.content"
                                    rows="5"
                                />

                                <div class="mt-2 text-xs text-gray-500">Give us enough information as possible.</div>
                                <InputError class="mt-2" :message="contactErrors.content" />
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end mt-4">

                        <PrimaryButton class="ms-4" :class="{ 'opacity-25': loading }" :disabled="loading">
                            send
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