<template>
  <!-- Active Session Header -->
  <div
    class="w-full sticky top-0 z-10 pt-2"
    :class="{ 'p-2 bg-white': showAll }"
    v-if="
      activeSession &&
      isParticipant &&
      ![SessionStatusEnum.held, SessionStatusEnum.abandoned].includes(activeSession?.status)
    "
  >
    <div
      v-if="activeSession"
      class="w-[80%] md:w-[70%] lg:w-[60%] mb-2 mx-auto"
    >
      <div
        @click="$emit('clicked-show-all')"
        class="p-2 bg-green-300 w-fit ml-auto rounded-lg text-green-800 text-center cursor-pointer"
      >
        {{ showAll ? "hide session information" : "show session information" }}
      </div>
    </div>
    
    <template v-if="showAll">
      <div
        class="p-2 w-[80%] md:w-[70%] lg:w-[60%] mx-auto rounded-lg select-none cursor-pointer"
        :class="[
          activeSession?.status == SessionStatusEnum.abandoned
            ? 'bg-red-300'
            : 'bg-green-300',
        ]"
        @dblclick="$emit('clicked-active-session')"
      >
        <div
          class="flex justify-end items-center text-sm space-x-2 font-bold"
          v-if="activeSession?.status !== SessionStatusEnum.abandoned"
        >
          <div
            class="text-blue-600 p-2 rounded bg-blue-200"
            v-if="timer.beforeStart > 0"
          >
            {{ timer.beforeStart }} minutes to start
          </div>
          <div
            class="text-green-800 p-2 rounded bg-green-200"
            v-if="timer.beforeEnd > 0"
          >
            {{ timer.beforeEnd }} minutes to end
          </div>
          <div class="text-gray-600 p-2 rounded bg-gray-200" v-if="timer.beforeEnd < 0">
            {{ timer.beforeEnd }} minutes beyond end time
          </div>
        </div>
        <div v-else class="text-red-600 p-2 rounded bg-red-200 w-fit ml-auto">
          abandoned
        </div>
        <div
          class="my-2 mx-auto w-[90%] text-center"
          :class="[
            activeSession?.status == SessionStatusEnum.abandoned
              ? 'text-red-800'
              : 'text-green-800',
          ]"
        >
          {{ activeSession.name }}
        </div>
      </div>
      
      <div
        v-if="onlineParticipants.length > 1"
        class="p-2 text-center mt-2 text-gray-600 select-none font-bold text-sm w-[80%] md:w-[70%] lg:w-[60%] mx-auto rounded-lg bg-slate-300"
      >
        {{ onlineParticipants.find((u) => u.id !== userId).name }} is online
      </div>
    </template>

    <div
      v-if="sessionActionRunning"
      class="p-2 bg-green-300 w-[80%] md:w-[70%] lg:w-[60%] mt-2 mx-auto rounded-lg text-green-800 text-center"
    >
      {{ sessionActionRunning }}
    </div>
  </div>

  <!-- Active Discussion Header -->
  <div
    class="w-full sticky top-0 z-10 pt-2"
    :class="{ 'p-2 bg-white': showAll }"
    v-if="
      activeDiscussion &&
      ![DiscussionStatusEnum.held, DiscussionStatusEnum.abandoned].includes(
        activeDiscussion?.status
      )
    "
  >
    <div
      v-if="activeDiscussion"
      class="w-[80%] md:w-[70%] lg:w-[60%] mb-2 mx-auto"
    >
      <div
        @click="$emit('clicked-show-all')"
        class="p-2 bg-green-300 w-fit ml-auto rounded-lg text-green-800 text-center cursor-pointer"
      >
        {{ showAll ? "hide discussion information" : "show discussion information" }}
      </div>
    </div>
    
    <template v-if="showAll">
      <div
        class="p-2 w-[80%] md:w-[70%] lg:w-[60%] mx-auto rounded-lg select-none cursor-pointer"
        :class="[
          activeDiscussion?.status == DiscussionStatusEnum.abandoned
            ? 'bg-red-300'
            : 'bg-green-300',
        ]"
        @dblclick="$emit('clicked-active-session')"
      >
        <div
          class="flex justify-end items-center text-sm space-x-2 font-bold"
          v-if="activeDiscussion?.status !== DiscussionStatusEnum.abandoned"
        >
          <div
            class="text-blue-600 p-2 rounded bg-blue-200"
            v-if="timer.beforeStart > 0"
          >
            {{ timer.beforeStart }} minutes to start
          </div>
          <div
            class="text-green-800 p-2 rounded bg-green-200"
            v-if="timer.beforeEnd > 0"
          >
            {{ timer.beforeEnd }} minutes to end
          </div>
          <div class="text-gray-600 p-2 rounded bg-gray-200" v-if="timer.beforeEnd < 0">
            {{ timer.beforeEnd }} minutes beyond end time
          </div>
        </div>
        <div v-else class="text-red-600 p-2 rounded bg-red-200 w-fit ml-auto">
          abandoned
        </div>
        <div
          class="my-2 mx-auto w-[90%] text-center"
          :class="[
            activeDiscussion?.status == DiscussionStatusEnum.abandoned
              ? 'text-red-800'
              : 'text-green-800',
          ]"
        >
          {{ activeDiscussion.name }}
        </div>
      </div>
    </template>

    <div
      v-if="sessionActionRunning"
      class="p-2 bg-green-300 w-[80%] md:w-[70%] lg:w-[60%] mt-2 mx-auto rounded-lg text-green-800 text-center"
    >
      {{ sessionActionRunning }}
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import useEnums from '@/Composables/useEnums'

const { SessionStatusEnum, DiscussionStatusEnum } = useEnums()

const showAll = ref(false)

defineProps({
  activeSession: { default: null },
  activeDiscussion: { default: null },
  timer: { default: () => ({ beforeStart: 0, beforeEnd: 0, set: false }) },
  isParticipant: { type: Boolean, default: false },
  userId: { default: null },
  sessionActionRunning: { type: String, default: '' },
  onlineParticipants: { default: () => [] },
})

defineEmits(['clicked-show-all', 'clicked-active-session'])
</script>