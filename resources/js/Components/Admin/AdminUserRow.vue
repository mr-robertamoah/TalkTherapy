<script setup>
// SCRUM-430: one person in the admin users list -- who they are, their email, role and status, and
// their actions in the row (no "show actions" step). The list (AdminUsersComponent) owns the
// modals and the requests; this row only says which action was picked.
import AdminRow from '@/Components/Admin/AdminRow.vue'
import AdminCell from '@/Components/Admin/AdminCell.vue'
import AdminStatusPill from '@/Components/Admin/AdminStatusPill.vue'
import AdminActionButton from '@/Components/Admin/AdminActionButton.vue'
import Avatar from '@/Components/Avatar.vue'
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

const props = defineProps({
    user: { type: Object, required: true },
    busy: { type: Boolean, default: false },
})

defineEmits(['edit', 'delete', 'reset-two-factor', 'cancel-reset'])

const isSuperAdmin = computed(() => !!usePage().props.auth.user?.isSuperAdmin)
const handle = computed(() => `@${props.user.username ?? 'user ' + props.user.id}`)
const fullName = computed(() => [props.user.firstName, props.user.otherNames, props.user.lastName].filter(Boolean).join(' '))

const roleTone = { 'super admin': 'indigo', admin: 'blue', counsellor: 'green', user: 'gray' }

function formatDate(value) {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : ''
}
</script>

<template>
    <AdminRow>
        <AdminCell label="User">
            <div class="flex items-center gap-3 min-w-0">
                <Avatar v-if="user.avatar" class="shrink-0" aria-hidden="true" :avatar-text="'...'" :size="32" :src="user.avatar" />
                <div v-else class="shrink-0 h-8 w-8 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center text-xs font-semibold" aria-hidden="true">
                    {{ (user.username ?? '?').slice(0, 1).toUpperCase() }}
                </div>
                <div class="min-w-0">
                    <div class="font-medium text-gray-900 truncate">{{ fullName || 'Name not set' }}</div>
                    <div class="text-xs text-gray-500 truncate">{{ handle }}</div>
                </div>
            </div>
        </AdminCell>

        <AdminCell label="Email">
            <div class="[overflow-wrap:anywhere]">{{ user.email || 'not set' }}</div>
            <!-- SCRUM-427: a new address waiting for its owner to confirm it (super administrators only). -->
            <div v-if="user.pendingEmail" class="mt-1 text-xs text-amber-900 [overflow-wrap:anywhere]">waiting for confirmation: {{ user.pendingEmail }}</div>
        </AdminCell>

        <AdminCell label="Verified" half>
            <AdminStatusPill :label="user.emailVerified ? 'verified' : 'not verified'" :tone="user.emailVerified ? 'green' : 'amber'" />
        </AdminCell>

        <AdminCell label="Role" half>
            <AdminStatusPill v-if="user.role" :label="user.role" :tone="roleTone[user.role] ?? 'gray'" />
        </AdminCell>

        <AdminCell label="Status" half>
            <AdminStatusPill v-if="user.accountSuspended" label="suspended" tone="red" />
            <AdminStatusPill v-else-if="user.accountSuspended === false" label="active" tone="gray" />
        </AdminCell>

        <!-- SCRUM-34c / SCRUM-424: super administrators only (they can reset it). -->
        <AdminCell v-if="isSuperAdmin" label="Two-factor" half>
            <AdminStatusPill v-if="user.twoFactorResetDueAt" :label="`reset ${formatDate(user.twoFactorResetDueAt)}`" tone="amber" />
            <AdminStatusPill v-else :label="user.twoFactorEnabled ? 'on' : 'off'" :tone="user.twoFactorEnabled ? 'green' : 'gray'" />
        </AdminCell>

        <AdminCell actions>
            <AdminActionButton tone="primary" :aria-label="`Edit ${handle}`" @click="$emit('edit', user)">Edit</AdminActionButton>
            <!-- SCRUM-366: what this administrator has read (super admins only). -->
            <AdminActionButton
                v-if="user.isAdmin && isSuperAdmin"
                :href="route('administrator.content-accesses', { admin: user.id })"
                :aria-label="`Access history: ${handle}`"
            >Access history</AdminActionButton>
            <template v-if="isSuperAdmin">
                <AdminActionButton v-if="user.twoFactorResetDueAt" :disabled="busy" :aria-label="`Cancel reset: the two-factor reset of ${handle}`" @click="$emit('cancel-reset', user)">Cancel reset</AdminActionButton>
                <AdminActionButton v-else-if="user.twoFactorEnabled" :aria-label="`Reset two-factor: ${handle}`" @click="$emit('reset-two-factor', user)">Reset two-factor</AdminActionButton>
            </template>
            <!-- A super administrator's (UserService::deleteUserByAdmin). -->
            <AdminActionButton v-if="isSuperAdmin" tone="danger" :aria-label="`Delete ${handle}`" @click="$emit('delete', user)">Delete</AdminActionButton>
        </AdminCell>
    </AdminRow>
</template>
