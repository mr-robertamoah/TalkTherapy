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
import ConfirmedPdfExport from '@/Components/ConfirmedPdfExport.vue'
import useHelpNow from '@/Composables/useHelpNow'

const props = defineProps({
    limits: { type: Object, required: true },
    // SCRUM-38: what the export needs to ask for.
    exportGate: { type: Object, required: true },
})

const { show: showHelp } = useHelpNow()

// Fetched on every visit, never kept as a page prop (code review): going Back mustn't show entries deleted since.
// SCRUM-440 (the user's TODO): the journal as day notes -- one per day, its newest entry and how many -- in numbered
// pages, searchable. Search sends the term in the body (never the URL); the server reads only your own entries.
const days = ref([])
const pageInfo = ref({ page: 1, lastPage: 1, total: 0 })
const loaded = ref(false)
const loadingDays = ref(false)
const loadError = ref('')
const listHeading = ref(null)
const searchInput = ref('')
const searching = ref('') // the term the notes show results for
const resultStatus = ref('')

onMounted(() => loadDays(1))

// Only the latest request's answer is shown (code review): a save's reload can cross a search still on its way.
let latestRequest = 0

async function loadDays(page = pageInfo.value.page) {
    const request = ++latestRequest
    loadingDays.value = true
    loadError.value = ''
    try {
        const { data } = searching.value
            ? await axios.post(route('profile.journal.search'), { q: searching.value, page })
            : await axios.get(route('profile.journal.days'), { params: { page } })
        if (request !== latestRequest) return
        // A page emptied by a delete: straight to the last one there is (one request, not one a page).
        if (!data.data.length && page > data.lastPage) return loadDays(data.lastPage)
        days.value = data.data
        pageInfo.value = { page: data.page, lastPage: data.lastPage, total: data.total }
        loaded.value = true
        resultStatus.value = searching.value
            ? (data.total ? `${data.total} day${data.total === 1 ? '' : 's'} with entries matching "${searching.value}".` : `No entries match "${searching.value}".`)
            : ''
    } catch (err) {
        if (request !== latestRequest) return
        loadError.value = err.response?.status === 422 ? (err.response.data.errors?.q?.[0] ?? 'Please check your search.')
            : err.response?.status === 429 ? 'You are searching very quickly. Please wait a moment.'
            : 'Your journal could not be loaded. Please try again.'
    } finally {
        if (request === latestRequest) loadingDays.value = false
    }
}

// The pressed button may now be disabled (the first or last page): focus goes to the list's heading.
async function goToPage(page) {
    await loadDays(page)
    nextTick(() => listHeading.value?.focus())
}

function search() {
    searching.value = searchInput.value.trim()
    loadDays(1)
}

function clearSearch() {
    searchInput.value = ''
    searching.value = ''
    loadDays(1)
}

// --- one day, in a dialog ---
const openDay = ref(null) // the note
const dayEntries = ref([])
const dayCursor = ref(null)
const dayLoading = ref(false)
const dayError = ref('')
const dayHeading = ref(null)

async function showDay(note, more = false) {
    openDay.value = note
    dayLoading.value = true
    dayError.value = ''
    if (!more) {
        dayEntries.value = []
        dayCursor.value = null
    }
    try {
        const { data } = await axios.get(route('profile.journal.entries'), { params: { date: note.date, ...(more ? { cursor: dayCursor.value } : {}) } })
        dayEntries.value = [...dayEntries.value, ...data.data]
        dayCursor.value = data.nextCursor
    } catch {
        dayError.value = 'This day could not be loaded. Please try again.'
    } finally {
        dayLoading.value = false
    }
}

function closeDay() {
    openDay.value = null
}

function matches(entry) {
    if (!searching.value) return false
    // As the server compares (not locale-dependent), so the highlight matches the results.
    const term = searching.value.toLowerCase()
    return `${entry.title ?? ''}\n${entry.content}`.toLowerCase().includes(term)
}

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
    closeDay()
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
            await axios.patch(route('profile.journal.update', editing.value), form.value)
            status.value = 'Entry saved.'
        } else {
            await axios.post(route('profile.journal.store'), form.value)
            status.value = 'Entry added.'
        }
        startNew()
        // Re-dated entries move between days: the notes are fetched again.
        loadDays()
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
            closeDay()
            status.value = 'Your journal was deleted.'
        } else {
            const id = confirming.value.id
            await axios.delete(route('profile.journal.destroy', id))
            dayEntries.value = dayEntries.value.filter((entry) => entry.id !== id)
            if (openDay.value) openDay.value = { ...openDay.value, entries: openDay.value.entries - 1 }
            if (!dayEntries.value.length) closeDay()
            status.value = 'Entry deleted.'
        }
        if (editing.value && (confirming.value === 'all' || editing.value === confirming.value.id)) startNew()
        confirming.value = null
        await loadDays()
        // The button that opened the dialog is gone with its entry: focus goes to the day still open, else the notes.
        nextTick(() => (openDay.value ? dayHeading.value : listHeading.value)?.focus())
    } catch {
        deleteError.value = 'It could not be deleted. Please try again.'
    } finally {
        deleting.value = false
    }
}

function day(value) {
    return new Date(`${value}T00:00:00Z`).toLocaleDateString(undefined, { weekday: 'short', year: 'numeric', month: 'long', day: 'numeric', timeZone: 'UTC' })
}

function plural(n, one, many) {
    return `${n} ${n === 1 ? one : many}`
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
                        <h3 id="journal-list-title" ref="listHeading" tabindex="-1" class="text-lg font-semibold text-gray-900 focus:outline-none">Your days</h3>
                        <ConfirmedPdfExport v-if="pageInfo.total || searching" id-prefix="journal-export" :gate="exportGate" />
                    </div>

                    <!-- SCRUM-440: search titles and text. -->
                    <form v-if="pageInfo.total || searching" role="search" class="flex flex-wrap items-end gap-2" @submit.prevent="search">
                        <div class="flex-1 min-w-[12rem]">
                            <InputLabel for="journal-search" value="Search your journal" />
                            <TextInput id="journal-search" v-model="searchInput" type="search" class="mt-1 block w-full" minlength="2" maxlength="100" autocomplete="off" />
                        </div>
                        <PrimaryButton class="disabled:opacity-50 disabled:cursor-not-allowed" :disabled="loadingDays || searchInput.trim().length < 2">Search</PrimaryButton>
                        <SecondaryButton v-if="searching" type="button" @click="clearSearch">Clear search</SecondaryButton>
                    </form>
                    <p class="text-sm text-gray-700" role="status">{{ loadingDays && !loaded ? 'Loading your journal…' : resultStatus }}</p>
                    <InputError role="alert" :message="loadError" />

                    <p v-if="loaded && !pageInfo.total && !searching" class="bg-white shadow-sm border border-gray-200 rounded-lg p-6 text-sm text-gray-600">
                        No entries yet. What you write appears here, a note for each day.
                    </p>

                    <!-- A note for each day: its newest entry, and how many. -->
                    <ul v-if="days.length" role="list" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <li v-for="note in days" :key="note.date">
                            <button
                                type="button"
                                class="flex h-full w-full flex-col rounded-lg border border-amber-200 bg-amber-50 p-4 text-left shadow-sm transition hover:border-amber-300 hover:shadow focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                :aria-label="`Open ${day(note.date)}, ${plural(note.entries, 'entry', 'entries')}${note.matches ? `, ${note.matches} matching` : ''}${note.latest?.title ? `, latest: ${note.latest.title}` : ''}`"
                                @click="showDay(note)"
                            >
                                <span class="flex items-baseline justify-between gap-2">
                                    <span class="text-xs font-semibold uppercase tracking-wide text-amber-900">{{ day(note.date) }}</span>
                                    <span class="shrink-0 rounded-full bg-white px-2 py-0.5 text-xs font-medium text-amber-900 border border-amber-200">
                                        {{ note.matches ? `${note.matches} of ${note.entries} match` : plural(note.entries, 'entry', 'entries') }}
                                    </span>
                                </span>
                                <span v-if="note.latest?.title" class="mt-2 font-semibold text-gray-900 [overflow-wrap:anywhere]">{{ note.latest.title }}</span>
                                <span class="mt-1 text-sm text-gray-800 line-clamp-4 whitespace-pre-line [overflow-wrap:anywhere]">{{ note.latest?.preview }}</span>
                            </button>
                        </li>
                    </ul>

                    <!-- Pages of days. -->
                    <nav v-if="pageInfo.lastPage > 1" aria-label="Journal pages" class="flex items-center justify-between gap-3 text-sm">
                        <SecondaryButton type="button" :disabled="loadingDays || pageInfo.page <= 1" @click="goToPage(pageInfo.page - 1)">Newer days</SecondaryButton>
                        <span>Page {{ pageInfo.page }} of {{ pageInfo.lastPage }}</span>
                        <SecondaryButton type="button" :disabled="loadingDays || pageInfo.page >= pageInfo.lastPage" @click="goToPage(pageInfo.page + 1)">Older days</SecondaryButton>
                    </nav>

                    <div v-if="pageInfo.total" class="pt-4 border-t border-gray-200">
                        <button type="button" class="text-sm text-red-700 underline hover:text-red-900" @click="confirming = 'all'">Delete my whole journal</button>
                    </div>
                </section>

                <!-- One day's entries, newest first. -->
                <MiniModal :show="!!openDay" max-width="2xl" labelledby="journal-day-title" @close="closeDay">
                    <div v-if="openDay" class="p-6 text-sm text-gray-700">
                        <h3 id="journal-day-title" ref="dayHeading" tabindex="-1" class="text-lg font-semibold text-gray-900 focus:outline-none">{{ day(openDay.date) }}</h3>
                        <p class="mt-1 text-xs text-gray-500" role="status">{{ dayLoading ? 'Loading…' : plural(openDay.entries, 'entry', 'entries') }}</p>
                        <InputError role="alert" :message="dayError" />
                        <article
                            v-for="(entry, index) in dayEntries"
                            :key="entry.id"
                            class="mt-4 rounded-lg border p-4"
                            :class="matches(entry) ? 'border-indigo-300 bg-indigo-50' : 'border-gray-200 bg-white'"
                            :aria-labelledby="`journal-entry-${entry.id}`"
                        >
                            <h4 :id="`journal-entry-${entry.id}`" class="font-semibold text-gray-900 [overflow-wrap:anywhere]">
                                {{ entry.title || 'Untitled' }} <span v-if="matches(entry)" class="ms-1 text-xs font-normal text-indigo-800">matches your search</span>
                            </h4>
                            <p class="mt-2 text-gray-800 whitespace-pre-line [overflow-wrap:anywhere]">{{ entry.content }}</p>
                            <div class="mt-3 flex gap-3">
                                <button type="button" class="min-h-[44px] px-2 text-indigo-700 underline hover:text-indigo-900" :aria-label="`Edit entry ${index + 1}${entry.title ? `, ${entry.title}` : ''}`" @click="startEdit(entry)">Edit</button>
                                <button type="button" class="min-h-[44px] px-2 text-red-700 underline hover:text-red-900" :aria-label="`Delete entry ${index + 1}${entry.title ? `, ${entry.title}` : ''}`" @click="confirming = entry">Delete</button>
                            </div>
                        </article>
                        <SecondaryButton v-if="dayCursor" type="button" class="mt-4" :disabled="dayLoading" @click="showDay(openDay, true)">Show more</SecondaryButton>
                        <div class="mt-6 flex justify-end">
                            <SecondaryButton type="button" @click="closeDay">Close</SecondaryButton>
                        </div>
                    </div>
                </MiniModal>

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
