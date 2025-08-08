<template>
  <!-- Navigation -->
  <div id="therapy-info-id" class="relative"></div>
  <div
    class="bg-white shadow-sm sticky top-0 z-10 max-w-7xl mx-auto sm:px-6 lg:px-8 my-4 p-3 flex justify-start items-center border-b"
  >
    <button
      v-for="item in allTabItems"
      :key="item.id"
      @click="setActiveTab(item.id)"
      class="py-2 px-4 cursor-pointer shrink-0 text-sm mr-2 text-center rounded-lg transition-all duration-200 font-medium"
      :class="[
        activeTab == item.id 
          ? 'bg-gray-700 text-white shadow-md' 
          : 'text-gray-600 hover:bg-gray-100 hover:text-gray-800'
      ]"
    >
      {{ item.name }}
    </button>
  </div>

  <!-- Tab Content -->
  <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
    <div class="overflow-hidden shadow-sm sm:rounded-lg my-8">
      <div class="relative">
        <!-- Tab content with transitions -->
        <transition name="tab-fade" mode="out-in">
          <div :key="activeTab">
            <!-- Background Story / About -->
            <div v-if="activeTab === 'therapy_background_story'" class="bg-white p-6 w-full" id="therapy_background_story">
            <div class="text-gray-600 tracking-wide font-semibold">
              {{ therapyType === 'group' ? 'About' : 'Background Story' }}
            </div>
            <div
              class="my-4 min-h-40 max-h-[500px] overflow-hidden overflow-y-auto text-sm"
              :class="[
                backgroundContent
                  ? 'text-gray-700 text-justify'
                  : 'flex justify-center items-center text-gray-600',
              ]"
            >
              {{ backgroundContent || (therapyType === 'group' ? 'nothing said about therapy' : 'no background story') }}
            </div>
          </div>

          <!-- Participants -->
          <div v-if="activeTab === 'therapy_participants'" class="w-full" id="therapy_participants">
          <!-- Counsellors Section -->
          <div class="bg-white p-6 w-full">
            <div class="text-gray-600 tracking-wide font-semibold">
              {{ therapyType === 'group' ? 'Counsellors' : 'Counsellor' }}
            </div>
            
            <!-- Individual Therapy Counsellor -->
            <template v-if="therapyType === 'individual'">
              <div v-if="counsellor" class="my-4">
                <CounsellorComponent
                  :counsellor="counsellor"
                  :has-view="false"
                  :visit-page="!computedIsCounsellor"
                  class="bg-stone-200"
                />
              </div>
              <div v-else-if="pendingRequest" class="relative">
                <FormLoader :show="request.responding" :text="'responding'" />
                <div
                  class="text-center text-sm text-gray-600 w-full"
                  v-if="
                    pendingRequest.from?.isCounsellor &&
                    pendingRequest.from?.userId == $page.props.auth.user?.id
                  "
                >
                  you have already sent a request to assist.
                </div>

                <template v-else>
                  <div class="text-center text-sm text-gray-600 w-full">
                    you have a pending request to assist.
                  </div>

                  <div
                    class="flex justify-end items-center p-2 overflow-hidden overflow-x-auto space-x-2"
                  >
                    <PrimaryButton
                      :disabled="request.responding"
                      @click="$emit('clicked-response', 'accepted')"
                      class="shrink-0"
                      >accept</PrimaryButton
                    >
                    <DangerButton
                      :disabled="request.responding"
                      @click="$emit('clicked-response', 'rejected')"
                      class="shrink-0"
                      >reject</DangerButton
                    >
                  </div>
                </template>
              </div>
              <div v-else class="my-4 flex justify-center items-start flex-col">
                <PrimaryButton
                  @click="$emit('clicked-assistance-request')"
                  v-if="computedCanParticipate"
                  >{{
                    !computedIsUser ? "request to assist" : "send assistance request"
                  }}</PrimaryButton
                >
                <div class="mt-2 text-sm text-center p-2 text-gray-600">
                  no counsellor yet
                </div>
              </div>
            </template>

            <!-- Group Therapy Counsellors -->
            <template v-else>
              <div v-if="counsellors.length" class="my-4 flex justify-start p-2 items-center">
                <CounsellorComponent
                  v-for="(counsellor, idx) in counsellors"
                  :key="idx"
                  :counsellor="counsellor"
                  :has-view="false"
                  :visit-page="!computedIsCounsellor"
                  class="bg-stone-200"
                />
              </div>
              <div v-else class="my-4 flex justify-center items-start flex-col">
                <PrimaryButton
                  @click="$emit('clicked-assistance-request')"
                  v-if="computedCanParticipate"
                  >{{
                    !computedIsUser ? "request to assist" : "send assistance request"
                  }}</PrimaryButton
                >
                <div class="mt-2 text-sm text-center p-2 text-gray-600">
                  no counsellors yet
                </div>
              </div>
            </template>
          </div>

          <!-- User/Creator Section -->
          <div class="bg-white p-6 shrink-0 mt-4 w-full">
            <div class="text-gray-600 tracking-wide font-semibold">
              {{ therapyType === 'group' ? (therapy.addedby.isUser ? 'User' : 'Counsellor') : 'User' }}
            </div>
            <div class="my-4">
              <UserComponent :user="userToShow" v-if="therapyType === 'individual' || (therapyType === 'group' && userToShow.isUser)" />
              <CounsellorComponent :counsellor="userToShow" v-else-if="therapyType === 'group' && !userToShow.isUser" />
            </div>
          </div>

          <!-- Links Section (for users without counsellors) -->
          <div
            v-if="shouldShowLinks"
            class="bg-white p-6 shrink-0 mt-4 w-full text-sm text-gray-600"
          >
            <div class="text-gray-600 tracking-wide font-semibold">Links</div>
            <div class="my-2 text-justify w-full">
              A link can be given to any counsellor and once they use the link they
              will be automatically assigned to this therapy.
            </div>
            <div
              v-if="getting.show"
              class="text-center text-sm w-full my-4 text-green-600 bg-green-200"
            >
              {{
                getting.type == "create link"
                  ? "creating link"
                  : "getting counsellor links"
              }}
            </div>
            <div
              class="flex justify-start items-center space-x-3 overflow-hidden overflow-x-auto"
            >
              <template v-if="counsellorLinks.data?.length">
                <LinkComponent
                  v-for="(link, idx) in counsellorLinks.data"
                  :key="link.id"
                  :link="link"
                  @updated="(lk) => updateLink(lk, idx)"
                  @deleted="(lk) => deleteLink(idx)"
                  class="w-[90%] shrink-0 bg-white"
                />

                <div
                  title="get more counsellor links"
                  @click="$emit('get-counsellor-links')"
                  v-if="counsellorLinks.page"
                  class="cursor-pointer p-2 text-gray-600 font-bold"
                >
                  ...
                </div>
              </template>
              <div v-else class="h-10 flex justify-center items-center w-full">
                no links for {{ therapyType === 'group' ? 'counsellors' : 'counsellor' }} as at now.
              </div>
            </div>

            <div class="flex justify-end mt-4">
              <PrimaryButton
                @click="$emit('create-counsellor-link')"
                class="ms-4"
                :class="{
                  'opacity-25': getting.show && getting.type == 'create link',
                }"
                :disabled="getting.show && getting.type == 'create link'"
              >
                get link
              </PrimaryButton>
            </div>
          </div>
          </div>

          <!-- Details -->
          <div v-if="activeTab === 'therapy_details'">
            <TherapyDetails :therapy="therapy" :therapy-type="therapyType" />
          </div>

          <!-- Payment Details -->
          <div v-if="activeTab === 'therapy_payment_details' && therapy.paymentType == 'PAID'">
            <TherapyPaymentDetails 
              :therapy="therapy" 
              :therapy-type="therapyType" 
            />
          </div>

          <!-- Other Details -->
          <div v-if="activeTab === 'therapy_other_details'">
            <TherapyOtherDetails :therapy="therapy" :therapy-type="therapyType" />
          </div>

          <!-- Stats -->
          <div v-if="activeTab === 'therapy_stats'">
            <TherapyStats :therapy="therapy" />
          </div>

            <!-- Chat History -->
            <div v-if="activeTab === 'chat_history'" class="bg-white p-6 w-full">
              <div class="text-gray-600 tracking-wide font-semibold mb-4">Chat History</div>
              <slot name="therapy-component" />
            </div>
          </div>
        </transition>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import CounsellorComponent from '@/Components/CounsellorComponent.vue'
import UserComponent from '@/Components/UserComponent.vue'
import LinkComponent from '@/Components/LinkComponent.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import FormLoader from '@/Components/FormLoader.vue'
import TherapyDetails from '@/Components/TherapyDetails.vue'
import TherapyPaymentDetails from '@/Components/TherapyPaymentDetails.vue'
import TherapyOtherDetails from '@/Components/TherapyOtherDetails.vue'
import TherapyStats from '@/Components/TherapyStats.vue'

const props = defineProps({
  therapy: { default: null },
  therapyType: { type: String, default: 'individual' },
  scrollItems: { default: () => [] },
  activeItemId: { type: String, default: '' },
  counsellor: { default: null },
  counsellors: { default: () => [] },
  pendingRequest: { default: null },
  request: { default: () => ({ responding: false, status: null }) },
  getting: { default: () => ({ show: false, type: '' }) },
  counsellorLinks: { default: () => ({ page: 1, data: [] }) },
  computedIsUser: { type: Boolean, default: false },
  computedIsCounsellor: { type: Boolean, default: false },
  computedCanParticipate: { type: Boolean, default: false },
  computedIsParticipant: { type: Boolean, default: false },
})

const activeTab = ref('therapy_background_story')

const allTabItems = computed(() => {
  const baseItems = [
    { id: "therapy_background_story", name: props.therapyType === 'group' ? "about" : "background story" },
    { id: "therapy_participants", name: "participants" },
    { id: "therapy_details", name: "details" },
    { id: "therapy_payment_details", name: "payment details" },
    { id: "therapy_other_details", name: "other details" },
    { id: "therapy_stats", name: "stats" },
    { id: "chat_history", name: "chat history" },
  ]
  
  return baseItems.filter((item) =>
    props.therapy.paymentType == 'PAID'
      ? item
      : item.id !== 'therapy_payment_details'
  )
})

function setActiveTab(tabId) {
  activeTab.value = tabId
}

const backgroundContent = computed(() => {
  return props.therapyType === 'group' 
    ? props.therapy.about 
    : props.therapy.backgroundStory
})

const userToShow = computed(() => {
  return props.therapyType === 'group' 
    ? props.therapy.addedby 
    : props.therapy.user
})

const shouldShowLinks = computed(() => {
  if (props.therapyType === 'group') {
    return props.computedIsUser && !props.counsellors.length
  }
  return props.computedIsUser && !props.counsellor
})

defineEmits([
  'clicked-response',
  'clicked-assistance-request',
  'create-counsellor-link',
  'get-counsellor-links',
])
</script>

<style scoped>
.tab-fade-enter-active,
.tab-fade-leave-active {
  transition: opacity 0.3s ease;
}

.tab-fade-enter-from,
.tab-fade-leave-to {
  opacity: 0;
}
</style>