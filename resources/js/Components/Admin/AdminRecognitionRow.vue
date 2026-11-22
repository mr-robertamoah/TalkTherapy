<script setup>
// TT-7.11d2/SCRUM-474: one counsellor on the admin recognition page -- a suggestion, or a row decided this month that
// is no longer suggested ("others"). The actions (super admin only) are a two-click confirm, as on Payouts; the page
// does the posting.
import AdminRow from '@/Components/Admin/AdminRow.vue'
import AdminCell from '@/Components/Admin/AdminCell.vue'
import AdminStatusPill from '@/Components/Admin/AdminStatusPill.vue'
import AdminActionButton from '@/Components/Admin/AdminActionButton.vue'
import { formatUtc } from '@/Support/commissionFree.js'
import { consentPill, decisionPill, recognitionAlerts } from '@/Support/recognition.js'
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps({
    row: { type: Object, required: true },
    // A suggestion can be approved; an "others" row only re-approved when it was skipped (the page decides).
    canApprove: { type: Boolean, default: false },
    canSkip: { type: Boolean, default: false },
    // Which action is waiting for its second click on this row: 'approve' | 'skip' | null.
    confirming: { type: String, default: null },
    busy: { type: Boolean, default: false },
})

const emit = defineEmits(['ask', 'confirm', 'cancel'])

const alerts = computed(() => recognitionAlerts(props.row.flags))
const decision = computed(() => decisionPill(props.row.decision))
const consent = computed(() => consentPill(props.row.consent))
const name = computed(() => props.row.name ?? 'Deleted counsellor')
</script>

<template>
    <AdminRow>
        <AdminCell label="Rank" half>
            <span class="font-semibold text-gray-900">{{ row.rank ?? '--' }}</span>
            <AdminStatusPill v-if="row.tiedAtCutoff" class="ml-2" label="tied at cutoff" tone="indigo" />
        </AdminCell>
        <AdminCell label="Counsellor">
            <Link
                v-if="!row.flags?.deleted"
                :href="route('counsellor.show', { counsellorId: row.counsellorId })"
                class="font-medium text-gray-900 underline decoration-gray-300 hover:decoration-gray-700"
            >{{ name }}</Link>
            <span v-else class="font-medium text-gray-500">{{ name }}</span>
            <div v-if="row.addedManually" class="mt-1"><AdminStatusPill label="added manually" tone="blue" /></div>
        </AdminCell>
        <AdminCell label="Stars" half>{{ row.stars ?? '--' }}</AdminCell>
        <AdminCell label="First star" half><span class="whitespace-nowrap">{{ row.firstStarAt ? formatUtc(row.firstStarAt) : '--' }}</span></AdminCell>
        <AdminCell label="Alerts">
            <div v-if="alerts.length" class="flex flex-wrap gap-1">
                <AdminStatusPill v-for="alert in alerts" :key="alert.label" :label="alert.label" :tone="alert.tone" />
            </div>
            <span v-else class="text-gray-400">none</span>
        </AdminCell>
        <AdminCell label="Decision" half>
            <div class="flex flex-wrap gap-1">
                <AdminStatusPill :label="decision.label" :tone="decision.tone" />
                <AdminStatusPill v-if="consent" :label="consent.label" :tone="consent.tone" />
            </div>
        </AdminCell>
        <AdminCell actions>
            <template v-if="confirming">
                <AdminActionButton
                    :tone="confirming === 'skip' ? 'danger' : 'primary'"
                    :disabled="busy"
                    :aria-label="`Confirm ${confirming} ${name}`"
                    @click="emit('confirm', confirming)"
                >confirm {{ confirming }}</AdminActionButton>
                <AdminActionButton :disabled="busy" @click="emit('cancel')">cancel</AdminActionButton>
            </template>
            <template v-else>
                <AdminActionButton v-if="canApprove" :aria-label="`Approve ${name}`" @click="emit('ask', 'approve')">approve</AdminActionButton>
                <AdminActionButton v-if="canSkip" :aria-label="`Skip ${name}`" @click="emit('ask', 'skip')">skip</AdminActionButton>
            </template>
        </AdminCell>
    </AdminRow>
</template>
