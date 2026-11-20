<script setup>
// TT-7.10b/SCRUM-468: money whose platform fee was waived (a commission-free period) -- "Commission-free" for a
// counsellor's earning, "Waived" for an organisation's charge or invoice line. The amount waived is shown, not only on
// hover, so touch and screen-reader users get it too. Never which period, nor its reason.
import { computed } from 'vue'

const props = defineProps({
    label: { type: String, required: true },
    amount: { type: Number, default: null }, // minor units (pesewas/cents)
    currency: { type: String, default: null },
})

const amountText = computed(() => (props.amount != null && props.currency ? `${props.currency} ${(props.amount / 100).toFixed(2)}` : null))
const spoken = computed(() => (amountText.value ? `${props.label}: platform fee of ${amountText.value} waived` : `${props.label}: platform fee waived`))
</script>

<template>
    <span class="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-800 whitespace-nowrap" :title="spoken">
        <span aria-hidden="true">{{ label }}<template v-if="amountText"> · {{ amountText }} waived</template></span>
        <span class="sr-only">{{ spoken }}</span>
    </span>
</template>
