<script setup>
// SCRUM-51/415: a link to hand to someone -- shown read-only (selected on focus), with a copy button and a
// spoken result. Where the clipboard can't be used (an insecure page, a refused permission) it says so;
// the link stays there to select and copy by hand. `share` adds the device's own share sheet where there is
// one (phones), in place of nothing.
import { ref } from 'vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps({
    url: { type: String, required: true },
    label: { type: String, required: true },
    id: { type: String, required: true },
    share: { type: Object, default: null }, // { title, text } for navigator.share
})

const copied = ref(false)
const copyFailed = ref(false)
const canShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function'
let copiedTimer = null

async function copy() {
    copyFailed.value = false
    try {
        await navigator.clipboard.writeText(props.url)
        copied.value = true
        clearTimeout(copiedTimer)
        copiedTimer = setTimeout(() => { copied.value = false }, 2000)
    } catch {
        copied.value = false
        copyFailed.value = true
    }
}

async function shareLink() {
    try {
        await navigator.share({ ...props.share, url: props.url })
    } catch {
        // Closed without sharing -- nothing to say.
    }
}
</script>

<template>
    <div class="space-y-2">
        <label :for="id" class="block text-sm font-medium text-gray-700">{{ label }}</label>
        <div class="flex flex-col sm:flex-row gap-2">
            <input
                :id="id"
                :value="url"
                readonly
                class="flex-1 min-w-0 rounded-md border-gray-300 bg-gray-50 text-sm text-gray-800 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                @focus="(event) => event.target.select()"
            />
            <div class="flex gap-2">
                <SecondaryButton type="button" @click="copy">{{ copied ? 'copied' : 'copy' }}</SecondaryButton>
                <SecondaryButton v-if="share && canShare" type="button" @click="shareLink">share</SecondaryButton>
            </div>
        </div>
        <p class="text-xs text-gray-500" role="status" aria-live="polite">
            {{ copied ? 'Link copied.' : (copyFailed ? "Couldn't copy -- select the link and copy it yourself." : '') }}
        </p>
    </div>
</template>
