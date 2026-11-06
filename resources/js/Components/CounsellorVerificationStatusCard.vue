<script setup>
// SCRUM-30/31: a counsellor's own view of their verification, on their profile -- where it stands,
// until when, and the way to (re-)verify (the profile's existing verify form, via `verify`).
// Everything comes from the server (CounsellorVerification::projection for the owner).
import { computed } from 'vue'
import PrimaryButton from './PrimaryButton.vue'
import CounsellorVerificationBadge from './CounsellorVerificationBadge.vue'

const props = defineProps({
    verification: { type: Object, required: true },
})

defineEmits(['verify'])

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' }) : ''
}

const message = computed(() => {
    const v = props.verification

    if (v.hasPendingRequest) {
        return v.state === 'verified'
            ? `Your re-verification is being reviewed. You stay verified until ${formatDate(v.expiresAt)}.`
            : 'Your verification request is being reviewed.'
    }

    switch (v.state) {
        case 'verified':
            return v.renewalOpen
                ? `Your verification ends on ${formatDate(v.expiresAt)}. Re-verify now so you can keep taking on new clients.`
                : `You are verified until ${formatDate(v.expiresAt)}.`
        case 'lapsed':
            return `Your verification ended on ${formatDate(v.expiresAt)}. Your current clients carry on, but you can't take on new clients, join groups, post or link to an organisation until you re-verify.`
        case 'revoked':
            return 'An administrator revoked your verification. Your current clients carry on, but you can\'t take on new work until you are verified again. Check your notifications for the reason.'
        default:
            return 'You aren\'t verified yet. Clients see this on your profile, and you can take on clients once an administrator has checked your ID and licence.'
    }
})

const buttonLabel = computed(() => props.verification.state === 'unverified' ? 'Request verification' : 'Re-verify now')
const canRequest = computed(() => props.verification.mayRequest && !props.verification.hasPendingRequest)
</script>

<template>
    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
        <div class="flex flex-wrap items-center gap-2">
            <div class="text-sm font-semibold text-gray-900">Your verification</div>
            <CounsellorVerificationBadge :verification="verification" />
        </div>
        <p class="mt-2 text-sm text-gray-600">{{ message }}</p>
        <div v-if="canRequest" class="mt-3 flex justify-end">
            <PrimaryButton @click="$emit('verify')">{{ buttonLabel }}</PrimaryButton>
        </div>
    </div>
</template>
