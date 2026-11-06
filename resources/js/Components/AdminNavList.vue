<script setup>
// SCRUM-387: the admin sidebar's groups and links -- AdminLayout shows it in the desktop sidebar
// and in the mobile drawer.
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    groups: { type: Array, required: true },
    badges: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['navigate'])

function isCurrent(item) {
    return route().current(item.match)
}

function badgeFor(item) {
    return item.countKey ? (props.badges?.[item.countKey] ?? 0) : 0
}

// A group with one item named like the group (Overview, Requests to resolve) needs no heading.
function showsHeading(group) {
    return !(group.items.length === 1 && group.items[0].label === group.label)
}
</script>

<template>
    <nav aria-label="Administration" class="p-4">
        <template v-for="group in groups" :key="group.key">
            <h2
                v-if="showsHeading(group)"
                class="mt-5 mb-1 px-3 text-xs font-semibold uppercase tracking-wider text-gray-500"
            >{{ group.label }}</h2>
            <ul class="space-y-1" :class="{ 'mt-2': !showsHeading(group) }">
                <li v-for="item in group.items" :key="item.key">
                    <Link
                        :href="route(item.route)"
                        :aria-current="isCurrent(item) ? 'page' : undefined"
                        class="flex items-center justify-between gap-2 min-h-[44px] px-3 py-2 rounded-md text-sm transition duration-75"
                        :class="isCurrent(item)
                            ? 'bg-gray-800 text-white font-medium'
                            : 'text-gray-700 hover:bg-gray-100'"
                        @click="emit('navigate')"
                    >
                        <span>{{ item.label }}</span>
                        <span
                            v-if="badgeFor(item)"
                            class="shrink-0 px-2 py-0.5 rounded-full text-xs font-medium border"
                            :class="isCurrent(item)
                                ? 'bg-white text-gray-900 border-white'
                                : 'bg-amber-50 text-amber-800 border-amber-200'"
                        >{{ badgeFor(item) }} waiting</span>
                    </Link>
                </li>
            </ul>
        </template>
    </nav>
</template>
