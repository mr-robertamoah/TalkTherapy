<script setup>
import { Head, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import Pagination from '@/Components/Pagination.vue'

// SCRUM-360: every time an administrator read content they could only reach as an administrator
// (a private therapy's or discussion's messages and files, a licence or identity document).
// Super administrators only. Rows can't be changed or deleted.

const props = defineProps({
    accesses: { type: Object, required: true },
    filters: { type: Object, required: true },
    resources: { type: Array, required: true },
})

const labels = {
    therapy: 'therapy page',
    session_messages: 'session messages',
    topic_messages: 'topic messages',
    message: 'a message',
    message_replies: 'message replies',
    message_attachment: 'message attachment',
    discussion_messages: 'discussion messages',
    license_document: 'licence document',
    identity_document: 'identity document',
}

function filter(changes) {
    const query = { ...props.filters, ...changes }
    Object.keys(query).forEach((key) => (query[key] === null || query[key] === '') && delete query[key])
    router.get(route('administrator.content-accesses'), query, { preserveScroll: true })
}

function goToPage(url) {
    if (url) router.get(url, {}, { preserveScroll: true })
}

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : '--'
}
</script>

<template>
    <Head title="Read log" />

    <AuthenticatedLayout>
        <div class="my-8 w-full sm:w-[90%] md:w-[75%] mx-auto space-y-8 px-4 sm:px-0">
            <div>
                <div class="text-2xl font-bold text-gray-900">Admin read log</div>
                <div class="w-16 h-1 bg-blue-600 mt-2"></div>
                <div class="mt-2 text-sm text-gray-600">
                    Every time an administrator opened a conversation, file or document they could only reach because
                    they are an administrator. Entries are kept permanently and can't be changed. Confidential messages
                    read during a complaint review are recorded on the report itself.
                </div>
            </div>

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
            </div>

            <div class="bg-white shadow-sm border border-gray-200 sm:rounded-lg p-6">
                <div v-if="accesses.data.length === 0" class="text-sm text-gray-500 italic">Nothing recorded.</div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-gray-500 border-b border-gray-200">
                                <th class="py-2 pr-4">When</th>
                                <th class="py-2 pr-4">Administrator</th>
                                <th class="py-2 pr-4">Read</th>
                                <th class="py-2 pr-4">Of</th>
                                <th class="py-2">From</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="access in accesses.data" :key="access.id" class="border-b border-gray-100 align-top">
                                <td class="py-3 pr-4 whitespace-nowrap">{{ formatDate(access.at) }}</td>
                                <td class="py-3 pr-4">
                                    <button type="button" class="underline text-gray-800 hover:text-gray-900" @click="() => filter({ admin: access.admin.id })">
                                        {{ access.admin.name }}
                                    </button>
                                </td>
                                <td class="py-3 pr-4">
                                    {{ labels[access.resource] ?? access.resource }}
                                    <span v-if="access.page && access.page > 1" class="text-gray-500">(page {{ access.page }})</span>
                                </td>
                                <td class="py-3 pr-4">
                                    {{ access.subject }}
                                    <span v-if="access.fileId" class="text-gray-500">· file #{{ access.fileId }}</span>
                                </td>
                                <td class="py-3 text-gray-500">{{ access.ip ?? '--' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination :meta="accesses" @navigate="goToPage" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
