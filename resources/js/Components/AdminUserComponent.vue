<template>
    <div v-bind="$attrs" class="p-4 bg-slate-600 rounded-lg select-none">
        <!-- SCRUM-193: the user's avatar beside their username, when they have one. -->
        <div class="mb-2 flex justify-center items-center gap-2">
            <Avatar v-if="user.avatar" class="shrink-0" :avatar-text="'...'" :size="32" :src="user.avatar"/>
            <div class="text-gray-200 text-sm font-semibold tracking-wide">@{{ user.username ?? 'not set'}}</div>
        </div>
        <div class="flex justify-start items-center overflow-hidden overflow-x-auto p-2 space-x-3">
            <div class="flex flex-col justify-start items-center p-2 rounded bg-slate-100 text-nowrap">
                <div class="mb-2 text-gray-600 min-w-[150px] w-fit text-center text-sm font-semibold tracking-wide">{{ !!user.firstName ? user.firstName : 'not set'}}</div>
                <div class="text-gray-500 text-xs">first name</div>
            </div>
            <div class="flex flex-col justify-start items-center p-2 rounded bg-slate-100 text-nowrap">
                <div class="mb-2 text-gray-600 min-w-[150px] w-fit text-center text-sm font-semibold tracking-wide">{{ !!user.lastName ? user.lastName : 'not set'}}</div>
                <div class="text-gray-500 text-xs">last name</div>
            </div>
            <div class="flex flex-col justify-start items-center p-2 rounded bg-slate-100 text-nowrap">
                <div class="mb-2 text-gray-600 min-w-[150px] w-fit text-center text-sm font-semibold tracking-wide">{{ !!user.otherNames ? user.otherNames : 'not set'}}</div>
                <div class="text-gray-500 text-xs">other names</div>
            </div>
            <div class="flex flex-col justify-start items-center p-2 rounded bg-slate-100 text-nowrap">
                <div class="mb-2 text-gray-600 min-w-[150px] w-fit text-center text-sm font-semibold tracking-wide">{{ !!user.email ? user.email : 'not set'}}</div>
                <div class="text-gray-500 text-xs">email</div>
            </div>
            <div class="flex flex-col justify-start items-center p-2 rounded bg-slate-100 text-nowrap">
                <div class="mb-2 text-gray-600 min-w-[150px] w-fit text-center text-sm font-semibold tracking-wide">{{ computedGender }}</div>
                <div class="text-gray-500 text-xs">gender</div>
            </div>
            <div class="flex flex-col justify-start items-center p-2 rounded bg-slate-100 text-nowrap">
                <div class="mb-2 text-gray-600 min-w-[150px] w-fit text-center text-sm font-semibold tracking-wide">{{ user.age }}</div>
                <div class="text-gray-500 text-xs">age</div>
            </div>
            <div class="flex flex-col justify-start items-center p-2 rounded bg-slate-100 text-nowrap">
                <div class="mb-2 text-gray-600 min-w-[150px] w-fit text-center text-sm font-semibold tracking-wide">{{ !!user.dob ? (new Date(user.dob)).toDateString() : 'not set'}}</div>
                <div class="text-gray-500 text-xs">date of birth</div>
            </div>
            <div class="flex flex-col justify-start items-center p-2 rounded bg-slate-100 text-nowrap">
                <div class="mb-2 text-gray-600 min-w-[150px] w-fit text-center text-sm font-semibold tracking-wide">{{ user.emailVerified ? 'Yes' : 'No'}}</div>
                <div class="text-gray-500 text-xs">email verified</div>
            </div>
            <div class="flex flex-col justify-start items-center p-2 rounded bg-slate-100 text-nowrap">
                <div class="mb-2 text-gray-600 min-w-[150px] w-fit text-center text-sm font-semibold tracking-wide">{{ user.isCounsellor ? 'Yes' : 'No'}}</div>
                <div class="text-gray-500 text-xs">is counsellor</div>
            </div>
        </div>
        <div class="flex flex-col justify-start items-center mt-3">
            
            <div
                @click="() => showActions = !showActions"
                class="text-gray-300 text-xs cursor-pointer hover:bg-gray-300 hover:text-gray-600 p-2 rounded">
                {{ showActions ? 'hide actions' : 'show actions' }}
            </div>
        </div>
        <div class="flex overflow-hidden overflow-x-auto space-x-3 justify-start items-center mt-3" v-if="showActions">
            <PrimaryButton @click="() => showModal('update')">update</PrimaryButton>        
            <!-- SCRUM-366: what this administrator has read (super admins only). -->
            <Link
                v-if="user.isAdmin && $page.props.auth.user?.isSuperAdmin"
                :href="route('administrator.content-accesses', { admin: user.id })"
                class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50"
            >access history</Link>
            <!-- SCRUM-34c: lost phone and recovery codes (super admins only). SCRUM-424: it waits before applying. -->
            <template v-if="$page.props.auth.user?.isSuperAdmin">
                <template v-if="user.twoFactorResetDueAt">
                    <span class="shrink-0 whitespace-nowrap rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-medium text-amber-900">two-factor reset scheduled for {{ new Date(user.twoFactorResetDueAt).toLocaleString() }}</span>
                    <SecondaryButton :disabled="loading" @click="cancelReset">cancel reset</SecondaryButton>
                </template>
                <SecondaryButton v-else-if="user.twoFactorEnabled" @click="() => openReset()">reset two-factor</SecondaryButton>
            </template>
            <PrimaryButton>ban</PrimaryButton>        
            <DangerButton @click="() => showModal('delete')">delete</DangerButton>      
        </div>
    </div>

    <MiniModal
        :show="modalData.show && modalData.type == 'delete'"
        @close="closeModal"
    >
        <div>
            <FormLoader :danger="true" class="mx-auto" :show="loading" :text="'deleting user account'"/>
            <div class="text-gray-600 text-center font-bold tracking-wide">
                Delete @{{ user.username }} Account
            </div>

            <hr class="my-2">

            <div class="relative">
                <div class="my-4 text-sm text-red-700 text-center w-[90%] mx-auto font-bold tracking-wide">
                    Are you sure you want to delete this account?
                </div>
            </div>

            <div class="flex space-x-2 justify-end items-center w-full p-2">
                <PrimaryButton @click="() => closeModal()" class="shrink-0">cancel</PrimaryButton>
                <DangerButton @click="deleteUser" class="shrink-0">delete</DangerButton>
            </div>
        </div>

    </MiniModal>

    <MiniModal
        :show="modalData.show && modalData.type == 'resetTwoFactor'"
        @close="closeModal"
    >
        <form @submit.prevent="resetTwoFactor">
            <FormLoader class="mx-auto" :show="loading" :text="'resetting two-factor sign-in'"/>
            <div class="text-gray-800 text-center font-bold tracking-wide">
                Reset @{{ user.username }}'s two-factor sign-in
            </div>

            <hr class="my-2">

            <div class="space-y-3 p-2 text-sm text-gray-700">
                <p>
                    Only do this once you're sure it's really them -- for someone who lost both their phone and their
                    recovery codes (follow the identity-check procedure). It turns two-factor sign-in off after a
                    waiting period, unless they cancel it: they can then sign in with their password alone.
                </p>
                <p v-if="user.emailVerified">They're told now, by email with a link to cancel it, and signed out everywhere when it applies.</p>
                <p v-if="!user.emailVerified" class="rounded-md border border-amber-200 bg-amber-50 p-2 text-amber-900">
                    This account has no verified email, so they couldn't cancel it. Reset it on the server instead.
                </p>
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
                <DangerButton type="submit" :disabled="loading || !user.emailVerified || !resetPassword || ($page.props.auth.user?.twoFactorEnabled && !resetCode)" class="shrink-0">schedule reset</DangerButton>
            </div>
        </form>
    </MiniModal>

    <AdminUserUpdateModal
        :show="modalData.show && modalData.type == 'update'"
        @close="closeModal"
        @updated="updateUser"
        :user="user"
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
import useAlert from "@/Composables/useAlert"
import useAuth from "@/Composables/useAuth"
import useModal from "@/Composables/useModal"
import Alert from "@/Components/Alert.vue"
import AdminUserUpdateModal from "@/Components/AdminUserUpdateModal.vue"
import { ref, computed } from "vue"
import { Link } from "@inertiajs/vue3"
import DangerButton from "./DangerButton.vue"
import PrimaryButton from "./PrimaryButton.vue"
import Avatar from "./Avatar.vue"
import FormLoader from "./FormLoader.vue"
import MiniModal from "./MiniModal.vue"
import SecondaryButton from "./SecondaryButton.vue"
import InputLabel from "./InputLabel.vue"
import InputError from "./InputError.vue"
import TextInput from "./TextInput.vue"


const { goToLogin } = useAuth()
const { alertData, setFailedAlertData, clearAlertData, setSuccessAlertData } = useAlert()
const { modalData, closeModal, showModal } = useModal()

const emits = defineEmits(['deleted', 'updated'])

const props = defineProps({
    user: {
        default: null
    }
})

const loading = ref(false)
const showActions = ref(false)

const computedGender = computed(() => {
    const u = props.user

    return u?.gender == 'NON_BINARY' ? 'NON-BINARY' : (u?.gender ?? 'not set')
})

function updateUser(user) {
    emits('updated', user)
}

// SCRUM-34c
const resetPassword = ref('')
const resetCode = ref('')
const resetError = ref('')
const resetReason = ref('')

function openReset() {
    resetPassword.value = ''
    resetCode.value = ''
    resetReason.value = ''
    resetError.value = ''
    showModal('resetTwoFactor')
}

async function resetTwoFactor() {
    if (loading.value) return
    loading.value = true
    resetError.value = ''

    await axios
    .post(route('admin.users.two_factor.reset', { userId: props.user.id }), { current_password: resetPassword.value, code: resetCode.value || null, reason: resetReason.value || null })
    .then((res) => {
        setSuccessAlertData({ message: `A reset of ${props.user.username}'s two-factor sign-in is scheduled. They've been told and can cancel it.` })
        emits('updated', res.data.user)
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
        loading.value = false
    })
}
  
// SCRUM-424
async function cancelReset() {
    if (loading.value) return
    loading.value = true

    await axios
    .delete(route('admin.users.two_factor.reset.cancel', { userId: props.user.id }))
    .then((res) => {
        setSuccessAlertData({ message: `The scheduled two-factor reset for ${props.user.username} is cancelled.` })
        emits('updated', res.data.user)
    })
    .catch((err) => {
        setFailedAlertData({ message: err.response?.data?.message ?? 'Something unfortunate happened. Please try again later.' })
    })
    .finally(() => { loading.value = false })
}

async function deleteUser() {
    loading.value = true
    
    await axios
    .delete(route(`admin.users.delete`, { userId: props.user.id}))
    .then((res) => {
        console.log(res)
        setSuccessAlertData({
            message: `${props.user.username}'s information has successfully been deleted.`,
        })
        emits('deleted', res.data.user)
        closeModal()
    })
    .catch((err) => {
        console.log(err)
        if (err.response?.data?.message) {
            setFailedAlertData({
                message: err.response?.data?.message,
            })
            return
        }

        setFailedAlertData({
            message: 'Something unfortunate happened. Please try again later.',
        })
    })
    .finally(() => {
        loading.value = false
    })
}

</script>