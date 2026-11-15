<script setup>
// SCRUM-387: the old /administrator "counsellors > show" tab as its own page -- the admin.counsellors
// list with "get more" paging and a name search (SCRUM-390: the shared admin search). The search is
// in the URL (?search=), so a search can be shared or reloaded.
import { Head } from '@inertiajs/vue3'
import { onMounted, ref, watch } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import FormLoader from '@/Components/FormLoader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import MiniModal from '@/Components/MiniModal.vue'
import CounsellorModal from '@/Components/CounsellorModal.vue'
import Alert from '@/Components/Alert.vue'
import AdminSearchBox from '@/Components/Admin/AdminSearchBox.vue'
import AdminTable from '@/Components/Admin/AdminTable.vue'
import AdminCounsellorRow from '@/Components/Admin/AdminCounsellorRow.vue'
import useAlert from '@/Composables/useAlert'

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

// SCRUM-430: a table; the delete confirmation is the page's (one for the list).
const columns = [
    { label: 'Counsellor' },
    { label: 'Profession' },
    { label: 'Contact' },
    { label: 'Verification' },
    { label: 'Actions', srOnly: true },
]

const { alertData, setFailedAlertData, setSuccessAlertData, clearAlertData } = useAlert()
const toDelete = ref(null)
const viewing = ref(null)
const lastViewed = ref(null)
const deleting = ref(false)

async function deleteCounsellor() {
    const counsellor = toDelete.value
    if (!counsellor || deleting.value) return
    deleting.value = true

    await axios.delete(route('admin.counsellors.delete', { counsellorId: counsellor.id }))
        .then(() => {
            setSuccessAlertData({ message: `${counsellor.name}'s counsellor account has been deleted.` })
            counsellors.value = counsellors.value.filter((c) => c.id !== counsellor.id)
            toDelete.value = null
        })
        .catch((err) => {
            setFailedAlertData({ message: err.response?.data?.message ?? 'Something unfortunate happened. Please try again later.' })
        })
        .finally(() => { deleting.value = false })
}
</script>

<template>
    <Head title="Counsellors" />

    <div class="my-8 w-full max-w-7xl mx-auto space-y-6 px-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Counsellors</h1>
            <div class="w-16 h-1 bg-blue-600 mt-2"></div>
            <p class="mt-2 text-sm text-gray-600">Every counsellor profile. A super administrator can remove one that has nothing in progress.</p>
        </div>

        <AdminSearchBox :model-value="term" label="Search by name or username" @search="searchFor" />

        <div class="relative">
            <FormLoader class="mx-auto" :show="loading" :text="'getting counsellors'" />

            <AdminTable
                :columns="columns"
                caption="Counsellors"
                :empty="!loading && counsellors.length === 0"
                empty-text="No counsellors found."
            >
                <AdminCounsellorRow
                    v-for="counsellor in counsellors"
                    :key="counsellor.id"
                    :counsellor="counsellor"
                    @view="(c) => { viewing = c; lastViewed = c }"
                    @delete="(c) => toDelete = c"
                />
            </AdminTable>

            <div v-if="nextPage && counsellors.length && !loading" class="flex justify-center pt-6">
                <PrimaryButton @click="() => getCounsellors()">get more</PrimaryButton>
            </div>
        </div>
    </div>

    <!-- Kept mounted once opened (the last one shown), so it opens and closes like any modal. -->
    <CounsellorModal v-if="lastViewed" :show="!!viewing" :counsellor="lastViewed" @close="() => viewing = null" />

    <MiniModal :show="!!toDelete" @close="() => toDelete = null">
        <div v-if="toDelete">
            <FormLoader :danger="true" class="mx-auto" :show="deleting" :text="'deleting counsellor account'"/>
            <div class="text-gray-800 text-center font-bold tracking-wide">
                Delete {{ toDelete.name }}'s counsellor account
            </div>

            <hr class="my-2">

            <div class="my-4 text-sm text-red-700 text-center w-[90%] mx-auto font-bold tracking-wide">
                Are you sure you want to delete this counsellor account? This is the same eligibility-gated
                deletion the counsellor themselves would trigger -- it will fail if they have pending sessions,
                an in-session therapy, an active group therapy or organization affiliation, or pending requests
                awaiting their decision.
            </div>

            <div class="flex space-x-2 justify-end items-center w-full p-2">
                <SecondaryButton type="button" @click="() => toDelete = null" class="shrink-0">cancel</SecondaryButton>
                <DangerButton :disabled="deleting" @click="deleteCounsellor" class="shrink-0">delete</DangerButton>
            </div>
        </div>
    </MiniModal>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>
