<script setup>
// SCRUM-50 (TT-8.2): one category on "What we hold about you" -- what is held (the default slot), why, how long, who
// can see it, and where to change it.
import { Link } from '@inertiajs/vue3'

defineProps({
    id: { type: String, required: true },
    title: { type: String, required: true },
    link: { type: Object, default: null }, // { href, label }
})
</script>

<template>
    <section :aria-labelledby="`${id}-title`" class="bg-white shadow-sm border border-gray-200 rounded-lg p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 :id="`${id}-title`" class="text-lg font-semibold text-gray-900">{{ title }}</h2>
            <Link v-if="link" :href="link.href" class="text-sm text-indigo-700 underline hover:text-indigo-900">{{ link.label }}</Link>
        </div>

        <div class="mt-3 text-sm text-gray-800">
            <slot />
        </div>

        <dl class="mt-4 grid gap-3 border-t border-gray-100 pt-4 text-sm sm:grid-cols-3">
            <div>
                <dt class="font-medium text-gray-900">Why</dt>
                <dd class="mt-1 text-gray-600"><slot name="why" /></dd>
            </div>
            <div>
                <dt class="font-medium text-gray-900">How long</dt>
                <dd class="mt-1 text-gray-600"><slot name="kept" /></dd>
            </div>
            <div>
                <dt class="font-medium text-gray-900">Who can see it</dt>
                <dd class="mt-1 text-gray-600"><slot name="who" /></dd>
            </div>
        </dl>
    </section>
</template>
