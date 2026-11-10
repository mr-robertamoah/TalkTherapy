<script setup>
// SCRUM-32 (TT-4.3c): a neutral notice the signed-in person sees until they dismiss it -- about themselves
// ("make sure you have the support you need") or, as a guardian, asking them to check in with their ward.
// Never anything about a flag. "Get help now" opens the "I need help now" dialog.
import { onBeforeUnmount, onMounted, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import MiniModal from '@/Components/MiniModal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import useHelpNow from '@/Composables/useHelpNow'
import useSafetyNotice from '@/Composables/useSafetyNotice'

const page = usePage()
const { show: showHelp } = useHelpNow()
// SCRUM-39 (TT-5.4c): the state is shared with the notification bell, which opens this same dialog.
const { notice, load: loadNotice, dismiss, putAside } = useSafetyNotice()

function load() {
    return loadNotice({ enabled: !!page.props.auth?.user && !!page.props.features?.safetyFlags })
}

// "I've read this": dismissed for good, then the next unread one (if any) shows.
async function dismissAndNext() {
    await dismiss()
    load()
}

// Opens the help dialog; the notice comes back on the next page until they've read it.
function getHelp() {
    putAside()
    showHelp()
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
                <SecondaryButton type="button" @click="dismissAndNext">I've read this</SecondaryButton>
                <PrimaryButton type="button" @click="getHelp">Get help now</PrimaryButton>
            </div>
        </div>
    </MiniModal>
</template>
