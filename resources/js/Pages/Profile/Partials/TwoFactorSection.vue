<script setup>
// SCRUM-34: two-factor sign-in on the profile -- turn it on (password, scan the QR, type a code, keep the recovery
// codes), make new recovery codes or turn it off (each: password and a code). Recovery codes are shown once, here.
import { computed, nextTick, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import TextInput from '@/Components/TextInput.vue'

const page = usePage()
const enabled = computed(() => !!page.props.auth?.user?.twoFactorEnabled)
// SCRUM-424: a reset the team scheduled, still waiting to apply.
const resetDueAt = computed(() => page.props.auth?.user?.twoFactorResetDueAt)
const cancellingReset = ref(false)
const resetError = ref('')

async function cancelReset() {
    if (cancellingReset.value) return
    cancellingReset.value = true
    resetError.value = ''
    try {
        await axios.delete(route('two_factor.reset.cancel'))
        router.reload({ only: ['auth'] })
    } catch (error) {
        resetError.value = error?.response?.data?.message || 'Something went wrong. Please try again.'
    } finally {
        cancellingReset.value = false
    }
}

// idle | password (for: setup, codes, disable) | scan | codes
const step = ref('idle')
const purpose = ref(null)
const busy = ref(false)
const password = ref('')
const code = ref('')
const errors = ref({})
const setup = ref(null) // { secret, qrSvg }
const recoveryCodes = ref([])
const copied = ref(false)
const passwordInput = ref(null)
const codeInput = ref(null)

function reset() {
    step.value = 'idle'
    purpose.value = null
    password.value = ''
    code.value = ''
    errors.value = {}
    setup.value = null
    copied.value = false
}

function ask(forWhat) {
    reset()
    purpose.value = forWhat
    step.value = 'password'
    nextTick(() => passwordInput.value?.focus())
}

function failed(error) {
    const data = error?.response?.data ?? {}
    const fieldErrors = Object.fromEntries(Object.entries(data.errors ?? {}).map(([key, value]) => [key, Array.isArray(value) ? value[0] : value]))
    errors.value = Object.keys(fieldErrors).length ? fieldErrors : { alert: data.message || 'Something went wrong. Please try again.' }
}

async function submitPassword() {
    if (busy.value) return
    busy.value = true
    errors.value = {}
    try {
        if (purpose.value === 'setup') {
            const { data } = await axios.post(route('two_factor.setup'), { current_password: password.value })
            setup.value = data
            step.value = 'scan'
            nextTick(() => codeInput.value?.focus())
        } else if (purpose.value === 'codes') {
            const { data } = await axios.post(route('two_factor.recovery_codes'), { current_password: password.value, code: code.value })
            showCodes(data.recoveryCodes)
        } else {
            await axios.delete(route('two_factor.destroy'), { data: { current_password: password.value, code: code.value } })
            reset()
            router.reload({ only: ['auth'] })
        }
        password.value = ''
    } catch (error) {
        failed(error)
    } finally {
        busy.value = false
    }
}

async function confirm() {
    if (busy.value) return
    busy.value = true
    errors.value = {}
    try {
        const { data } = await axios.post(route('two_factor.confirm'), { code: code.value })
        setup.value = null
        showCodes(data.recoveryCodes)
        router.reload({ only: ['auth'] })
    } catch (error) {
        failed(error)
    } finally {
        busy.value = false
    }
}

function showCodes(codes) {
    recoveryCodes.value = codes
    code.value = ''
    step.value = 'codes'
}

async function copyCodes() {
    try {
        await navigator.clipboard.writeText(recoveryCodes.value.join('\n'))
        copied.value = true
    } catch {
        copied.value = false
    }
}

// The user's TODO: keep the codes as a file too. Made in the browser -- they never travel again.
function downloadCodes() {
    const text = ['TalkTherapy two-factor recovery codes', 'Each one signs you in once. Keep them somewhere safe.', '', ...recoveryCodes.value, ''].join('\n')
    const url = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }))
    const link = Object.assign(document.createElement('a'), { href: url, download: 'talktherapy-recovery-codes.txt' })
    document.body.appendChild(link)
    link.click()
    link.remove()
    // Later, not at once: some browsers abort a download whose address is revoked straight away.
    setTimeout(() => URL.revokeObjectURL(url), 1000)
}

function doneWithCodes() {
    recoveryCodes.value = []
    reset()
}

// The server-drawn SVG as an image, not inserted as HTML (security review: no markup sink).
const qrSrc = computed(() => (setup.value?.qrSvg ? `data:image/svg+xml;base64,${btoa(setup.value.qrSvg)}` : ''))

const passwordTitle = computed(() => ({
    setup: 'Confirm it\'s you to start',
    codes: 'Confirm it\'s you to make new recovery codes',
    disable: 'Confirm it\'s you to turn two-factor sign-in off',
}[purpose.value] ?? ''))
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">Two-Factor Sign-In</h2>
            <p class="mt-1 text-sm text-gray-600">
                Add a second step when you sign in: a 6-digit code from an authenticator app on your phone (such as
                Google Authenticator, Microsoft Authenticator or 1Password). Someone who learns your password still
                can't get in without it. Turning it on or off, or making new recovery codes, signs you out on your other
                devices.
            </p>
            <p class="mt-3 text-sm">
                Status:
                <span v-if="enabled" class="font-medium text-green-700">on</span>
                <span v-else class="font-medium text-gray-700">off</span>
            </p>
        </header>

        <!-- SCRUM-424: a reset the team scheduled. -->
        <div v-if="enabled && resetDueAt" role="status" class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            <p>
                <span class="font-medium">A reset of your two-factor sign-in is scheduled</span> for
                {{ new Date(resetDueAt).toLocaleString() }}. If you didn't ask the TalkTherapy team for it, cancel it and
                change your password.
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <SecondaryButton type="button" :disabled="cancellingReset" @click="cancelReset">Cancel the reset</SecondaryButton>
            </div>
            <InputError class="mt-2" :message="resetError" />
        </div>

        <!-- Not started -->
        <div v-if="step === 'idle'" class="mt-6 flex flex-wrap gap-3">
            <PrimaryButton v-if="!enabled" @click="ask('setup')">Turn on</PrimaryButton>
            <template v-else>
                <SecondaryButton @click="ask('codes')">New recovery codes</SecondaryButton>
                <DangerButton @click="ask('disable')">Turn off</DangerButton>
            </template>
        </div>

        <!-- Password (and a code, to turn off) -->
        <form v-else-if="step === 'password'" class="mt-6 space-y-4" @submit.prevent="submitPassword">
            <p class="text-sm font-medium text-gray-800">{{ passwordTitle }}</p>
            <div>
                <InputLabel for="two_factor_password" value="Your password" />
                <TextInput
                    id="two_factor_password"
                    ref="passwordInput"
                    v-model="password"
                    type="password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                />
                <InputError class="mt-2" :message="errors.current_password" />
            </div>
            <div v-if="purpose !== 'setup'">
                <InputLabel for="two_factor_code" value="A code from your app, or a recovery code" />
                <TextInput
                    id="two_factor_code"
                    v-model="code"
                    type="text"
                    class="mt-1 block w-full"
                    autocomplete="one-time-code"
                    maxlength="20"
                />
                <InputError class="mt-2" :message="errors.code" />
            </div>
            <InputError :message="errors.alert" />
            <div class="flex items-center gap-3">
                <PrimaryButton type="submit" :disabled="busy || !password || (purpose !== 'setup' && !code)">Continue</PrimaryButton>
                <SecondaryButton type="button" :disabled="busy" @click="reset">Cancel</SecondaryButton>
            </div>
        </form>

        <!-- Scan and confirm -->
        <form v-else-if="step === 'scan'" class="mt-6 space-y-4" @submit.prevent="confirm">
            <ol class="list-decimal space-y-1 pl-5 text-sm text-gray-700">
                <li>Open your authenticator app and add an account.</li>
                <li>Scan this code, or type the key below instead.</li>
                <li>Enter the 6-digit code the app shows.</li>
            </ol>
            <img :src="qrSrc" alt="QR code to scan with your authenticator app" width="192" height="192" class="rounded-lg border border-gray-200 bg-white p-3" />
            <div>
                <p class="text-xs text-gray-600">Key</p>
                <code class="mt-1 block break-all rounded-md border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-sm text-gray-900">{{ setup.secret }}</code>
            </div>
            <div>
                <InputLabel for="two_factor_confirm_code" value="6-digit code" />
                <TextInput
                    id="two_factor_confirm_code"
                    ref="codeInput"
                    v-model="code"
                    type="text"
                    inputmode="numeric"
                    class="mt-1 block w-40"
                    autocomplete="one-time-code"
                    maxlength="7"
                />
                <InputError class="mt-2" :message="errors.code" />
            </div>
            <InputError :message="errors.alert" />
            <div class="flex items-center gap-3">
                <PrimaryButton type="submit" :disabled="busy || !code">Turn on</PrimaryButton>
                <SecondaryButton type="button" :disabled="busy" @click="reset">Cancel</SecondaryButton>
            </div>
        </form>

        <!-- Recovery codes, shown once -->
        <div v-else-if="step === 'codes'" class="mt-6 space-y-4">
            <div role="status" class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                <span class="font-medium">Save these recovery codes somewhere safe.</span>
                Each one signs you in once if you lose your phone. They won't be shown again.
            </div>
            <ul class="grid grid-cols-2 gap-2 rounded-md border border-gray-200 bg-gray-50 p-4 font-mono text-sm text-gray-900">
                <li v-for="recoveryCode in recoveryCodes" :key="recoveryCode">{{ recoveryCode }}</li>
            </ul>
            <div class="flex flex-wrap items-center gap-3">
                <SecondaryButton type="button" @click="copyCodes">Copy</SecondaryButton>
                <SecondaryButton type="button" @click="downloadCodes">Download</SecondaryButton>
                <PrimaryButton type="button" @click="doneWithCodes">I've saved them</PrimaryButton>
                <p v-if="copied" class="text-sm text-gray-600" role="status">Copied.</p>
            </div>
        </div>
    </section>
</template>
