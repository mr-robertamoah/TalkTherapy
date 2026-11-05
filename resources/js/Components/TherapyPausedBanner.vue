<template>
    <!-- SCRUM-288: shown while a therapy is paused because a minor has no active guardian. Plain card
         (no translucency or blur), calm wording, and the same crisis signposting as Pages/Suspended.vue.
         Never says why the guardian is missing or who they were.
         SCRUM-381: a counsellor (1:1, or of a group with paused members) can ask the super
         administrator for a safeguarding exception from here. -->
    <div class="w-full">
    <div class="w-full rounded-lg border border-amber-200 bg-amber-50 p-4 shadow-sm text-sm text-gray-700" role="status">
        <template v-if="viewerPaused">
            <div class="font-semibold text-gray-900 mb-1">This therapy is paused</div>
            <p>
                Your therapy is paused until a parent or guardian is added to your account. You can still
                read your past messages and sessions, but you can't send messages, start sessions or join
                calls for now.
            </p>
            <p class="mt-2">
                It starts again on its own as soon as a guardian is added, or when you turn 18.
                <Link :href="route('profile.show') + '#profile-guardianship-id'" class="font-semibold text-indigo-700 underline">Add a guardian from your profile</Link>.
            </p>
        </template>

        <template v-else-if="pausedMembers.length">
            <div class="font-semibold text-gray-900 mb-1">{{ pausedMembers.length === 1 ? 'A member\'s participation is paused' : 'Some members\' participation is paused' }}</div>
            <p>
                These members' accounts have no guardian, so they can read the group but can't write in it or
                join its calls. The rest of the group carries on as usual.
            </p>
            <ul class="mt-2 space-y-2">
                <li v-for="member in pausedMembers" :key="member.key" class="flex flex-wrap items-center justify-between gap-2 bg-white border border-amber-200 rounded-md px-3 py-2">
                    <span class="font-medium text-gray-900">{{ member.name }}</span>
                    <span v-if="member.exceptionRequested" class="text-xs font-medium text-amber-800 bg-amber-100 rounded-full px-2.5 py-1">Requested — waiting for review</span>
                    <button
                        v-else
                        type="button"
                        class="text-xs font-semibold text-indigo-700 border border-indigo-200 bg-white hover:bg-indigo-50 rounded-md px-2.5 py-1"
                        @click="requesting = member"
                    >Ask for a safeguarding exception</button>
                </li>
            </ul>
        </template>

        <template v-else>
            <div class="font-semibold text-gray-900 mb-1">This therapy is paused</div>
            <p>
                This client's participation is paused until their account has a guardian again. You can read the
                history, but messages, sessions and calls are on hold. Upcoming sessions stay scheduled; you can
                still cancel one if you need to.
            </p>
            <div v-if="therapy && therapyType !== 'group'" class="mt-2">
                <span v-if="therapy.safeguardingExceptionRequested" class="inline-block text-xs font-medium text-amber-800 bg-amber-100 rounded-full px-2.5 py-1">Safeguarding exception requested — waiting for review</span>
                <button
                    v-else
                    type="button"
                    class="text-xs font-semibold text-indigo-700 border border-indigo-200 bg-white hover:bg-indigo-50 rounded-md px-2.5 py-1"
                    @click="requesting = {}"
                >Ask for a safeguarding exception</button>
            </div>
        </template>

        <p class="mt-2 text-gray-600">
            If you need support urgently, please contact your local emergency services or a crisis line.
        </p>
    </div>

    <SafeguardingExceptionRequestModal
        v-if="therapy?.safeguardingExceptionMaxDays && !viewerPaused"
        :show="!!requesting"
        :therapy-id="therapy.id"
        :max-days="therapy.safeguardingExceptionMaxDays"
        :is-group="therapyType === 'group'"
        :member="requesting?.key ? requesting : null"
        @close="requesting = null"
    />
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import SafeguardingExceptionRequestModal from '@/Components/SafeguardingExceptionRequestModal.vue'

const props = defineProps({
    // true when the viewer themselves is the paused minor; false when they are a counsellor
    viewerPaused: { type: Boolean, default: false },
    // SCRUM-381: the therapy or group as the page has it (TherapyResource / GroupTherapyResource).
    therapy: { type: Object, default: null },
    therapyType: { type: String, default: 'individual' },
})

const requesting = ref(null)

const pausedMembers = computed(() => props.therapyType === 'group' ? (props.therapy?.pausedMembers ?? []) : [])
</script>
