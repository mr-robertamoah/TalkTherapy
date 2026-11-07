<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import AdminSearchBox from '@/Components/Admin/AdminSearchBox.vue'
import { searchPage } from '@/Composables/useAdminSearch'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Pagination from '@/Components/Pagination.vue'
import ReportStatusBadge from '@/Components/ReportStatusBadge.vue'

// SCRUM-387: the admin area's shell (sidebar inside AuthenticatedLayout), kept across admin pages.
defineOptions({ layout: AdminLayout })

// SCRUM-340: the complaint-review queue. Active reports (open, then under review, oldest first) by
// default; each status can be listed on its own. Reviewing happens on the per-report page.

const props = defineProps({
    search: { type: String, default: '' },
    searchTooMany: { type: Boolean, default: false },
    reports: { type: Object, required: true },
    status: { type: String, default: null },
    statuses: { type: Array, required: true },
})

const filters = [
    { value: null, label: 'active' },
    ...props.statuses.map((value) => ({ value, label: value.replace('_', ' ') })),
]

function filterBy(status) {
    router.get(route('administrator.reports'), status ? { status } : {}, { preserveScroll: true })
}

function goToPage(url) {
    if (url) router.get(url, {}, { preserveScroll: true })
}

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString() : '--'
}

// SCRUM-390: the page's search, kept in the URL with its other filters.
function searchFor(term) {
    searchPage('administrator.reports', term, { status: props.status ?? undefined })
}
</script>

<template>
    <Head title="Reports" />

    <div>
        <div class="my-8 w-full max-w-6xl mx-auto space-y-8 px-4">
            <div>
                <div class="text-2xl font-bold text-gray-900">Reports</div>
                <div class="w-16 h-1 bg-blue-600 mt-2"></div>
                <div class="mt-2 text-sm text-gray-600">
                    Complaints raised by clients and counsellors. Open a report to review it, record who it is
                    about and, when needed, read the reported conversation.
                </div>
            </div>
            <AdminSearchBox :model-value="search" label="Search by the reporter's name or username, or the therapy's name" :too-many="searchTooMany" @search="searchFor" />

            <div class="flex flex-wrap gap-2">
                <button
                    v-for="filter in filters"
                    :key="filter.label"
                    type="button"
                    class="px-3 py-1 text-sm rounded-md border capitalize transition duration-75"
                    :class="status === filter.value
                        ? 'bg-gray-800 text-white border-gray-800'
                        : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
                    @click="() => filterBy(filter.value)"
                >{{ filter.label }}</button>
            </div>

            <div class="bg-white shadow-sm border border-gray-200 sm:rounded-lg p-6">
                <div v-if="reports.data.length === 0" class="text-sm text-gray-500 italic">
                    No reports here.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-gray-500 border-b border-gray-200">
                                <th class="py-2 pr-4">#</th>
                                <th class="py-2 pr-4">About</th>
                                <th class="py-2 pr-4">Reported by</th>
                                <th class="py-2 pr-4">Reported person</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4">Submitted</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="report in reports.data" :key="report.id" class="border-b border-gray-100 align-top">
                                <td class="py-3 pr-4 text-gray-500">{{ report.id }}</td>
                                <template v-if="report.involved">
                                    <td colspan="3" class="py-3 pr-4 text-gray-600 italic">
                                        You are part of this report, so another administrator reviews it.
                                    </td>
                                    <td class="py-3 pr-4"><ReportStatusBadge :status="report.status" /></td>
                                    <td class="py-3 pr-4 whitespace-nowrap">{{ formatDate(report.createdAt) }}</td>
                                    <td class="py-3"></td>
                                </template>
                                <template v-else>
                                    <td class="py-3 pr-4">
                                        <div class="font-medium text-gray-900 capitalize">{{ report.subject.type }}</div>
                                        <div v-if="report.therapy" class="text-gray-500">{{ report.therapy.name }}</div>
                                        <div class="text-gray-600 mt-1 line-clamp-2 max-w-[280px]">{{ report.description }}</div>
                                    </td>
                                    <td class="py-3 pr-4">{{ report.reporter?.name ?? '--' }}</td>
                                    <td class="py-3 pr-4">
                                        <span v-if="report.reportedPerson">{{ report.reportedPerson.name }}</span>
                                        <span v-else class="text-amber-700">not recorded</span>
                                    </td>
                                    <td class="py-3 pr-4"><ReportStatusBadge :status="report.status" /></td>
                                    <td class="py-3 pr-4 whitespace-nowrap">{{ formatDate(report.createdAt) }}</td>
                                    <td class="py-3 text-right">
                                        <Link
                                            :href="route('administrator.reports.show', { report: report.id })"
                                            class="inline-flex items-center px-3 py-1 rounded-md bg-gray-800 text-white text-xs uppercase tracking-wider hover:bg-gray-700"
                                        >review</Link>
                                    </td>
                                </template>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination :meta="reports.meta" @navigate="goToPage" />
            </div>
        </div>
    </div>
</template>
