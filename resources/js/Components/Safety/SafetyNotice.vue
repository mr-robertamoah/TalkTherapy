<script setup>
// SCRUM-32 (TT-4.3c): a neutral notice the signed-in person sees until they dismiss it -- about themselves
// ("make sure you have the support you need") or, as a guardian, asking them to check in with their ward.
// Never anything about a flag. "Get help now" opens the "I need help now" dialog.
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import axios from 'axios'
import { router, usePage } from '@inertiajs/vue3'
import MiniModal from '@/Components/MiniModal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import useHelpNow from '@/Composables/useHelpNow'

const page = usePage()
const { show: showHelp } = useHelpNow()
const notice = ref(null)

async function load() {
    if (!page.props.auth?.user || !page.props.features?.safetyFlags) return
    await axios.get(route('safety_notice.current')).then((res) => { notice.value = res.data.notice }).catch(() => {})
}

async function dismiss() {
    const id = notice.value?.id
    notice.value = null
    if (id) await axios.post(route('safety_notice.dismiss', { notice: id })).catch(() => {})
    load()
}

// Opens the help dialog; the notice comes back on the next page until they've read it.
function getHelp() {
    notice.value = null
    showHelp()
}

// Escape or a click outside only puts it aside for now (reviews): only "I've read this" dismisses it.
function putAside() {
    notice.value = null
}

// This sits outside the Inertia page (app root), so it looks again on every page visit (reviews).
let stopListening = null
// Inertia's 'navigate' also fires for the first page, so that covers the first load too (QA: no double fetch).
onMounted(() => {
    stopListening = router.on('navigate', () => { if (!notice.value) load() })
})
onBeforeUnmount(() => stopListening?.())
watch(() => page.props.auth?.user?.id, () => load())
</script>

<template>
    <MiniModal :show="!!notice" labelledby="safety-notice-title" @close="putAside">
        <div v-if="notice" class="space-y-4 text-gray-900">
            <h2 id="safety-notice-title" class="text-lg font-semibold">A message from the TalkTherapy team</h2>
            <p class="text-sm text-gray-700">{{ notice.message }}</p>
            <div class="flex flex-wrap justify-end gap-2">
                <SecondaryButton type="button" @click="dismiss">I've read this</SecondaryButton>
                <PrimaryButton type="button" @click="getHelp">Get help now</PrimaryButton>
            </div>
        </div>
    </MiniModal>
</template>
