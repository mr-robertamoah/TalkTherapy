<script setup>
// SCRUM-430: one suspension in the admin suspensions list. Its full details, the appeal and the
// lift / decide actions open in a dialog (the page shows SuspensionCard there -- the same card the
// report's page uses), so this row stays one line.
import AdminRow from '@/Components/Admin/AdminRow.vue'
import AdminCell from '@/Components/Admin/AdminCell.vue'
import AdminStatusPill from '@/Components/Admin/AdminStatusPill.vue'
import AdminActionButton from '@/Components/Admin/AdminActionButton.vue'
import { computed } from 'vue'

const props = defineProps({
    suspension: { type: Object, required: true },
})

defineEmits(['open'])

const statusTone = { active: 'amber', lifted: 'gray', ended: 'gray' }
const actionLabel = computed(() => props.suspension.canDecideAppeal ? 'Decide appeal' : (props.suspension.canLift ? 'Details & lift' : 'Details'))
const who = computed(() => props.suspension.person?.username ? `@${props.suspension.person.username}` : (props.suspension.person?.name ?? 'this person'))

function formatDate(value) {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : ''
}
</script>

<template>
    <AdminRow>
        <AdminCell label="Person">
            <template v-if="suspension.involved">
                <span class="text-gray-600 italic">You are involved in this suspension, so another administrator handles it.</span>
            </template>
            <template v-else>
                <div class="font-medium text-gray-900">{{ suspension.person.name }}</div>
                <div v-if="suspension.person.username" class="text-xs text-gray-500">@{{ suspension.person.username }}</div>
            </template>
        </AdminCell>

        <AdminCell label="Scope" half>
            <span v-if="!suspension.involved">{{ suspension.scopeLabel }}</span>
        </AdminCell>

        <AdminCell label="Status" half>
            <div class="flex flex-wrap gap-1">
                <AdminStatusPill :label="suspension.status" :tone="statusTone[suspension.status] ?? 'gray'" />
                <AdminStatusPill v-if="suspension.appeal?.pending" label="appeal waiting" tone="red" />
            </div>
        </AdminCell>

        <AdminCell label="Period">
            <div class="whitespace-nowrap">{{ formatDate(suspension.startsAt) }}</div>
            <div v-if="!suspension.involved" class="text-xs text-gray-500 whitespace-nowrap">
                until {{ suspension.endsAt ? formatDate(suspension.endsAt) : 'lifted by an administrator' }}
            </div>
        </AdminCell>

        <AdminCell label="Applied by">
            <span v-if="!suspension.involved">{{ suspension.appliedBy }}</span>
        </AdminCell>

        <AdminCell actions>
            <template v-if="!suspension.involved">
                <AdminActionButton :href="route('administrator.reports.show', { report: suspension.reportId })" :aria-label="`Report: the one behind the suspension of ${who}`">Report</AdminActionButton>
                <AdminActionButton
                    :tone="suspension.canLift || suspension.canDecideAppeal ? 'primary' : 'default'"
                    :aria-label="`${actionLabel}: the suspension of ${who}`"
                    @click="$emit('open', suspension)"
                >{{ actionLabel }}</AdminActionButton>
            </template>
        </AdminCell>
    </AdminRow>
</template>
