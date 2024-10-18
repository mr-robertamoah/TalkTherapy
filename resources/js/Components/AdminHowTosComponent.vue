<template>
    <div v-bind="$attrs" class="min-h-[50vh]">
        
        <FormLoader class="mx-auto" :show="loading" :text="'getting how-tos'"/>
        <div>
            <div class="flex justify-end items-center w-full mb-4 mt-2">
                <PrimaryButton @click="() => showModal('create')">create how-to</PrimaryButton>
            </div>
            <div>
                <div class="text-sm text-gray-600 font-bold mx-auto max-w-[400px] text-center">Filters</div>
                <div class="flex justify-end items-center w-full my-2" v-if="filterChecked.name || filterChecked.page">
                    <PrimaryButton @click="getHowTos">use filter</PrimaryButton>
                </div>
                <div class="mt-4 mx-auto max-w-[400px]">
                    <label class="flex items-center">
                        <Checkbox name="filter-name" v-model:checked="filterChecked.name" />
                        <span class="ms-2 text-sm text-gray-600">by name.</span>
                    </label>

                    <TextInput
                        v-if="filterChecked.name"
                        v-model="filters.name"
                        type="text"
                        class="w-full mt-4"
                        placeholder="name of how-tos"
                    />
                </div>
                <div class="mt-4 mx-auto max-w-[400px]">
                    <label class="flex items-center">
                        <Checkbox name="filter-page" v-model:checked="filterChecked.pageLike" />
                        <span class="ms-2 text-sm text-gray-600">by page.</span>
                    </label>

                    <TextInput
                        v-if="filterChecked.pageLike"
                        v-model="filters.pageLike"
                        type="text"
                        class="w-full mt-4"
                        placeholder="page of how-tos"
                    />
                </div>
            </div>
        </div>
        <div class="min-h-[50vh]" v-if="howTos.data.length">
            <div class="min-h-[45vh]">
                <AdminHowToComponent
                    v-for="howTo in howTos.data"
                    :key="howTo.id"
                    :howTo="howTo"
                    class="my-4"
                    @deleted="deleteHowTo"
                    @updated="updateHowTo"
                    :canEdit="true"
                />
            </div>

            <div class="flex justify-center items-center w-full sm:w-[80%] mx-auto my-8" v-if="howTos.data.length && howTos.page">
                <PrimaryButton @click="() => getHowTos()">get more</PrimaryButton>
            </div>
        </div>

        <div v-else class="w-full h-[45vh] flex justify-center items-center text-gray-600 text-sm">
            <div>no how-tos yet</div>
        </div>
    </div>
        
    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />

    <AdminHowToCreateModal
        :show="modalData.show && modalData.type == 'create'"
        @created="addHowTo"
        @close="closeModal"
    />
</template>

<script setup>
import useAuth from "@/Composables/useAuth"
import Alert from "@/Components/Alert.vue"
import TextInput from "@/Components/TextInput.vue"
import Checkbox from "@/Components/Checkbox.vue"
import AdminHowToCreateModal from "@/Components/AdminHowToCreateModal.vue"
import { onBeforeMount, ref, unref, watch } from "vue";
import useAlert from "@/Composables/useAlert";
import PrimaryButton from "./PrimaryButton.vue";
import FormLoader from "./FormLoader.vue";
import AdminHowToComponent from "./AdminHowToComponent.vue";
import useModal from "@/Composables/useModal"


const { goToLogin } = useAuth()
const { alertData, setFailedAlertData, clearAlertData } = useAlert()
const { modalData, showModal, closeModal } = useModal()

const props = defineProps({
    show: {
        type: Boolean,
        default: false
    },
})

const loading = ref(false)
const howTos = ref({
    data: [],
    page: 1,
    next: false,
    previous: false,
})
const filters = ref({
    name: '',
    pageLike: '',
})
const filterChecked = ref({
    name: false,
    pageLike: false,
})

onBeforeMount(() => {
    if (props.show && howTos.value.page == 1)
        getHowTos()
})

function getFilters() {
    let data = {}

    Object.keys(unref(filters)).forEach((key) => {
        data[key] = filters.value[key] ?? null
    })

    return data
}

function addHowTo(howTo) {
    howTos.value.data = [howTo, ...howTos.value.data]
}

function updateHowTo(user) {
    howTos.value.data.splice(howTos.value.data.findIndex((u) => u.id == user.id), 1, user)
}

function deleteHowTo(user) {
    howTos.value.data.splice(howTos.value.data.findIndex((u) => u.id == user.id), 1)
}

async function getHowTos() {
    loading.value = true

    await axios.get(route('api.how-tos', {
        page: howTos.value.page,
        ...getFilters()
    }))
        .then((res) => {
            console.log(res)
            if (howTos.value.page == 1)
                howTos.value.data = []
            
            howTos.value.data = [
                ...howTos.value.data,
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
    if (res.data.links.next) howTos.value.page += 1
    else howTos.value.page = 0
}
</script>