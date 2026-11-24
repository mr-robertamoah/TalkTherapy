<script setup>
// TT-7.11e2/SCRUM-477: the optional "Also waive the platform fee" part of approving (or adding) a counsellor on the
// admin recognition page -- off by default; ticking it shows the period (typed and sent as UTC, labelled GMT) and the
// reason, prefilled by the page (waiverPrefill). The page checks the fields (waiverOptionErrors) before it posts the
// approval, then posts the period once the approval is saved.
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import TextInput from '@/Components/TextInput.vue'
import { ref } from 'vue'

const option = defineModel({ type: Object, required: true }) // { enabled, starts_at, ends_at, reason }

defineProps({
    // Makes the field ids unique when more than one could be on the page.
    idPrefix: { type: String, required: true },
    disabled: { type: Boolean, default: false },
    // Field => message, from waiverOptionErrors().
    errors: { type: Object, default: () => ({}) },
    reasonMax: { type: Number, required: true },
})

const checkbox = ref(null)

// The page moves focus here when the approve panel opens.
defineExpose({ focus: () => checkbox.value?.focus() })
</script>

<template>
    <div>
        <label :for="`${idPrefix}-enabled`" class="inline-flex items-center gap-2 text-sm text-gray-800 min-h-[44px] md:min-h-0">
            <input
                :id="`${idPrefix}-enabled`"
                ref="checkbox"
                v-model="option.enabled"
                type="checkbox"
                :disabled="disabled"
                class="rounded border-gray-300 text-gray-800 shadow-sm focus:ring-gray-500"
            />
            Also waive the platform fee
        </label>

        <div v-if="option.enabled" class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <InputLabel :for="`${idPrefix}-starts`" value="Starts (UTC/GMT)" />
                <TextInput
                    :id="`${idPrefix}-starts`"
                    v-model="option.starts_at"
                    type="datetime-local"
                    required
                    :disabled="disabled"
                    class="mt-1 block w-full text-sm"
                    :aria-invalid="errors.starts_at ? 'true' : undefined"
                    :aria-describedby="errors.starts_at ? `${idPrefix}-starts-error` : undefined"
                />
                <InputError :id="`${idPrefix}-starts-error`" class="mt-1" :message="errors.starts_at" />
            </div>
            <div>
                <InputLabel :for="`${idPrefix}-ends`" value="Ends (UTC/GMT)" />
                <TextInput
                    :id="`${idPrefix}-ends`"
                    v-model="option.ends_at"
                    type="datetime-local"
                    required
                    :disabled="disabled"
                    class="mt-1 block w-full text-sm"
                    :aria-invalid="errors.ends_at ? 'true' : undefined"
                    :aria-describedby="errors.ends_at ? `${idPrefix}-ends-error` : undefined"
                />
                <InputError :id="`${idPrefix}-ends-error`" class="mt-1" :message="errors.ends_at" />
            </div>
            <div class="sm:col-span-2">
                <InputLabel :for="`${idPrefix}-reason`" value="Reason (administrators only)" />
                <TextInput
                    :id="`${idPrefix}-reason`"
                    v-model="option.reason"
                    type="text"
                    :maxlength="reasonMax"
                    required
                    :disabled="disabled"
                    class="mt-1 block w-full text-sm"
                    :aria-invalid="errors.reason ? 'true' : undefined"
                    :aria-describedby="errors.reason ? `${idPrefix}-reason-error` : undefined"
                />
                <InputError :id="`${idPrefix}-reason-error`" class="mt-1" :message="errors.reason" />
            </div>
            <p class="sm:col-span-2 text-xs text-gray-500">No platform commission on this counsellor's payments in this period. The approval is saved first; if the period is refused (an overlap, an open report), the approval stays and you'll be told why.</p>
        </div>
    </div>
</template>
