<script setup>
// TT-7.11e2/SCRUM-477: the optional "Also waive the platform fee" part of approving (or adding) a counsellor on the
// admin recognition page -- off by default; ticking it shows the period (typed and sent as UTC, labelled GMT) and the
// reason, prefilled by the page (waiverPrefill). The page posts the period after the approval succeeds.
const option = defineModel({ type: Object, required: true }) // { enabled, starts_at, ends_at, reason }

defineProps({
    // Makes the field ids unique when more than one could be on the page.
    idPrefix: { type: String, required: true },
    disabled: { type: Boolean, default: false },
})
</script>

<template>
    <div>
        <label :for="`${idPrefix}-enabled`" class="inline-flex items-center gap-2 text-sm text-gray-800 min-h-[44px] md:min-h-0">
            <input
                :id="`${idPrefix}-enabled`"
                v-model="option.enabled"
                type="checkbox"
                :disabled="disabled"
                class="rounded border-gray-300 text-gray-800 shadow-sm focus:ring-gray-500"
                :aria-controls="`${idPrefix}-fields`"
                :aria-expanded="option.enabled ? 'true' : 'false'"
            />
            Also waive the platform fee
        </label>

        <div v-show="option.enabled" :id="`${idPrefix}-fields`" class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <label :for="`${idPrefix}-starts`" class="block text-xs font-medium text-gray-700">Starts (UTC/GMT)</label>
                <input
                    :id="`${idPrefix}-starts`"
                    v-model="option.starts_at"
                    type="datetime-local"
                    :required="option.enabled"
                    :disabled="disabled"
                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500"
                />
            </div>
            <div>
                <label :for="`${idPrefix}-ends`" class="block text-xs font-medium text-gray-700">Ends (UTC/GMT)</label>
                <input
                    :id="`${idPrefix}-ends`"
                    v-model="option.ends_at"
                    type="datetime-local"
                    :required="option.enabled"
                    :disabled="disabled"
                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500"
                />
            </div>
            <div class="sm:col-span-2">
                <label :for="`${idPrefix}-reason`" class="block text-xs font-medium text-gray-700">Reason (administrators only)</label>
                <input
                    :id="`${idPrefix}-reason`"
                    v-model="option.reason"
                    type="text"
                    maxlength="500"
                    :required="option.enabled"
                    :disabled="disabled"
                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500"
                />
            </div>
            <p class="sm:col-span-2 text-xs text-gray-500">No platform commission on this counsellor's payments in this period. The approval is saved first; if the period is refused (an overlap, an open report), the approval stays and you'll be told why.</p>
        </div>
    </div>
</template>
