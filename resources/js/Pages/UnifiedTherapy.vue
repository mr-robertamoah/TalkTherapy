<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, ref, watch, watchEffect } from 'vue'
import { default as _ } from 'lodash'

import BaseTherapyLayout from '@/Components/BaseTherapyLayout.vue'
import MiniModal from '@/Components/MiniModal.vue'
import Modal from '@/Components/Modal.vue'
import Alert from '@/Components/Alert.vue'
import FormLoader from '@/Components/FormLoader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import TextInput from '@/Components/TextInput.vue'
import CounsellorComponent from '@/Components/CounsellorComponent.vue'
import UserComponent from '@/Components/UserComponent.vue'
import TherapyComponent from '@/Components/TherapyComponent.vue'
import CreateReportModal from '@/Components/CreateReportModal.vue'
import UpdateIndividualTherapyFormModal from '@/Components/UpdateIndividualTherapyFormModal.vue'
import UpdateGroupTherapyFormModal from '@/Components/UpdateGroupTherapyFormModal.vue'
import CreateSessionFormModal from '@/Components/CreateSessionFormModal.vue'
import CreateDiscussionFormModal from '@/Components/CreateDiscussionFormModal.vue'
import DiscussionModal from '@/Components/DiscussionModal.vue'

import useTherapyState from '@/Composables/useTherapyState'
import useModal from '@/Composables/useModal'
import useAlert from '@/Composables/useAlert'
import useAuth from '@/Composables/useAuth'
import useAppLink from '@/Composables/useAppLink'
import useUtilities from '@/Composables/useUtilities'
import useEnums from '@/Composables/useEnums'

const { modalData, showModal, closeModal } = useModal()
const { RequestTypeEnum, SessionStatusEnum } = useEnums()
const { goToLogin } = useAuth()
const { createLink, getlinks } = useAppLink()
const { getReadableStatus } = useUtilities()
const {
  alertData,
  clearAlertData,
  setAlertData,
  setSuccessAlertData,
  setFailedAlertData,
} = useAlert()

const props = defineProps({
  therapy: { default: null },
  therapyType: { type: String, default: 'individual' },
  recentSessions: { default: null },
  recentTopics: { default: null },
  pendingRequest: { default: null },
})

// Initialize therapy state
const therapyRef = ref(props.therapy)
const {
  activeSession,
  activeDiscussion,
  recentSessions,
  recentTopics,
  discussions,
  onlineParticipants,
  listening,
  timer,
  computedTherapy,
  computedIsUser,
  computedIsCounsellor,
  computedIsParticipant,
  computedIsInSession,
  userId,
  startTimer,
  listenToTherapy,
  updateSessionOrTopic,
  deleteSessionOrTopic,
  addSessionOrTopic,
} = useTherapyState(therapyRef, props.therapyType)

// Tab items configuration (kept for compatibility)
const scrollItems = computed(() => {
  const baseItems = [
    { id: "therapy_background_story", name: props.therapyType === 'group' ? "about" : "background story" },
    { id: "therapy_participants", name: "participants" },
    { id: "therapy_details", name: "details" },
    { id: "therapy_payment_details", name: "payment details" },
    { id: "therapy_other_details", name: "other details" },
    { id: "therapy_stats", name: "stats" },
  ]
  return baseItems
})

// UI state
const loader = ref({ show: false, type: "" })
const sessionActionRunning = ref("")
const request = ref({ responding: false, status: null })
const counsellorSearch = ref("")
const newSession = ref(null)
const counsellor = ref(null)
const counsellors = ref([])
const counsellorLinks = ref({ page: 1, data: [] })
const currentUpdatedSessionOrTopic = ref(null)
const getting = ref({ show: false, type: "" })
const currentDeletedSessionOrTopic = ref(null)
const activeItemId = ref('')
const searchedCounsellors = ref([])
const selectedCounsellors = ref([])
const page = ref(1)
const therapyForm = useForm({})

// Computed properties
const computedCanParticipate = computed(() => {
  const user = usePage().props.auth.user
  if (user?.id == computedTherapy.value.user?.id || user?.counsellor) return true
  return false
})

// Watchers
watch(() => counsellorSearch.value, () => {
  if (counsellorSearch.value) debouncedGetCounsellors()
})

watchEffect(() => {
  if (props.therapy?.id || props.therapy?.data?.id) {
    waitForAlert()
    
    if (props.therapyType === 'individual') {
      counsellor.value = props.therapy.data
        ? props.therapy.data.counsellor
        : props.therapy.counsellor
    } else {
      counsellors.value = props.therapy.data
        ? props.therapy.data.counsellors
        : props.therapy.counsellors
    }
  }
})

watchEffect(() => {
  if (props.therapy?.activeSession?.id) {
    activeSession.value = props.therapy.activeSession
    startTimer()
  }
})

watchEffect(() => {
  if (props.therapy?.activeDiscussion?.id) {
    activeDiscussion.value = props.therapy.activeDiscussion
    startTimer()
  }
})

watchEffect(() => {
  if (props.recentSessions?.data?.length)
    recentSessions.value = [...props.recentSessions.data]
  if (props.recentSessions?.length) 
    recentSessions.value = [...props.recentSessions]
})

watchEffect(() => {
  if (props.recentTopics?.data?.length) 
    recentTopics.value = [...props.recentTopics.data]
  if (props.recentTopics?.length) 
    recentTopics.value = [...props.recentTopics]
})

watchEffect(() => {
  let currentTherapy = props.therapy?.data ? props.therapy?.data : props.therapy
  let user = usePage().props.auth.user

  if (!user?.id || !currentTherapy.id) return

  // Get counsellor links for users without counsellors
  if (shouldGetCounsellorLinks()) getCounsellorlinks()

  // Get discussions for counsellors
  if (user?.counsellor) getDiscussions(currentTherapy)

  if (listening.value) return
  listening.value = true
  listenToTherapy(currentTherapy)
})

// Helper functions
function shouldGetCounsellorLinks() {
  const user = usePage().props.auth.user
  const currentTherapy = computedTherapy.value
  
  if (props.therapyType === 'individual') {
    return !currentTherapy.counsellor && user?.id == currentTherapy.user.id
  } else {
    return (
      (user?.id == currentTherapy.addedby.id && currentTherapy.addedby.isUser) ||
      (user?.id == currentTherapy.addedby.userId && currentTherapy.addedby.isCounsellor)
    )
  }
}

// Event handlers
function clickedShowAll() {
  // Implementation for showing all session/discussion info
}

function clickedActiveSession() {
  if (activeSession.value) {
    showModal("have session")
    return
  }
  showModal("have discussion")
}

function handleSessionTopicUpdate(item) {
  updateSessionOrTopic(item)
  currentUpdatedSessionOrTopic.value = item
}

function handleSessionTopicDelete(item) {
  deleteSessionOrTopic(item)
  currentDeletedSessionOrTopic.value = item
}



// API calls
async function waitForAlert() {
  if (isNotParticipant()) return

  await axios
    .post(route(`alert.wait`), {
      alertableType: props.therapyType === 'individual' ? "Therapy" : "GroupTherapy",
      alertableId: props.therapy?.id,
    })
    .then((res) => {
      console.log(res)
    })
    .catch((err) => {
      console.log(err)
    })
}

function isNotParticipant() {
  if (props.therapyType === 'individual') {
    const counsellor = props.therapy?.data
      ? props.therapy?.data?.counsellor
      : props.therapy?.counsellor
    const user = props.therapy?.data ? props.therapy?.data?.user : props.therapy?.user

    if (!counsellor || !user) return false
    return userId.value !== counsellor?.userId && userId.value !== user?.id
  } else {
    const user = props.therapy?.data ? props.therapy?.data?.user : props.therapy?.user
    if (!user) return false

    const counsellor = (
      props.therapy?.data
        ? props.therapy?.data?.counsellors
        : props.therapy?.counsellors
    ).find((c) => c.userId == userId.value)

    return !counsellor && userId.value !== user.id
  }
}

async function getDiscussions(therapy) {
  if (!discussions.value.page) return

  setLoader("discussions")
  await axios
    .get(
      route("api.discussions", {
        page: discussions.value.page,
        counsellorId: usePage().props.auth.user?.counsellor?.id,
        forId: therapy.id,
        forType: props.therapyType === 'individual' ? "Therapy" : "GroupTherapy",
      })
    )
    .then((res) => {
      console.log(res)

      if (discussions.value.page == 1) discussions.value.data = []

      discussions.value.data = [...discussions.value.data, ...res.data.data]

      updateRefPage(res, discussions)
    })
    .catch((err) => {
      console.log(err)
      goToLogin(err)

      if (err.response?.data?.message) {
        setFailedAlertData({
          message: err.response.data.message,
          time: 10000,
        })
        return
      }

      setFailedAlertData({
        message: "Something unfortunate happened. Please try again later.",
        time: 5000,
      })
    })

  endLoader()
}

const debouncedGetCounsellors = _.debounce(() => {
  getCounsellors()
}, 500)

async function getCounsellors() {
  setLoader("counsellors")
  await axios
    .get(
      route("counsellors.request.get", { name: counsellorSearch.value, page: page.value })
    )
    .then((res) => {
      console.log(res)
      if (page.value > 1) {
        searchedCounsellors.value = [...searchedCounsellors.value, ...res.data.data]
        updatePage(res)
        return
      }

      searchedCounsellors.value = [...res.data.data]
      updatePage(res)
    })
    .catch((err) => {
      console.log(err)
      goToLogin(err)
    })

  endLoader()
}

async function createCounsellorLink() {
  setGetting("create link")

  const link = await createLink({
    type: props.therapyType === 'individual' ? "THERAPY_COUNSELLOR" : "GROUP_THERAPY_COUNSELLOR",
    addedbyId: usePage().props.auth.user?.id,
    addedbyType: "User",
    forId: computedTherapy.value?.id,
    forType: props.therapyType === 'individual' ? "Therapy" : "GroupTherapy",
  })

  clearGetting()
  if (!link) return

  counsellorLinks.value.data = [link, ...counsellorLinks.value.data]
}

async function getCounsellorlinks() {
  if (!counsellorLinks.value.page) return
  setGetting("links")

  const therapy = props.therapy?.data ? props.therapy?.data : props.therapy

  const res = await getlinks({
    page: counsellorLinks.value.page,
    type: props.therapyType === 'individual' ? "THERAPY_COUNSELLOR" : "GROUP_THERAPY_COUNSELLOR",
    forId: therapy.id,
    forType: props.therapyType === 'individual' ? "Therapy" : "GroupTherapy",
  })

  clearGetting()
  if (!res) return

  if (counsellorLinks.value.page == 1) counsellorLinks.value.data = []

  counsellorLinks.value.data = [...counsellorLinks.value.data, ...res.data.data]

  updateRefPage(res, counsellorLinks)
}

// Action handlers
function clickedReport() {
  showModal("report")
}

function clickedCreateSession() {
  showModal("create session")
}

function clickedCreateDiscussion() {
  showModal("create discussion")
}

function clickedEndTherapy() {
  showModal("end")
}

function clickedUpdate() {
  showModal("update")
}

function clickedDelete() {
  showModal("delete")
}

async function clickedResponse(response) {
  request.value.responding = true
  await axios
    .post(route("requests.respond", { requestId: props.pendingRequest.id }), { response })
    .then((res) => {
      console.log(res)

      request.value.status = res.data.request.status
      if (res.data.request?.status !== response.toUpperCase() && response == "accepted") {
        setSuccessAlertData({
          time: 5000,
          message:
            res.data.request.type == RequestTypeEnum.therapy
              ? "Your response was successful, but another counsellor may have already accepted to assist."
              : "",
        })
        return
      }

      if (res.data.request?.status == "ACCEPTED") {
        if (props.therapyType === 'individual') {
          counsellor.value = res.data.request.to
        } else {
          counsellors.value.push(res.data.request.to)
        }
      }

      setSuccessAlertData({
        time: 5000,
        message: "You have successful responded to the request.",
      })
    })
    .catch((err) => {
      console.log(err)

      setFailedAlertData({
        time: 5000,
        message: "Something unfortunate happened. Please try again shortly.",
      })
    })
  request.value.responding = false
}

// Utility functions
function setLoader(type) {
  loader.value.type = type
  loader.value.show = true
}

function endLoader() {
  loader.value.type = ""
  loader.value.show = false
}

function setGetting(type) {
  getting.value.type = type
  getting.value.show = true
}

function clearGetting() {
  getting.value.type = ""
  getting.value.show = false
}

function updatePage(res) {
  if (res.data.links.next) page.value += 1
  else page.value = 0
}

function updateRefPage(res, data) {
  if (res.data.links.next) data.value.page += 1
  else data.value.page = 0
}

function addToSelected(counsellor) {
  selectedCounsellors.value = [
    ...selectedCounsellors.value.filter((c) => c.id !== counsellor.id),
    counsellor,
  ]
}

function removeFromSelected(counsellor) {
  selectedCounsellors.value = [
    ...selectedCounsellors.value.filter((c) => c.id !== counsellor.id),
  ]
}

async function sendAssistanceRequest() {
  setLoader("assistance")
  const routeName = props.therapyType === 'individual' ? 'therapies.assist' : 'group.therapies.assist'
  const paramName = props.therapyType === 'individual' ? 'therapyId' : 'groupTherapyId'
  
  await axios
    .post(route(routeName, { [paramName]: computedTherapy.value?.id }), {
      counsellorIds: selectedCounsellors.value.map((c) => c.id),
    })
    .then((res) => {
      console.log(res)
      setSuccessAlertData({
        message: computedIsUser.value
          ? "You have successfully requested for assistance for this therapy."
          : "You have successfully sent an assistance request for this therapy.",
        time: 4000,
      })

      closeModal()
    })
    .catch((err) => {
      console.log(err)
      goToLogin(err)
      setFailedAlertData({
        message: computedIsUser.value
          ? "Your request for assistance failed, please try again in a short while."
          : "Your request to assist has failed, please try again in a short while.",
        time: 4000,
      })
    })

  endLoader()
}

async function deleteTherapy() {
  const routeName = props.therapyType === 'individual' ? 'therapies.delete' : 'group.therapies.delete'
  const paramName = props.therapyType === 'individual' ? 'therapyId' : 'groupTherapyId'
  
  therapyForm.delete(route(routeName, { [paramName]: computedTherapy.value.id }), {
    onStart: () => setLoader("delete"),
    onFinish: () => endLoader(),
    onError: (err) => {
      console.log(err)
      if (err.response?.data?.message) {
        setAlertData({
          message: err.response.data.message,
          type: "failed",
          show: true,
        })
        return
      }

      setAlertData({
        message: "Something unfortunate happened. Please try again later.",
        type: "failed",
        show: true,
      })
    },
    onSuccess: (res) => {
      console.log(res)

      setAlertData({
        message: "Your therapy has been successfully deleted.",
        type: "success",
        show: true,
        time: 4000,
      })
      closeModal()
    },
  })
}

async function endTherapy() {
  const routeName = props.therapyType === 'individual' ? 'therapies.end' : 'group.therapies.end'
  const paramName = props.therapyType === 'individual' ? 'therapyId' : 'groupTherapyId'
  
  therapyForm.post(route(routeName, { [paramName]: computedTherapy.value.id }), {
    onStart: () => setLoader("delete"),
    onFinish: () => endLoader(),
    onError: (err) => {
      console.log(err)
      if (err.response?.data?.message) {
        setAlertData({
          message: err.response.data.message,
          type: "failed",
          show: true,
        })
        return
      }

      setAlertData({
        message: "Something unfortunate happened. Please try again later.",
        type: "failed",
        show: true,
      })
    },
    onSuccess: (res) => {
      console.log(res)

      setAlertData({
        message: "Your therapy has been successfully ended.",
        type: "success",
        show: true,
        time: 4000,
      })
      closeModal()
    },
  })
}

// Session actions
async function clickedAbandonSession() {
  if (!activeSession.value?.id) return

  sessionActionRunning.value = 'abandoning session'
  await axios.post(route('api.sessions.abandon', activeSession.value.id))
    .then((res) => {
      updateSessionOrTopic(res.data.session)
    })
    .catch((err) => {
      console.log(err)
      goToLogin(err)
      setFailedAlertData({
        message: `Something unfortunate happened while ${sessionActionRunning.value}. Try again shortly.`,
      })
    })
    .finally(() => {
      sessionActionRunning.value = ''
    })
}

async function clickedStartSession() {
  if (!activeSession.value?.id) return

  sessionActionRunning.value = 'starting session'
  await axios.post(route('api.sessions.in_session', activeSession.value.id))
    .then((res) => {
      updateSessionOrTopic(res.data.session)
      showModal('therapy')
    })
    .catch((err) => {
      console.log(err)
      goToLogin(err)
      setFailedAlertData({
        message: `Something unfortunate happened while ${sessionActionRunning.value}. Try again shortly.`,
        timer: 4000,
      })
    })
    .finally(() => {
      sessionActionRunning.value = ''
    })
}

async function clickedEndSession() {
  if (!activeSession.value?.id) return

  sessionActionRunning.value = 'ending session'
  await axios.post(route('api.sessions.end', activeSession.value.id))
    .then((res) => {
      updateSessionOrTopic(res.data.session)
    })
    .catch((err) => {
      console.log(err)
      goToLogin(err)
      setFailedAlertData({
        message: `Something unfortunate happened while ${sessionActionRunning.value}. Try again shortly.`,
        timer: 4000,
      })
    })
    .finally(() => {
      sessionActionRunning.value = ''
    })
}

function clickedSessionAction(action) {
  if (action == "start") return clickedStartSession()
  if (action == "end") return clickedEndSession()
  clickedAbandonSession()
}

function reportCreated(report) {
  console.log(report)
}
</script>

<template>
  <Head :title="`${therapyType === 'group' ? 'Group ' : ''}Therapy${computedTherapy ? ` - ${computedTherapy.name}` : ''}`" />

  <BaseTherapyLayout
    :therapy="therapy"
    :therapy-type="therapyType"
    :active-session="activeSession"
    :active-discussion="activeDiscussion"
    :recent-sessions="recentSessions"
    :recent-topics="recentTopics"
    :discussions="discussions"
    :timer="timer"
    :computed-therapy="computedTherapy"
    :computed-is-user="computedIsUser"
    :computed-is-counsellor="computedIsCounsellor"
    :computed-is-participant="computedIsParticipant"
    :computed-is-in-session="computedIsInSession"
    :computed-can-participate="computedCanParticipate"
    :user-id="userId"
    :loader="loader"
    :session-action-running="sessionActionRunning"
    :scroll-items="scrollItems"
    :active-item-id="activeItemId"
    :getting="getting"
    :counsellor="counsellor"
    :counsellors="counsellors"
    :pending-request="pendingRequest"
    :request="request"
    :counsellor-links="counsellorLinks"
    @clicked-show-all="clickedShowAll"
    @clicked-active-session="clickedActiveSession"
    @handle-session-topic-update="handleSessionTopicUpdate"
    @handle-session-topic-delete="handleSessionTopicDelete"
    @get-discussions="getDiscussions"

    @clicked-response="clickedResponse"
    @create-counsellor-link="createCounsellorLink"
    @get-counsellor-links="getCounsellorlinks"
    @clicked-report="clickedReport"
    @clicked-create-session="clickedCreateSession"
    @clicked-create-discussion="clickedCreateDiscussion"
    @clicked-end-therapy="clickedEndTherapy"
    @clicked-update="clickedUpdate"
    @clicked-delete="clickedDelete"
  >
    <template #therapy-component>
      <TherapyComponent
        :therapy="computedTherapy"
        :new-session="newSession"
        :active-session="activeSession"
        :deleted-session-or-topic="currentDeletedSessionOrTopic"
        :updated-session-or-topic="currentUpdatedSessionOrTopic"
        :is-participant="computedIsParticipant"
        :is-user="computedIsUser"
        :is-counsellor="computedIsCounsellor"
        @update-active-session="(data) => (activeSession = data)"
        @created="addSessionOrTopic"
        @updated="updateSessionOrTopic"
        @done-updating="() => (currentUpdatedSessionOrTopic = null)"
        @done-deleting="() => (currentDeletedSessionOrTopic = null)"
        @deleted="deleteSessionOrTopic"
      />
    </template>

    <template #modals>
      <!-- Assistance Request Modal -->
      <MiniModal
        :show="modalData.show && ['request assistance', 'delete', 'end'].includes(modalData.type)"
        @close="closeModal"
      >
        <div class="select-none">
          <template v-if="modalData.type == 'request assistance'">
            <div class="text-gray-600 text-center font-bold tracking-wide">
              Assistance Request
            </div>

            <hr class="my-2" />

            <div class="relative">
              <FormLoader
                :text="
                  loader.type == 'assistance'
                    ? computedIsUser
                      ? 'requesting assistance'
                      : 'requesting to assist'
                    : 'getting counsellors'
                "
                :show="loader.show && ['assistance', 'counsellors'].includes(loader.type)"
              />

              <div v-if="computedIsUser" class="overflow-hidden overflow-y-auto h-[60vh]">
                <div class="w-[90%] mx-auto text-sm text-center text-gray-600 my-2">
                  Search counsellors, select (<em>by double clicking</em>) and send request.
                  You can send the request to multiple counsellors.
                </div>
                <div class="my-2 mx-auto w-[90%]">
                  <TextInput
                    v-model="counsellorSearch"
                    placeholder="search for counsellor"
                    class="w-full"
                  />
                </div>

                <div
                  class="p-2 flex items-center space-x-4 justify-start overflow-hidden overflow-x-auto"
                >
                  <template v-if="searchedCounsellors.length">
                    <CounsellorComponent
                      v-for="(counsellor, idx) in searchedCounsellors"
                      :counsellor="counsellor"
                      :key="idx"
                      :has-view="false"
                      :visit-page="false"
                      :for-request="true"
                      title="double click to select"
                      @dblclick="addToSelected(counsellor)"
                      class="shrink-0 bg-stone-200"
                    />
                    <div
                      v-if="page !== 0 && searchedCounsellors.length"
                      class="p-2 text-gray-500 transition duration-75 cursor-pointer hover:text-gray-700"
                      @click="debouncedGetCounsellors"
                      title="get more counsellors"
                    >
                      ...
                    </div>
                  </template>

                  <div v-else class="my-2 text-center text-sm w-full text-gray-600">
                    no searched counsellor
                  </div>
                </div>

                <div class="text-sm text-gray-600 font-semibold mb-2 mt-4">
                  Selected Counsellors
                </div>

                <div
                  class="p-2 flex items-center justify-start overflow-hidden overflow-x-auto"
                >
                  <template v-if="selectedCounsellors.length">
                    <CounsellorComponent
                      v-for="(counsellor, idx) in selectedCounsellors"
                      :counsellor="counsellor"
                      :key="idx"
                      :has-view="false"
                      class="bg-stone-200"
                      @click="removeFromSelected(counsellor)"
                    />
                  </template>
                  <div v-else class="my-2 text-center text-sm w-full text-gray-600">
                    no selected counsellor
                  </div>
                </div>
              </div>

              <div v-else>
                <UserComponent :user="computedTherapy.addedby" v-if="therapyType === 'group' && computedTherapy.addedby.isUser" />
                <CounsellorComponent :counsellor="computedTherapy.addedby" v-else-if="therapyType === 'group'" />
                <UserComponent :user="computedTherapy.user" v-else />

                <div class="w-[90%] mx-auto text-sm text-center text-gray-600">
                  You are sending a request to assist with this {{ therapyType }} therapy.
                </div>
              </div>

              <div class="flex justify-end mt-4">
                <PrimaryButton
                  :disabled="
                    !(computedIsUser && selectedCounsellors.length) &&
                    !$page.props.auth.user?.counsellor
                  "
                  @click="sendAssistanceRequest"
                  >send request</PrimaryButton
                >
              </div>
            </div>
          </template>

          <template v-if="modalData.type == 'delete'">
            <div class="text-gray-600 text-center font-bold tracking-wide">
              Delete {{ therapyType === 'group' ? 'Group ' : '' }}Therapy
            </div>

            <hr class="my-2" />

            <div class="relative">
              <FormLoader :text="'deleting therapy'" :show="loader.show" :danger="true" />

              <div
                class="my-4 text-sm text-red-700 text-center w-[90%] mx-auto font-bold tracking-wide"
              >
                Are you sure you want to delete this {{ therapyType }} therapy?
              </div>
            </div>

            <div class="flex space-x-2 justify-end items-center w-full p-2">
              <PrimaryButton @click="closeModal" class="shrink-0"
                >cancel</PrimaryButton
              >
              <DangerButton @click="deleteTherapy" class="shrink-0">delete</DangerButton>
            </div>
          </template>

          <template v-if="modalData.type == 'end'">
            <div class="text-gray-600 text-center font-bold tracking-wide">
              End {{ therapyType === 'group' ? 'Group ' : '' }}Therapy
            </div>

            <hr class="my-2" />

            <div class="relative">
              <FormLoader :text="'ending therapy'" :show="loader.show" />

              <div
                class="my-4 text-sm text-gray-700 text-center w-[90%] mx-auto font-bold tracking-wide"
              >
                Are you sure you want to end this {{ therapyType }} therapy?
              </div>
            </div>

            <div class="flex space-x-2 justify-end items-center w-full p-2">
              <PrimaryButton @click="closeModal" class="shrink-0"
                >cancel</PrimaryButton
              >
              <DangerButton @click="endTherapy" class="shrink-0">end</DangerButton>
            </div>
          </template>
        </div>
      </MiniModal>

      <!-- Session Actions Modal -->
      <MiniModal
        :show="modalData.show && ['have session'].includes(modalData.type)"
        @close="closeModal"
      >
        <div class="select-none">
          <div class="text-gray-600 text-center font-bold tracking-wide">Session Actions</div>

          <hr class="my-2" />

          <div class="relative">
            <div
              class="space-y-3 flex flex-col justify-center items-center"
              v-if="activeSession"
            >
              <template v-if="!sessionActionRunning">
                <PrimaryButton
                  v-if="
                    [SessionStatusEnum.pending, SessionStatusEnum.inSessionConfirmation].includes(
                      activeSession?.status
                    ) &&
                    userId !== activeSession?.updatedById &&
                    activeSession?.status !== SessionStatusEnum.inSession &&
                    timer.beforeEnd > 0
                  "
                  @click="clickedStartSession"
                  class="shrink-0"
                  >start session for you</PrimaryButton
                >
                <PrimaryButton
                  v-if="activeSession.type == 'ONLINE'"
                  @click="() => showModal('therapy')"
                  class="shrink-0"
                  >show message box</PrimaryButton
                >
                <PrimaryButton
                  v-if="
                    [
                      SessionStatusEnum.pending,
                      SessionStatusEnum.inSession,
                      SessionStatusEnum.inSessionConfirmation,
                    ].includes(activeSession?.status) &&
                    timer.beforeEnd > 0 &&
                    computedIsInSession
                  "
                  @click="clickedAbandonSession"
                  class="shrink-0"
                  >abandon session</PrimaryButton
                >
                <PrimaryButton
                  v-if="
                    computedIsInSession &&
                    timer.beforeEnd < 0 &&
                    ((userId !== activeSession?.updatedById &&
                      SessionStatusEnum.heldConfirmation == activeSession?.status) ||
                      activeSession?.status == SessionStatusEnum.inSession)
                  "
                  @click="clickedEndSession"
                  class="shrink-0"
                  >end session for you</PrimaryButton
                >
              </template>
              <div v-else class="text-sm text-gray-600 my-2 w-full text-center">
                performing action...
              </div>
            </div>
            <div v-else class="text-sm text-gray-600 my-4 text-center">
              no actions to perform
            </div>
          </div>
        </div>
      </MiniModal>

      <!-- Therapy Session Modal -->
      <Modal
        :show="modalData.show && ['therapy'].includes(modalData.type)"
        @close="closeModal"
      >
        <div class="select-none p-4">
          <div
            class="sticky top-0 text-gray-600 text-center font-bold tracking-wide capitalize flex space-x-1 justify-center items-center"
          >
            <div>{{ activeSession ? activeSession.name : "Session" }}</div>
            <div class="text-gray-600 font-normal lowercase">
              . {{ getReadableStatus(activeSession?.status) }}
            </div>
          </div>

          <hr class="my-2" />

          <div class="relative p-4 pt-0">
            <TherapyComponent
              :show-sessions="false"
              :therapy="computedTherapy"
              :newSession="newSession"
              :activeSession="activeSession"
              :is-participant="computedIsParticipant"
              :is-user="computedIsUser"
              :is-counsellor="computedIsCounsellor"
              :can-start="timer.beforeEnd > 0"
              :can-end="computedIsInSession && timer.beforeEnd < 0"
              :can-abandon="timer.beforeEnd > 0 && computedIsInSession"
              @session-action="clickedSessionAction"
              @updated="updateSessionOrTopic"
            />
          </div>
        </div>
      </Modal>

      <!-- Update Therapy Modal -->
      <UpdateIndividualTherapyFormModal
        v-if="therapyType === 'individual'"
        :show="modalData.show && modalData.type == 'update'"
        :therapy="computedTherapy"
        @close-modal="closeModal"
      />
      
      <UpdateGroupTherapyFormModal
        v-else
        :show="modalData.show && modalData.type == 'update'"
        :therapy="computedTherapy"
        @close-modal="closeModal"
      />

      <!-- Create Session Modal -->
      <CreateSessionFormModal
        :show="modalData.show && modalData.type == 'create session'"
        :therapy="computedTherapy"
        @close-modal="closeModal"
        @on-success="(data) => { if (data) newSession = data }"
      />

      <!-- Create Discussion Modal -->
      <CreateDiscussionFormModal
        :show="modalData.show && modalData.type == 'create discussion'"
        :therapy="computedTherapy"
        :forType="therapyType === 'individual' ? 'Therapy' : 'GroupTherapy'"
        :loadedSessions="recentSessions"
        @close-modal="closeModal"
        @on-success="(data) => { if (data) discussions.data = [data, ...discussions.data] }"
      />

      <!-- Create Report Modal -->
      <CreateReportModal
        :show="modalData.show && modalData.type == 'report'"
        :item="computedTherapy"
        :type="therapyType === 'individual' ? 'Therapy' : 'GroupTherapy'"
        @close-modal="closeModal"
        @on-success="reportCreated"
      />

      <!-- Discussion Modal -->
      <DiscussionModal
        :discussion="activeDiscussion"
        :timer="timer"
        :show="modalData.type == 'have discussion' && modalData.show"
        @close="closeModal"
        @changeStatus="(status) => (activeDiscussion.status = status)"
        v-if="activeDiscussion"
      />

      <!-- Alert -->
      <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
      />
    </template>
  </BaseTherapyLayout>
</template>