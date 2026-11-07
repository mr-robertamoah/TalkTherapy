<script setup>
// SCRUM-387: the old /administrator "counsellors > show" tab as its own page -- the admin.counsellors
// list with "get more" paging and a name search (SCRUM-390: the shared admin search). The search is
// in the URL (?search=), so a search can be shared or reloaded.
import { Head } from '@inertiajs/vue3'
import { onMounted, ref, watch } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import CounsellorComponent from '@/Components/CounsellorComponent.vue'
import FormLoader from '@/Components/FormLoader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AdminSearchBox from '@/Components/Admin/AdminSearchBox.vue'

defineOptions({ layout: AdminLayout })

const props = defineProps({
    search: { type: String, default: '' },
})

// SCRUM-390: the shared admin search (AdminSearchBox, ?search= on admin.counsellors).
const term = ref(props.search)
const counsellors = ref([])
const nextPage = ref(1)
const loading = ref(false)
let latest = 0

async function getCounsellors({ reset = false } = {}) {
    if (reset) nextPage.value = 1
    if (!nextPage.value) return

    const ticket = ++latest
    const page = nextPage.value
    const query = { page }
    if (term.value) query.search = term.value

    loading.value = true
    await axios.get(route('admin.counsellors', query))
        .then((res) => {
            if (ticket !== latest) return
            counsellors.value = page > 1 ? [...counsellors.value, ...res.data.data] : [...res.data.data]
            nextPage.value = res.data?.links?.next ? page + 1 : 0
        })
        .catch((err) => console.log(err))
        .finally(() => {
            if (ticket === latest) loading.value = false
        })
}

// Keep the URL in step without a server round trip (the list is fetched over the API): the
// history entry Inertia saved stays, with its URL and its `search` prop changed, so coming back to
// it (back/forward, a restored tab) shows this search, not the one the page was first opened with.
function searchFor(search) {
    term.value = search
    const url = route('administrator.counsellors', search ? { search } : {})
    const state = window.history.state ?? {}
    window.history.replaceState({ ...state, url, props: { ...(state.props ?? {}), search } }, '', url)
    getCounsellors({ reset: true })
}

// And the other way: a visit that brings a different ?search= shows it.
watch(() => props.search, (search) => {
    if ((search ?? '') !== term.value) {
        term.value = search ?? ''
        getCounsellors({ reset: true })
    }
})

onMounted(() => getCounsellors({ reset: true }))

function deletedCounsellor(counsellor) {
    counsellors.value = counsellors.value.filter((c) => c.id !== counsellor.id)
}
</script>

<template>
    <Head title="Counsellors" />

    <div class="my-8 w-full max-w-5xl mx-auto space-y-6 px-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Counsellors</h1>
            <div class="w-16 h-1 bg-blue-600 mt-2"></div>
            <p class="mt-2 text-sm text-gray-600">Every counsellor profile. A super administrator can remove one that has nothing in progress.</p>
        </div>

        <div class="bg-white shadow-sm border border-gray-200 rounded-lg p-4 sm:p-6 space-y-4">
            <AdminSearchBox :model-value="term" label="Search by name or username" @search="searchFor" />

            <div class="relative space-y-3">
                <FormLoader class="mx-auto" :show="loading" :text="'getting counsellors'" />

                <div v-if="!loading && counsellors.length === 0" class="text-sm text-gray-600">No counsellors found.</div>

                <div class="flex flex-wrap gap-3">
                    <CounsellorComponent
                        v-for="counsellor in counsellors"
                        :key="counsellor.id"
                        :counsellor="counsellor"
                        :can-delete="true"
                        class="bg-stone-200"
                        @deleted="deletedCounsellor(counsellor)"
                    />
                </div>

                <div v-if="nextPage && counsellors.length && !loading" class="flex justify-center pt-2">
                    <PrimaryButton @click="() => getCounsellors()">get more</PrimaryButton>
                </div>
            </div>
        </div>
    </div>
</template>
