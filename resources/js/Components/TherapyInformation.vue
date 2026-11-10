<template>
  <!-- Navigation -->
  <div id="therapy-info-id" class="relative"></div>
  <div
    class="bg-white shadow-sm sticky top-0 z-10 max-w-7xl mx-auto sm:px-6 lg:px-8 my-4 p-3 flex justify-start items-center border-b overflow-x-auto"
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
                  your offer to help is waiting for the client.
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
                  v-if="computedCanParticipate && therapy.status !== 'ENDED'"
                  >{{
                    !computedIsUser ? "offer to help" : "find a counsellor"
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
              <div v-else class="mt-2 text-sm p-2 text-gray-600">no counsellors yet</div>
              <!-- SCRUM-389: while the group has a free counsellor place -- its creator invites
                   counsellors, another counsellor offers to help (the creator accepts). -->
              <div
                v-if="therapy.viewer?.action === 'offerSent'"
                class="my-2 text-sm text-gray-600"
              >Your offer to help is waiting for the group's creator.</div>
              <div
                v-else-if="therapy.viewer?.action === 'invited'"
                class="my-2 text-sm text-gray-600"
              >You've been invited to help with this group. Accept it from your requests.</div>
              <div v-else-if="computedCanOfferOrInvite" class="my-2">
                <PrimaryButton @click="$emit('clicked-assistance-request')">
                  {{ computedIsUser ? "find a counsellor" : "offer to help" }}
                </PrimaryButton>
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

          <!-- Membership Section (group therapy join flow, SCRUM-72) -->
          <div v-if="therapyType === 'group'" class="bg-white p-6 shrink-0 mt-4 w-full">
            <div class="flex justify-between items-baseline">
              <div class="text-gray-600 tracking-wide font-semibold">Membership</div>
              <div v-if="therapy.membersCount != null" class="text-sm text-gray-500">
                {{ therapy.membersCount }} of {{ therapy.maxUsers }} {{ therapy.maxUsers == 1 ? 'member' : 'members' }}
              </div>
            </div>

            <div class="my-4 relative">
              <!-- The creator, viewing a request someone else sent to join -->
              <template v-if="computedIsUser && pendingMembershipRequest">
                <FormLoader :show="membershipRequest.responding" :text="'responding'" />

                <div class="text-center text-sm text-gray-600 w-full">
                  someone has requested to join this group therapy.
                </div>

                <div class="flex justify-end items-center p-2 overflow-hidden overflow-x-auto space-x-2">
                  <PrimaryButton
                    :disabled="membershipRequest.responding"
                    @click="$emit('clicked-membership-response', 'accepted')"
                    class="shrink-0"
                    >accept</PrimaryButton
                  >
                  <DangerButton
                    :disabled="membershipRequest.responding"
                    @click="$emit('clicked-membership-response', 'rejected')"
                    class="shrink-0"
                    >reject</DangerButton
                  >
                </div>
              </template>

              <!-- An ordinary member: they may leave (SCRUM-73) -->
              <template v-else-if="therapy.isMember">
                <div class="text-sm text-center text-gray-600 w-full">
                  you are a member of this group therapy.
                </div>
                <div v-if="therapy.status !== 'ENDED'" class="flex justify-center mt-3">
                  <DangerButton @click="$emit('clicked-leave-group')">leave group</DangerButton>
                </div>
              </template>

              <!-- The creator or a counsellor, with no pending request to respond to -->
              <template v-else-if="computedIsParticipant">
                <div class="text-sm text-center text-gray-600 w-full">
                  {{ computedIsUser ? 'you created this group therapy.' : 'you are a counsellor of this group therapy.' }}
                </div>
              </template>

              <!-- Opened from a member invitation link (SCRUM-383) -->
              <template v-else-if="linkInvitation">
                <div class="text-center text-sm text-gray-700 w-full">
                  you have been invited to join this group therapy.
                </div>
                <div class="flex justify-center mt-3">
                  <PrimaryButton @click="$emit('clicked-join-group', { invitation: linkInvitation.uuid })">join group</PrimaryButton>
                </div>
              </template>

              <!-- Own pending join request -->
              <template v-else-if="pendingMembershipRequest">
                <FormLoader :show="membershipRequest.responding" :text="'responding'" />

                <div class="text-center text-sm text-gray-600 w-full">
                  your request to join this group therapy is pending approval.
                </div>
              </template>

              <!-- Not a member, nothing pending -->
              <template v-else-if="therapy.status !== 'ENDED'">
                <div class="flex justify-center">
                  <PrimaryButton @click="$emit('clicked-join-group')">join group</PrimaryButton>
                </div>
              </template>

              <template v-else>
                <div class="text-sm text-center text-gray-600 w-full">
                  this group therapy has ended.
                </div>
              </template>
            </div>
          </div>

          <!-- Invitation links (SCRUM-383): counsellors, and for a group, members -->
          <TherapyLinksPanel
            v-if="shouldShowCounsellorLinks"
            title="Counsellor links"
            :description="therapyType === 'group'
              ? 'Give a link to a counsellor. Once they use it they join this group as one of its counsellors. Each link works once.'
              : 'A link can be given to any counsellor and once they use the link they will be automatically assigned to this therapy.'"
            empty-label="counsellor links"
            :links="counsellorLinks"
            :busy="getting.show && ['links counsellor', 'create link counsellor'].includes(getting.type)"
            :busy-text="getting.type == 'create link counsellor' ? 'creating link' : 'getting counsellor links'"
            :creating="getting.show && getting.type == 'create link counsellor'"
            @create="$emit('create-link', 'counsellor')"
            @more="$emit('get-links', 'counsellor')"
            @updated="(idx, link) => $emit('link-updated', 'counsellor', idx, link)"
            @deleted="(idx) => $emit('link-deleted', 'counsellor', idx)"
          />

          <TherapyLinksPanel
            v-if="shouldShowMemberLinks"
            title="Member links"
            description="Give a link to someone you want in this group. They join without waiting for your approval once they confirm on the group's page, choosing whether to be anonymous. Each link works once."
            empty-label="member links"
            :links="memberLinks"
            :busy="getting.show && ['links member', 'create link member'].includes(getting.type)"
            :busy-text="getting.type == 'create link member' ? 'creating link' : 'getting member links'"
            :creating="getting.show && getting.type == 'create link member'"
            @create="$emit('create-link', 'member')"
            @more="$emit('get-links', 'member')"
            @updated="(idx, link) => $emit('link-updated', 'member', idx, link)"
            @deleted="(idx) => $emit('link-deleted', 'member', idx)"
          />
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
              :computed-is-participant="computedIsParticipant"
              :computed-is-counsellor="computedIsCounsellor"
            />
          </div>

          <!-- Video Consent -->
          <div v-if="activeTab === 'therapy_video_consent'">
            <VideoConsentPanel :therapy="therapy" :is-counsellor="computedIsCounsellor" />
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
import TherapyLinksPanel from '@/Components/TherapyLinksPanel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import FormLoader from '@/Components/FormLoader.vue'
import TherapyDetails from '@/Components/TherapyDetails.vue'
import TherapyPaymentDetails from '@/Components/TherapyPaymentDetails.vue'
import TherapyOtherDetails from '@/Components/TherapyOtherDetails.vue'
import TherapyStats from '@/Components/TherapyStats.vue'
import VideoConsentPanel from '@/Components/VideoConsentPanel.vue'

const props = defineProps({
  therapy: { default: null },
  therapyType: { type: String, default: 'individual' },
  scrollItems: { default: () => [] },
  activeItemId: { type: String, default: '' },
  counsellor: { default: null },
  counsellors: { default: () => [] },
  pendingRequest: { default: null },
  request: { default: () => ({ responding: false, status: null }) },
  pendingMembershipRequest: { default: null },
  membershipRequest: { default: () => ({ responding: false, status: null }) },
  getting: { default: () => ({ show: false, type: '' }) },
  counsellorLinks: { default: () => ({ page: 1, data: [] }) },
  memberLinks: { default: () => ({ page: 1, data: [] }) },
  linkInvitation: { default: null },
  computedIsUser: { type: Boolean, default: false },
  computedIsCounsellor: { type: Boolean, default: false },
  computedCanParticipate: { type: Boolean, default: false },
  computedIsParticipant: { type: Boolean, default: false },
})

// SCRUM-389: a group with a free counsellor place, for its creator or a counsellor who isn't on it.
// From the server (GroupTherapyResource): the creator while the group can take a counsellor, or a
// counsellor whose card would offer to help (ResolveTherapyCardStateAction).
const computedCanOfferOrInvite = computed(() => {
  if (props.therapyType !== 'group') return false
  if (props.computedIsUser) return !!props.therapy?.canTakeCounsellor

  return props.therapy?.viewer?.action === 'offer'
})

// SCRUM-383 QA: someone opening a member invitation lands on Participants, where the invitation
// and its "join group" button are, not on "about".
const activeTab = ref(
  props.therapyType === 'group' && props.linkInvitation && !props.computedIsParticipant
    ? 'therapy_participants'
    : 'therapy_background_story'
)

const allTabItems = computed(() => {
  const baseItems = [
    { id: "therapy_background_story", name: props.therapyType === 'group' ? "about" : "background story" },
    { id: "therapy_participants", name: "participants" },
    { id: "therapy_details", name: "details" },
    { id: "therapy_payment_details", name: "payment details" },
    { id: "therapy_video_consent", name: "video consent" },
    { id: "therapy_other_details", name: "other details" },
    { id: "therapy_stats", name: "stats" },
    { id: "chat_history", name: "chat history" },
  ]

  return baseItems.filter((item) => {
    if (item.id === 'therapy_payment_details') return props.therapy.paymentType == 'PAID'
    // TT-3.1e-f/SCRUM-285: TherapyResource's own videoConsent field is already null unless this
    // therapy actually has a minor client -- no separate "is individual therapy" check needed
    // here, GroupTherapy never gets this field at all (it uses its own resource).
    if (item.id === 'therapy_video_consent') return !!props.therapy.videoConsent
    return true
  })
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

const shouldShowCounsellorLinks = computed(() => {
  if (props.therapyType === 'group') {
    const active = props.therapy.activeCounsellorIds?.length ?? 0
    return props.computedIsUser && props.therapy.status !== 'ENDED' && active < (props.therapy.maxCounsellors ?? 1)
  }
  return props.computedIsUser && !props.counsellor
})

// SCRUM-383: the creator invites members while the group is running and has room.
const shouldShowMemberLinks = computed(() => {
  return props.therapyType === 'group' && props.computedIsUser && props.therapy.status !== 'ENDED'
})

defineEmits([
  'clicked-response',
  'clicked-assistance-request',
  'clicked-join-group',
  'clicked-membership-response',
  'clicked-leave-group',
  'create-link',
  'get-links',
  'link-updated',
  'link-deleted',
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