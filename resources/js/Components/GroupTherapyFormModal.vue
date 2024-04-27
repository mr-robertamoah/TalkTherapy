<script setup>
import { ref, watch, watchEffect } from 'vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextBox from '@/Components/TextBox.vue';
import FormLoader from '@/Components/FormLoader.vue';
import Modal from '@/Components/Modal.vue';
import useErrorHandler from "@/Composables/useErrorHandler";
import useAlert from "@/Composables/useAlert";
import Alert from "./Alert.vue";
import PrimaryButton from "./PrimaryButton.vue";
import Checkbox from "./Checkbox.vue";
import Select from "./Select.vue";
import ProfileCaseSection from "@/Pages/Profile/Partials/ProfileCaseSection.vue";

const { setErrorData } = useErrorHandler()
const { alertData, clearAlertData, setAlertData } = useAlert()

defineProps({
    show: {
        default: false,
        type: Boolean
    }
})

const emits = defineEmits(['successful', 'closeModal'])

const loading = ref(false)
const therapyData = ref({
    'name': '',
    'backgroundStory': '',
    'anonymous': false,
    'allowInPerson': false,
    'public': false,
    'sessionType': '',
    'paymentType': '',
    'per': '',
    'amount': '',
    'maxSessions': '',
    'currency': 'GHȻ',
    'cases': []
})
const therapyErrors = ref({
    'name': '',
    'backgroundStory': '',
    'anonymous': '',
    'allowInPerson': '',
    'public': '',
    'sessionType': '',
    'paymentType': '',
    'per': '',
    'amount': '',
    'currency': '',
    'maxSessions': '',
    'cases': ''
})

watchEffect(() => {
    if (therapyData.value.sessionType == 'ONCE' && therapyData.value.paymentType == 'PAID')
        therapyData.value.per = 'PER_THERAPY'

    if (therapyData.value.sessionType == 'ONCE')
        therapyData.value.maxSessions = '1'
})
watch(
    () => therapyData.value.paymentType,
    () => {
        if (therapyData.value.paymentType == 'FREE') {
            therapyData.value.public = true
            therapyData.value.amount = ''
            therapyData.value.currency = 'GHȻ'
            therapyData.value.per = ''
        }
    }
)
 
async function createTherapy() {
    if (!therapyData.value.name) {
        setAlertData({
            message: "Name is required for a therapy.",
            time: 5000,
            show: true,
            type: 'failed'
        });
        return
    }

    if (
        therapyData.value.paymentType == 'PAID' &&
        !(therapyData.value.amount && therapyData.value.currency && therapyData.value.per)
    ) {
        setAlertData({
            message: "Amount, currency and per what? All of these are required since you selected PAID payment type.",
            time: 5000,
            show: true,
            type: 'failed'
        });
        return
    }
        
    if (
        therapyData.value.paymentType == 'FREE' &&
        !therapyData.value.public
    ) {
        setAlertData({
            message: "FREE payment types requires that you set public to true.",
            time: 5000,
            show: true,
            type: 'failed'
        });
        return
    }

    if (
        therapyData.value.paymentType == 'PAID' &&
        therapyData.value.sessionType == 'ONCE' &&
        therapyData.value.per !== 'PER_THERAPY'
    ) {
        setAlertData({
            message: "Since ONCE and PAID have been selected for session and payment types respectively, the per amount should be THERAPY.",
            time: 5000,
            show: true,
            type: 'failed'
        });
        return
    }

    if (
        therapyData.value.sessionType == 'PERIODIC' &&
        (!therapyData.value.maxSessions || therapyData.value.maxSessions < 2)
    ) {
        setAlertData({
            time: 5000,
            show: true,
            type: 'failed',
            message: "Since PERIODIC has been selected for the session type, the maximum number of sessions must be at least 2."
        });
        return
    }

    loading.value = true

    await axios
    .post(route(`therapies.create`), {
        ...therapyData.value,
        public: therapyData.value.public ? 1 : 0,
        allowInPerson: therapyData.value.allowInPerson ? 1 : 0,
        anonymous: therapyData.value.anonymous ? 1 : 0,
    })
    .then((res) => {
        console.log(res)
        
        setAlertData({
            message: 'Your therapy has been created successfully. Visit home page if you are already not there.',
            type: 'success',
            show: true,
            time: 4000
        })
        emits('successful', res.data.therapy)
        closeModal()
    })
    .catch((err) => {
        console.log(err)
        if (err.response?.data?.message) {
            setAlertData({
                message: err.response.data.message,
                type: 'failed',
                show: true,
            })
            return
        }

        if (err.response?.status == 422 && err.response?.data?.errors) {
            setErrorData(therapyErrors, err.response.data.errors, [
                'name', 'backgroundStory', 'anonymous', 'allowInPerson', 'public', 'sessionType',
                'paymentType', 'per', 'amount', 'currency', 'maxSessions'
            ])
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

function clearData() {
    therapyData.value.name = ''
    therapyData.value.backgroundStory = ''
    therapyData.value.paymentType = ''
    therapyData.value.sessionType = ''
    therapyData.value.maxSessions = ''
    therapyData.value.public = false
    therapyData.value.allowInPerson = false
    therapyData.value.anonymous = false
    therapyData.value.amount = ''
    therapyData.value.currency = 'GHȻ'
    therapyData.value.per = ''
    therapyData.value.cases = []
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
                >Create individual therapy</div>
                <hr>
            </div>

            <FormLoader class="mx-auto" :show="loading" :text="`creating individual therapy`"/>
            <div class="p-4 relative overflow-hidden overflow-y-auto h-[70vh] px-4 pb-4">
                <form 
                    @submit.prevent="createTherapy"
                >
                    <div class="p-4 rounded bg-gray-200 shadow-sm">
                        <div class="text-sm text-gray-600 text-start mb-4 font-semibold">This section can be updated any time.</div>
                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="name" value="Name" />

                            <TextInput
                                id="name"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="therapyData.name"
                                required
                                autofocus
                            />
                            
                            <InputError class="mt-2" :message="therapyErrors.name" />
                        </div>

                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="backgroundStory" value="Background Story" />

                            <TextBox
                                id="story"
                                class="mt-1 block w-full"
                                v-model="therapyData.backgroundStory"
                                rows="5"
                            />

                            <div class="mt-2 text-xs text-gray-500">This gives the potential counsellor a background story to start with.</div>
                            <InputError class="mt-2" :message="therapyErrors.backgroundStory" />
                        </div>

                        <div class="mt-4 mx-auto max-w-[400px]">
                            <label class="flex items-center">
                                <Checkbox name="remember" v-model:checked="therapyData.anonymous" />
                                <span class="ms-2 text-sm text-gray-600">Stay anonymous.</span>
                            </label>

                            <div class="mt-2 text-xs text-gray-500">If you check this box, not even your counsellor will know who you are unless you reveal yourself to him/her.</div>
                            <InputError class="mt-2" :message="therapyErrors.anonymous" />
                        </div>

                        <div class="mt-4 mx-auto max-w-[400px]">
                            <label class="flex items-center">
                                <Checkbox name="remember" v-model:checked="therapyData.allowInPerson" />
                                <span class="ms-2 text-sm text-gray-600">Allow in person sessions.</span>
                            </label>

                            <div class="mt-2 text-xs text-gray-500">If you check this box, counsellor can schedule in-person sessions with you.</div>
                            <InputError class="mt-2" :message="therapyErrors.allowInPerson" />
                        </div>
                    </div>

                    <ProfileCaseSection
                        :addedby="{
                            type: 'User',
                            id: $page.props.auth.user?.id
                        }"
                        @on-data="(data) => {
                            therapyData.cases = [...data.map((c) => c.id)]
                        }"
                        class="rounded bg-gray-200 shadow-sm"
                    />
                    <hr class="my-4">

                    <div class="p-4 rounded bg-gray-200 shadow-sm">
                        <div class="text-sm text-gray-600 text-start mb-4 font-semibold">This section cannot be updated after the first session.</div>
                        <div class="mx-auto max-w-[400px]">
                            <label class="flex items-center" :disabled="therapyData.paymentType == 'FREE'">
                                <Checkbox :disabled="therapyData.paymentType == 'FREE'" name="remember" v-model:checked="therapyData.public" />
                                <span class="ms-2 text-sm text-gray-600">Share to public.</span>
                            </label>

                            <div class="mt-2 text-xs text-gray-500">Free therapies are public. Do not worry about your anonymity. If you check anonymous, you will still stay anonymous even when the therapy is shared to the public.</div>
                            <InputError class="mt-2" :message="therapyErrors.public" />
                        </div>

                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="sessionType" value="Session Type" />

                            <Select
                                id="sessionType"
                                class="mt-1 block w-full"
                                v-model="therapyData.sessionType"
                                autocomplete="sessionType"
                                :options="['Once', 'Periodic']"
                                :default-option="'select sessionType'"
                                required
                            />

                            <div class="mt-2 text-xs text-gray-500">For Once, there can be only one session and therapy ends. Otherwise, counsellor can create as many sessions as possible.</div>
                            <InputError class="mt-2" :message="therapyErrors.sessionType" />
                        </div>

                        <div class="mt-4 mx-auto max-w-[400px]" v-if="therapyData.sessionType == 'PERIODIC'">
                            <InputLabel for="maxSessions" value="Maximum Sessions" />

                            <TextInput
                                id="maxSessions"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="therapyData.maxSessions"
                            />
                            
                            <InputError class="mt-2" :message="therapyErrors.maxSessions" />
                        </div>

                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="paymentType" value="Payment Type" />

                            <Select
                                id="paymentType"
                                class="mt-1 block w-full"
                                v-model="therapyData.paymentType"
                                autocomplete="paymentType"
                                :options="['Free', 'Paid']"
                                :default-option="'select payment type'"
                                required
                            />

                            <div class="mt-2 text-xs text-gray-500">If free is selected, your therapy becomes automatically public. The publicity does not affect your anonymity.</div>
                            <InputError class="mt-2" :message="therapyErrors.sessionType" />
                        </div>

                        <div class="mt-4 mx-auto max-w-[400px]" v-if="therapyData.paymentType == 'PAID'">
                            <div>
                                <InputLabel for="per" value="Amount Per" />
                                <Select
                                    id="per"
                                    class="mt-1 block w-full"
                                    v-model="therapyData.per"
                                    autocomplete="per"
                                    :options="[{name: 'Therapy', value: 'PER_THERAPY'}, {name: 'Session', value: 'PER_SESSION'}]"
                                    :default-option="'payment per?'"
                                    :disabled="therapyData.paymentType == 'PAID' && therapyData.sessionType == 'ONCE'"
                                />

                                <InputLabel class="mt-4" for="amount" value="Amount" />
                                <div class="flex justify-start items-center">
                                    <TextInput
                                        id="name"
                                        type="text"
                                        class="mt-1 block w-[30%] max-w-[100px] text-end"
                                        v-model="therapyData.currency"
                                        required
                                    />
                                    <TextInput
                                        id="amount"
                                        type="number"
                                        class="mt-1 block w-full"
                                        v-model="therapyData.amount"
                                        required
                                    />
                                </div>
                            </div>

                            <div class="mt-2 text-xs text-gray-500">Payment will automatically be PER THERAPY when session type is ONCE.</div>
                            <InputError class="mt-2" :message="therapyErrors.amount" />
                        </div>
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