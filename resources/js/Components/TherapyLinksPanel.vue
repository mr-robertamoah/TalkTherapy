<template>
  <div class="bg-white p-6 shrink-0 mt-4 w-full text-sm text-gray-600">
    <div class="text-gray-600 tracking-wide font-semibold">{{ title }}</div>
    <div class="my-2 text-justify w-full">{{ description }}</div>

    <div v-if="busy" class="text-center text-sm w-full my-4 text-green-700 bg-green-100 rounded">
      {{ busyText }}
    </div>

    <div class="flex justify-start items-center space-x-3 overflow-hidden overflow-x-auto">
      <template v-if="links.data?.length">
        <LinkComponent
          v-for="(link, idx) in links.data"
          :key="link.id"
          :link="link"
          @updated="(updated) => $emit('updated', idx, updated)"
          @deleted="() => $emit('deleted', idx)"
          class="w-[90%] shrink-0 bg-white"
        />

        <button
          type="button"
          :title="`get more ${emptyLabel}`"
          @click="$emit('more')"
          v-if="links.page"
          class="cursor-pointer p-2 text-gray-600 font-bold"
        >
          ...
        </button>
      </template>
      <div v-else class="h-10 flex justify-center items-center w-full">
        no {{ emptyLabel }} as at now.
      </div>
    </div>

    <div class="flex justify-end mt-4">
      <PrimaryButton
        @click="$emit('create')"
        class="ms-4"
        :class="{ 'opacity-25': creating }"
        :disabled="creating"
      >
        get link
      </PrimaryButton>
    </div>
  </div>
</template>

<script setup>
import LinkComponent from '@/Components/LinkComponent.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

// SCRUM-383: one invitation-link panel (counsellor links, and a group's member links) -- the list,
// "more", and "get link". The owner keeps the list (links: { page, data }), fetches and creates, and
// applies `updated(idx, link)` / `deleted(idx)` -- this panel never changes its prop.
defineProps({
  title: { type: String, required: true },
  description: { type: String, required: true },
  emptyLabel: { type: String, default: 'links' },
  links: { default: () => ({ page: 1, data: [] }) },
  busy: { type: Boolean, default: false },
  busyText: { type: String, default: '' },
  creating: { type: Boolean, default: false },
})

defineEmits(['create', 'more', 'updated', 'deleted'])
</script>
