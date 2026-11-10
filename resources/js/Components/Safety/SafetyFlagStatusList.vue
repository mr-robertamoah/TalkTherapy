<script setup>
// SCRUM-32 (TT-4.3b): a therapy's (or group's, or help chat's) safety flags as its counsellors see them --
// who each is about, when it was raised and where it stands. Never the reason, the outcome or who is
// handling it. The counsellor who raised one can withdraw it (with a reason the handling administrator reads).
import { onMounted, ref } from 'vue'
import axios from 'axios'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'

const props = defineProps({
    // The statuses route URL.
    url: { type: String, required: true },
})

const flags = ref([])
const withdrawing = ref(null)
const reason = ref('')
const busy = ref(false)
const error = ref('')

async function load() {
    await axios.get(props.url).then((res) => { flags.value = res.data.flags }).catch(() => {})
}

async function withdraw(flag) {
    busy.value = true
    error.value = ''
    await axios.post(route('api.safety_flags.withdraw', { flag: flag.id }), { reason: reason.value })
        .then(() => { withdrawing.value = null; reason.value = ''; load() })
        .catch((err) => { error.value = err.response?.data?.message ?? 'It could not be withdrawn.' })
        .finally(() => { busy.value = false })
}

function formatWhen(value) {
    return value ? new Date(value).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''
}

defineExpose({ load })
onMounted(load)
</script>

<template>
    <div v-if="flags.length" class="mt-3 space-y-2">
        <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-600">Safety flags here</h4>
        <ul class="divide-y divide-gray-100 rounded-md border border-gray-200">
            <li v-for="flag in flags" :key="flag.id" class="p-3 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-gray-900">
                        {{ flag.about }} · {{ flag.urgency }} · <span class="text-gray-600">{{ formatWhen(flag.raisedAt) }}{{ flag.mine ? '' : ' · by another counsellor' }}</span>
                    </span>
                    <span class="rounded-full border border-gray-300 bg-gray-50 px-2 py-0.5 text-xs text-gray-800">{{ flag.statusLabel }}</span>
                </div>
                <div v-if="flag.canWithdraw" class="mt-2">
                    <SecondaryButton v-if="withdrawing !== flag.id" type="button" @click="withdrawing = flag.id; reason = ''; error = ''">Withdraw</SecondaryButton>
                    <form v-else class="space-y-2" @submit.prevent="withdraw(flag)">
                        <label :for="`withdraw-${flag.id}`" class="block text-xs font-medium text-gray-700">Why are you withdrawing it? (the administrator handling it sees this)</label>
                        <input :id="`withdraw-${flag.id}`" v-model="reason" type="text" maxlength="1000" required class="block w-full rounded-md border-gray-300 text-sm shadow-sm" />
                        <p v-if="error" class="text-sm text-red-700" role="alert">{{ error }}</p>
                        <div class="flex gap-2">
                            <SecondaryButton type="button" :disabled="busy" @click="withdrawing = null">Keep it</SecondaryButton>
                            <DangerButton :disabled="busy || reason.trim().length < 5">Withdraw the flag</DangerButton>
                        </div>
                    </form>
                </div>
            </li>
        </ul>
    </div>
</template>
