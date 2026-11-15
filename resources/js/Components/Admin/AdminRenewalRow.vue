<script setup>
// SCRUM-430: one counsellor in the verification renewals list. Its history and the revoke action
// (CounsellorVerificationPanel) open in a dialog.
import AdminRow from '@/Components/Admin/AdminRow.vue'
import AdminCell from '@/Components/Admin/AdminCell.vue'
import AdminStatusPill from '@/Components/Admin/AdminStatusPill.vue'
import AdminActionButton from '@/Components/Admin/AdminActionButton.vue'
import Avatar from '@/Components/Avatar.vue'
import CounsellorVerificationBadge from '@/Components/CounsellorVerificationBadge.vue'
import { computed } from 'vue'

const props = defineProps({
    counsellor: { type: Object, required: true },
})

defineEmits(['open'])

const who = computed(() => props.counsellor.username ? `@${props.counsellor.username}` : props.counsellor.name)

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString(undefined, { dateStyle: 'medium' }) : ''
}
</script>

<template>
    <AdminRow>
        <AdminCell label="Counsellor">
            <div class="flex items-center gap-3 min-w-0">
                <Avatar class="shrink-0" aria-hidden="true" :avatar-text="'...'" :size="32" :src="counsellor.avatar ?? ''" />
                <div class="min-w-0">
                    <div class="font-medium text-gray-900 capitalize truncate">{{ counsellor.name }}</div>
                    <div v-if="counsellor.username" class="text-xs text-gray-500 truncate">@{{ counsellor.username }}</div>
                </div>
            </div>
        </AdminCell>
        <AdminCell label="Verification">
            <CounsellorVerificationBadge v-if="counsellor.verification" :verification="counsellor.verification" />
            <div v-if="counsellor.verification?.expiresAt" class="mt-1 text-xs text-gray-500">ends {{ formatDate(counsellor.verification.expiresAt) }}</div>
        </AdminCell>
        <AdminCell label="Re-verification" half>
            <AdminStatusPill v-if="counsellor.hasPendingRequest" label="asked for" tone="blue" />
            <span v-else class="text-gray-500">not asked</span>
        </AdminCell>
        <AdminCell actions>
            <AdminActionButton :aria-label="`History & revoke: ${who}`" @click="$emit('open', counsellor)">History &amp; revoke</AdminActionButton>
        </AdminCell>
    </AdminRow>
</template>
