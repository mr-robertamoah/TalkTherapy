<script setup>
// SCRUM-443 (the user's decisions): in-person sessions are for adults only, said where in-person is switched on; and
// meeting in person shows you to the others there, said wherever anonymity and in-person meet.
defineProps({
    // Whether in-person is (being) allowed / the session is in person.
    inPerson: { type: Boolean, default: false },
    anonymous: { type: Boolean, default: false },
    // The adults-only line belongs with the setting, not with a single session.
    showAdultsOnly: { type: Boolean, default: true },
})
</script>

<template>
    <div>
        <p v-if="showAdultsOnly" class="mt-2 text-xs text-gray-600">
            In-person sessions are for adults (18 and over) only. They can't be allowed while someone under 18 is taking part.
        </p>
        <p v-if="inPerson && anonymous" role="note" class="mt-2 rounded-md border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900">
            Meeting in person means the people there will see you, even though this therapy is anonymous.
        </p>
    </div>
</template>
