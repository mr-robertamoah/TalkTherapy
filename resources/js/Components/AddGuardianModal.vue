<script setup>
import { computed, ref, unref } from 'vue';
import LinkComponent from '@/Components/LinkComponent.vue';
import Modal from '@/Components/Modal.vue';
import useAlert from "@/Composables/useAlert";
import Alert from "./Alert.vue";
import PrimaryButton from "./PrimaryButton.vue";
import { usePage } from '@inertiajs/vue3';
import useAuth from '@/Composables/useAuth';
import useErrorHandler from '@/Composables/useErrorHandler';
import TextInput from './TextInput.vue';
import SecondaryButton from './SecondaryButton.vue';
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

// What's happening now, read out (SCRUM-439: the loaders were only visual).
const statusText = computed(() => loading.value ? 'Sending the request…'
    : getting.value.show ? ({ users: 'Searching…', links: 'Loading your links…' }[getting.value.type] ?? 'Making a link…') : '')

const userSearch = ref('')
// Whether the words now in the box have been searched for ("No one found" waits for that, not the debounce).
const searched = ref(false)
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

// The words, not their length (QA: a retype of the same length never searched again); an empty box clears results.
watch(userSearch, () => {
    searched.value = false
    if (userSearch.value.length) {
        debouncedGetUsers()
    } else {
        debouncedGetUsers.cancel()
        users.value = { data: [], page: 1 }
    }
})

watch(() => props.show, () => {
    if (props.show)
        getGuardianshiplinks()
})

const debouncedGetUsers = _.debounce(() => {
    users.value.page = 1
    searched.value = true
    getUsers()
}, 500)

async function sendGuardianshpRequest(person = null) {
    if (person) selectedUser.value = person

    if (!selectedUser.value) {
        setFailedAlertData({
            message: 'Please choose a person first.',
        })
        return
    }

    loading.value = true

    requestData.value.guardianId = selectedUser.value.id
    await axios.post(route(`api.users.guardianshiprequest`, {userId: user.id}), {...unref(requestData)})
        .then((res) => {
            
            setSuccessAlertData({
                message: 'The guardianship request has been sent successfully.',
            })

            if (selectedUser.value) selectedUser.value = null

            closeModal()
        })
        .catch((err) => {
            goToLogin(err)

            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                })
                return
            }

            if (err.alert) {
                setFailedAlertData({
                    message: err.alert,
                })
                return
            }

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
            })
        
        })

    loading.value = false
}

async function getGuardianshiplinks() {
    setGetting('links')

    const res = await getlinks({
        page: guardianshipLinks.value.page,
        type: 'GUARDIANSHIP',
        forType: 'User',
        forId: usePage().props.auth.user?.id
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

    await axios.get(route(`api.users`, {page: users.value.page, like: userSearch.value, for: 'guardian'}))
        .then((res) => {
            
            if (users.value.page == 1)
                users.value.data = []
            
            users.value.data = [
                ...users.value.data,
                ...res.data.data,
            ]

            updatePage(res, users)
        })
        .catch((err) => {
            goToLogin(err)

            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                })
                return
            }

            if (err.alert) {
                setFailedAlertData({
                    message: err.alert,
                })
                return
            }

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
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
    <!-- SCRUM-439: a plain heading, a labelled search with results as a list, real buttons, links listed down. -->
    <Modal
        :show="show"
        labelledby="add-guardian-title"
        @close="closeModal"
    >
        <div class="p-6 text-sm text-gray-700">
            <h2 id="add-guardian-title" class="text-lg font-semibold text-gray-900">Add a guardian</h2>
            <p class="mt-1">
                Your guardian is an adult who has a TalkTherapy account. Send them a request, or share a guardianship
                link with them.
            </p>
            <p class="mt-2 text-green-800" role="status">{{ statusText }}</p>

            <!-- SCRUM-441: finding a guardian needs your date of birth (it shows you're under 18). -->
            <p v-if="!user?.dob" class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-3 text-gray-700">
                To find someone by name, first add your date of birth in your profile details. You can still share a
                guardianship link below.
            </p>
            <section v-else class="mt-4" aria-labelledby="add-guardian-search-title">
                <h3 id="add-guardian-search-title" class="font-semibold text-gray-900">Find them</h3>
                <label for="add-guardian-search" class="mt-2 block text-xs font-medium text-gray-700">Name or username</label>
                <TextInput id="add-guardian-search" v-model="userSearch" type="search" class="mt-1 block w-full" autocomplete="off" />

                <ul v-if="users.data?.length" role="list" class="mt-3 divide-y divide-gray-100 rounded-lg border border-gray-200">
                    <li v-for="person in users.data" :key="person.id" class="flex flex-wrap items-center gap-2 p-3">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-gray-900 [overflow-wrap:anywhere]">{{ person.fullName || person.username }}</p>
                            <p class="text-xs text-gray-500 [overflow-wrap:anywhere]">@{{ person.username }}</p>
                        </div>
                        <PrimaryButton type="button" class="min-h-[44px]" :disabled="loading" :aria-label="`Send ${person.fullName || person.username} a guardianship request`" @click="sendGuardianshpRequest(person)">Send request</PrimaryButton>
                        <SecondaryButton type="button" class="min-h-[44px]" :disabled="getting.show" :aria-label="`Make a guardianship link for ${person.fullName || person.username}`" @click="() => { selectedUser = person; createGuardianLink() }">Make a link for them</SecondaryButton>
                    </li>
                </ul>
                <p v-else-if="userSearch && searched && !getting.show" class="mt-3 text-gray-600">No one found yet. Check the spelling, or try their username.</p>
                <SecondaryButton v-if="users.data?.length && users.page" type="button" class="mt-2" :disabled="getting.show" @click="getUsers">Load more people</SecondaryButton>
            </section>

            <section class="mt-6" aria-labelledby="add-guardian-links-title">
                <h3 id="add-guardian-links-title" class="font-semibold text-gray-900">Guardianship links</h3>
                <p class="mt-1">A general link works for any adult who opens it. A link made for one person works only for them.</p>
                <ul v-if="guardianshipLinks.data?.length" role="list" class="mt-3 space-y-2">
                    <li v-for="(link, idx) in guardianshipLinks.data" :key="link.id">
                        <LinkComponent :link="link" class="bg-white" @updated="(lk) => updateLink(lk, idx)" @deleted="() => deleteLink(idx)" />
                    </li>
                </ul>
                <p v-else-if="!getting.show" class="mt-3 text-gray-600">You have no guardianship links.</p>
                <div class="mt-3 flex flex-wrap gap-3">
                    <SecondaryButton v-if="guardianshipLinks.data?.length && guardianshipLinks.page" type="button" :disabled="getting.show" @click="getGuardianshiplinks">Load more links</SecondaryButton>
                    <PrimaryButton type="button" :disabled="getting.show" @click="() => { selectedUser = null; createGuardianLink() }">Make a general link</PrimaryButton>
                </div>
            </section>

            <div class="mt-6 flex justify-end">
                <SecondaryButton type="button" @click="closeModal">Close</SecondaryButton>
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
