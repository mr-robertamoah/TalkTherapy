<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import Modal from '@/Components/Modal.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Alert from '@/Components/Alert.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextBox from '@/Components/TextBox.vue';
import useAlert from '@/Composables/useAlert';
import useErrorHandler from '@/Composables/useErrorHandler';
import Checkbox from '@/Components/Checkbox.vue';
import StyledLink from '@/Components/StyledLink.vue';
import HelpButton from '@/Components/HelpButton.vue';
import PreferencePicker from '@/Components/PreferencePicker.vue';
import PreferenceMatchMark from '@/Components/PreferenceMatchMark.vue';
import useGuidedTours from '@/Composables/useGuidedTours';

// SCRUM-113 (QA): the saved preferences arrive as plain lists (resources aren't wrapped, see
// HandleInertiaRequestsV2), so reading `.data` found nothing -- the page showed none saved, and saving
// wiped them. Either shape is read.
const asList = (value) => Array.isArray(value) ? value : (value?.data ?? [])

// SCRUM-393: each kind is one PreferencePicker (search first, choose, load more, add). Matches
// PreferenceRanking::MAX_PER_KIND, which the server enforces.
const MAX_PER_KIND = 20

const KINDS = {
    cases: {
        singular: 'case', route: 'cases.get', createRoute: 'therapy-cases.create', response: 'case', detail: 'description',
        title: 'Cases',
        about: 'The cases you choose help us show you public therapies about them first, and counsellors who handle them.',
    },
    languages: {
        singular: 'language', route: 'languages.get', createRoute: 'languages.create', response: 'language', detail: 'about',
        title: 'Languages',
        about: 'The languages you choose help us show you therapies and counsellors you can talk with first.',
    },
    religions: {
        singular: 'religion', route: 'religions.get', createRoute: 'religions.create', response: 'religion', detail: 'about',
        title: 'Religions',
        about: 'Some people find it easier to talk with someone who shares their faith. You can skip this if it doesn\'t matter to you.',
    },
}

const { PAGES } = useGuidedTours()
const page = usePage()
const { alertData, clearAlertData, setAlertData } = useAlert()
const { setErrorData, clearError } = useErrorHandler()

const form = useForm({
    selectedCases: [],
    selectedLanguages: [],
    selectedReligions: [],
    anonymous: false,
})

// What's saved, from the page props (which a save refreshes).
const saved = computed(() => ({
    anonymous: page.props.auth?.user?.settings?.anonymous === true,
    cases: asList(page.props.cases),
    languages: asList(page.props.languages),
    religions: asList(page.props.religions),
}))

const anonymous = ref(saved.value.anonymous)
const selected = ref({
    cases: [...saved.value.cases],
    languages: [...saved.value.languages],
    religions: [...saved.value.religions],
})

const sameIds = (a, b) => a.length === b.length && a.every((item) => b.some((other) => other.id === item.id))
const changed = computed(() => anonymous.value !== saved.value.anonymous
    || Object.keys(KINDS).some((kind) => !sameIds(selected.value[kind], saved.value[kind])))

function submitPreferences() {
    form.anonymous = anonymous.value
    form.selectedCases = selected.value.cases.map((c) => c.id)
    form.selectedLanguages = selected.value.languages.map((l) => l.id)
    form.selectedReligions = selected.value.religions.map((r) => r.id)

    form.post(route('preferences.set'), {
        preserveScroll: true,
        onSuccess: () => {
            // Take what the server saved as the new starting point.
            anonymous.value = saved.value.anonymous
            selected.value = { cases: [...saved.value.cases], languages: [...saved.value.languages], religions: [...saved.value.religions] }
            setAlertData({ message: 'Your preferences are saved.', type: 'success', show: true })
        },
        onError: () => setAlertData({ message: 'Your preferences couldn\'t be saved. Please try again.', type: 'failed', show: true }),
    })
}

// Adding a case, language or religion that isn't listed.
// Function refs: a ref object inside v-for would be filled with an array.
const pickers = {}
const adding = ref({ kind: '', name: '', detail: '', saving: false })
const addErrors = ref({ name: '', detail: '' })

watch(() => adding.value.name, () => {
    if (adding.value.name?.length && addErrors.value.name?.length) clearError(addErrors, 'name')
})

function startAdding(kind, name) {
    addErrors.value = { name: '', detail: '' }
    adding.value = { kind, name, detail: '', saving: false }
}

function closeAdding() {
    adding.value = { kind: '', name: '', detail: '', saving: false }
}

async function saveNew() {
    const kind = KINDS[adding.value.kind]
    adding.value.saving = true

    await axios.post(route(kind.createRoute), { name: adding.value.name, [kind.detail]: adding.value.detail })
        .then((res) => {
            pickers[adding.value.kind]?.added(res.data[kind.response])
            closeAdding()
        })
        .catch((err) => {
            if (err.response?.status == 422) {
                const errors = err.response.data.errors
                if (errors) {
                    setErrorData(addErrors, { name: errors.name, detail: errors[kind.detail] }, ['name', 'detail'])
                    return
                }
                // A refusal (e.g. not allowed to add one) comes with only a message.
                setAlertData({ message: err.response.data.message ?? 'That couldn\'t be added.', type: 'failed', show: true })
                return
            }
            setAlertData({ message: 'Something unfortunate happened. Please try again later.', type: 'failed', show: true })
        })
        .finally(() => { adding.value.saving = false })
}

const card = 'bg-white overflow-hidden border border-gray-100 shadow-sm sm:rounded-lg p-6 text-gray-900'
</script>

<template>
    <Head title="Preferences" />

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Preferences</h2>
        </template>

        <div class="py-12">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="flex justify-end">
                    <HelpButton
                        title="get help on Preference Page"
                        :page="PAGES.preference"
                        :user="$page.props.auth?.user"
                    />
                </div>

                <!-- SCRUM-415: they opened a link before signing up -- take them back to it. -->
                <div v-if="page.props.continueUrl" class="bg-white rounded-lg shadow-sm border border-blue-200 p-4 sm:p-5 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-gray-700">You opened an invitation link before signing up. Your preferences can wait.</p>
                    <a
                        :href="page.props.continueUrl"
                        class="inline-flex items-center px-4 py-2 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                    >Continue to your invitation</a>
                </div>

                <div :class="card">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-medium text-gray-900">Set your preferences</h3>
                            <p class="mt-1 text-sm text-gray-600">You can set them now or later -- change them any time.</p>
                        </div>
                        <StyledLink :href="route('profile.show')" title="go to profile" :text="'user profile'" class="shrink-0" />
                    </div>
                    <div class="mt-4 flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-900">
                        <PreferenceMatchMark legend class="mt-0.5" />
                        <p>
                            Therapies, groups and counsellors that match your preferences are shown first, with this mark.
                            Only you see it.
                        </p>
                    </div>
                </div>

                <div id="preference-anon-id" class="relative"></div>
                <section :class="card" aria-labelledby="preference-anonymity">
                    <h3 id="preference-anonymity" class="text-lg font-medium text-gray-900">Anonymity</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Most people feel more open when they stay anonymous. With this on, you're anonymous in every
                        therapy you start, unless you change it here or for that therapy.
                    </p>
                    <label class="mt-4 flex items-center">
                        <Checkbox name="anonymous" v-model:checked="anonymous" />
                        <span class="ms-2 text-sm text-gray-700">Automatically stay anonymous</span>
                    </label>
                </section>

                <template v-for="(kind, key) in KINDS" :key="key">
                    <div :id="`preference-${key}-id`" class="relative"></div>
                    <section :class="card" :aria-labelledby="`preference-${key}`">
                        <h3 :id="`preference-${key}`" class="text-lg font-medium text-gray-900">{{ kind.title }}</h3>
                        <p class="mt-1 mb-4 text-sm text-gray-600">{{ kind.about }}</p>
                        <PreferencePicker
                            :ref="(el) => { pickers[key] = el }"
                            v-model="selected[key]"
                            :lookup-route="kind.route"
                            :singular="kind.singular"
                            :plural="key"
                            :max="MAX_PER_KIND"
                            @add="(name) => startAdding(key, name)"
                        />
                    </section>
                </template>

                <div id="preference-action-id" class="relative"></div>
                <div class="sticky bottom-0 z-10 flex flex-wrap items-center justify-end gap-3 border border-gray-100 bg-white p-4 shadow-sm sm:rounded-lg">
                    <span v-if="changed" class="text-sm text-gray-600">You have unsaved changes.</span>
                    <PrimaryButton
                        :class="{ 'opacity-25': form.processing }"
                        :disabled="form.processing || !changed"
                        @click="submitPreferences"
                    >
                        set preferences
                    </PrimaryButton>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>

    <Modal :show="!!adding.kind" max-width="md" @close="closeAdding">
        <form v-if="adding.kind" class="p-6" @submit.prevent="saveNew">
            <h2 class="text-lg font-semibold text-gray-900">Add a {{ KINDS[adding.kind].singular }}</h2>
            <p class="mt-1 text-sm text-gray-600">It's added to the list for everyone, and chosen for you.</p>

            <div class="mt-4">
                <InputLabel for="preference-new-name" value="Name" />
                <TextInput id="preference-new-name" v-model="adding.name" type="text" class="mt-1 block w-full" required autofocus />
                <InputError class="mt-2" :message="addErrors.name" />
            </div>

            <div class="mt-4">
                <InputLabel for="preference-new-detail" :value="KINDS[adding.kind].detail === 'description' ? 'Description (optional)' : 'About (optional)'" />
                <TextBox id="preference-new-detail" v-model="adding.detail" class="mt-1 block w-full" />
                <InputError class="mt-2" :message="addErrors.detail" />
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <SecondaryButton type="button" :disabled="adding.saving" @click="closeAdding">Cancel</SecondaryButton>
                <PrimaryButton :disabled="adding.saving">{{ adding.saving ? 'Adding…' : 'Add' }}</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
