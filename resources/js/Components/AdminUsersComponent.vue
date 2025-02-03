<template>
    <div v-bind="$attrs" class="min-h-[50vh]">
        
        <FormLoader class="mx-auto" :show="loading" :text="'getting users'"/>
        <div class="min-h-[50vh]" v-if="users.data.length">
            <div class="min-h-[45vh]">
                <AdminUserComponent
                    v-for="user in users.data"
                    :key="user.id"
                    :user="user"
                    class="mb-3"
                    @deleted="deleteUser"
                    @updated="updateUser"
                />
            </div>

            <div class="flex justify-center items-center w-full sm:w-[80%] mx-auto my-8" v-if="users.data.length && users.page">
                <PrimaryButton @click="() => getUsers()">get more</PrimaryButton>
            </div>
        </div>

        <div v-else class="w-full h-[45vh] flex justify-center items-center text-gray-600 text-sm">
            <div>no users yet</div>
        </div>
    </div>
        
    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>

<script setup>
import useAuth from "@/Composables/useAuth"
import Alert from "@/Components/Alert.vue"
import { onBeforeMount, ref, unref, watch } from "vue";
import useAlert from "@/Composables/useAlert";
import PrimaryButton from "./PrimaryButton.vue";
import FormLoader from "./FormLoader.vue";
import AdminUserComponent from "./AdminUserComponent.vue";


const { goToLogin } = useAuth()
const { alertData, setFailedAlertData, clearAlertData } = useAlert()

const props = defineProps({
    show: {
        type: Boolean,
        default: false
    },
})

const loading = ref(false)
const users = ref({
    data: [],
    page: 1,
    next: false,
    previous: false,
})
const filters = ref({
    name: '',
    username: '',
    age: null,
})

onBeforeMount(() => {
    if (props.show && users.value.page == 1)
        getUsers()
})

function getFilters() {
    let data = {}

    Object.keys(unref(filters)).forEach((key) => {
        data[key] = filters.value[key]
    })

    return data
}

function updateUser(user) {
    users.value.data.splice(users.value.data.findIndex((u) => u.id == user.id), 1, user)
}

function deleteUser(user) {
    users.value.data.splice(users.value.data.findIndex((u) => u.id == user.id), 1)
}

async function getUsers() {
    loading.value = true

    await axios.get(route('admin.users', {
        page: users.value.page,
        ...getFilters()
    }))
        .then((res) => {
            console.log(res)
            if (users.value.page == 1)
                users.value.data = []
            
            users.value.data = [
                ...users.value.data,
                ...res.data.data,
            ]

            updatePage(res)
        })
        .catch((err) => {
            console.log(err)
            setFailedAlertData({
                message: "Something unfortunate happened. Please try again in a short while."
            })
            goToLogin(err)
        })
        .finally(() => {
            loading.value = false
        })
}

function updatePage(res) {
    if (res.data.links.next) users.value.page += 1
    else users.value.page = 0
}
</script>