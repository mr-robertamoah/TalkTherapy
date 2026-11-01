<script setup>
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'

// SCRUM-359: one suspension as an admin sees it (AdminSuspensionResource), with lift and appeal
// actions when the viewer may use them. Used on the suspensions list and on a report's page.

const props = defineProps({
    suspension: { type: Object, required: true },
    showReportLink: { type: Boolean, default: true },
})

const emit = defineEmits(['done', 'failed'])

const statusClasses = {
    active: 'bg-amber-100 text-amber-800',
    lifted: 'bg-gray-100 text-gray-700',
    ended: 'bg-gray-100 text-gray-700',
}

const mode = ref(null) // 'lift' | 'appeal' | null
const liftForm = useForm({ reason: '' })
const appealForm = useForm({ decision: '', note: '' })

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : '--'
}

function options(message, form) {
    return {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            mode.value = null
            emit('done', message)
        },
        onError: (errors) => emit('failed', errors.alert ?? Object.values(errors)[0] ?? 'Something went wrong.'),
    }
}

function lift() {
    liftForm.post(route('admin.suspensions.lift', { suspension: props.suspension.id }), options('Suspension lifted.', liftForm))
}

function decide(decision) {
    appealForm.decision = decision
    appealForm.post(route('admin.suspensions.appeal', { suspension: props.suspension.id }), options('Appeal decided.', appealForm))
}
</script>

<template>
    <div class="border border-gray-200 rounded-md p-4 text-sm space-y-3">
        <div v-if="suspension.involved" class="flex flex-wrap items-center gap-2">
            <span class="px-2 py-0.5 rounded text-xs font-medium capitalize" :class="statusClasses[suspension.status]">{{ suspension.status }}</span>
            <span class="text-gray-600 italic">You are involved in this suspension, so another administrator handles it.</span>
        </div>

        <template v-else>
            <div class="flex flex-wrap items-center gap-2">
                <span class="px-2 py-0.5 rounded text-xs font-medium capitalize" :class="statusClasses[suspension.status]">{{ suspension.status }}</span>
                <span class="font-medium text-gray-900">{{ suspension.person.name }}</span>
                <span v-if="suspension.person.username" class="text-gray-500">@{{ suspension.person.username }}</span>
                <span class="text-gray-500">· {{ suspension.scopeLabel }}</span>
                <span v-if="suspension.appeal?.pending" class="px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">appeal waiting</span>
            </div>

            <dl class="grid grid-cols-1 sm:grid-cols-[8rem_1fr] gap-x-4 gap-y-1">
                <dt class="text-gray-500">From</dt><dd>{{ formatDate(suspension.startsAt) }}</dd>
                <dt class="text-gray-500">Until</dt><dd>{{ suspension.endsAt ? formatDate(suspension.endsAt) : 'lifted by an administrator' }}</dd>
                <dt class="text-gray-500">Applied by</dt><dd>{{ suspension.appliedBy }}</dd>
                <dt class="text-gray-500">Reason given</dt><dd class="whitespace-pre-line">{{ suspension.publicReason }}</dd>
                <template v-if="suspension.internalNote">
                    <dt class="text-gray-500">Internal note</dt><dd class="whitespace-pre-line">{{ suspension.internalNote }}</dd>
                </template>
                <template v-if="suspension.lifted">
                    <dt class="text-gray-500">Lifted</dt><dd>{{ formatDate(suspension.lifted.at) }} by {{ suspension.lifted.by }}: {{ suspension.lifted.reason }}</dd>
                </template>
                <template v-if="showReportLink">
                    <dt class="text-gray-500">Report</dt>
                    <dd><Link :href="route('administrator.reports.show', { report: suspension.reportId })" class="underline text-gray-700 hover:text-gray-900">#{{ suspension.reportId }}</Link></dd>
                </template>
            </dl>

            <div v-if="suspension.appeal" class="bg-gray-50 border border-gray-200 rounded-md p-3 space-y-1">
                <div class="text-gray-500">Appeal, {{ formatDate(suspension.appeal.at) }}</div>
                <div class="whitespace-pre-line text-gray-900">{{ suspension.appeal.text }}</div>
                <div v-if="suspension.appeal.decision" class="text-gray-700">
                    <span class="capitalize">{{ suspension.appeal.decision }}</span> by {{ suspension.appeal.decidedBy }}, {{ formatDate(suspension.appeal.decidedAt) }}: {{ suspension.appeal.note }}
                </div>
            </div>

            <div v-if="!mode && (suspension.canLift || suspension.canDecideAppeal)" class="flex flex-wrap gap-2">
                <SecondaryButton v-if="suspension.canDecideAppeal" @click="mode = 'appeal'">decide appeal</SecondaryButton>
                <DangerButton v-if="suspension.canLift" @click="mode = 'lift'">lift early</DangerButton>
            </div>

            <div v-if="mode === 'lift'" class="space-y-2">
                <InputLabel :for="`lift-${suspension.id}`" value="Why are you lifting it? (kept in the report history)" />
                <textarea :id="`lift-${suspension.id}`" v-model="liftForm.reason" rows="2" maxlength="2000" class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"></textarea>
                <div class="flex gap-2">
                    <PrimaryButton :disabled="liftForm.processing || liftForm.reason.trim().length < 10" @click="lift">lift</PrimaryButton>
                    <SecondaryButton @click="mode = null">cancel</SecondaryButton>
                </div>
            </div>

            <div v-if="mode === 'appeal'" class="space-y-2">
                <InputLabel :for="`appeal-${suspension.id}`" value="Note for the person (they are emailed it)" />
                <textarea :id="`appeal-${suspension.id}`" v-model="appealForm.note" rows="2" maxlength="2000" class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"></textarea>
                <div class="flex flex-wrap gap-2">
                    <PrimaryButton :disabled="appealForm.processing || appealForm.note.trim().length < 10" @click="decide('lifted')">accept and lift</PrimaryButton>
                    <SecondaryButton :disabled="appealForm.processing || appealForm.note.trim().length < 10" @click="decide('upheld')">uphold</SecondaryButton>
                    <SecondaryButton @click="mode = null">cancel</SecondaryButton>
                </div>
            </div>
        </template>
    </div>
</template>
