<script setup>
import { ref, onMounted } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

// useAlert() is deliberately non-singleton -- calling it locally here would update state
// nothing renders, since only the parent MyOrganizations.vue mounts an <Alert>. Emit up instead.
const emit = defineEmits(['alert', 'applied'])

const organizations = ref([])
const nextPageUrl = ref(null)
const loading = ref(false)
const loadingMore = ref(false)
const applyingId = ref(null)

async function load() {
    loading.value = true
    await axios.get(route('organizations.index', { isProvider: true }))
        .then((res) => {
            organizations.value = [...res.data.data]
            nextPageUrl.value = res.data.links?.next ?? null
        })
        .finally(() => {
            loading.value = false
        })
}

async function loadMore() {
    if (!nextPageUrl.value) return

    loadingMore.value = true
    await axios.get(nextPageUrl.value)
        .then((res) => {
            organizations.value = [...organizations.value, ...res.data.data]
            nextPageUrl.value = res.data.links?.next ?? null
        })
        .finally(() => {
            loadingMore.value = false
        })
}

async function apply(organization) {
    applyingId.value = organization.id
    await axios.post(route('organizations.counsellors.apply', { organizationId: organization.id }))
        .then(() => {
            emit('alert', { type: 'success', message: `Application sent to ${organization.name}.` })
            emit('applied')
        })
        .catch((err) => {
            emit('alert', { type: 'failed', message: err.response?.data?.message ?? 'Could not send the application.' })
        })
    applyingId.value = null
}

onMounted(load)
</script>

<template>
    <div class="bg-white overflow-hidden shadow-xl border border-gray-100 sm:rounded-xl">
        <div class="p-6 sm:p-8">
            <div class="mb-4">
                <div class="text-xl font-bold text-gray-900">Browse Organizations</div>
                <div class="w-12 h-1 bg-blue-600 mt-2"></div>
                <div class="text-sm text-gray-600 mt-2">Verified organizations open to counsellor affiliation.</div>
            </div>

            <div v-if="loading" class="text-center py-8 text-gray-500">loading...</div>
            <div v-else-if="!organizations.length" class="text-center py-8 text-gray-500">No verified organizations to show right now.</div>

            <div v-else class="space-y-3">
                <div v-for="organization in organizations" :key="organization.id" class="border border-gray-200 rounded-lg p-4 flex items-center justify-between">
                    <div>
                        <div class="text-sm font-semibold text-gray-900">{{ organization.name }}</div>
                        <div v-if="organization.description" class="text-xs text-gray-500 mt-1">{{ organization.description }}</div>
                    </div>
                    <PrimaryButton :disabled="applyingId === organization.id" @click="() => apply(organization)">apply</PrimaryButton>
                </div>

                <div v-if="nextPageUrl" class="text-center mt-4">
                    <button @click="loadMore" :disabled="loadingMore" class="text-sm text-blue-600 hover:underline">
                        {{ loadingMore ? 'loading...' : 'load more' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
