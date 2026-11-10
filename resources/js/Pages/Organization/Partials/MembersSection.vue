<script setup>
import { ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';
import FormLoader from '@/Components/FormLoader.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import useErrorHandler from '@/Composables/useErrorHandler';

const props = defineProps({
    organizationId: {
        type: [Number, String],
        required: true,
    },
    initialMembers: {
        type: Object,
        required: true,
    },
})

// useAlert() is deliberately non-singleton -- calling it locally here would update state
// nothing renders, since only the parent Show.vue mounts an <Alert>. Emit up instead.
const emit = defineEmits(['alert', 'invited'])
const { setErrorData } = useErrorHandler()

const members = ref([...props.initialMembers.data])
const meta = ref(props.initialMembers.meta)
const loadingPage = ref(false)

const showInviteModal = ref(false)
const inviting = ref(false)
const selectedUser = ref(null)
const inviteError = ref('')

const billingConfigMember = ref(null)
const billingForm = ref({ mode: 'RETAINER', per: 'PER_THERAPY', includeGroupTherapies: true })
const savingBillingConfig = ref(false)
const billingErrors = ref({})

// AC7: an affiliation that exists but is PENDING (billing not yet finalized) is a different
// state from a pending Request in the queue below -- worded distinctly here.
function statusLabel(member) {
    if (member.status === 'PENDING') return 'Affiliated -- awaiting billing agreement'
    if (member.status === 'ACTIVE') return 'Active'
    return 'Ended'
}

function billingSummary(member) {
    const billingConfig = member.billingConfig
    if (!billingConfig) return 'No billing config set'
    if (billingConfig.mode === 'RETAINER') return 'Retainer'
    return `Pay per use (${billingConfig.per === 'PER_THERAPY' ? 'per therapy' : 'per session'})`
}

async function goToPage(url) {
    if (!url) return

    loadingPage.value = true
    await axios.get(url)
        .then((res) => {
            members.value = [...res.data.data]
            meta.value = res.data.meta
        })
        .finally(() => {
            loadingPage.value = false
        })
}

async function invite() {
    inviteError.value = ''
    inviting.value = true

    await axios.post(route('organizations.members.invite', { organizationId: props.organizationId }), {
        userId: selectedUser.value?.id,
    })
        .then(() => {
            emit('alert', { type: 'success', message: 'Invite sent.' })
            emit('invited')
            showInviteModal.value = false
            selectedUser.value = null
        })
        .catch((err) => {
            inviteError.value = err.response?.data?.message ?? 'Could not send the invite.'
            emit('alert', { type: 'failed', message: inviteError.value })
        })

    inviting.value = false
}

function openBillingConfig(member) {
    billingConfigMember.value = member
    billingForm.value = {
        mode: member.billingConfig?.mode ?? 'RETAINER',
        per: member.billingConfig?.per ?? 'PER_THERAPY',
        includeGroupTherapies: member.billingConfig?.includeGroupTherapies ?? true,
    }
    billingErrors.value = {}
}

async function saveBillingConfig() {
    savingBillingConfig.value = true
    billingErrors.value = {}

    await axios.post(route('organization_members.billing_configs.store', { organizationMemberId: billingConfigMember.value.id }), billingForm.value)
        .then((res) => {
            const member = members.value.find((m) => m.id === billingConfigMember.value.id)
            if (member) member.billingConfig = res.data.billingConfig ?? res.data
            emit('alert', { type: 'success', message: 'Billing config saved.' })
            billingConfigMember.value = null
        })
        .catch((err) => {
            // Laravel's validation shape is {field: [messages]} -- setErrorData unwraps each to
            // a plain string InputError can render, rather than displaying the raw array.
            if (err.response?.data?.errors) {
                setErrorData(billingErrors, err.response.data.errors, ['mode', 'per', 'includeGroupTherapies'])
            }
            emit('alert', { type: 'failed', message: err.response?.data?.message ?? 'Could not save billing config.' })
        })

    savingBillingConfig.value = false
}
</script>

<template>
    <div class="bg-white overflow-hidden shadow-xl border border-gray-100 sm:rounded-xl">
        <div class="p-6 sm:p-8">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <div class="text-xl font-bold text-gray-900">Members</div>
                    <div class="w-12 h-1 bg-blue-600 mt-2"></div>
                </div>
                <PrimaryButton @click="() => showInviteModal = true">invite member</PrimaryButton>
            </div>

            <div v-if="!members.length" class="text-center py-8 text-gray-500">No members yet.</div>

            <div v-else class="overflow-x-auto">
                <table class="min-w-full text-sm text-left">
                    <thead>
                        <tr class="text-gray-500 uppercase text-xs">
                            <th class="py-2 pr-4">Member</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 pr-4">Billing</th>
                            <th class="py-2 pr-4"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="member in members" :key="member.id" class="border-t border-gray-100">
                            <td class="py-3 pr-4">{{ member.user?.deleted ? 'Deleted user' : (member.user?.fullName ?? member.user?.username) }}</td>
                            <td class="py-3 pr-4">{{ statusLabel(member) }}</td>
                            <td class="py-3 pr-4">{{ billingSummary(member) }}</td>
                            <td class="py-3 pr-4">
                                <SecondaryButton @click="() => openBillingConfig(member)" class="text-xs">edit billing</SecondaryButton>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="loadingPage" class="text-center mt-4 text-sm text-gray-500">loading...</div>
                <Pagination v-else :meta="meta" @navigate="goToPage" />
            </div>
        </div>
    </div>

    <Modal :show="showInviteModal" @close="() => { showInviteModal = false; selectedUser = null }">
        <div class="p-6">
            <div class="text-lg font-bold text-gray-900 mb-4">Invite a Member</div>
            <FormLoader :show="inviting" text="sending invite" />

            <form @submit.prevent="invite">
                <InputLabel for="user" value="User" />
                <SearchSelect
                    id="user"
                    v-model="selectedUser"
                    search-route="api.users"
                    placeholder="search for user by name or username"
                    :disabled="inviting"
                />
                <InputError class="mt-2" :message="inviteError" />

                <div class="flex items-center justify-end mt-4">
                    <PrimaryButton :disabled="inviting || !selectedUser">send invite</PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>

    <Modal :show="!!billingConfigMember" @close="() => billingConfigMember = null">
        <div class="p-6" v-if="billingConfigMember">
            <div class="text-lg font-bold text-gray-900 mb-4">Billing Config</div>
            <FormLoader :show="savingBillingConfig" text="saving" />

            <form @submit.prevent="saveBillingConfig" class="space-y-4">
                <div>
                    <InputLabel for="mode" value="Mode" />
                    <select id="mode" v-model="billingForm.mode" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <option value="RETAINER">Retainer</option>
                        <option value="PAY_PER_USE">Pay per use</option>
                    </select>
                    <InputError class="mt-2" :message="billingErrors.mode" />
                </div>

                <div v-if="billingForm.mode === 'PAY_PER_USE'">
                    <InputLabel for="per" value="Per" />
                    <select id="per" v-model="billingForm.per" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <option value="PER_THERAPY">Per therapy</option>
                        <option value="PER_SESSION">Per session</option>
                    </select>
                    <InputError class="mt-2" :message="billingErrors.per" />
                </div>

                <div class="flex items-center">
                    <Checkbox id="includeGroupTherapies" :checked="billingForm.includeGroupTherapies" @update:checked="(val) => billingForm.includeGroupTherapies = val" />
                    <InputLabel for="includeGroupTherapies" value="Include group therapies" class="ml-2" />
                </div>

                <div class="flex items-center justify-end">
                    <PrimaryButton :disabled="savingBillingConfig">save</PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>
