<script setup>
// SCRUM-430: one counsellor in the admin counsellors list (the public CounsellorComponent card is
// left as it is for everywhere else). The page owns the view and delete dialogs; this row only asks for them.
import AdminRow from '@/Components/Admin/AdminRow.vue'
import AdminCell from '@/Components/Admin/AdminCell.vue'
import AdminActionButton from '@/Components/Admin/AdminActionButton.vue'
import Avatar from '@/Components/Avatar.vue'
import CounsellorVerificationBadge from '@/Components/CounsellorVerificationBadge.vue'
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

const props = defineProps({
    counsellor: { type: Object, required: true },
})

defineEmits(['view', 'delete'])

const isSuperAdmin = computed(() => !!usePage().props.auth.user?.isSuperAdmin)
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

        <AdminCell label="Profession">
            <span class="capitalize">{{ counsellor.profession?.name ?? 'not set' }}</span>
        </AdminCell>

        <AdminCell label="Contact">
            <div class="break-all">{{ counsellor.email || 'no email' }}</div>
            <div v-if="counsellor.phone" class="text-xs text-gray-500">{{ counsellor.phone }}</div>
        </AdminCell>

        <AdminCell label="Verification">
            <CounsellorVerificationBadge v-if="counsellor.verification" :verification="counsellor.verification" />
            <div v-if="counsellor.verification?.expiresAt" class="mt-1 text-xs text-gray-500">until {{ formatDate(counsellor.verification.expiresAt) }}</div>
        </AdminCell>

        <AdminCell actions>
            <!-- The admin view (verification history and revoke, statistics -- CounsellorModal), as the old card's "view". -->
            <AdminActionButton v-if="!counsellor.anonymous" tone="primary" :aria-label="`View: ${who}`" @click="$emit('view', counsellor)">View</AdminActionButton>
            <!-- SCRUM-134: a super administrator's (EnsureCanDeleteCounsellorAction). -->
            <AdminActionButton v-if="isSuperAdmin" tone="danger" :aria-label="`Delete: the counsellor account of ${who}`" @click="$emit('delete', counsellor)">Delete</AdminActionButton>
        </AdminCell>
    </AdminRow>
</template>
