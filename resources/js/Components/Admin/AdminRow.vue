<script setup>
// SCRUM-430: one row of an AdminTable -- a table row from md up; below it a two-column grid, where
// each cell spans both columns unless it's a short one (AdminCell `half`).
</script>

<template>
    <tr class="grid grid-cols-2 gap-x-4 md:table-row px-4 py-3 md:p-0 hover:bg-gray-50 transition-colors duration-75">
        <slot />
    </tr>
</template>
