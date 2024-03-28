<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3'


defineProps({
    therapies: {
        default: []
    }
})
</script>

<template>
    <Head title="Therapies" />

    <AuthenticatedLayout>

    </AuthenticatedLayout>
</template>