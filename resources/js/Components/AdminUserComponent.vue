<template>
    <div v-bind="$attrs" class="p-4 bg-slate-600 rounded-lg select-none">
        <div class="mb-2 text-gray-200 text-center text-sm font-semibold tracking-wide">@{{ user.username ?? 'not set'}}</div>
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
import DangerButton from "./DangerButton.vue"
import PrimaryButton from "./PrimaryButton.vue"
import FormLoader from "./FormLoader.vue"
import MiniModal from "./MiniModal.vue"


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