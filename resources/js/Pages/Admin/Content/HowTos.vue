<script setup>
// SCRUM-387: the old /administrator "others > howTos" tab as its own page. AdminHowTosComponent
// keeps its own filters and "get more" paging over the api.how-tos API.
import { Head } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminHowTosComponent from '@/Components/AdminHowTosComponent.vue'

defineOptions({ layout: AdminLayout })
</script>

<template>
    <Head title="How-tos" />

    <div class="my-8 w-full max-w-5xl mx-auto space-y-6 px-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">How-tos</h1>
            <div class="w-16 h-1 bg-blue-600 mt-2"></div>
            <p class="mt-2 text-sm text-gray-600">The guided tours shown on the app's pages. Create, edit or remove them here.</p>
        </div>

        <div class="bg-white shadow-sm border border-gray-200 rounded-lg p-4 sm:p-6">
            <AdminHowTosComponent :show="true" class="w-full" />
        </div>
    </div>
</template>
