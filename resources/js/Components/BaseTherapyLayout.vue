<template>
  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-start items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight capitalize">
          {{ computedTherapy.name }}
        </h2>
        <div class="ml-2 lowercase text-sm">
          . {{ toDiffForHumans(computedTherapy.createdAt) }}
        </div>
      </div>
    </template>

    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4 flex justify-end">
      <HelpButton
        :title="`get help on ${therapyType} Page`"
        :page="PAGES.therapy"
        class="mr-4"
        :user="$page.props.auth?.user"
      />
    </div>

    <!-- SCRUM-73: someone who left the group. For a private group this is all they see (the
         server sends only its name and a masked preview); a public one they can still read below. -->
    <div v-if="isFormerMember && !computedIsParticipant" class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
      <div class="bg-white border border-gray-200 shadow-sm sm:rounded-lg p-6">
        <div class="text-gray-800 font-semibold">You left this group</div>
        <p class="mt-2 text-sm text-gray-600">
          <template v-if="formerMemberView">
            You are no longer a member of <strong>{{ computedTherapy.name }}</strong>, so its members, sessions, topics and messages are hidden.
          </template>
          <template v-else>You are no longer a member of <strong>{{ computedTherapy.name }}</strong>.</template>
          You can still report something that happened while you were in it.
        </p>
        <p v-if="pendingMembershipRequest" class="mt-2 text-sm text-gray-600">
          Your request to join again is waiting for the creator's answer.
        </p>
        <p v-else-if="computedTherapy.status === 'ENDED'" class="mt-2 text-sm text-gray-600">
          This group therapy has ended.
        </p>
        <div class="mt-4 flex flex-wrap gap-2 justify-end">
          <SecondaryButton @click="$emit('clicked-report')">make a report</SecondaryButton>
          <PrimaryButton
            v-if="formerMemberView && !pendingMembershipRequest && computedTherapy.status !== 'ENDED'"
            @click="$emit('clicked-join-group')"
          >{{ computedTherapy.allowAnyone ? 'join again' : 'ask to join again' }}</PrimaryButton>
        </div>
      </div>
    </div>

    <template v-if="!formerMemberView">
    <!-- Active Session/Discussion Sticky Header -->
    <TherapyActiveHeader
      :active-session="activeSession"
      :active-discussion="activeDiscussion"
      :timer="timer"
      :is-participant="computedIsParticipant"
      :session-action-running="sessionActionRunning"
      @clicked-show-all="$emit('clicked-show-all')"
      @clicked-active-session="$emit('clicked-active-session')"
      @clicked-active-discussion="$emit('clicked-active-discussion')"
    />

    <!-- Pending Session Schedule Proposal (SCRUM-208/TT-2.5c, individual therapy only) -->
    <SessionScheduleProposalSection
      v-if="therapyType === 'individual'"
      :proposal="pendingSessionScheduleProposal"
      :user-id="userId"
      :computed-is-participant="computedIsParticipant"
      :responding="scheduleProposalRequest.responding"
      @clicked-response="(response, reason) => $emit('clicked-schedule-response', response, reason)"
      @clicked-counter-offer="$emit('clicked-schedule-counter-offer')"
    />

    <div class="pt-0 pb-12">
      <!-- Recent Sessions -->
      <TherapyRecentSections
        :recent-sessions="recentSessions"
        :recent-topics="recentTopics"
        :therapy="therapy"
        @on-update="$emit('handle-session-topic-update', $event)"
        @on-delete="$emit('handle-session-topic-delete', $event)"
      />

      <!-- Discussions (for counsellors) -->
      <TherapyDiscussions
        v-if="$page.props.auth.user?.counsellor"
        :discussions="discussions"
        :loader="loader"
        :computed-therapy="computedTherapy"
        @get-discussions="$emit('get-discussions', $event)"
        @on-update="$emit('handle-discussion-update', $event)"
        @on-delete="$emit('handle-discussion-delete', $event)"
      />

      <!-- Likes and comments (SCRUM-354): public therapies only -- `engagement` is absent otherwise -->
      <TherapyEngagementBar
        v-if="computedTherapy.engagement"
        :therapy="computedTherapy"
        :type="therapyType === 'individual' ? 'Therapy' : 'GroupTherapy'"
      />

      <!-- Therapy Information -->
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <TherapyInformation
          :therapy="computedTherapy"
          :therapy-type="therapyType"
          :scroll-items="scrollItems"
          :active-item-id="activeItemId"
          :counsellor="counsellor"
          :counsellors="counsellors"
          :pending-request="pendingRequest"
          :request="request"
          :pending-membership-request="pendingMembershipRequest"
          :membership-request="membershipRequest"
          :getting="getting"
          :counsellor-links="counsellorLinks"
          :member-links="memberLinks"
          :link-invitation="linkInvitation"
          :computed-is-user="computedIsUser"
          :computed-is-counsellor="computedIsCounsellor"
          :computed-is-participant="computedIsParticipant"
          :computed-can-participate="computedCanParticipate"
          @clicked-response="$emit('clicked-response', $event)"
          @create-link="$emit('create-link', $event)"
          @get-links="$emit('get-links', $event)"
          @link-updated="(kind, idx, link) => $emit('link-updated', kind, idx, link)"
          @link-deleted="(kind, idx) => $emit('link-deleted', kind, idx)"
          @clicked-join-group="$emit('clicked-join-group', $event)"
          @clicked-leave-group="$emit('clicked-leave-group')"
          @clicked-membership-response="$emit('clicked-membership-response', $event)"
        >
          <template #therapy-component>
            <slot name="therapy-component" />
          </template>
        </TherapyInformation>
      </div>

      <!-- SCRUM-288: explain why the actions below are limited, on the overview page too. -->
      <!-- SCRUM-381: and for a group's counsellors, which of its members are paused. -->
      <div v-if="computedIsParticipant && (computedTherapy.viewerAccessPaused || computedTherapy.clientAccessPaused || computedTherapy.pausedMembers?.length)" class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
        <TherapyPausedBanner :viewer-paused="!!computedTherapy.viewerAccessPaused" :therapy="computedTherapy" :therapy-type="therapyType" />
      </div>

      <!-- Therapy Actions -->
      <TherapyActions
        v-if="computedIsParticipant"
        :therapy="computedTherapy"
        :therapy-type="therapyType"
        :computed-is-user="computedIsUser"
        :computed-is-counsellor="computedIsCounsellor"
        :computed-is-participant="computedIsParticipant"
        :computed-is-in-session="computedIsInSession"
        :active-session="activeSession"
        :pending-session-schedule-proposal="pendingSessionScheduleProposal"
        @clicked-report="$emit('clicked-report')"
        @clicked-create-session="$emit('clicked-create-session')"
        @clicked-create-discussion="$emit('clicked-create-discussion')"
        @clicked-end-therapy="$emit('clicked-end-therapy')"
        @clicked-update="$emit('clicked-update')"
        @clicked-delete="$emit('clicked-delete')"
        @clicked-propose-schedule="$emit('clicked-propose-schedule')"
      />
    </div>
    </template>

    <!-- Modals -->
    <slot name="modals" />
  </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import HelpButton from '@/Components/HelpButton.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import TherapyPausedBanner from '@/Components/TherapyPausedBanner.vue'
import TherapyActiveHeader from '@/Components/TherapyActiveHeader.vue'
import TherapyRecentSections from '@/Components/TherapyRecentSections.vue'
import TherapyDiscussions from '@/Components/TherapyDiscussions.vue'
import TherapyInformation from '@/Components/TherapyInformation.vue'
import TherapyEngagementBar from '@/Components/TherapyEngagementBar.vue'
import TherapyActions from '@/Components/TherapyActions.vue'
import SessionScheduleProposalSection from '@/Components/SessionScheduleProposalSection.vue'
import useLocalDateTimed from '@/Composables/useLocalDateTime'
import useGuidedTours from '@/Composables/useGuidedTours'

const { toDiffForHumans } = useLocalDateTimed()
const { PAGES } = useGuidedTours()

defineProps({
  // Therapy data
  therapy: { default: null },
  therapyType: { type: String, default: 'individual' },
  
  // State from composable
  activeSession: { default: null },
  activeDiscussion: { default: null },
  recentSessions: { default: () => [] },
  recentTopics: { default: () => [] },
  discussions: { default: () => ({ page: 1, data: [] }) },
  timer: { default: () => ({ beforeStart: 0, beforeEnd: 0, set: false }) },
  
  // Computed values
  computedTherapy: { default: null },
  computedIsUser: { type: Boolean, default: false },
  computedIsCounsellor: { type: Boolean, default: false },
  computedIsParticipant: { type: Boolean, default: false },
  computedIsInSession: { type: Boolean, default: false },
  computedCanParticipate: { type: Boolean, default: false },
  userId: { default: null },
  
  // UI state
  loader: { default: () => ({ show: false, type: '' }) },
  sessionActionRunning: { type: String, default: '' },
  scrollItems: { default: () => [] },
  activeItemId: { type: String, default: '' },
  getting: { default: () => ({ show: false, type: '' }) },
  
  // Therapy-specific data
  counsellor: { default: null },
  counsellors: { default: () => [] },
  pendingRequest: { default: null },
  request: { default: () => ({ responding: false, status: null }) },
  pendingMembershipRequest: { default: null },
  membershipRequest: { default: () => ({ responding: false, status: null }) },
  counsellorLinks: { default: () => ({ page: 1, data: [] }) },
  memberLinks: { default: () => ({ page: 1, data: [] }) },
  linkInvitation: { default: null },
  // SCRUM-73: the viewer left this group; formerMemberView -- and it is private, so only the
  // "you left" box shows.
  isFormerMember: { type: Boolean, default: false },
  formerMemberView: { type: Boolean, default: false },
  pendingSessionScheduleProposal: { default: null },
  scheduleProposalRequest: { default: () => ({ responding: false }) },
})

defineEmits([
  'clicked-show-all',
  'clicked-active-session',
  'clicked-active-discussion',
  'handle-session-topic-update',
  'handle-session-topic-delete',
  'get-discussions',
  'handle-discussion-update',
  'handle-discussion-delete',
  'clicked-response',
  'clicked-join-group',
  'clicked-membership-response',
  'clicked-leave-group',
  'create-link',
  'get-links',
  'link-updated',
  'link-deleted',
  'clicked-report',
  'clicked-create-session',
  'clicked-create-discussion',
  'clicked-end-therapy',
  'clicked-update',
  'clicked-delete',
  'clicked-propose-schedule',
  'clicked-schedule-response',
  'clicked-schedule-counter-offer',
])
</script>