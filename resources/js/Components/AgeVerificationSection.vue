<script setup>
import { ref } from 'vue';
import InputLabel from './InputLabel.vue';
import InputError from './InputError.vue';
import TextBox from './TextBox.vue';
import PrimaryButton from './PrimaryButton.vue';
import SecondaryButton from './SecondaryButton.vue';
import FormLoader from './FormLoader.vue';
import Alert from './Alert.vue';
import useAlert from '@/Composables/useAlert';
import useAuth from '@/Composables/useAuth';

const props = defineProps({
    // TT-4.11e/SCRUM-306 closeout QA finding (two passes): without this, `submitted` was purely
    // local, in-session state initialized to `false` on every fresh page load -- a user with a
    // genuinely already-submitted request saw "submit a statement" (implying none exists) rather
    // than "submit another statement." Deliberately "has ever submitted," not "has a pending
    // one" -- the first fix scoped this to pending-only, which left the label reverting back to
    // "submit a statement" the moment an admin decided the request, the same misleading-label bug
    // just shifted to a different state transition. "submit another statement" is accurate
    // whether the prior submission is still pending, accepted, or rejected.
    hasSubmittedRequest: {
        type: Boolean,
        default: false,
    },
})

const { goToLogin } = useAuth()
const { alertData, clearAlertData, setSuccessAlertData, setFailedAlertData } = useAlert()

const open = ref(false)
const submitting = ref(false)
const submitted = ref(props.hasSubmittedRequest)
const attestation = ref('')
const document = ref(null)
const documentInput = ref(null)
const errors = ref({})

function openForm() {
    open.value = true
}

// The native <input type="file"> is visually hidden (see the template) -- this button-triggered
// pattern (hidden input + a styled button that proxies the click) mirrors ImageUploadField.vue's
// own convention elsewhere in this app, rather than the raw OS file-picker button, which doesn't
// match this form's own styled inputs/buttons.
function triggerDocumentInput() {
    documentInput.value?.click()
}

function onDocumentChange(event) {
    document.value = event.target.files[0] ?? null
}

function clearDocument() {
    document.value = null
    if (documentInput.value) documentInput.value.value = ''
}

async function submit() {
    submitting.value = true
    errors.value = {}

    const formData = new FormData()
    formData.append('attestation', attestation.value)
    if (document.value) formData.append('document', document.value)

    await axios.post(route('api.users.age-verification'), formData)
        .then(() => {
            submitted.value = true
            open.value = false
            attestation.value = ''
            clearDocument()
            setSuccessAlertData({
                message: 'Your submission has been sent for review. Thank you.',
            })
        })
        .catch((err) => {
            goToLogin(err)

            if (err.response?.status == 422) {
                errors.value = err.response.data.errors ?? {}
                return
            }

            setFailedAlertData({
                message: err.response?.data?.message ?? 'Something unfortunate happened. Please try again later.',
            })
        })

    submitting.value = false
}
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">Age Verification</h2>

            <p class="mt-1 text-sm text-gray-600">
                Optionally strengthen your account's date of birth with a short statement and, if you'd
                like, a supporting document. This is entirely optional and reviewed only by an
                administrator -- it never delays or blocks your access to therapy.
            </p>
        </header>

        <FormLoader :text="'submitting'" :show="submitting" />

        <div v-if="submitted && !open" class="mt-4 p-4 bg-indigo-50 border border-indigo-200 rounded-lg text-sm text-indigo-700">
            Your submission has been sent to an administrator for review.
        </div>

        <PrimaryButton v-if="!open" @click="openForm" class="mt-4">
            {{ submitted ? 'submit another statement' : 'submit a statement' }}
        </PrimaryButton>

        <form v-if="open" @submit.prevent="submit" class="mt-6 space-y-6">
            <div>
                <InputLabel for="attestation" value="Your statement" />

                <TextBox
                    id="attestation"
                    rows="4"
                    class="mt-1 block w-full"
                    v-model="attestation"
                    placeholder="e.g. I confirm that the date of birth on my account is accurate."
                />

                <InputError class="mt-2" :message="errors.attestation?.[0]" />
            </div>

            <div>
                <InputLabel for="document" value="Supporting document (optional)" />

                <div class="mt-1 flex items-center gap-3">
                    <SecondaryButton type="button" @click="triggerDocumentInput">
                        {{ document ? 'change file' : 'choose file' }}
                    </SecondaryButton>

                    <span v-if="document" class="text-sm text-gray-600 truncate max-w-[12rem]">{{ document.name }}</span>
                    <span v-else class="text-sm text-gray-400">no file selected</span>

                    <button
                        v-if="document"
                        type="button"
                        @click="clearDocument"
                        title="remove selected file"
                        aria-label="remove selected file"
                        class="text-gray-400 hover:text-red-600 text-lg leading-none"
                    >&times;</button>
                </div>

                <input
                    id="document"
                    ref="documentInput"
                    type="file"
                    accept=".jpg,.jpeg,.png,.pdf"
                    class="hidden"
                    @change="onDocumentChange"
                />

                <InputError class="mt-2" :message="errors.document?.[0]" />
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="submitting">submit</PrimaryButton>
                <div
                    @click="() => { if (!submitting) open = false }"
                    class="cursor-pointer ml-2 inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                >cancel</div>
            </div>
        </form>
    </section>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>
