<script setup>
// SCRUM-430: one cell of an AdminRow. Below md the column's label shows above the value (the
// header row is hidden there); from md up the table's own header names it.
defineProps({
    label: { type: String, default: '' },
    // The row's actions: right-aligned on wide screens, a wrapped button row when stacked.
    actions: { type: Boolean, default: false },
    // A short value (a status pill): below md it takes one of the row's two columns, not both.
    half: { type: Boolean, default: false },
})
</script>

<template>
    <td class="block md:table-cell py-1.5 md:px-4 md:py-3 align-top" :class="[half ? 'col-span-1' : 'col-span-2', { 'md:text-right pt-3 md:pt-3': actions }]">
        <span v-if="label && !actions" class="md:hidden block text-xs font-medium text-gray-500">{{ label }}</span>
        <div v-if="actions" class="flex flex-wrap gap-2 md:justify-end">
            <slot />
        </div>
        <slot v-else />
    </td>
</template>
