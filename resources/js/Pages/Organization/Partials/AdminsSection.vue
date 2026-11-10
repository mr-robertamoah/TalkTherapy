<script setup>
import { ref, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import FormLoader from '@/Components/FormLoader.vue';
import SearchSelect from '@/Components/SearchSelect.vue';

const props = defineProps({
    organizationId: {
        type: [Number, String],
        required: true,
    },
    initialAdmins: {
        type: Array,
        required: true,
    },
})

// useAlert() is deliberately non-singleton -- calling it locally here would update state
// nothing renders, since only the parent Show.vue mounts an <Alert>. Emit up instead.
const emit = defineEmits(['alert'])

const currentUserId = usePage().props.auth.user?.id
const admins = ref([...props.initialAdmins])

// AC1/AC2: only an owner sees/can use add/promote/demote/remove -- real enforcement lives
// server-side (EnsureUserIsOrganizationOwnerAction via OrganizationAdminService), this is
// purely a display convenience so a plain admin isn't shown controls that would just 403.
const isOwner = computed(() => admins.value.find((admin) => admin.id === currentUserId)?.role === 'OWNER')

const showInviteModal = ref(false)
const inviting = ref(false)
const selectedUser = ref(null)
const roleInput = ref('ADMIN')
const inviteError = ref('')

const actingOnId = ref(null)

function applyAdmins(newAdmins) {
    admins.value = [...newAdmins]
}

async function invite() {
    inviteError.value = ''
    inviting.value = true

    await axios.post(route('organizations.admins.store', { organizationId: props.organizationId }), {
        userId: selectedUser.value?.id,
        role: roleInput.value,
    })
        .then((res) => {
            applyAdmins(res.data.admins)
            emit('alert', { type: 'success', message: 'Admin added.' })
            showInviteModal.value = false
            selectedUser.value = null
            roleInput.value = 'ADMIN'
        })
        .catch((err) => {
            inviteError.value = err.response?.data?.message ?? 'Could not add the admin.'
            emit('alert', { type: 'failed', message: inviteError.value })
        })

    inviting.value = false
}

async function setRole(admin, role) {
    actingOnId.value = admin.id

    await axios.patch(route('organizations.admins.update', { organizationId: props.organizationId, userId: admin.id }), { role })
        .then((res) => {
            applyAdmins(res.data.admins)
            emit('alert', { type: 'success', message: 'Admin role updated.' })
        })
        .catch((err) => {
            // AC3: a rejected demote/remove of the last owner surfaces the backend's own message.
            emit('alert', { type: 'failed', message: err.response?.data?.message ?? 'Could not update the admin role.' })
        })

    actingOnId.value = null
}

async function remove(admin) {
    actingOnId.value = admin.id

    await axios.delete(route('organizations.admins.destroy', { organizationId: props.organizationId, userId: admin.id }))
        .then((res) => {
            applyAdmins(res.data.admins)
            emit('alert', { type: 'success', message: 'Admin removed.' })
        })
        .catch((err) => {
            emit('alert', { type: 'failed', message: err.response?.data?.message ?? 'Could not remove the admin.' })
        })

    actingOnId.value = null
}
</script>

<template>
    <div class="bg-white overflow-hidden shadow-xl border border-gray-100 sm:rounded-xl">
        <div class="p-6 sm:p-8">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <div class="text-xl font-bold text-gray-900">Admins</div>
                    <div class="w-12 h-1 bg-blue-600 mt-2"></div>
                </div>
                <PrimaryButton v-if="isOwner" @click="() => showInviteModal = true">add admin</PrimaryButton>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-left">
                    <thead>
                        <tr class="text-gray-500 uppercase text-xs">
                            <th class="py-2 pr-4">Admin</th>
                            <th class="py-2 pr-4">Role</th>
                            <th v-if="isOwner" class="py-2 pr-4"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="admin in admins" :key="admin.id" class="border-t border-gray-100">
                            <td class="py-3 pr-4">{{ admin.fullName ?? admin.username }}</td>
                            <td class="py-3 pr-4">
                                <span class="text-xs font-semibold px-2 py-1 rounded-full" :class="admin.role === 'OWNER' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700'">
                                    {{ admin.role === 'OWNER' ? 'Owner' : 'Admin' }}
                                </span>
                            </td>
                            <td v-if="isOwner" class="py-3 pr-4">
                                <div class="flex items-center space-x-2">
                                    <SecondaryButton
                                        @click="() => setRole(admin, admin.role === 'OWNER' ? 'ADMIN' : 'OWNER')"
                                        :disabled="actingOnId === admin.id"
                                        class="text-xs"
                                    >{{ admin.role === 'OWNER' ? 'demote' : 'promote' }}</SecondaryButton>
                                    <DangerButton
                                        @click="() => remove(admin)"
                                        :disabled="actingOnId === admin.id"
                                        class="text-xs"
                                    >remove</DangerButton>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <Modal :show="showInviteModal" @close="() => { showInviteModal = false; selectedUser = null }">
        <div class="p-6">
            <div class="text-lg font-bold text-gray-900 mb-4">Add an Admin</div>
            <FormLoader :show="inviting" text="adding admin" />

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

                <div class="mt-4">
                    <InputLabel for="role" value="Role" />
                    <select id="role" v-model="roleInput" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <option value="ADMIN">Admin</option>
                        <option value="OWNER">Owner</option>
                    </select>
                </div>

                <div class="flex items-center justify-end mt-4">
                    <PrimaryButton :disabled="inviting || !selectedUser">add admin</PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>
