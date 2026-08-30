<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    initialAdministeredOrganizations: {
        type: Object,
        required: true,
    },
})

const organizations = ref([...props.initialAdministeredOrganizations.data])
const nextPageUrl = ref(props.initialAdministeredOrganizations.links?.next ?? null)
const loadingMore = ref(false)

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
</script>

<template>
    <div class="bg-white overflow-hidden shadow-xl border border-gray-100 sm:rounded-xl">
        <div class="p-6 sm:p-8">
            <div class="mb-4">
                <div class="text-xl font-bold text-gray-900">Organizations I Administer</div>
                <div class="w-12 h-1 bg-blue-600 mt-2"></div>
            </div>

            <div v-if="!organizations.length" class="text-center py-8 text-gray-500">You don't administer any organization yet.</div>

            <div v-else class="space-y-3">
                <div v-for="organization in organizations" :key="organization.id" class="border border-gray-200 rounded-lg p-4 flex items-center justify-between">
                    <div>
                        <div class="text-sm font-semibold text-gray-900">{{ organization.name }}</div>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="text-xs font-semibold px-2 py-1 rounded-full" :class="organization.isVerified ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'">
                                {{ organization.isVerified ? 'Verified' : 'Verification pending' }}
                            </span>
                            <span class="text-xs font-semibold px-2 py-1 rounded-full" :class="organization.role === 'OWNER' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700'">
                                {{ organization.role === 'OWNER' ? 'Owner' : 'Admin' }}
                            </span>
                        </div>
                    </div>
                    <Link
                        :href="route('organizations.dashboard', { organizationId: organization.id })"
                        class="disabled:bg-gray-600 inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                    >open dashboard</Link>
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
