<script setup>
import { inject, ref, watch, watchEffect } from 'vue';
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
import useLoader from '@/Composables/useLoader';
import CounsellorComponent from './CounsellorComponent.vue';
import { usePage } from '@inertiajs/vue3';
import useEnums from '@/Composables/useEnums';

// allowAnyone check
// create as a counsellor
// if creating as a user, counsellors share equally
const { setErrorData } = useErrorHandler()
const { alertData, clearAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()
const { PaymentTypeEnum, SessionTypeEnum } = useEnums()
const counsellors = ref({ data: [], page: 1, selected: [] });
const counsellorSearch = ref("");
const loading = ref(false)
const { loader, hideLoader, showLoader } = useLoader()
const { updateNewGroupTherapy } = inject('onCreatedNewGroupTherapy', { updateNewGroupTherapy: null })

defineProps({
    show: {
        default: false,
        type: Boolean
    }
})

const emits = defineEmits(['successful', 'closeModal'])

const therapyData = ref({
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
const therapyErrors = ref({
    'name': '',
    'about': '',
    'anonymous': '',
    'allowInPerson': '',
    'public': '',
    'sessionType': '',
    'paymentType': '',
    'per': '',
    'amount': '',
    'currency': '',
    'maxSessions': '',
    'maxUsers': '',
    'counsellorIds': '',
    'maxCounsellors': '',
    'cases': '',
    'allowAnyone': '',
    'shareEqually': '',
    'sharePercentage': '',
})

watchEffect(() => {
    if (therapyData.value.sessionType == SessionTypeEnum.once && therapyData.value.paymentType == PaymentTypeEnum.paid)
        therapyData.value.per = 'PER_THERAPY'

    if (therapyData.value.sessionType == SessionTypeEnum.once)
        therapyData.value.maxSessions = '1'
})
watch(
    () => therapyData.value.paymentType,
    () => {
        if (therapyData.value.paymentType == PaymentTypeEnum.free) {
            therapyData.value.public = true
            therapyData.value.amount = ''
            therapyData.value.currency = 'GHȻ'
            therapyData.value.per = ''
        }
    }
)
watch(
  () => counsellorSearch.value?.length,
  () => {
    if (counsellorSearch.value?.length) debouncedGetCounsellors();
  }
);

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
 
async function createTherapy() {
    if (!therapyData.value.name) {
        setFailedAlertData({
            message: "Name is required for a therapy.",
            time: 10000
        });
        return
    }

    if (
        therapyData.value.paymentType == PaymentTypeEnum.paid &&
        !(therapyData.value.amount && therapyData.value.currency && therapyData.value.per)
    ) {
        setFailedAlertData({
            message: "Amount, currency and per what? All of these are required since you selected PAID payment type.",
            time: 10000
        });
        return
    }
        
    if (
        therapyData.value.paymentType == PaymentTypeEnum.free &&
        !therapyData.value.public
    ) {
        setFailedAlertData({
            message: "FREE payment types requires that you set public to true.",
            time: 10000
        });
        return
    }

    if (
        therapyData.value.paymentType == PaymentTypeEnum.paid &&
        therapyData.value.sessionType == SessionTypeEnum.once &&
        therapyData.value.per !== 'PER_THERAPY'
    ) {
        setFailedAlertData({
            message: "Since ONCE and PAID have been selected for session and payment types respectively, you must select per THERAPY.",
            time: 10000
        });
        return
    }

    if (
        therapyData.value.sessionType == SessionTypeEnum.periodic &&
        (!therapyData.value.maxSessions || therapyData.value.maxSessions < 2)
    ) {
        setFailedAlertData({
            time: 10000,
            message: "Since PERIODIC has been selected for the session type, the maximum number of sessions must be at least 2."
        });
        return
    }

    if (counsellors.value.selected.length)
        therapyData.value.counsellorIds = counsellors.value.selected.map((c) => c.id)

    if (therapyData.value.asCounsellor)
        therapyData.value.counsellorId = usePage().props.auth.user?.counsellor?.id

    loading.value = true

    const data = {...therapyData.value}
    delete data.asCounsellor

    await axios
    .post(route(`group.therapies.create`), {
        ...data,
        public: therapyData.value.public ? 1 : 0,
        allowInPerson: therapyData.value.allowInPerson ? 1 : 0,
        anonymous: therapyData.value.anonymous ? 1 : 0,
        allowAnyone: therapyData.value.allowAnyone ? 1 : 0,
    })
    .then((res) => {
        console.log(res)
        
        setSuccessAlertData({
            message: 'Your therapy has been created successfully. Visit Therapies page if you are already not there.',
            time: 10000
        })
        emits('successful', res.data.groupTherapy)
        
        if (updateNewGroupTherapy)
            updateNewGroupTherapy(res.data.groupTherapy)

        closeModal()
    })
    .catch((err) => {
        console.log(err)
        if (err.response?.data?.message) {
            setFailedAlertData({
                message: err.response.data.message,
                time: 10000
            })
            return
        }

        if (err.response?.status == 422 && err.response?.data?.errors) {
            setErrorData(therapyErrors, err.response.data.errors, [
                'name', 'about', 'anonymous', 'allowInPerson', 'public', 'sessionType',
                'paymentType', 'per', 'amount', 'currency', 'maxSessions', 'maxUsers', 'maxCounsellors',
                'allowAnyone'
            ])
            return
        }

        setFailedAlertData({
            message: 'Something unfortunate happened. Please try again later.',
            time: 10000
        })
    })
    .finally(() => {
        loading.value = false
    })
}

function clearData() {
    therapyData.value.name = ''
    therapyData.value.about = ''
    therapyData.value.paymentType = ''
    therapyData.value.sessionType = ''
    therapyData.value.maxSessions = ''
    therapyData.value.maxCounsellors = ''
    therapyData.value.counsellorId = ''
    therapyData.value.maxUsers = ''
    therapyData.value.allowAnyone = false
    therapyData.value.public = false
    therapyData.value.allowInPerson = false
    therapyData.value.anonymous = false
    therapyData.value.asCounsellor = false
    therapyData.value.shareEqually = false
    therapyData.value.sharePercentage = ''
    therapyData.value.amount = ''
    therapyData.value.currency = 'GHȻ'
    therapyData.value.per = ''
    therapyData.value.cases = []
    therapyData.value.counsellorIds = []
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
                >Create Group therapy</div>
                <hr>
            </div>

            <FormLoader class="mx-auto" :show="loading" :text="`creating individual therapy`"/>
            <div class="p-4 relative overflow-hidden overflow-y-auto h-[80vh] px-4 pb-4">
                <form 
                    @submit.prevent="createTherapy"
                >
                    <div class="p-4 rounded bg-gray-200 shadow-sm">
                        <div class="mt-4 mx-auto max-w-[400px]" v-if="$page.props.auth.user?.counsellor">
                            <label class="flex items-center">
                                <Checkbox name="remember" v-model:checked="therapyData.asCounsellor" />
                                <span class="ms-2 text-sm text-gray-600">Create as Counsellor.</span>
                            </label>

                            <div class="mt-2 text-xs text-gray-500">If you check this box, you will be creating the therapy as a counsellor and not a user.</div>
                        </div>
                        
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
                            <InputLabel for="about" value="About" />

                            <TextBox
                                id="story"
                                class="mt-1 block w-full"
                                v-model="therapyData.about"
                                rows="5"
                            />

                            <div class="mt-2 text-xs text-gray-500">This gives the potential counsellor and user a background story to start with.</div>
                            <InputError class="mt-2" :message="therapyErrors.about" />
                        </div>

                        <div class="mt-4 mx-auto max-w-[400px]">
                            <label class="flex items-center">
                                <Checkbox name="remember" v-model:checked="therapyData.anonymous" />
                                <span class="ms-2 text-sm text-gray-600">Stay anonymous.</span>
                            </label>

                            <div class="mt-2 text-xs text-gray-500">If you check this box, users will be anonymous by default, unless they choose not to be so when joining.</div>
                            <InputError class="mt-2" :message="therapyErrors.anonymous" />
                        </div>

                        <div class="mt-4 mx-auto max-w-[400px]">
                            <label class="flex items-center">
                                <Checkbox name="remember" v-model:checked="therapyData.allowInPerson" />
                                <span class="ms-2 text-sm text-gray-600">Allow in person sessions.</span>
                            </label>

                            <div class="mt-2 text-xs text-gray-500">If you check this box, counsellors can schedule in-person sessions with you.</div>
                            <InputError class="mt-2" :message="therapyErrors.allowInPerson" />
                        </div>

                        <div class="mt-4 mx-auto max-w-[400px]">
                            <label class="flex items-center">
                                <Checkbox name="remember" v-model:checked="therapyData.allowAnyone" />
                                <span class="ms-2 text-sm text-gray-600">Allow anyone to join.</span>
                            </label>

                            <div class="mt-2 text-xs text-gray-500">If you check this box, users can join without sending a requests.</div>
                            <InputError class="mt-2" :message="therapyErrors.allowAnyone" />
                        </div>
                    </div>
                    <hr class="mt-4 -mb-4">

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
                            <label class="flex items-center" :disabled="therapyData.paymentType == PaymentTypeEnum.free">
                                <Checkbox :disabled="therapyData.paymentType == PaymentTypeEnum.free" name="remember" v-model:checked="therapyData.public" />
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
                                :options="[SessionTypeEnum.once, SessionTypeEnum.periodic]"
                                :default-option="'select sessionType'"
                                required
                            />

                            <div class="mt-2 text-xs text-gray-500">For Once, there can be only one session and therapy ends. Otherwise, counsellor can create as many sessions as possible.</div>
                            <InputError class="mt-2" :message="therapyErrors.sessionType" />
                        </div>

                        <div class="mt-4 mx-auto max-w-[400px]" v-if="therapyData.sessionType == SessionTypeEnum.periodic">
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
                            <InputLabel for="maxUsers" value="Maximum Users" />

                            <TextInput
                                id="maxUsers"
                                type="number"
                                class="mt-1 block w-full"
                                default="50"
                                v-model="therapyData.maxUsers"
                            />
                            
                            <div class="mt-2 text-xs text-gray-500">This is the maximum number of users that can join the group therapy.</div>
                            <InputError class="mt-2" :message="therapyErrors.maxUsers" />
                        </div>

                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="maxCounsellors" value="Maximum Counsellors" />

                            <TextInput
                                id="maxCounsellors"
                                type="number"
                                class="mt-1 block w-full"
                                default="10"
                                v-model="therapyData.maxCounsellors"
                            />
                            
                            <div class="mt-2 text-xs text-gray-500">This is the maximum number of counsellors that can join the group therapy.</div>
                            <InputError class="mt-2" :message="therapyErrors.maxCounsellors" />
                        </div>

                        <div class="mt-4 mx-auto max-w-[400px]">
                            <InputLabel for="paymentType" value="Payment Type" />

                            <Select
                                id="paymentType"
                                class="mt-1 block w-full"
                                v-model="therapyData.paymentType"
                                autocomplete="paymentType"
                                :options="[PaymentTypeEnum.free, PaymentTypeEnum.paid]"
                                :default-option="'select payment type'"
                                required
                            />

                            <div class="mt-2 text-xs text-gray-500">If free is selected, your therapy becomes automatically public. The publicity does not affect your anonymity.</div>
                            <InputError class="mt-2" :message="therapyErrors.sessionType" />
                        </div>

                        <template v-if="therapyData.paymentType == PaymentTypeEnum.paid">
                            <div class="mt-4 mx-auto max-w-[400px]">
                                <div>
                                    <InputLabel for="per" value="Amount Per" />
                                    <Select
                                        id="per"
                                        class="mt-1 block w-full"
                                        v-model="therapyData.per"
                                        autocomplete="per"
                                        :options="[{name: 'Therapy', value: 'PER_THERAPY'}, {name: 'Session', value: 'PER_SESSION'}]"
                                        :default-option="'payment per?'"
                                        :disabled="therapyData.paymentType == PaymentTypeEnum.paid && therapyData.sessionType == SessionTypeEnum.once"
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
                            <div v-if="therapyData.asCounsellor" class="mt-4 mx-auto max-w-[400px]">
                                <div class="text-gray-600">How do you want earnings shared?</div>
                                <div class="mt-4">
                                    <label class="flex items-center">
                                        <Checkbox name="remember" v-model:checked="therapyData.shareEqually" />
                                        <span class="ms-2 text-sm text-gray-600">Share the earnings equally.</span>
                                    </label>

                                    <div class="mt-2 text-xs text-gray-500">If you check this box, users can join without sending a requests.</div>
                                    <InputError class="mt-2" :message="therapyErrors.shareEqually" />
                                </div>
                            </div>
                            <div v-if="!therapyData.shareEqually" class="mt-4 mx-auto max-w-[400px]">
                                <div class="text-gray-600">What percentage will you give to the participating counsellors?</div>
                                <div >
                                    <InputLabel for="percentage" value="Percentage" />

                                    <TextInput
                                        id="percentage"
                                        type="number"
                                        class="mt-1 block w-full"
                                        v-model="therapyData.sharePercentage"
                                        max="100"
                                        :min="therapyData.asCounsellor ? 40 : 70"
                                    />
                                    
                                    <div 
                                        class="mt-2 text-xs text-gray-500" 
                                        v-if="therapyData.asCounsellor"
                                    >Share percentage cannot be more than 100 and less than 40%.</div>
                                    <div 
                                        class="mt-2 text-xs text-gray-500" 
                                        v-else
                                    >The percentage to counsellors cannot be less than 70%.</div>
                                    <InputError class="mt-2" :message="therapyErrors.sharePercentage" />
                                </div>
                            </div>
                        </template>
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