<script setup>
// TT-7.10b/SCRUM-468: the one-line notice counsellors and organisations see while a platform-wide commission-free
// period is running or coming up -- only its dates (the reason is for administrators).
import { computed } from 'vue'
import { commissionFreeNoticeText } from '@/Support/commissionFree.js'

const props = defineProps({
    notice: { type: Object, default: null },
})

const text = computed(() => commissionFreeNoticeText(props.notice))
</script>

<template>
    <p v-if="text" role="status" class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ text }}.</p>
</template>
