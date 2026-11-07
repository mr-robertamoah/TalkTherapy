<script setup>
// SCRUM-393: simplified -- the frame shows the kind by colour and a preferences match as a mark;
// the card keeps the name, one line of facts, a short description and the action.
import { computed } from "vue"
import { Link, usePage } from "@inertiajs/vue3";
import TherapyCardAction from "./TherapyCardAction.vue";
import TherapyCardFrame from "./TherapyCardFrame.vue";

const props = defineProps({
    therapy: {
        default: null,
    },
    showGoTo: {
        default: false,
    }
})

const user = computed(() => usePage().props.auth.user)
const computedIsParticipant = computed(() => !!user.value && (
    user.value.id == props.therapy?.userId || user.value.id == props.therapy?.counsellor?.userId
))
const computedCanViewPage = computed(() => {
    return props.showGoTo || computedIsParticipant.value || props.therapy.public || user.value?.isAdmin
})
const facts = computed(() => {
    const sessions = props.therapy.sessionsHeld || 0
    return [
        props.therapy.counsellor?.name || 'No counsellor yet',
        `${sessions} session${sessions === 1 ? '' : 's'}`,
        props.therapy.public ? '' : 'Private',
        props.therapy.anonymous ? 'Anonymous' : '',
    ]
})
</script>

<template>
    <TherapyCardFrame
        kind="individual"
        :name="therapy.name"
        :description="therapy.backgroundStory"
        :facts="facts"
        :matches-preferences="!!therapy.viewer?.matchesPreferences"
    >
        <!-- Action: SCRUM-114, the viewer's button where the server decided it. -->
        <TherapyCardAction v-if="therapy.viewer" :item="therapy" kind="individual" />
        <div v-else-if="computedCanViewPage" class="text-center">
            <Link
                :href="route('therapies.get', { therapyId: therapy.id })"
                class="inline-flex min-h-[40px] items-center justify-center rounded-lg bg-blue-600 px-4 text-sm font-medium text-white transition-colors hover:bg-blue-700"
            >{{ computedIsParticipant ? 'Open' : 'View' }}</Link>
        </div>
    </TherapyCardFrame>
</template>
