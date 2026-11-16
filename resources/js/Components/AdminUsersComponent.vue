<template>
    <div v-bind="$attrs">
        <FormLoader class="mx-auto" :show="loading" :text="'getting users'"/>

        <AdminTable
            :columns="columns"
            caption="Users"
            :empty="!loading && !users.data.length"
            empty-text="No users found."
        >
            <AdminUserRow
                v-for="user in users.data"
                :key="user.id"
                :user="user"
                :busy="busyId === user.id"
                @edit="(u) => open('update', u)"
                @delete="(u) => open('delete', u)"
                @reset-two-factor="openReset"
                @cancel-reset="cancelReset"
            />
        </AdminTable>

        <div class="flex justify-center items-center w-full my-6" v-if="users.data.length && users.page">
            <PrimaryButton :disabled="loading" @click="() => getUsers()">get more</PrimaryButton>
        </div>
    </div>

    <MiniModal
        :show="modalData.show && modalData.type == 'delete'"
        @close="closeModal"
    >
        <div v-if="selected">
            <FormLoader :danger="true" class="mx-auto" :show="saving" :text="'deleting user account'"/>
            <div class="text-gray-800 text-center font-bold tracking-wide">
                Delete @{{ selected.username }}'s account
            </div>

            <hr class="my-2">

            <div class="my-4 text-sm text-red-700 text-center w-[90%] mx-auto font-bold tracking-wide">
                Are you sure you want to delete this account?
            </div>

            <div class="flex space-x-2 justify-end items-center w-full p-2">
                <SecondaryButton type="button" @click="() => closeModal()" class="shrink-0">cancel</SecondaryButton>
                <DangerButton :disabled="saving" @click="deleteUser" class="shrink-0">delete</DangerButton>
            </div>
        </div>
    </MiniModal>

    <MiniModal
        :show="modalData.show && modalData.type == 'resetTwoFactor'"
        @close="closeModal"
    >
        <form v-if="selected" @submit.prevent="resetTwoFactor">
            <FormLoader class="mx-auto" :show="saving" :text="'resetting two-factor sign-in'"/>
            <div class="text-gray-800 text-center font-bold tracking-wide">
                Reset @{{ selected.username }}'s two-factor sign-in
            </div>

            <hr class="my-2">

            <div class="space-y-3 p-2 text-sm text-gray-700">
                <p>
                    Only do this once you're sure it's really them -- for someone who lost both their phone and their
                    recovery codes (follow the identity-check procedure). It turns two-factor sign-in off after a
                    waiting period, unless they cancel it: they can then sign in with their password alone.
                </p>
                <p v-if="selected.emailVerified && !selected.googleOnlyNotice">They're told now, by email with a link to cancel it, and signed out everywhere when it applies.</p>
                <p v-if="!selected.emailVerified && !selected.googleOnlyNotice" class="rounded-md border border-amber-200 bg-amber-50 p-2 text-amber-900">
                    This account has no verified email, so they couldn't cancel it. Reset it on the server instead.
                </p>
                <!-- SCRUM-456: what a reset means when Google is the only way in -- done on the server, which asks to confirm it. -->
                <p v-if="selected.googleOnlyNotice" class="rounded-md border border-amber-200 bg-amber-50 p-2 text-amber-900">{{ selected.googleOnlyNotice }}</p>
                <div>
                    <InputLabel for="reset_two_factor_password" value="Your password" />
                    <TextInput
                        id="reset_two_factor_password"
                        v-model="resetPassword"
                        type="password"
                        class="mt-1 block w-full"
                        autocomplete="current-password"
                    />
                </div>
                <!-- When this administrator has two-factor on, their own code too. -->
                <div v-if="$page.props.auth.user?.twoFactorEnabled">
                    <InputLabel for="reset_two_factor_code" value="A code from your app, or one of your recovery codes" />
                    <TextInput
                        id="reset_two_factor_code"
                        v-model="resetCode"
                        type="text"
                        class="mt-1 block w-full"
                        autocomplete="one-time-code"
                        maxlength="20"
                    />
                </div>
                <div>
                    <InputLabel for="reset_two_factor_reason" value="Ticket number or short note (optional)" />
                    <TextInput
                        id="reset_two_factor_reason"
                        v-model="resetReason"
                        type="text"
                        class="mt-1 block w-full"
                        maxlength="255"
                    />
                    <p class="mt-1 text-xs text-gray-500">Kept with the reset, never shown to them. No health or personal details.</p>
                </div>
                <InputError :message="resetError" />
            </div>

            <div class="flex space-x-2 justify-end items-center w-full p-2">
                <SecondaryButton type="button" @click="() => closeModal()" class="shrink-0">cancel</SecondaryButton>
                <DangerButton type="submit" :disabled="saving || !selected.emailVerified || !!selected.googleOnlyNotice || !resetPassword || ($page.props.auth.user?.twoFactorEnabled && !resetCode)" class="shrink-0">schedule reset</DangerButton>
            </div>
        </form>
    </MiniModal>

    <AdminUserUpdateModal
        v-if="selected"
        :show="modalData.show && modalData.type == 'update'"
        @close="closeModal"
        @updated="updateUser"
        :user="selected"
    />

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>

<script setup>
// SCRUM-430: the admin users list as a table (AdminTable / AdminUserRow) with each person's actions
// in their row. This component owns the paging, the modals and the requests; one set of modals for
// the whole list, opened for the person picked.
import useAuth from "@/Composables/useAuth"
import useAlert from "@/Composables/useAlert"
import useModal from "@/Composables/useModal"
import Alert from "@/Components/Alert.vue"
import AdminTable from "@/Components/Admin/AdminTable.vue"
import AdminUserRow from "@/Components/Admin/AdminUserRow.vue"
import AdminUserUpdateModal from "@/Components/AdminUserUpdateModal.vue"
import { computed, onBeforeMount, ref, watch } from "vue"
import { usePage } from "@inertiajs/vue3"
import PrimaryButton from "./PrimaryButton.vue"
import SecondaryButton from "./SecondaryButton.vue"
import DangerButton from "./DangerButton.vue"
import FormLoader from "./FormLoader.vue"
import MiniModal from "./MiniModal.vue"
import InputLabel from "./InputLabel.vue"
import InputError from "./InputError.vue"
import TextInput from "./TextInput.vue"

const { goToLogin } = useAuth()
const { alertData, setFailedAlertData, clearAlertData, setSuccessAlertData } = useAlert()
const { modalData, closeModal, showModal } = useModal()

const props = defineProps({
    show: {
        type: Boolean,
        default: false
    },
    // SCRUM-390: the page's search (names, username, email).
    search: {
        type: String,
        default: '',
    },
})

const columns = computed(() => [
    { label: 'User' },
    { label: 'Email' },
    { label: 'Verified' },
    { label: 'Role' },
    { label: 'Status' },
    ...(usePage().props.auth.user?.isSuperAdmin ? [{ label: 'Two-factor' }] : []),
    { label: 'Actions', srOnly: true },
])

watch(() => props.search, () => {
    users.value.page = 1
    getUsers()
})

const loading = ref(false)
const busyId = ref(null)
// The modals' own requests (delete, reset) -- separate from loading the list.
const saving = ref(false)
const selected = ref(null)
const users = ref({
    data: [],
    page: 1,
})

onBeforeMount(() => {
    if (props.show && users.value.page == 1)
        getUsers()
})

function open(type, user) {
    selected.value = user
    showModal(type)
}

// The update and reset routes send the row back as the list shows it (UserService::forAdminRow).
function updateUser(user) {
    const index = users.value.data.findIndex((u) => u.id == user.id)
    if (index !== -1) users.value.data.splice(index, 1, user)
}

function removeUser(user) {
    const index = users.value.data.findIndex((u) => u.id == user.id)
    if (index !== -1) users.value.data.splice(index, 1)
}

// Only the latest load's answer is shown, so a slow older search can't mix into a newer one (review).
let latestLoad = 0

async function getUsers() {
    const ticket = ++latestLoad
    loading.value = true

    await axios.get(route('admin.users', {
        page: users.value.page,
        ...(props.search ? { search: props.search } : {}),
    }))
        .then((res) => {
            if (ticket !== latestLoad) return
            if (users.value.page == 1)
                users.value.data = []

            users.value.data = [
                ...users.value.data,
                ...res.data.data,
            ]

            users.value.page = res.data.links.next ? users.value.page + 1 : 0
        })
        .catch((err) => {
            console.log(err)
            setFailedAlertData({
                message: "Something unfortunate happened. Please try again in a short while."
            })
            goToLogin(err)
        })
        .finally(() => {
            if (ticket === latestLoad) loading.value = false
        })
}

// SCRUM-34c
const resetPassword = ref('')
const resetCode = ref('')
const resetError = ref('')
const resetReason = ref('')

function openReset(user) {
    resetPassword.value = ''
    resetCode.value = ''
    resetReason.value = ''
    resetError.value = ''
    open('resetTwoFactor', user)
}

async function resetTwoFactor() {
    if (saving.value || !selected.value) return
    const user = selected.value
    saving.value = true
    resetError.value = ''

    await axios
    .post(route('admin.users.two_factor.reset', { userId: user.id }), { current_password: resetPassword.value, code: resetCode.value || null, reason: resetReason.value || null })
    .then((res) => {
        setSuccessAlertData({ message: `A reset of ${user.username}'s two-factor sign-in is scheduled. They've been told and can cancel it.` })
        updateUser(res.data.user)
        closeModal()
    })
    .catch((err) => {
        resetError.value = err.response?.data?.errors?.current_password?.[0]
            ?? err.response?.data?.errors?.code?.[0]
            ?? err.response?.data?.message
            ?? 'Something unfortunate happened. Please try again later.'
    })
    .finally(() => {
        resetPassword.value = ''
        resetCode.value = ''
        saving.value = false
    })
}

// SCRUM-424
async function cancelReset(user) {
    if (busyId.value) return
    busyId.value = user.id

    await axios
    .delete(route('admin.users.two_factor.reset.cancel', { userId: user.id }))
    .then((res) => {
        setSuccessAlertData({ message: `The scheduled two-factor reset for ${user.username} is cancelled.` })
        updateUser(res.data.user)
    })
    .catch((err) => {
        setFailedAlertData({ message: err.response?.data?.message ?? 'Something unfortunate happened. Please try again later.' })
    })
    .finally(() => { busyId.value = null })
}

async function deleteUser() {
    if (saving.value || !selected.value) return
    const user = selected.value
    saving.value = true

    await axios
    .delete(route(`admin.users.delete`, { userId: user.id }))
    .then(() => {
        setSuccessAlertData({
            message: `${user.username}'s account has been deleted.`,
        })
        removeUser(user)
        closeModal()
    })
    .catch((err) => {
        setFailedAlertData({
            message: err.response?.data?.message ?? 'Something unfortunate happened. Please try again later.',
        })
    })
    .finally(() => {
        saving.value = false
    })
}
</script>
