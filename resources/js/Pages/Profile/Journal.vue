<script setup>
// SCRUM-36 (TT-5.1): the signed-in person's private journal. Only they can read it -- no counsellor, guardian,
// organisation or administrator -- and the page says so, beside the way to urgent help (nothing here is read, so
// nothing here can raise the alarm). SCRUM-38: export as a PDF, behind the same checks as "Download a copy".
import { computed, nextTick, onMounted, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import MiniModal from '@/Components/MiniModal.vue'
import TextInput from '@/Components/TextInput.vue'
import JournalExport from './Partials/JournalExport.vue'
import useHelpNow from '@/Composables/useHelpNow'

const props = defineProps({
    limits: { type: Object, required: true },
    // SCRUM-38: what the export needs to ask for.
    exportGate: { type: Object, required: true },
})

const { show: showHelp } = useHelpNow()

// Fetched on every visit, never kept as a page prop (code review): going Back mustn't show entries deleted since.
const list = ref([])
const nextCursor = ref(null)
const loaded = ref(false)
const loadingMore = ref(false)
const loadError = ref('')
const listHeading = ref(null)

onMounted(() => loadMore(true))

const today = () => {
    const now = new Date()
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

// The form: a new entry, or the one being edited.
const editing = ref(null)
const form = ref({ title: '', content: '', written_on: today() })
const errors = ref({})
const saving = ref(false)
const status = ref('')
const contentInput = ref(null)
const formHeading = ref(null)

const remaining = computed(() => props.limits.content - (form.value.content?.length ?? 0))

function startNew() {
    editing.value = null
    form.value = { title: '', content: '', written_on: today() }
    errors.value = {}
}

function startEdit(entry) {
    editing.value = entry.id
    form.value = { title: entry.title ?? '', content: entry.content, written_on: entry.writtenOn }
    errors.value = {}
    status.value = ''
    nextTick(() => {
        formHeading.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
        contentInput.value?.focus()
    })
}

function firstErrors(err) {
    return Object.fromEntries(Object.entries(err.response?.data?.errors ?? {}).map(([key, messages]) => [key, messages[0]]))
}

async function save() {
    saving.value = true
    errors.value = {}
    status.value = ''

    try {
        if (editing.value) {
            const { data } = await axios.patch(route('profile.journal.update', editing.value), form.value)
            list.value = sortEntries(list.value.map((entry) => entry.id === data.entry.id ? data.entry : entry))
            status.value = 'Entry saved.'
        } else {
            const { data } = await axios.post(route('profile.journal.store'), form.value)
            list.value = sortEntries([data.entry, ...list.value])
            status.value = 'Entry added.'
        }
        startNew()
        // The button that was pressed changed (QA): back to writing.
        nextTick(() => contentInput.value?.focus())
    } catch (err) {
        if (err.response?.status === 422) {
            errors.value = firstErrors(err)
        } else if (err.response?.status === 429) {
            errors.value = { form: 'You are saving very quickly. Please wait a moment and try again.' }
        } else {
            errors.value = { form: 'Something went wrong and the entry was not saved. Please try again.' }
        }
    } finally {
        saving.value = false
    }
}

function sortEntries(entries) {
    return [...entries].sort((a, b) => b.writtenOn.localeCompare(a.writtenOn) || b.id - a.id)
}

async function loadMore(first = false) {
    loadingMore.value = true
    loadError.value = ''
    try {
        const { data } = await axios.get(route('profile.journal.entries'), { params: first ? {} : { cursor: nextCursor.value } })
        const known = new Set(list.value.map((entry) => entry.id))
        // Sorted again (code review): an entry added or re-dated here may belong among the older ones.
        list.value = sortEntries([...list.value, ...data.data.filter((entry) => !known.has(entry.id))])
        nextCursor.value = data.nextCursor
        loaded.value = true
    } catch {
        loadError.value = first ? 'Your journal could not be loaded. Please try again.' : 'Older entries could not be loaded. Please try again.'
    } finally {
        loadingMore.value = false
    }
}

// Every shown entry deleted while older ones are still to load: show those (not "no entries").
function refillIfEmpty() {
    if (!list.value.length && nextCursor.value) loadMore()
}

// Deleting -- one entry, or all of them -- asks first, and says it can't be undone.
const confirming = ref(null) // an entry, or 'all'
const deleting = ref(false)
const deleteError = ref('')

function closeConfirm() {
    if (!deleting.value) confirming.value = null
}

async function confirmDelete() {
    deleting.value = true
    deleteError.value = ''
    try {
        if (confirming.value === 'all') {
            await axios.delete(route('profile.journal.destroy-all'))
            list.value = []
            nextCursor.value = null
            status.value = 'Your journal was deleted.'
        } else {
            const id = confirming.value.id
            await axios.delete(route('profile.journal.destroy', id))
            list.value = list.value.filter((entry) => entry.id !== id)
            status.value = 'Entry deleted.'
        }
        if (editing.value && (confirming.value === 'all' || editing.value === confirming.value.id)) startNew()
        confirming.value = null
        // The button that opened the dialog is gone with its entry: focus goes to the list.
        nextTick(() => listHeading.value?.focus())
        refillIfEmpty()
    } catch {
        deleteError.value = 'It could not be deleted. Please try again.'
    } finally {
        deleting.value = false
    }
}

function day(value) {
    return new Date(`${value}T00:00:00Z`).toLocaleDateString(undefined, { weekday: 'short', year: 'numeric', month: 'long', day: 'numeric', timeZone: 'UTC' })
}
</script>

<template>
    <Head title="Your journal" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Your journal</h2>
        </template>

        <div class="py-10 bg-gray-50 min-h-screen">
            <div class="mx-auto w-full max-w-3xl px-4 space-y-6">
                <div class="bg-white shadow-sm border border-gray-200 rounded-lg p-6 text-sm text-gray-700 space-y-2">
                    <p>
                        A private place to write about how you're doing. <strong>Only you can read it</strong> -- not your
                        counsellor, a guardian, an organisation or the TalkTherapy team. It is stored encrypted.
                    </p>
                    <p>
                        Because nobody reads it, nobody will see if you write that you're struggling. If you need help
                        now, <button type="button" class="text-indigo-700 underline hover:text-indigo-900" @click="showHelp">get urgent help</button>
                        or talk to your counsellor.
                    </p>
                    <p class="text-xs text-gray-500">
                        Your journal is counted in <Link :href="route('profile.your-data')" class="underline">what we hold about you</Link>
                        and deleted when you close your account.
                    </p>
                </div>

                <section aria-labelledby="journal-form-title" class="bg-white shadow-sm border border-gray-200 rounded-lg p-6">
                    <h3 id="journal-form-title" ref="formHeading" class="text-lg font-semibold text-gray-900 scroll-mt-4">
                        {{ editing ? 'Edit entry' : 'New entry' }}
                    </h3>
                    <form class="mt-4 space-y-4" @submit.prevent="save" @input="status = ''">
                        <div class="grid gap-4 sm:grid-cols-[1fr_auto]">
                            <div>
                                <InputLabel for="journal-title" value="Title (optional)" />
                                <TextInput id="journal-title" v-model="form.title" type="text" class="mt-1 block w-full" :maxlength="limits.title" />
                                <InputError role="alert" class="mt-2" :message="errors.title" />
                            </div>
                            <div>
                                <InputLabel for="journal-date" value="Date" />
                                <TextInput id="journal-date" v-model="form.written_on" type="date" class="mt-1 block w-full" :max="today()" required />
                                <InputError role="alert" class="mt-2" :message="errors.written_on" />
                            </div>
                        </div>
                        <div>
                            <InputLabel for="journal-content" value="What's on your mind?" />
                            <textarea
                                id="journal-content"
                                ref="contentInput"
                                v-model="form.content"
                                rows="8"
                                :maxlength="limits.content"
                                required
                                aria-describedby="journal-content-count"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                            ></textarea>
                            <p id="journal-content-count" class="mt-1 text-xs text-gray-500 text-end">{{ remaining.toLocaleString() }} characters left</p>
                            <InputError role="alert" class="mt-2" :message="errors.content" />
                        </div>
                        <InputError role="alert" :message="errors.form" />
                        <div class="flex flex-wrap items-center gap-3">
                            <PrimaryButton :disabled="saving" :class="{ 'opacity-25': saving }">{{ editing ? 'Save changes' : 'Add entry' }}</PrimaryButton>
                            <SecondaryButton v-if="editing" type="button" :disabled="saving" @click="startNew">Cancel</SecondaryButton>
                            <p role="status" class="text-sm text-green-800">{{ status }}</p>
                        </div>
                    </form>
                </section>

                <section aria-labelledby="journal-list-title" class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 id="journal-list-title" ref="listHeading" tabindex="-1" class="text-lg font-semibold text-gray-900 focus:outline-none">Entries</h3>
                        <JournalExport v-if="list.length || nextCursor" :gate="exportGate" />
                    </div>

                    <p v-if="!loaded && loadingMore" class="text-sm text-gray-600" role="status">Loading your journal…</p>
                    <InputError role="alert" v-if="!loaded" :message="loadError" />
                    <p v-else-if="loaded && !list.length && !nextCursor" class="bg-white shadow-sm border border-gray-200 rounded-lg p-6 text-sm text-gray-600">
                        No entries yet. What you write appears here, newest first.
                    </p>

                    <article
                        v-for="entry in list"
                        :key="entry.id"
                        class="bg-white shadow-sm border border-gray-200 rounded-lg p-6"
                        :aria-labelledby="`journal-entry-${entry.id}`"
                    >
                        <header class="flex flex-wrap items-baseline justify-between gap-2">
                            <h4 :id="`journal-entry-${entry.id}`" class="font-semibold text-gray-900 [overflow-wrap:anywhere]">
                                {{ entry.title || day(entry.writtenOn) }}
                            </h4>
                            <p v-if="entry.title" class="text-xs text-gray-500">{{ day(entry.writtenOn) }}</p>
                        </header>
                        <p class="mt-3 text-sm text-gray-800 whitespace-pre-line [overflow-wrap:anywhere]">{{ entry.content }}</p>
                        <div class="mt-4 flex gap-3 text-sm">
                            <button type="button" class="min-h-[44px] px-2 text-indigo-700 underline hover:text-indigo-900" :aria-label="`Edit ${entry.title || 'the entry of ' + day(entry.writtenOn)}`" @click="startEdit(entry)">Edit</button>
                            <button type="button" class="min-h-[44px] px-2 text-red-700 underline hover:text-red-900" :aria-label="`Delete ${entry.title || 'the entry of ' + day(entry.writtenOn)}`" @click="confirming = entry">Delete</button>
                        </div>
                    </article>

                    <div v-if="nextCursor" class="text-center">
                        <SecondaryButton type="button" :disabled="loadingMore" @click="loadMore()">{{ loadingMore ? 'Loading…' : 'Show older entries' }}</SecondaryButton>
                        <InputError role="alert" class="mt-2" :message="loadError" />
                    </div>

                    <div v-if="list.length || nextCursor" class="pt-4 border-t border-gray-200">
                        <button type="button" class="text-sm text-red-700 underline hover:text-red-900" @click="confirming = 'all'">Delete my whole journal</button>
                    </div>
                </section>

                <!-- Asks before deleting: nothing deleted can be brought back. The shared dialog keeps focus inside it. -->
                <MiniModal :show="!!confirming" max-width="md" labelledby="journal-delete-title" @close="closeConfirm">
                    <div class="p-6">
                        <h3 id="journal-delete-title" class="text-lg font-semibold text-gray-900">{{ confirming === 'all' ? 'Delete your whole journal?' : 'Delete this entry?' }}</h3>
                        <p class="mt-2 text-sm text-gray-700">
                            {{ confirming === 'all' ? 'Every entry is deleted for good.' : 'It is deleted for good.' }} This can't be undone -- not even by the TalkTherapy team.
                        </p>
                        <InputError role="alert" class="mt-2" :message="deleteError" />
                        <div class="mt-6 flex justify-end gap-3">
                            <SecondaryButton type="button" :disabled="deleting" @click="closeConfirm">Cancel</SecondaryButton>
                            <DangerButton type="button" :disabled="deleting" @click="confirmDelete">{{ deleting ? 'Deleting…' : 'Delete' }}</DangerButton>
                        </div>
                    </div>
                </MiniModal>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
