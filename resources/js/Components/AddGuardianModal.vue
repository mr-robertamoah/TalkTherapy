<script setup>
import { computed, ref, unref } from 'vue';
import LinkComponent from '@/Components/LinkComponent.vue';
import FormLoader from '@/Components/FormLoader.vue';
import Modal from '@/Components/Modal.vue';
import useAlert from "@/Composables/useAlert";
import Alert from "./Alert.vue";
import PrimaryButton from "./PrimaryButton.vue";
import { usePage } from '@inertiajs/vue3';
import useAuth from '@/Composables/useAuth';
import useErrorHandler from '@/Composables/useErrorHandler';
import TextInput from './TextInput.vue';
import UserComponent from './UserComponent.vue';
import useAppLink from '@/Composables/useAppLink';
import { watch } from 'vue';

const { goToLogin } = useAuth()
const { clearErrorData } = useErrorHandler()
const { createLink, getlinks } = useAppLink()
const { alertData, clearAlertData, setSuccessAlertData, setFailedAlertData } = useAlert()

const user = usePage().props.auth.user

const props = defineProps({
    show: {
        default: false,
        type: Boolean
    },
})

const emits = defineEmits(['close'])

const userSearch = ref('')
const userStatus = ref('')
const loading = ref(false)
const getting = ref({
    show: false,
    type: '',
})
const users = ref({ data: [], page: 1 })
const selectedUser = ref(null)
const guardianshipLinks = ref({ page: 1, data: []})
const requestData = ref({
    'guardianId': [],
})

watch(() => userSearch.value.length, () => {
    if (userSearch.value.length)
        debouncedGetUsers()
})

watch(() => props.show, () => {
    if (props.show)
        getGuardianshiplinks()
})

const debouncedGetUsers = _.debounce(() => {
    users.value.page = 1
    getUsers()
}, 500)

async function sendGuardianshpRequest() {
    if (!selectedUser.value) {
        setSuccessAlertData({
            message: 'Please select a user before proceeding.',
            time: 5000
        })
        return
    }

    loading.value = true
    userStatus.value = 'sending request'

    requestData.value.guardianId = selectedUser.value.id
    await axios.post(route(`api.users.guardianshiprequest`, {userId: user.id}), {...unref(requestData)})
        .then((res) => {
            console.log(res)
            
            setSuccessAlertData({
                message: 'The guardianship request has been sent successfully.',
                time: 4000
            })

            if (selectedUser.value) selectedUser.value = null

            closeModal()
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)

            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                    time: 5000
                })
                return
            }

            if (err.alert) {
                setFailedAlertData({
                    message: err.alert,
                    time: 5000
                })
                return
            }

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
                time: 5000
            })
        
        })

    loading.value = false
    userStatus.value = ''
}

async function getGuardianshiplinks() {
    setGetting('links')

    const res = await getlinks({
        page: guardianshipLinks.value.page,
        type: 'GUARDIANSHIP'
    })
    
    clearGetting()
    if (!res) return 
            
    if (guardianshipLinks.value.page == 1)
        guardianshipLinks.value.data = []
    
    guardianshipLinks.value.data = [
        ...guardianshipLinks.value.data,
        ...res.data.data,
    ]

    updatePage(res, guardianshipLinks)
}

async function createGuardianLink() {
    setGetting('create link')

    const link = await createLink({
        type: 'GUARDIANSHIP',
        addedbyId: user?.id,
        addedbyType: 'User',
        toId: selectedUser.value ? selectedUser.value?.id : null,
        toType: selectedUser.value ? 'User' : null,
        forId: user?.id,
        forType: 'User',
    })

    if (selectedUser.value) selectedUser.value = null
    
    clearGetting()
    if (!link) return
            
    guardianshipLinks.value.data = [link, ...guardianshipLinks.value.data]
}

function updatePage(res, data) {
    if (res.data.links.next) data.value.page = data.value.page + 1
    else data.value.page = 0
}

async function getUsers() {
    setGetting('users')

    await axios.get(route(`api.users`, {page: users.value.page, like: userSearch.value}))
        .then((res) => {
            console.log(res)
            
            if (users.value.page == 1)
                users.value.data = []
            
            users.value.data = [
                ...users.value.data,
                ...res.data.data,
            ]

            updatePage(res, users)
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)

            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                    time: 10000
                })
                return
            }

            if (err.alert) {
                setFailedAlertData({
                    message: err.alert,
                    time: 5000
                })
                return
            }

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
                time: 5000
            })
        
        })

    clearGetting()
}

function clearData() {
    requestData.value.guardianId = ''
}

function setGetting(type) {
    getting.value.type = type
    getting.value.show = true
}

function clearGetting() {
    getting.value.type = ''
    getting.value.show = false
}

function updateLink(link, idx) {
    guardianshipLinks.value.data.splice(idx, 1, link)
}

function deleteLink(idx) {
    guardianshipLinks.value.data.splice(idx, 1)
}

function closeModal() {
    clearData()
    emits('close')
}
</script>

<template>
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="select-none">

            <div class="p-4 w-full mt-2 mb-4">
                <div
                    class="capitalize w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Request Guardianship</div>
                <hr>
            </div>

            <div class="relative overflow-hidden overflow-y-auto h-[75vh] px-4">

                <FormLoader class="mx-auto" :show="loading" :text="`sending guardianship request`"/>
                <FormLoader class="mx-auto" :show="getting.show" :text="getting.type == 'users' ? 'getting users' : (getting.type == 'links' ? 'getting guardianship links' : `creating link`)"/>
                <div class="p-4 relative">
                    <div class="p-4 rounded bg-gray-200 shadow-sm text-gray-600 text-sm">
                        Requesting as a Ward
                    </div>
                    <div class="p-4 rounded bg-gray-200 shadow-sm my-4 text-gray-600 text-sm">
                        <div>
                            <div>Links</div>
                            <div class="my-2 text-justify w-full">A general link can be given to any user. If you want a link specific to a user, search for a user; click/tap user to reveal options and click get link.</div>
                            <div class="flex justify-start items-center space-x-3 overflow-hidden overflow-x-auto">
                                <template v-if="guardianshipLinks.data?.length">
                                    <LinkComponent
                                        v-for="(link, idx) in guardianshipLinks.data"
                                        :key="link.id"
                                        :link="link"
                                        @updated="(lk) => updateLink(lk, idx)"
                                        @deleted="(lk) => deleteLink(idx)"
                                        class="w-[90%] shrink-0 bg-white"
                                    />

                                    <div
                                        title="get more guardian links"
                                        @click="getGuardianshiplinks"
                                        v-if="guardianshipLinks.page"
                                        class="cursor-pointer p-2 text-gray-600 font-bold">...</div>
                                </template>
                                <div v-else class="h-10 flex justify-center items-center w-full">no links for guardianship as at now.</div>
                            </div>
                        </div>

                        <div class="flex justify-end mt-4">
                            <PrimaryButton
                                @click="createGuardianLink"
                                class="ms-4" 
                                :class="{ 'opacity-25': getting.show && getting.type == 'create links'}" 
                                :disabled="getting.show && getting.type == 'create links'"
                            >
                                get general link
                            </PrimaryButton>
                        </div>
                    </div>
                    <form 
                        @submit.prevent="sendGuardianshpRequest"
                    >
                        <div class="pb-4">
                            <div class="mb-4 text-sm text-gray-600">
                                <div class="p-4 rounded bg-gray-200 shadow-sm mt-4">
                                    <div class="text-left text-sm font-bold mb-2">Select Users</div>
                                    <div class="text-left text-sm mb-2">Type name or username of users in order to search. Then click user to reveal options.</div>
                                    <div class="w-full flex justify-center items-center my-4">
                                        <TextInput
                                            v-model="userSearch"
                                            class="w-[90%]"
                                            type="text"
                                            placeholder="search for user"
                                        />
                                    </div>
                                    <div class="bg-white rounded p-2 my-2 flex justify-start items-center overflow-hidden overflow-x-auto space-x-3">
                                        <template v-if="users.data?.length">
                                            <UserComponent
                                                v-for="user in users.data"
                                                :key="user.id"
                                                :user="user"
                                                class="w-[90%] shrink-0"
                                                @click="() => selectedUser = user"
                                            >
                                                <div v-if="selectedUser && selectedUser.id == user.id">
                                                    <div v-if="userStatus">{{ userStatus }}</div>
                                                    <div class="rounded p-1 text-sm text-gray-600 w-fit">
                                                        <div
                                                            @click="createGuardianLink"
                                                            class="rounded mb-2 bg-white p-1 cursor-pointer text-center transition hover:bg-gray-600 hover:text-gray-200"
                                                        >get guardianship link</div>
                                                        <div
                                                            @click="sendGuardianshpRequest"
                                                            class="rounded mb-2 bg-white p-1 cursor-pointer text-center transition hover:bg-gray-600 hover:text-gray-200"
                                                        >send guardianship request</div>
                                                    </div>
                                                </div>
                                            </UserComponent>

                                            <div
                                                title="get more users"
                                                @click="getUsers"
                                                v-if="users.page"
                                                class="cursor-pointer p-2 text-gray-600 font-bold">...</div>
                                        </template>
                                        <div v-else class="w-full text-sm text-gray-600 text-center mt-4 mb-2">no searched user yet</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </Modal>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>