<script setup>
// SCRUM-415: the person's own applications to join organisations as a member, still waiting for an
// admin -- from a join link or from the directory below. Read-only for now (withdrawing is SCRUM-417).
import { ref } from 'vue';

const props = defineProps({
    initialApplications: {
        type: Object,
        required: true,
    },
})

const applications = ref([...props.initialApplications.data])
const nextPageUrl = ref(props.initialApplications.links?.next ?? null)
const loadingMore = ref(false)
const loadError = ref('')

async function loadMore() {
    if (!nextPageUrl.value) return

    loadingMore.value = true
    loadError.value = ''
    await axios.get(nextPageUrl.value)
        .then((res) => {
            applications.value = [...applications.value, ...res.data.data]
            nextPageUrl.value = res.data.links?.next ?? null
        })
        .catch(() => { loadError.value = "Couldn't load more applications. Try again." })
        .finally(() => {
            loadingMore.value = false
        })
}

// After applying from the directory on this page.
async function reload() {
    loadError.value = ''
    await axios.get(route('organizations.mine.applications'))
        .then((res) => {
            applications.value = res.data.data
            nextPageUrl.value = res.data.links?.next ?? null
        })
        .catch(() => { loadError.value = "Couldn't refresh your applications. Reload the page to see them." })
}

defineExpose({ reload })
</script>

<template>
    <section class="bg-white overflow-hidden shadow-xl border border-gray-100 sm:rounded-xl" aria-labelledby="pending-applications-title">
        <div class="p-6 sm:p-8">
            <div class="mb-4">
                <h3 id="pending-applications-title" class="text-xl font-bold text-gray-900">Pending applications</h3>
                <div class="w-12 h-1 bg-blue-600 mt-2"></div>
                <p class="text-sm text-gray-600 mt-2">
                    Organizations you've asked to join. You'll be notified when an admin responds.
                </p>
            </div>

            <p v-if="!applications.length" class="text-center py-6 text-gray-500">You have no applications waiting.</p>

            <ul v-else class="divide-y divide-gray-100">
                <li v-for="application in applications" :key="application.id" class="py-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span class="font-medium text-gray-900">{{ application.organization?.deleted ? 'Deleted organization' : application.organization?.name }}</span>
                    <span class="text-gray-500">Requested {{ application.createdAt }}</span>
                </li>
            </ul>

            <p v-if="loadError" class="mt-3 text-sm text-red-700" role="alert">{{ loadError }}</p>

            <div v-if="nextPageUrl" class="text-center mt-4">
                <button type="button" @click="loadMore" :disabled="loadingMore" class="text-sm text-blue-600 hover:underline">
                    {{ loadingMore ? 'loading...' : 'load more' }}
                </button>
            </div>
        </div>
    </section>
</template>
