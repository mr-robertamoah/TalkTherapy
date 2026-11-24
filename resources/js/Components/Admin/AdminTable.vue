<script setup>
// SCRUM-430: the admin area's one list layout -- a real table from md up (column headers with
// scope), and below md the same rows stacked, each cell with its own label (AdminCell), so a
// phone never scrolls sideways. Rows come in the default slot as AdminRow / AdminCell; one DOM
// for both widths, so names and handlers aren't duplicated. A table wider than its card (SCRUM-477: the recognition
// page's eight columns at ~1580px) scrolls sideways rather than clipping its last column.
defineProps({
    // [{ label: 'Email', srOnly?: true, class?: '...' }]
    columns: { type: Array, required: true },
    // What the table lists, for screen readers.
    caption: { type: String, required: true },
    empty: { type: Boolean, default: false },
    emptyText: { type: String, default: 'Nothing here yet.' },
})
</script>

<template>
    <div class="bg-white border border-gray-200 shadow-sm rounded-lg overflow-x-auto">
        <table class="w-full text-sm text-left text-gray-700">
            <caption class="sr-only">{{ caption }}</caption>
            <thead class="hidden md:table-header-group bg-gray-50 border-b border-gray-200">
                <tr>
                    <th
                        v-for="column in columns"
                        :key="column.label"
                        scope="col"
                        class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 whitespace-nowrap"
                        :class="column.class"
                    >
                        <span :class="{ 'sr-only': column.srOnly }">{{ column.label }}</span>
                    </th>
                </tr>
            </thead>
            <tbody class="block md:table-row-group divide-y divide-gray-100">
                <tr v-if="empty" class="block md:table-row">
                    <td :colspan="columns.length" class="block md:table-cell px-4 py-10 text-center text-sm text-gray-500">{{ emptyText }}</td>
                </tr>
                <slot v-else />
            </tbody>
        </table>
    </div>
</template>
