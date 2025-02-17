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
import useEnums from '@/Composables/useEnums';
import CounsellorComponent from './CounsellorComponent.vue';
import useLoader from '@/Composables/useLoader';

const { alertData, clearAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()
const { PaymentTypeEnum, SessionTypeEnum } = useEnums()
const { loader, showLoader, hideLoader } = useLoader()
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

const counsellors = ref({ data: [], page: 1, selected: [] });
const counsellorSearch = ref("");
const therapyForm = useForm({
    'name': '',
    'about': '',
    'anonymous': false,
    'allowInPerson': false,
    'public': false,
    'sessionType': '',
    'paymentType': '',
    'per': '',
    'amount': '',
    'maxSessions': '',
    'counsellorId': '',
    'currency': 'GHȻ',
    'cases': [],
    'counsellorIds': [],
    'maxUsers': '',
    'maxCounsellors': '',
    'allowAnyone': false,
    'shareEqually': false,
    'asCounsellor': false,
    'sharePercentage': '',
})

watchEffect(() => {
    if (therapyForm.sessionType == SessionTypeEnum.once && therapyForm.paymentType == PaymentTypeEnum.paid)
        therapyForm.per = 'PER_THERAPY'

    if (therapyForm.sessionType == SessionTypeEnum.once)
        therapyForm.maxSessions = '1'
})
watch(
    () => therapyForm.paymentType,
    () => {
        if (therapyForm.paymentType == PaymentTypeEnum.free) {
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
            therapyForm.about = props.therapy.about
            therapyForm.paymentType = props.therapy.paymentType
            therapyForm.sessionType = props.therapy.sessionType
            therapyForm.maxSessions = props.therapy.maxSessions
            therapyForm.maxUsers = props.therapy.maxUsers
            therapyForm.maxCounsellors = props.therapy.maxCounsellors
            therapyForm.allowAnyone = props.therapy.allowAnyone
            therapyForm.public = props.therapy.public
            therapyForm.allowInPerson = props.therapy.allowInPerson
            therapyForm.anonymous = props.therapy.anonymous
            if (props.therapy.paymentData) {
                therapyForm.inPersonAmount = props.therapy.paymentData['inPersonAmount']
                therapyForm.amount = props.therapy.paymentData['amount']
                therapyForm.currency = props.therapy.paymentData['currency']
                therapyForm.per = props.therapy.paymentData['per']
                therapyForm.shareEqually = props.therapy.paymentData['shareEqually']
                therapyForm.sharePercentage = props.therapy.paymentData['sharePercentage']
            }
            therapyForm.cases = [...props.therapy.cases.map((c) => c.id)]
        }
    }
)
watch(
  () => counsellorSearch.value?.length,
  () => {
    if (counsellorSearch.value?.length) debouncedGetCounsellors();
  }
);

const computedShowInPersonAmount = computed(() => {
    return therapyForm.allowInPerson && therapyForm.paymentType == PaymentTypeEnum.paid && therapyForm.per == 'PER_SESSION'
})
 
async function updateTherapy() {
    if (!therapyForm.name) {
        setFailedAlertData({
            message: "Name is required for a therapy.",
            time: 10000,
        });
        return
    }

    if (
        therapyForm.paymentType == PaymentTypeEnum.paid &&
        !(therapyForm.amount && therapyForm.currency && therapyForm.per)
    ) {
        setFailedAlertData({
            message: "Amount, currency and per what? All of these are required since you selected PAID payment type.",
            time: 10000,
        });
        return
    }
        
    if (
        therapyForm.paymentType == PaymentTypeEnum.free &&
        !therapyForm.public
    ) {
        setFailedAlertData({
            message: "FREE payment types requires that you set public to true.",
            time: 10000,
        });
        return
    }

    if (
        therapyForm.paymentType == PaymentTypeEnum.paid &&
        therapyForm.sessionType == SessionTypeEnum.once &&
        therapyForm.per !== 'PER_THERAPY'
    ) {
        setFailedAlertData({
            message: "Since ONCE and PAID have been selected for session and payment types respectively, you must select per THERAPY.",
            time: 10000,
        });
        return
    }

    if (
        therapyForm.sessionType == SessionTypeEnum.periodic &&
        (!therapyForm.maxSessions || therapyForm.maxSessions < 2)
    ) {
        setFailedAlertData({
            time: 10000,
            message: "Since PERIODIC has been selected for the session type, the maximum number of sessions must be at least 2."
        });
        return
    }

    if (counsellors.value.selected.length)
        therapyForm.counsellorIds = counsellors.value.selected.map((c) => c.id)

    therapyForm.patch(route(`group.therapies.update`, { groupTherapyId: props.therapy.id }), {
        onStart: () => {
            showLoader('updating')
        },
        onFinish: () => {
            hideLoader()
        },
        onError: (err) => {
            console.log(err)
            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                    time: 10000,
                })
                return
            }

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
                time: 10000,
            })
        },
        onSuccess: (res) => {
            console.log(res)
            
            setSuccessAlertData({
                message: 'Your therapy has been successfully updated.',
                time: 10000,
            })
            closeModal()
        }
    })
}

const debouncedGetCounsellors = _.debounce(() => {
  counsellors.value.page = 1;
  getCounsellors();
}, 500);

async function getCounsellors() {
  if (!counsellors.value.page) return;

  showLoader("counsellors");
  await axios
    .get(
      route("api.counsellors", {
        page: counsellors.value.page,
        name: counsellorSearch.value,
      })
    )
    .then((res) => {
      console.log(res);
      if (counsellors.value.page == 1) counsellors.value.data = [];

      counsellors.value.data = [...counsellors.value.data, ...res.data.data];

      updatePage(res, counsellors);
    })
    .catch((err) => {
      console.log(err);
    })
    .finally(() => {
      hideLoader();
    });
}

function selectCounsellor(counsellor) {
    let selected = counsellors.value.selected.find((c) => c.id == counsellor.id)

    if (selected) return

    counsellors.value.selected.push(counsellor)
}

function removeCounsellor(counsellor) {
    let selectedIdx = counsellors.value.selected.findIndex((c) => c.id == counsellor.id)

    if (selectedIdx <= -1) return

    counsellors.value.selected.splice(selectedIdx, 1)
}

function updatePage(res, data) {
  if (res.data.links.next) data.value.page = data.value.page + 1;
  else data.value.page = 0;
}

function clearData() {
    therapyForm.name = ''
    therapyForm.about = ''
    therapyForm.paymentType = ''
    therapyForm.sessionType = ''
    therapyForm.maxSessions = ''
    therapyForm.maxCounsellors = ''
    therapyForm.counsellorId = ''
    therapyForm.maxUsers = ''
    therapyForm.allowAnyone = false
    therapyForm.public = false
    therapyForm.allowInPerson = false
    therapyForm.anonymous = false
    therapyForm.asCounsellor = false
    therapyForm.shareEqually = false
    therapyForm.sharePercentage = ''
    therapyForm.amount = ''
    therapyForm.currency = 'GHȻ'
    therapyForm.per = ''
    therapyForm.cases = []
    therapyForm.counsellorIds = []
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
                >Update Group therapy</div>
                <hr>
            </div>

            <FormLoader class="mx-auto" :show="loader.show && loader.type == 'updating'" :text="`updating group therapy`"/>
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
                                <InputLabel for="about" value="About" />

                                <TextBox
                                    id="story"
                                    class="mt-1 block w-full"
                                    v-model="therapyForm.about"
                                    rows="5"
                                />

                                <div class="mt-2 text-xs text-gray-500">This gives the potential counsellor and user a background story to start with.</div>
                                <InputError class="mt-2" :message="therapyForm.errors.about" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]">
                                <label class="flex items-center">
                                    <Checkbox name="remember" v-model:checked="therapyForm.anonymous" />
                                    <span class="ms-2 text-sm text-gray-600">Stay anonymous.</span>
                                </label>

                                <div class="mt-2 text-xs text-gray-500">If you check this box, users will be anonymous by default, unless they choose not to be so when joining.</div>
                                <InputError class="mt-2" :message="therapyForm.errors.anonymous" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]">
                                <label class="flex items-center">
                                    <Checkbox name="remember" v-model:checked="therapyForm.allowInPerson" />
                                    <span class="ms-2 text-sm text-gray-600">Allow in person sessions.</span>
                                </label>

                                <div class="mt-2 text-xs text-gray-500">If you check this box, counsellors can schedule in-person sessions with you.</div>
                                <InputError class="mt-2" :message="therapyForm.errors.allowInPerson" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]">
                                <label class="flex items-center">
                                    <Checkbox name="remember" v-model:checked="therapyForm.allowAnyone" />
                                    <span class="ms-2 text-sm text-gray-600">Allow anyone to join.</span>
                                </label>

                                <div class="mt-2 text-xs text-gray-500">If you check this box, users can join without sending a requests.</div>
                                <InputError class="mt-2" :message="therapyForm.errors.allowAnyone" />
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
                        <hr class="mb-4 -mt-4">

                        <div
                            class="p-4 rounded bg-gray-200 my-4 shadow-sm min-h-[100px]"
                        >
                            <div
                                class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-600"
                            >
                                Counsellor Search
                            </div>
                            <div
                                v-if="loader.show && loader.type == 'counsellors'"
                                class="text-center text-sm w-full my-4 text-green-600 bg-green-200"
                            >
                                getting counsellors
                            </div>
                            <div class="text-left text-sm mb-2 text-gray-600">
                                Type name or username of counsellor in order to search. Then double click counsellor
                                to select counsellor. Selected counsellors will be sent a request to join therapy.
                            </div>
                            <div class="w-full flex justify-center items-center my-4">
                                <TextInput
                                v-model="counsellorSearch"
                                class="w-[90%]"
                                type="text"
                                placeholder="search for counsellor"
                                />
                            </div>
                            <div
                                class="p-2 flex justify-start gap-3 items-center overflow-hidden overflow-x-auto my-2"
                            >
                                <template v-if="counsellors.data?.length">
                                    <CounsellorComponent
                                        v-for="counsellor in counsellors.data"
                                        :counsellor="counsellor"
                                        :has-view="false"
                                        :key="counsellor.id"
                                        class="min-w-[30%] max-w-fit shrink-0 shadow shadow-slate-300 bg-slate-50"
                                        :use-minimal="true"
                                        @dblclick="() => selectCounsellor(counsellor)"
                                    >
                                    </CounsellorComponent>

                                    <div
                                        title="get more counsellors"
                                        @click="getCounsellors"
                                        v-if="counsellors.page"
                                        class="cursor-pointer p-2 text-gray-600 font-bold"
                                    >
                                        ...
                                    </div>
                                </template>
                            </div>
                            <div
                                class="p-2 flex justify-start gap-3 items-center overflow-hidden overflow-x-auto my-2"
                            >
                                <template v-if="counsellors.selected?.length">
                                    <CounsellorComponent
                                        v-for="counsellor in counsellors.selected"
                                        :counsellor="counsellor"
                                        :has-view="false"
                                        :white-text="true"
                                        :key="counsellor.id"
                                        class="min-w-[30%] max-w-fit shrink-0 shadow shadow-slate-300 bg-slate-600 text-slate-50"
                                        :use-minimal="true"
                                        @dblclick="() => removeCounsellor(counsellor)"
                                    >
                                    </CounsellorComponent>
                                </template>
                                <div v-else class="w-full text-center text-sm text-gray-600">
                                    no counsellors selected
                                </div>
                            </div>
                        </div>
                        <hr class="my-4">

                        <div class="p-4 rounded bg-gray-200 shadow-sm">
                            <div class="text-sm text-gray-600 text-start mb-4 font-semibold">You cannot update payment after the first session.</div>
                            <div class="mx-auto max-w-[400px]">
                                <label class="flex items-center" :disabled="therapyForm.paymentType == PaymentTypeEnum.free">
                                    <Checkbox :disabled="therapyForm.paymentType == PaymentTypeEnum.free" name="remember" v-model:checked="therapyForm.public" />
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
                                    :options="[SessionTypeEnum.once, SessionTypeEnum.periodic]"
                                    :default-option="'select sessionType'"
                                    required
                                />

                                <div class="mt-2 text-xs text-gray-500">For Once, there can be only one session and therapy ends. Otherwise, counsellor can create as many sessions as possible.</div>
                                <InputError class="mt-2" :message="therapyForm.errors.sessionType" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="maxUsers" value="Maximum Users" />

                                <TextInput
                                    id="maxUsers"
                                    type="number"
                                    class="mt-1 block w-full"
                                    v-model.number="therapyForm.maxUsers"
                                    steps="1"
                                />

                                <div class="mt-2 text-xs text-gray-500">This is the maximum number of users that can join the group therapy.</div>
                                <InputError class="mt-2" :message="therapyForm.errors.maxUsers" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]">
                                <InputLabel for="maxCounsellors" value="Maximum Counsellors" />

                                <TextInput
                                    id="maxCounsellors"
                                    type="number"
                                    class="mt-1 block w-full"
                                    v-model.number="therapyForm.maxCounsellors"
                                    steps="1"
                                />
                                
                                <div class="mt-2 text-xs text-gray-500">This is the maximum number of counsellors that can join the group therapy.</div>
                                <InputError class="mt-2" :message="therapyForm.errors.maxCounsellors" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]" v-if="therapyForm.sessionType == SessionTypeEnum.periodic && !therapy.sessionsHeld">
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
                                    :options="[PaymentTypeEnum.free, PaymentTypeEnum.paid]"
                                    :default-option="'select payment type'"
                                    required
                                />

                                <div class="mt-2 text-xs text-gray-500">If free is selected, your therapy becomes automatically public. The publicity does not affect your anonymity.</div>
                                <InputError class="mt-2" :message="therapyForm.errors.sessionType" />
                            </div>

                            <div class="mt-4 mx-auto max-w-[400px]" v-if="therapyForm.paymentType == PaymentTypeEnum.paid && !therapy.sessionsHeld">
                                <div>
                                    <InputLabel for="per" value="Amount Per" />
                                    <Select
                                        id="per"
                                        class="mt-1 block w-full"
                                        v-model="therapyForm.per"
                                        autocomplete="per"
                                        :options="[{name: 'Therapy', value: 'PER_THERAPY'}, {name: 'Session', value: 'PER_SESSION'}]"
                                        :default-option="'payment per?'"
                                        :disabled="therapyForm.paymentType == PaymentTypeEnum.paid && therapyForm.sessionType == SessionTypeEnum.once"
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

                            <template>
                                <div class="mt-4 mx-auto max-w-[400px]">
                                    <div class="text-gray-600">How do you want earnings shared?</div>
                                    <div class="mt-4">
                                        <label class="flex items-center">
                                            <Checkbox name="remember" v-model:checked="therapyForm.shareEqually" />
                                            <span class="ms-2 text-sm text-gray-600">Share the earnings equally.</span>
                                        </label>

                                        <div class="mt-2 text-xs text-gray-500">If you check this box, users can join without sending a requests.</div>
                                        <InputError class="mt-2" :message="therapyForm.errors.shareEqually" />
                                    </div>
                                </div>
                                <div v-if="!therapyForm.shareEqually" class="mt-4 mx-auto max-w-[400px]">
                                    <div class="text-gray-600">What percentage will you give to the participating counsellors?</div>
                                    <div >
                                        <InputLabel for="percentage" value="Percentage" />

                                        <TextInput
                                            id="percentage"
                                            type="number"
                                            class="mt-1 block w-full"
                                            v-model="therapyForm.sharePercentage"
                                            max="100"
                                            :min="therapyForm.asCounsellor ? 40 : 70"
                                        />
                                        
                                        <div 
                                            class="mt-2 text-xs text-gray-500" 
                                            v-if="therapy.addedby.isCounsellor && therapy.addedby.userId == $page.props.auth.user?.id"
                                        >Share percentage cannot be more than 100 and less than 40%.</div>
                                        <div 
                                            class="mt-2 text-xs text-gray-500" 
                                            v-else
                                        >The percentage to counsellors cannot be less than 70%.</div>
                                        <InputError class="mt-2" :message="therapyForm.errors.sharePercentage" />
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="flex items-center justify-end mt-4">

                        <PrimaryButton class="ms-4" :class="{ 'opacity-25': loader.show }" :disabled="loader.show">
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