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

    <!-- Active Session/Discussion Sticky Header -->
    <TherapyActiveHeader
      :active-session="activeSession"
      :active-discussion="activeDiscussion"
      :timer="timer"
      :is-participant="computedIsParticipant"
      :user-id="userId"
      :session-action-running="sessionActionRunning"
      @clicked-show-all="$emit('clicked-show-all')"
      @clicked-active-session="$emit('clicked-active-session')"
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
          :getting="getting"
          :counsellor-links="counsellorLinks"
          :computed-is-user="computedIsUser"
          :computed-is-counsellor="computedIsCounsellor"
          :computed-can-participate="computedCanParticipate"
          @clicked-response="$emit('clicked-response', $event)"
          @create-counsellor-link="$emit('create-counsellor-link')"
          @get-counsellor-links="$emit('get-counsellor-links')"
        >
          <template #therapy-component>
            <slot name="therapy-component" />
          </template>
        </TherapyInformation>
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
        @clicked-report="$emit('clicked-report')"
        @clicked-create-session="$emit('clicked-create-session')"
        @clicked-create-discussion="$emit('clicked-create-discussion')"
        @clicked-end-therapy="$emit('clicked-end-therapy')"
        @clicked-update="$emit('clicked-update')"
        @clicked-delete="$emit('clicked-delete')"
      />
    </div>

    <!-- Modals -->
    <slot name="modals" />
  </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import HelpButton from '@/Components/HelpButton.vue'
import TherapyActiveHeader from '@/Components/TherapyActiveHeader.vue'
import TherapyRecentSections from '@/Components/TherapyRecentSections.vue'
import TherapyDiscussions from '@/Components/TherapyDiscussions.vue'
import TherapyInformation from '@/Components/TherapyInformation.vue'
import TherapyActions from '@/Components/TherapyActions.vue'
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
  counsellorLinks: { default: () => ({ page: 1, data: [] }) },
})

defineEmits([
  'clicked-show-all',
  'clicked-active-session',
  'handle-session-topic-update',
  'handle-session-topic-delete',
  'get-discussions',
  'handle-discussion-update',
  'handle-discussion-delete',
  'clicked-response',
  'create-counsellor-link',
  'get-counsellor-links',
  'clicked-report',
  'clicked-create-session',
  'clicked-create-discussion',
  'clicked-end-therapy',
  'clicked-update',
  'clicked-delete',
])
</script>