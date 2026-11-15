<script setup>
import { Head, router } from '@inertiajs/vue3'
import AdminSearchBox from '@/Components/Admin/AdminSearchBox.vue'
import { searchPage } from '@/Composables/useAdminSearch'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Pagination from '@/Components/Pagination.vue'
import AdminTable from '@/Components/Admin/AdminTable.vue'
import AdminRow from '@/Components/Admin/AdminRow.vue'
import AdminCell from '@/Components/Admin/AdminCell.vue'

// SCRUM-387: the admin area's shell (sidebar inside AuthenticatedLayout), kept across admin pages.
defineOptions({ layout: AdminLayout })

// SCRUM-360: every time an administrator read content they could only reach as an administrator
// (a private therapy's or discussion's messages and files, a licence or identity document).
// Super administrators only. Rows can't be changed or deleted.

const props = defineProps({
    search: { type: String, default: '' },
    accesses: { type: Object, required: true },
    filters: { type: Object, required: true },
    resources: { type: Array, required: true },
})

const labels = {
    therapy: 'therapy page',
    session: 'session details',
    session_messages: 'session messages',
    topic_messages: 'topic messages',
    message: 'a message',
    message_replies: 'message replies',
    message_attachment: 'message attachment',
    discussion_messages: 'discussion messages',
    license_document: 'licence document',
    identity_document: 'identity document',
    help_request_identity: 'who asked for urgent help',
    safety_flag_identity: 'who a safety flag is about',
    safety_flag_reason: "a safety flag's reason",
}

function filter(changes) {
    const { subjectLabel, ...current } = props.filters
    const query = { ...current, ...changes }
    Object.keys(query).forEach((key) => (query[key] === null || query[key] === '') && delete query[key])
    router.get(route('administrator.content-accesses'), query, { preserveScroll: true })
}

// SCRUM-430: the shared admin table.
const columns = [
    { label: 'When' },
    { label: 'Administrator' },
    { label: 'Read' },
    { label: 'Of' },
    { label: 'From' },
]

function goToPage(url) {
    if (url) router.get(url, {}, { preserveScroll: true })
}

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : '--'
}

// SCRUM-390: the page's search, kept in the URL with its other filters.
function searchFor(term) {
    searchPage('administrator.content-accesses', term, { resource: props.filters.resource ?? undefined, admin: props.filters.admin ?? undefined, subject: props.filters.subject ?? undefined, subject_id: props.filters.subject_id ?? undefined })
}
</script>

<template>
    <Head title="Read log" />

    <div>
        <div class="my-8 w-full max-w-7xl mx-auto space-y-8 px-4">
            <div>
                <div class="text-2xl font-bold text-gray-900">Admin read log</div>
                <div class="w-16 h-1 bg-blue-600 mt-2"></div>
                <div class="mt-2 text-sm text-gray-600">
                    Every time an administrator opened a conversation, file or document they could only reach because
                    they are an administrator. Entries are kept permanently and can't be changed. Confidential messages
                    read during a complaint review are recorded on the report itself.
                </div>
            </div>
            <AdminSearchBox :model-value="search" label="Search by the administrator's name or username" @search="searchFor" />

            <div class="flex flex-wrap items-center gap-3">
                <label for="resource_filter" class="text-sm text-gray-700">Show</label>
                <select
                    id="resource_filter"
                    :value="filters.resource ?? ''"
                    class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                    @change="(event) => filter({ resource: event.target.value })"
                >
                    <option value="">everything</option>
                    <option v-for="resource in resources" :key="resource" :value="resource">{{ labels[resource] ?? resource }}</option>
                </select>
                <button
                    v-if="filters.admin"
                    type="button"
                    class="text-sm text-gray-700 underline hover:text-gray-900"
                    @click="() => filter({ admin: null })"
                >show every administrator</button>
                <button
                    v-if="filters.subject"
                    type="button"
                    class="text-sm text-gray-700 underline hover:text-gray-900"
                    @click="() => filter({ subject: null, subject_id: null })"
                >show everything read, not just {{ filters.subjectLabel ?? `this ${filters.subject}` }}</button>
            </div>

            <div class="space-y-4">
                <AdminTable :columns="columns" caption="Admin read log" :empty="accesses.data.length === 0" empty-text="Nothing recorded.">
                    <AdminRow v-for="access in accesses.data" :key="access.id">
                        <AdminCell label="When"><span class="whitespace-nowrap">{{ formatDate(access.at) }}</span></AdminCell>
                        <AdminCell label="Administrator">
                            <button
                                type="button"
                                class="underline text-gray-800 hover:text-gray-900 text-left"
                                :aria-label="`Show only what ${access.admin.name} read`"
                                @click="() => filter({ admin: access.admin.id })"
                            >{{ access.admin.name }}</button>
                        </AdminCell>
                        <AdminCell label="Read">
                            {{ labels[access.resource] ?? access.resource }}
                            <span v-if="access.page && access.page > 1" class="text-gray-500">(page {{ access.page }})</span>
                        </AdminCell>
                        <AdminCell label="Of">
                            <button
                                v-if="access.subjectKey"
                                type="button"
                                class="underline text-gray-800 hover:text-gray-900 text-left"
                                :aria-label="`Show only what was read of ${access.subject}`"
                                @click="() => filter({ subject: access.subjectKey, subject_id: access.subjectId })"
                            >{{ access.subject }}</button>
                            <template v-else>{{ access.subject }}</template>
                            <span v-if="access.fileId" class="text-gray-500">· {{ access.fileName ?? 'an attachment' }}</span>
                        </AdminCell>
                        <AdminCell label="From"><span class="text-gray-500">{{ access.ip ?? '--' }}</span></AdminCell>
                    </AdminRow>
                </AdminTable>

                <Pagination :meta="accesses" @navigate="goToPage" />
            </div>
        </div>
    </div>
</template>
