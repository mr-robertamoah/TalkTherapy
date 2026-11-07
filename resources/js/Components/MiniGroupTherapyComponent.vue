<script setup>
// SCRUM-393: simplified -- the frame shows the kind by colour and a preferences match as a mark;
// the card keeps the name, one line of facts, a short description and the action.
import { computed } from "vue"
import { Link, usePage } from "@inertiajs/vue3";
import TherapyCardAction from "./TherapyCardAction.vue";
import TherapyCardFrame from "./TherapyCardFrame.vue";

const props = defineProps({
    groupTherapy: {
        default: null,
    },
    showGoTo: {
        default: false,
    }
})

// Where the card has no server-decided button (lists other than Home and Public): the creator.
// SCRUM-114: it used to read a `counsellor` a group doesn't have.
const user = computed(() => usePage().props.auth.user)
const computedIsParticipant = computed(() => !!user.value && user.value.id == props.groupTherapy?.userId)
const computedCanViewPage = computed(() => {
    return props.showGoTo || computedIsParticipant.value || props.groupTherapy.public || user.value?.isAdmin
})
const facts = computed(() => {
    const group = props.groupTherapy
    const counsellors = group.counsellorsCount || 0
    const sessions = group.sessionsHeld || 0
    let members = ''
    if (group.memberCount !== undefined && group.memberCount !== null) members = `${group.memberCount}/${group.maxUsers} members`
    else if (group.maxUsers) members = `Up to ${group.maxUsers} members`

    return [
        `${counsellors} counsellor${counsellors === 1 ? '' : 's'}`,
        members,
        `${sessions} session${sessions === 1 ? '' : 's'}`,
        group.public ? '' : 'Private',
        group.anonymous ? 'Anonymous' : '',
    ]
})
</script>

<template>
    <TherapyCardFrame
        kind="group"
        :name="groupTherapy.name"
        :description="groupTherapy.about"
        :facts="facts"
        :matches-preferences="!!groupTherapy.viewer?.matchesPreferences"
    >
        <!-- Action: SCRUM-114, the viewer's button where the server decided it. -->
        <TherapyCardAction v-if="groupTherapy.viewer" :item="groupTherapy" kind="group" />
        <div v-else-if="computedCanViewPage" class="text-center">
            <Link
                :href="route('group.therapies.get', { groupTherapyId: groupTherapy.id })"
                class="inline-flex min-h-[40px] items-center justify-center rounded-lg bg-orange-600 px-4 text-sm font-medium text-white transition-colors hover:bg-orange-700"
            >{{ computedIsParticipant ? 'Open' : 'View' }}</Link>
        </div>
    </TherapyCardFrame>
</template>
