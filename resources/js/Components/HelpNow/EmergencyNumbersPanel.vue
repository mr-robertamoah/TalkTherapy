<script setup>
// SCRUM-33, extracted for SCRUM-32: the emergency number(s) for a country (verified numbers only; none
// means "contact your local emergency services", never a guess), with a country picker. Shared state
// with the "I need help now" dialog (useHelpNow), so it loads once -- in a counsellor's safety flag it
// starts on their own country, and changing it there changes the dialog's too (one list of numbers).
import { defineAsyncComponent, onMounted, ref, watch } from 'vue'
import useHelpNow from '@/Composables/useHelpNow'
// Loaded only when "Change country" is used: the full list isn't sent to every page.
const CountrySelect = defineAsyncComponent(() => import('@/Components/CountrySelect.vue'))

const props = defineProps({
    heading: { type: String, default: 'If you or someone else is in immediate danger' },
    inputId: { type: String, default: 'emergency-numbers-country' },
    countryLabel: { type: String, default: 'Your country' },
})

const { numbers, numbersLoading, numbersFailed, loadNumbers } = useHelpNow()
const changingCountry = ref(false)
const countryCode = ref('')

watch(countryCode, (code) => {
    if (!code) return
    changingCountry.value = false
    loadNumbers(code)
})

onMounted(() => {
    if (!numbers.value.country && !numbersLoading.value) loadNumbers()
})

</script>

<template>
    <section class="rounded-lg border border-red-200 bg-red-50 p-4" :aria-labelledby="`${props.inputId}-heading`">
        <h3 :id="`${props.inputId}-heading`" class="text-sm font-semibold text-red-900">{{ heading }}</h3>
        <p v-if="numbersLoading" class="mt-2 text-sm text-red-900">Finding your emergency number…</p>
        <template v-else-if="numbers.numbers?.length">
            <p class="mt-1 text-sm text-red-900">Call now{{ numbers.country?.name ? ` (${numbers.country.name})` : '' }}:</p>
            <ul class="mt-2 space-y-2">
                <li v-for="number in numbers.numbers" :key="number.id">
                    <a :href="`tel:${number.number.replace(/[^0-9+]/g, '')}`" class="inline-flex min-h-[44px] items-center gap-2 rounded-md bg-red-700 px-4 text-base font-semibold text-white hover:bg-red-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-red-300">
                        {{ number.label }}: <span class="tabular-nums">{{ number.number }}</span>
                    </a>
                    <span v-if="number.notes" class="ml-2 text-xs text-red-900">{{ number.notes }}</span>
                </li>
            </ul>
            <p class="mt-2 text-xs text-red-900">Checked {{ numbers.numbers[0].verifiedAt }}.</p>
        </template>
        <p v-else class="mt-2 text-sm text-red-900">
            Contact your local emergency services{{ numbers.country?.name ? ` in ${numbers.country.name}` : '' }} now.
            <template v-if="numbersFailed"> (We couldn't load the number just now.)</template>
        </p>

        <div class="mt-3 text-sm">
            <button v-if="!changingCountry" type="button" class="font-medium text-red-900 underline" @click="changingCountry = true">
                Not in {{ numbers.country?.name ?? 'this country' }}? Change country
            </button>
            <div v-else>
                <label :for="props.inputId" class="block text-xs font-medium text-red-900">{{ countryLabel }}</label>
                <CountrySelect v-model="countryCode" by="code" :input-id="props.inputId" :clearable="false" class="mt-1" />
            </div>
        </div>
    </section>
</template>
