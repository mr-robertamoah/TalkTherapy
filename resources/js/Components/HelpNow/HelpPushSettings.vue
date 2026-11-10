<script setup>
// SCRUM-399: "Alert this device when I'm available" -- web push for urgent help rings, per device. Shown on the
// counsellor dashboard's urgent help card when it's switched on for the platform.
import { computed, onMounted } from 'vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import useWebPush, { needsHomeScreen, pushSupported } from '@/Composables/useWebPush'

const { info, thisDeviceOn, busy, error, load, enable, disable, removeDevice } = useWebPush()

const supported = pushSupported()
const homeScreen = needsHomeScreen()
const otherDevices = computed(() => info.value.devices.filter((device) => !device.current))

function when(value) {
    return value ? new Date(value).toLocaleDateString() : null
}

onMounted(load)
</script>

<template>
    <div v-if="info.enabled" class="mt-4 border-t border-gray-100 pt-4 space-y-3" role="region" aria-labelledby="help-push-title">
        <h4 id="help-push-title" class="text-sm font-semibold text-gray-900">Alerts on this device</h4>
        <p class="text-sm text-gray-600">
            Get a notification when someone needs urgent help, even with TalkTherapy closed. It says only that someone needs help.
            Alerts can be delayed or silenced by your device, so they add to the ring in the app; they don't replace it.
        </p>

        <p v-if="homeScreen" class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-800">
            On iPhone and iPad, add TalkTherapy to your Home Screen first (Share, then Add to Home Screen), and open it from there to turn alerts on.
        </p>
        <p v-else-if="!supported" class="text-sm text-gray-600">This browser can't show alerts.</p>
        <div v-else class="flex flex-wrap items-center gap-2">
            <PrimaryButton v-if="!thisDeviceOn" type="button" :disabled="busy" @click="enable">{{ busy ? 'Turning on…' : 'Alert this device' }}</PrimaryButton>
            <template v-else>
                <span class="rounded-full border border-green-200 bg-green-50 px-2 py-0.5 text-xs font-medium text-green-800">This device is alerted</span>
                <SecondaryButton type="button" :disabled="busy" @click="disable">Turn off on this device</SecondaryButton>
            </template>
        </div>
        <p v-if="error" class="text-sm text-red-700" role="alert">{{ error }}</p>

        <div v-if="otherDevices.length">
            <p class="text-sm font-medium text-gray-800">Your other devices</p>
            <ul class="mt-1 divide-y divide-gray-100 rounded-md border border-gray-200">
                <li v-for="device in otherDevices" :key="device.id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                    <span class="text-gray-800">{{ device.label }} <span class="text-gray-500">· added {{ when(device.addedAt) }}<template v-if="device.lastUsedAt"> · last alerted {{ when(device.lastUsedAt) }}</template></span></span>
                    <button type="button" class="text-sm text-gray-600 underline hover:text-gray-900" @click="removeDevice(device.id)">Remove</button>
                </li>
            </ul>
        </div>
    </div>
</template>
