<script setup>
// Takes a Laravel paginator's own `meta` object (current_page/last_page/links, from
// `$resource->response()->getData(true)`) and just renders it -- no page-tracking state of its
// own, so it works identically regardless of how the parent fetches each page.
defineProps({
    meta: {
        type: Object,
        required: true,
    },
})

const emit = defineEmits(['navigate'])
</script>

<template>
    <div v-if="meta.last_page > 1" class="flex items-center justify-center flex-wrap gap-1 mt-4">
        <button
            v-for="(link, index) in meta.links"
            :key="index"
            :disabled="!link.url || link.active"
            @click="() => emit('navigate', link.url)"
            v-html="link.label"
            class="px-3 py-1 text-sm rounded-md border transition ease-in-out duration-150"
            :class="link.active
                ? 'bg-gray-800 text-white border-gray-800'
                : (link.url ? 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' : 'bg-gray-50 text-gray-300 border-gray-200 cursor-not-allowed')"
        ></button>
    </div>
</template>
