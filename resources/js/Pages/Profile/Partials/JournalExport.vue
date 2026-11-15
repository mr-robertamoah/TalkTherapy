<script setup>
// SCRUM-38 (TT-5.3): export the journal as a PDF -- all of it or a date range -- after the password (and the
// two-step sign-in code when it's on), as "Download a copy" asks. Fetched, so a wrong password shows here.
import { nextTick, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import TextInput from '@/Components/TextInput.vue'

const props = defineProps({
    gate: { type: Object, required: true },
})

const open = ref(false)
const busy = ref(false)
const done = ref(false)
const form = ref({ current_password: '', code: '', from: '', to: '' })
const errors = ref({})
const passwordInput = ref(null)
const openButton = ref(null)

function show() {
    open.value = true
    done.value = false
    nextTick(() => passwordInput.value?.focus())
}

function close() {
    open.value = false
    form.value = { current_password: '', code: '', from: '', to: '' }
    errors.value = {}
    nextTick(() => openButton.value?.$el?.focus())
}

async function exportPdf() {
    busy.value = true
    errors.value = {}

    try {
        const response = await axios.post(route('profile.journal.export'), {
            ...form.value,
            from: form.value.from || null,
            to: form.value.to || null,
        }, { responseType: 'blob' })
        const name = /filename="?([^";]+)"?/.exec(response.headers['content-disposition'] ?? '')?.[1] ?? 'talktherapy-journal.pdf'
        const url = URL.createObjectURL(response.data)
        const link = document.createElement('a')
        link.href = url
        link.download = name
        document.body.appendChild(link)
        link.click()
        link.remove()
        setTimeout(() => URL.revokeObjectURL(url), 1000)
        close()
        done.value = true
    } catch (error) {
        const status = error.response?.status
        let body = {}
        try { body = JSON.parse(await error.response?.data?.text?.()) } catch { body = {} }

        if (status === 422) {
            errors.value = Object.fromEntries(Object.entries(body.errors ?? {}).map(([key, messages]) => [key, messages[0]]))
        } else if (status === 429) {
            errors.value = { form: 'You have tried to export too many times. Please try again in an hour.' }
        } else {
            errors.value = { form: 'Something went wrong. Please try again later.' }
        }
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <div class="w-full sm:w-auto">
        <p v-if="!gate.passwordSet" class="text-sm text-gray-600">
            To export your journal, <Link :href="`${route('privacy')}#contact`" class="underline">contact us</Link> -- your account has no password to confirm it's you.
        </p>
        <p v-else-if="gate.emailChangeWaiting" class="text-sm text-amber-900">
            A new email address is waiting on your account. You can export your journal once that change is finished or stopped.
        </p>
        <template v-else>
            <p role="status" class="text-sm text-green-800" :class="{ 'mb-2': done }">{{ done ? "Your journal has downloaded as a PDF. We've let you know on your account." : '' }}</p>
            <SecondaryButton v-if="!open" ref="openButton" type="button" @click="show">Export as PDF</SecondaryButton>

            <form v-else class="mt-2 w-full sm:w-96 space-y-3 bg-white shadow-sm border border-gray-200 rounded-lg p-4" aria-labelledby="journal-export-title" @submit.prevent="exportPdf">
                <h4 id="journal-export-title" class="font-semibold text-gray-900">Export your journal as a PDF</h4>
                <p class="text-sm text-gray-700">
                    The file has your entries' full text, so keep it somewhere safe. Confirm it's you{{ gate.twoFactorEnabled ? ' with your password and a two-step sign-in code' : ' with your password' }}.
                    Leave the dates empty for every entry (up to {{ gate.maxEntries }}).
                </p>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel for="journal-export-from" value="From (optional)" />
                        <TextInput id="journal-export-from" v-model="form.from" type="date" class="mt-1 block w-full" />
                        <InputError role="alert" class="mt-1" :message="errors.from" />
                    </div>
                    <div>
                        <InputLabel for="journal-export-to" value="To (optional)" />
                        <TextInput id="journal-export-to" v-model="form.to" type="date" class="mt-1 block w-full" />
                        <InputError role="alert" class="mt-1" :message="errors.to" />
                    </div>
                </div>
                <div>
                    <InputLabel for="journal-export-password" value="Password" />
                    <TextInput id="journal-export-password" ref="passwordInput" v-model="form.current_password" type="password" class="mt-1 block w-full" autocomplete="current-password" required />
                    <InputError role="alert" class="mt-1" :message="errors.current_password" />
                </div>
                <div v-if="gate.twoFactorEnabled">
                    <InputLabel for="journal-export-code" value="Two-step sign-in code (or a recovery code)" />
                    <TextInput id="journal-export-code" v-model="form.code" type="text" class="mt-1 block w-full" autocomplete="one-time-code" required />
                    <InputError role="alert" class="mt-1" :message="errors.code" />
                </div>
                <InputError role="alert" :message="errors.form" />
                <div class="flex gap-3">
                    <PrimaryButton :disabled="busy" :class="{ 'opacity-25': busy }">{{ busy ? 'Preparing…' : 'Export' }}</PrimaryButton>
                    <SecondaryButton type="button" :disabled="busy" @click="close">Cancel</SecondaryButton>
                </div>
            </form>
        </template>
    </div>
</template>
