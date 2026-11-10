<script setup>
// SCRUM-390: the one search box on every admin list page. Waits for a pause in typing (300 ms), or
// Enter, then emits `search` with the trimmed text ('' clears it). Fewer than 2 characters search
// nothing (the server ignores them too); more than 100 can't be typed.
import { onBeforeUnmount, ref, watch } from 'vue'

const props = defineProps({
    modelValue: { type: String, default: '' },
    label: { type: String, default: 'Search' },
    placeholder: { type: String, default: '' },
    tooMany: { type: Boolean, default: false },
})

const emits = defineEmits(['search'])

const MAX = 100
const text = ref(props.modelValue ?? '')
let timer = null
let last = (props.modelValue ?? '').trim()

// Only a change from outside (Back, a filter switch) replaces the text: the page echoing back a search
// this box sent must not wipe what was typed while it was on its way (review).
watch(() => props.modelValue, (value) => {
    const incoming = (value ?? '').trim()
    if (incoming === last) return
    text.value = value ?? ''
    last = incoming
})

function send() {
    clearTimeout(timer)
    const term = text.value.trim()
    const effective = term.length >= 2 ? term : ''
    if (effective === last) return
    last = effective
    emits('search', effective)
}

function typed() {
    clearTimeout(timer)
    timer = setTimeout(send, 300)
}

function clear() {
    text.value = ''
    send()
}

onBeforeUnmount(() => clearTimeout(timer))

const id = `admin-search-${Math.random().toString(36).slice(2, 8)}`
</script>

<template>
    <div class="w-full sm:max-w-md">
        <label :for="id" class="block text-sm font-medium text-gray-700">{{ label }}</label>
        <div class="mt-1 flex items-center gap-2">
            <input
                :id="id"
                v-model="text"
                type="search"
                :maxlength="MAX"
                :placeholder="placeholder"
                class="w-full min-h-[44px] rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                @input="typed"
                @keydown.enter.prevent="send"
            />
            <button
                v-if="text"
                type="button"
                class="min-h-[44px] shrink-0 rounded-md border border-gray-300 bg-white px-3 text-sm text-gray-700 hover:bg-gray-50"
                @click="clear"
            >Clear</button>
        </div>
        <p v-if="tooMany" class="mt-1 text-xs text-amber-800" role="status">More than 200 matches. Add to your search to narrow it.</p>
    </div>
</template>
