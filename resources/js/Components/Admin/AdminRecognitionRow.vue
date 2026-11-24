<script setup>
// TT-7.11d2/SCRUM-474: one counsellor on the admin recognition page -- a suggestion, or a row decided this month that
// is no longer suggested ("others"). The actions (super admin only) are a two-click confirm, as on Payouts; the page
// does the posting.
//
// TT-7.11e2/SCRUM-477: the "Fee waiver" column -- the period linked to this row's recognition (from the page props),
// its dates (GMT), status and a needs-review alert; for a super administrator its reason and "end now" / "delete"
// (two-click, too). Approving shows the page's `approve-options` slot (the optional waiver) in a row of its own below,
// with the confirm and cancel buttons.
import AdminRow from '@/Components/Admin/AdminRow.vue'
import AdminCell from '@/Components/Admin/AdminCell.vue'
import AdminStatusPill from '@/Components/Admin/AdminStatusPill.vue'
import AdminActionButton from '@/Components/Admin/AdminActionButton.vue'
import { formatUtc, waiverStatusPill } from '@/Support/commissionFree.js'
import { consentPill, decisionPill, recognitionAlerts } from '@/Support/recognition.js'
import { Link } from '@inertiajs/vue3'
import { computed, nextTick, ref, useSlots, watch } from 'vue'

// Two root elements (the row, and the approve panel under it): attributes go on the row.
defineOptions({ inheritAttrs: false })

const props = defineProps({
    row: { type: Object, required: true },
    // A suggestion can be approved; an "others" row only re-approved when it was skipped (the page decides).
    canApprove: { type: Boolean, default: false },
    canSkip: { type: Boolean, default: false },
    // Which action is waiting for its second click on this row: 'approve' | 'skip' | null.
    confirming: { type: String, default: null },
    busy: { type: Boolean, default: false },
    // The approve panel's confirm stays disabled while the waiver option has errors (the page checks it).
    confirmDisabled: { type: Boolean, default: false },
    // The page's feeWaivers entry for this row's recognition, or null. `redacted`: the viewer's own row -- only that
    // one exists.
    waiver: { type: Object, default: null },
    // Which waiver action is waiting for its second click: 'end' | 'delete' | null.
    waiverConfirming: { type: String, default: null },
    waiverBusy: { type: Boolean, default: false },
    // The number of columns, for the approve-options row's colspan.
    columnCount: { type: Number, required: true },
})

const emit = defineEmits(['ask', 'confirm', 'cancel', 'waiver-ask', 'waiver-confirm', 'waiver-cancel'])
const slots = useSlots()

const alerts = computed(() => recognitionAlerts(props.row.flags))
const decision = computed(() => decisionPill(props.row.decision))
const consent = computed(() => consentPill(props.row.consent))
const name = computed(() => props.row.name ?? 'Deleted counsellor')
const waiverStatus = computed(() => (props.waiver && !props.waiver.redacted ? waiverStatusPill(props.waiver.status) : null))
// Approving with options: the confirm step moves to the row below.
const approveBelow = computed(() => props.confirming === 'approve' && !!slots['approve-options'])

// When the panel opens, focus moves into it: to its first checkbox (the waiver option), else its heading.
const panel = ref(null)
const panelHeading = ref(null)

watch(approveBelow, async (open) => {
    if (!open) return
    await nextTick()
    const target = panel.value?.querySelector('input[type="checkbox"]:not([disabled])') ?? panelHeading.value
    target?.focus()
})
</script>

<template>
    <AdminRow v-bind="$attrs">
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
        <AdminCell label="Fee waiver">
            <span v-if="!waiver" class="text-gray-400">--</span>
            <span v-else-if="waiver.redacted" class="text-gray-700">waiver given</span>
            <div v-else class="space-y-1">
                <div class="text-gray-800">
                    <span class="whitespace-nowrap">{{ formatUtc(waiver.startsAt) }}</span>
                    <span class="text-gray-500"> to </span>
                    <span class="whitespace-nowrap">{{ waiver.endsAt ? formatUtc(waiver.endsAt) : 'until ended' }}</span>
                </div>
                <div class="flex flex-wrap gap-1">
                    <AdminStatusPill :label="waiverStatus.label" :tone="waiverStatus.tone" />
                    <AdminStatusPill v-if="waiver.needsReview" label="needs review" tone="red" />
                </div>
                <p v-if="waiver.reason" class="text-xs text-gray-500 break-words">{{ waiver.reason }}</p>
                <div v-if="waiver.canEnd || waiver.canDelete" class="flex flex-wrap gap-2 pt-1">
                    <template v-if="waiverConfirming">
                        <AdminActionButton
                            tone="danger"
                            :disabled="waiverBusy"
                            :aria-label="`Confirm ${waiverConfirming === 'end' ? 'end the fee waiver now' : 'delete the fee waiver'} for ${name}`"
                            @click="emit('waiver-confirm', waiverConfirming)"
                        >{{ waiverConfirming === 'end' ? 'confirm end now' : 'confirm delete' }}</AdminActionButton>
                        <AdminActionButton :disabled="waiverBusy" @click="emit('waiver-cancel')">cancel</AdminActionButton>
                    </template>
                    <template v-else>
                        <AdminActionButton v-if="waiver.canEnd" :aria-label="`End ${name}'s fee waiver now`" @click="emit('waiver-ask', 'end')">end now</AdminActionButton>
                        <AdminActionButton v-if="waiver.canDelete" tone="danger" :aria-label="`Delete ${name}'s fee waiver`" @click="emit('waiver-ask', 'delete')">delete</AdminActionButton>
                    </template>
                </div>
            </div>
        </AdminCell>
        <AdminCell actions>
            <span aria-live="polite" class="text-xs text-gray-500">{{ approveBelow ? 'confirm below' : '' }}</span>
            <template v-if="approveBelow" />
            <template v-else-if="confirming">
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
    <tr v-if="approveBelow" class="block md:table-row bg-gray-50 !border-t-0">
        <td ref="panel" :colspan="columnCount" class="block md:table-cell px-4 py-4">
            <p ref="panelHeading" tabindex="-1" class="text-sm font-medium text-gray-900 focus:outline-none">Approve {{ name }}</p>
            <div class="mt-3"><slot name="approve-options" /></div>
            <div class="mt-4 flex flex-wrap gap-2">
                <AdminActionButton tone="primary" :disabled="busy || confirmDisabled" :aria-label="`Confirm approve ${name}`" @click="emit('confirm', 'approve')">confirm approve</AdminActionButton>
                <AdminActionButton :disabled="busy" @click="emit('cancel')">cancel</AdminActionButton>
            </div>
        </td>
    </tr>
</template>
