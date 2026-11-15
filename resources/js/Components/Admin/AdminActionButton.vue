<script setup>
// SCRUM-430: a compact action in an admin row (44px tall on phones, compact from md up). `href`
// makes it an Inertia link. Give it a name that says who it's for ("Edit @maria") via aria-label.
import { Link } from '@inertiajs/vue3'

defineProps({
    href: { type: String, default: null },
    tone: { type: String, default: 'default' }, // default | primary | danger
    disabled: { type: Boolean, default: false },
})

const tones = {
    default: 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50',
    primary: 'bg-gray-800 text-white border-gray-800 hover:bg-gray-700',
    danger: 'bg-white text-red-700 border-red-300 hover:bg-red-50',
}
</script>

<template>
    <Link
        v-if="href"
        :href="href"
        class="inline-flex items-center justify-center min-h-[44px] md:min-h-0 px-3 py-1.5 rounded-md border text-xs font-semibold whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-1"
        :class="tones[tone] ?? tones.default"
    ><slot /></Link>
    <button
        v-else
        type="button"
        :disabled="disabled"
        class="inline-flex items-center justify-center min-h-[44px] md:min-h-0 px-3 py-1.5 rounded-md border text-xs font-semibold whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-1 disabled:opacity-50 disabled:cursor-not-allowed"
        :class="tones[tone] ?? tones.default"
    ><slot /></button>
</template>
