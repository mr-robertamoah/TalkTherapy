<script setup>
// SCRUM-387: the old /administrator "users" tab as its own page. AdminUsersComponent keeps its own
// "get more" paging over the admin.users API, so there is no page number to put in the URL.
import { Head } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminUsersComponent from '@/Components/AdminUsersComponent.vue'
import AdminSearchBox from '@/Components/Admin/AdminSearchBox.vue'
import { searchPage } from '@/Composables/useAdminSearch'

defineOptions({ layout: AdminLayout })

// SCRUM-390: kept in the URL (?search=).
defineProps({
    search: { type: String, default: '' },
})
</script>

<template>
    <Head title="Users" />

    <div class="my-8 w-full max-w-5xl mx-auto space-y-6 px-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Users</h1>
            <div class="w-16 h-1 bg-blue-600 mt-2"></div>
            <p class="mt-2 text-sm text-gray-600">Every account apart from your own. Edit someone's details or close their account.</p>
        </div>

        <AdminSearchBox :model-value="search" label="Search by name, username or email" @search="(term) => searchPage('administrator.users', term)" />

        <div class="bg-white shadow-sm border border-gray-200 rounded-lg p-4 sm:p-6">
            <AdminUsersComponent :show="true" :search="search" class="w-full" />
        </div>
    </div>
</template>
