<script setup>
import { ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import FormLoader from '@/Components/FormLoader.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchSelect from '@/Components/SearchSelect.vue';

const props = defineProps({
    organizationId: {
        type: [Number, String],
        required: true,
    },
    initialCounsellors: {
        type: Object,
        required: true,
    },
})

// useAlert() is deliberately non-singleton -- calling it locally here would update state
// nothing renders, since only the parent Show.vue mounts an <Alert>. Emit up instead.
const emit = defineEmits(['alert', 'invited'])

const counsellors = ref([...props.initialCounsellors.data])
const meta = ref(props.initialCounsellors.meta)
const loadingPage = ref(false)
const showInviteModal = ref(false)
const inviting = ref(false)
const selectedCounsellor = ref(null)
const inviteError = ref('')

// AC7: an affiliation that exists but is PENDING (compensation not yet finalized) is a
// different state from a pending Request in the queue below -- worded distinctly here.
function statusLabel(counsellor) {
    if (counsellor.status === 'PENDING') return 'Affiliated -- awaiting compensation agreement'
    if (counsellor.status === 'ACTIVE') return 'Active'
    return 'Ended'
}

function compensationSummary(counsellor) {
    const compensation = counsellor.compensation
    if (!compensation) return 'No compensation terms set'
    if (compensation.type === 'FREE') return 'Free'
    if (compensation.type === 'FIXED') return `${compensation.currency} ${compensation.amount}`
    if (compensation.type === 'PERCENTAGE') return `${compensation.percentage}% of ${compensation.basis === 'COUNSELLOR_RATE' ? "counsellor's rate" : 'negotiated rate'}`
    return '--'
}

async function goToPage(url) {
    if (!url) return

    loadingPage.value = true
    await axios.get(url)
        .then((res) => {
            counsellors.value = [...res.data.data]
            meta.value = res.data.meta
        })
        .finally(() => {
            loadingPage.value = false
        })
}

async function invite() {
    inviteError.value = ''
    inviting.value = true

    await axios.post(route('organizations.counsellors.invite', { organizationId: props.organizationId }), {
        counsellorId: selectedCounsellor.value?.id,
    })
        .then(() => {
            emit('alert', { type: 'success', message: 'Invite sent.' })
            emit('invited')
            showInviteModal.value = false
            selectedCounsellor.value = null
        })
        .catch((err) => {
            inviteError.value = err.response?.data?.message ?? 'Could not send the invite.'
            emit('alert', { type: 'failed', message: inviteError.value })
        })

    inviting.value = false
}
</script>

<template>
    <div class="bg-white overflow-hidden shadow-xl border border-gray-100 sm:rounded-xl">
        <div class="p-6 sm:p-8">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <div class="text-xl font-bold text-gray-900">Affiliated Counsellors</div>
                    <div class="w-12 h-1 bg-blue-600 mt-2"></div>
                </div>
                <PrimaryButton @click="() => showInviteModal = true">invite counsellor</PrimaryButton>
            </div>

            <div v-if="!counsellors.length" class="text-center py-8 text-gray-500">No counsellors affiliated yet.</div>

            <div v-else class="overflow-x-auto">
                <table class="min-w-full text-sm text-left">
                    <thead>
                        <tr class="text-gray-500 uppercase text-xs">
                            <th class="py-2 pr-4">Counsellor</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 pr-4">Compensation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="counsellor in counsellors" :key="counsellor.id" class="border-t border-gray-100">
                            <td class="py-3 pr-4">{{ counsellor.counsellor?.deleted ? 'Deleted counsellor' : counsellor.counsellor?.name }}</td>
                            <td class="py-3 pr-4">{{ statusLabel(counsellor) }}</td>
                            <td class="py-3 pr-4">{{ compensationSummary(counsellor) }}</td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="loadingPage" class="text-center mt-4 text-sm text-gray-500">loading...</div>
                <Pagination v-else :meta="meta" @navigate="goToPage" />
            </div>
        </div>
    </div>

    <Modal :show="showInviteModal" @close="() => { showInviteModal = false; selectedCounsellor = null }">
        <div class="p-6">
            <div class="text-lg font-bold text-gray-900 mb-4">Invite a Counsellor</div>
            <FormLoader :show="inviting" text="sending invite" />

            <form @submit.prevent="invite">
                <InputLabel for="counsellor" value="Counsellor" />
                <SearchSelect
                    id="counsellor"
                    v-model="selectedCounsellor"
                    search-route="api.counsellors"
                    search-param="name"
                    placeholder="search for counsellor by name or username"
                    :disabled="inviting"
                />
                <InputError class="mt-2" :message="inviteError" />

                <div class="flex items-center justify-end mt-4">
                    <PrimaryButton :disabled="inviting || !selectedCounsellor">send invite</PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>
