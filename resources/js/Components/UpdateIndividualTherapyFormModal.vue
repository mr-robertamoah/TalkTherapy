<script setup>
import { computed, ref, watch, watchEffect } from 'vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextBox from '@/Components/TextBox.vue';
import FormLoader from '@/Components/FormLoader.vue';
import Modal from '@/Components/Modal.vue';
import useAlert from "@/Composables/useAlert";
import Alert from "./Alert.vue";
import PrimaryButton from "./PrimaryButton.vue";
import Checkbox from "./Checkbox.vue";
import Select from "./Select.vue";
import ProfileCaseSection from "@/Pages/Profile/Partials/ProfileCaseSection.vue";
import { useForm } from '@inertiajs/vue3';

const { alertData, clearAlertData, setAlertData } = useAlert()

const props = defineProps({
    show: {
        default: false,
        type: Boolean
    },
    therapy: {
        default: null,
    }
})

const emits = defineEmits(['successful', 'closeModal'])

const loading = ref(false)
const therapyForm = useForm({
    'name': '',
    'backgroundStory': '',
    'anonymous': false,
    'allowInPerson': false,
    'public': false,
    'sessionType': '',
    'paymentType': '',
    'per': '',
    'amount': 0,
    'inPersonAmount': 0,
    'maxSessions': 0,
    'currency': 'GHȻ',
    'cases': []
})

watchEffect(() => {
    if (therapyForm.sessionType == 'ONCE' && therapyForm.paymentType == 'PAID')
        therapyForm.per = 'PER_THERAPY'

    if (therapyForm.sessionType == 'ONCE')
        therapyForm.maxSessions = '1'
})
watch(
    () => therapyForm.paymentType,
    () => {
        if (therapyForm.paymentType == 'FREE') {
            therapyForm.public = true
            therapyForm.amount = ''
            therapyForm.currency = 'GHȻ'
            therapyForm.per = ''
        }
    }
)
watch(
    () => props.show,
    () => {
        if (props.show) {
            therapyForm.name = props.therapy.name
            therapyForm.backgroundStory = props.therapy.backgroundStory
            therapyForm.paymentType = props.therapy.paymentType
            therapyForm.sessionType = props.therapy.sessionType
            therapyForm.maxSessions = props.therapy.maxSessions
            therapyForm.public = props.therapy.public
            therapyForm.allowInPerson = props.therapy.allowInPerson
            therapyForm.anonymous = props.therapy.anonymous
            if (props.therapy.paymentData) {
                therapyForm.inPersonAmount = props.therapy.paymentData['inPersonAmount']
                therapyForm.amount = props.therapy.paymentData['amount']
                therapyForm.currency = props.therapy.paymentData['currency']
                therapyForm.per = props.therapy.paymentData['per']
            }
            therapyForm.cases = [...props.therapy.cases.map((c) => c.id)]
        }
    }
)

const computedShowInPersonAmount = computed(() => {
    return therapyForm.allowInPerson && therapyForm.paymentType == 'PAID' && therapyForm.per == 'PER_SESSION'
})
 
async function updateTherapy() {
    if (!therapyForm.name) {
        setAlertData({
            message: "Name is required for a therapy.",
            show: true,
            type: 'failed'
        });
        return
    }

    if (
        therapyForm.paymentType == 'PAID' &&
        !(therapyForm.amount && therapyForm.currency && therapyForm.per)
    ) {
        setAlertData({
            message: "Amount, currency and per what? All of these are required since you selected PAID payment type.",
            show: true,
            type: 'failed'
        });
        return
    }
        
    if (
        therapyForm.paymentType == 'FREE' &&
        !therapyForm.public
    ) {
        setAlertData({
            message: "FREE payment types requires that you set public to true.",
            show: true,
            type: 'failed'
        });
        return
    }

    if (
        therapyForm.paymentType == 'PAID' &&
        therapyForm.sessionType == 'ONCE' &&
        therapyForm.per !== 'PER_THERAPY'
    ) {
        setAlertData({
            message: "Since ONCE and PAID have been selected for session and payment types respectively, the per amount should be THERAPY.",
            show: true,
            type: 'failed'
        });
        return
    }

    if (
        therapyForm.sessionType == 'PERIODIC' &&
        (!therapyForm.maxSessions || therapyForm.maxSessions < 2)
    ) {
        setAlertData({
            show: true,
            type: 'failed',
            message: "Since PERIODIC has been selected for the session type, the maximum number of sessions must be at least 2."
        });
        return
    }

    therapyForm.patch(route(`therapies.update`, { therapyId: props.therapy.id }), {
        onStart: () => {
            loading.value = true
        },
        onFinish: () => {
            loading.value = false
        },
        onError: (err) => {
            console.log(err)
            if (err.response?.data?.message) {
                setAlertData({
                    message: err.response.data.message,
                    type: 'failed',
                    show: true,
                })
                return
            }

            setAlertData({
                message: 'Something unfortunate happened. Please try again later.',
                type: 'failed',
                show: true,
            })
        },
        onSuccess: (res) => {
            console.log(res)
            
            setAlertData({
                message: 'Your therapy has been successfully updated.',
                type: 'success',
                show: true,
            })
            closeModal()
        }
    })
}

function clearData() {
    therapyForm.name = ''
    therapyForm.backgroundStory = ''
    therapyForm.paymentType = ''
    therapyForm.sessionType = ''
    therapyForm.maxSessions = ''
    therapyForm.public = false
    therapyForm.allowInPerson = false
    therapyForm.anonymous = false
    therapyForm.amount = ''
    therapyForm.currency = 'GHȻ'
    therapyForm.per = ''
    therapyForm.cases = []
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
                >Update individual therapy</div>
                <hr>
            </div>

            <FormLoader class="mx-auto" :show="loading" :text="`updating therapy`"/>
            <div class="p-4 relative">
                <form 
                    @submit.prevent="updateTherapy"
                >

                    <div v-if="therapy.sessionsHeld"
                        class="my-4 text-sm text-gray-600 w-full text-justify tracking-wide"
                    ><strong>Note:</strong> You cannot make changes to payment type and amounts since at least a session has been held. The same applies to session types (ONCE, PERIODIC).</div>
                
                    <div class="overflow-hidden overflow-y-auto h-[70vh] px-4 pb-4">
                        <div class="p-4 rounded bg-gray-200 shadow-sm">
                            <div class="text-sm text-gray-600 text-start mb-4 font-semibold">This section can be updated any time.</div>
                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="name" value="Name" />

                                <TextInput
                                    id="name"
                                    type="text"
                                    class="mt-1 block w-full"
                                    v-model="therapyForm.name"
                                    required
                                    autofocus
                                />
                                
                                <InputError class="mt-2" :message="therapyForm.errors.name" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="backgroundStory" value="Background Story" />

                                <TextBox
                                    id="story"
                                    class="mt-1 block w-full"
                                    v-model="therapyForm.backgroundStory"
                                    rows="5"
                                />

                                <div class="mt-2 text-xs text-gray-500">This gives the potential counsellor a background story to start with.</div>
                                <InputError class="mt-2" :message="therapyForm.errors.backgroundStory" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]">
                                <label class="flex items-center">
                                    <Checkbox name="remember" v-model:checked="therapyForm.anonymous" />
                                    <span class="ms-2 text-sm text-gray-600">Stay anonymous.</span>
                                </label>

                                <div class="mt-2 text-xs text-gray-500">If you check this box, not even your counsellor will know who you are unless you reveal yourself to him/her.</div>
                                <InputError class="mt-2" :message="therapyForm.errors.anonymous" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]">
                                <label class="flex items-center">
                                    <Checkbox name="remember" v-model:checked="therapyForm.allowInPerson" />
                                    <span class="ms-2 text-sm text-gray-600">Allow in person sessions.</span>
                                </label>

                                <div class="mt-2 text-xs text-gray-500">If you check this box, counsellor can schedule in-person sessions with you.</div>
                                <InputError class="mt-2" :message="therapyForm.errors.allowInPerson" />
                            </div>
                        </div>

                        <ProfileCaseSection
                            :addedby="{
                                type: 'User',
                                id: $page.props.auth.user?.id
                            }"
                            :selectedCases="therapy.cases ?? []"
                            @on-data="(data) => {
                                therapyForm.cases = [...data.map((c) => c.id)]
                            }"
                            class="rounded bg-gray-200 shadow-sm"
                        />
                        <hr class="my-4">

                        <div class="p-4 rounded bg-gray-200 shadow-sm">
                            <div class="text-sm text-gray-600 text-start mb-4 font-semibold">This section cannot be updated after the first session.</div>
                            <div class="mx-auto max-w-[400px]">
                                <label class="flex items-center" :disabled="therapyForm.paymentType == 'FREE'">
                                    <Checkbox :disabled="therapyForm.paymentType == 'FREE'" name="remember" v-model:checked="therapyForm.public" />
                                    <span class="ms-2 text-sm text-gray-600">Share to public.</span>
                                </label>

                                <div class="mt-2 text-xs text-gray-500">Free therapies are public. Do not worry about your anonymity. If you check anonymous, you will still stay anonymous even when the therapy is shared to the public.</div>
                                <InputError class="mt-2" :message="therapyForm.errors.public" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]" v-if="!therapy.sessionsHeld">
                                <InputLabel for="sessionType" value="Session Type" />

                                <Select
                                    id="sessionType"
                                    class="mt-1 block w-full"
                                    v-model="therapyForm.sessionType"
                                    autocomplete="sessionType"
                                    :options="['Once', 'Periodic']"
                                    :default-option="'select sessionType'"
                                    required
                                />

                                <div class="mt-2 text-xs text-gray-500">For Once, there can be only one session and therapy ends. Otherwise, counsellor can create as many sessions as possible.</div>
                                <InputError class="mt-2" :message="therapyForm.errors.sessionType" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]" v-if="therapyForm.sessionType == 'PERIODIC' && !therapy.sessionsHeld">
                                <InputLabel for="maxSessions" value="Maximum Sessions" />

                                <TextInput
                                    id="maxSessions"
                                    type="number"
                                    class="mt-1 block w-full"
                                    v-model.number="therapyForm.maxSessions"
                                    steps="1"
                                />
                                
                                <InputError class="mt-2" :message="therapyForm.errors.maxSessions" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="paymentType" value="Payment Type" />

                                <Select
                                    id="paymentType"
                                    class="mt-1 block w-full"
                                    v-model="therapyForm.paymentType"
                                    autocomplete="paymentType"
                                    :options="['Free', 'Paid']"
                                    :default-option="'select payment type'"
                                    required
                                />

                                <div class="mt-2 text-xs text-gray-500">If free is selected, your therapy becomes automatically public. The publicity does not affect your anonymity.</div>
                                <InputError class="mt-2" :message="therapyForm.errors.sessionType" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]" v-if="therapyForm.paymentType == 'PAID' && !therapy.sessionsHeld">
                                <div>
                                    <InputLabel for="per" value="Amount Per" />
                                    <Select
                                        id="per"
                                        class="mt-1 block w-full"
                                        v-model="therapyForm.per"
                                        autocomplete="per"
                                        :options="[{name: 'Therapy', value: 'PER_THERAPY'}, {name: 'Session', value: 'PER_SESSION'}]"
                                        :default-option="'payment per?'"
                                        :disabled="therapyForm.paymentType == 'PAID' && therapyForm.sessionType == 'ONCE'"
                                    />

                                    <InputLabel class="mt-4" for="amount" value="Amount" />
                                    <div class="flex justify-start items-center">
                                        <TextInput
                                            id="currency"
                                            type="text"
                                            class="mt-1 block w-[30%] max-w-[100px] text-end"
                                            v-model="therapyForm.currency"
                                            required
                                        />
                                        <TextInput
                                            id="amount"
                                            type="number"
                                            class="mt-1 block w-full"
                                            v-model="therapyForm.amount"
                                            required
                                        />
                                    </div>
                                    
                                    <template v-if="computedShowInPersonAmount">
                                        
                                        <InputLabel class="mt-4" for="inPersonAmount" value="In-person Amount" />
                                        <TextInput
                                            id="inPersonAmount"
                                            type="number"
                                            class="mt-1 block w-full"
                                            v-model="therapyForm.inPersonAmount"
                                        />
                                    </template>
                                </div>

                                <div class="mt-2 text-xs text-gray-500">Payment will automatically be PER THERAPY when session type is ONCE.</div>
                                <div class="mt-2 text-xs text-gray-500" v-if="computedShowInPersonAmount">We recommend that in-person therapies amount should be at least twice that of your online session amount.</div>
                                <InputError class="mt-2" :message="therapyForm.errors.amount" />
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