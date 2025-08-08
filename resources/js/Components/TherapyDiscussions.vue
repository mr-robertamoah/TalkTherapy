<template>
  <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
    <div class="p-6 overflow-hidden shadow-sm sm:rounded-lg my-8 bg-slate-200">
      <div class="text-gray-600 font-semibold tracking-wide text-center mb-4">
        Discussions
      </div>
      <div
        v-if="loader.show && loader.type == 'discussions'"
        class="text-center text-sm w-full my-4 text-green-600 bg-green-200"
      >
        getting discussions
      </div>
      <div
        class="flex space-x-3 justify-start items-center p-2 overflow-hidden overflow-x-auto w-full"
      >
        <template v-if="discussions.data?.length">
          <DiscussionBadge
            v-for="(item, idx) in discussions.data"
            :key="idx"
            :discussion="item"
            :show-details="true"
            :show-actions="$page.props.auth.user?.id == item.addedby?.userId"
            @onUdpate="(data) => $emit('on-update', data, idx)"
            @onDelete="(data) => $emit('on-delete', data, idx)"
            class="w-[60%] shrink-0 bg-slate-200 rounded"
          />

          <div
            title="get more discussions"
            @click="$emit('get-discussions', computedTherapy)"
            v-if="discussions.page"
            class="cursor-pointer p-2 text-gray-600 font-bold"
          >
            ...
          </div>
        </template>
        <div v-else class="text-sm text-center text-gray-600 my-2">
          no discussions
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import DiscussionBadge from '@/Components/DiscussionBadge.vue'

defineProps({
  discussions: { default: () => ({ page: 1, data: [] }) },
  loader: { default: () => ({ show: false, type: '' }) },
  computedTherapy: { default: null },
})

defineEmits(['get-discussions', 'on-update', 'on-delete'])
</script>