<script setup>
import CreateProfessionModal from '@/Components/CreateProfessionModal.vue';
import PreferenceItem from '@/Components/PreferenceItem.vue';
import TextInput from '@/Components/TextInput.vue';
import { watch } from 'vue';
import { onBeforeMount, ref } from 'vue';

const props = defineProps({
    loadedProfessions: {
        default: []
    },
    selectedProfession: {
        default: null
    },
    addedby: {
        default: {
            type: '',
            id: 0
        }
    },
})

const emits = defineEmits(['onData'])

const loading = ref(false)
const showModal = ref(false)
const professionsSearch = ref('')
const professionsPage = ref(0)
const professions = ref([])
const selectedProfession = ref(null)

onBeforeMount(() => {
    setProfessions()
})
watch(() => professionsSearch.value.length, () => {
    if (professionsSearch.value.length && professionsPage.value != 1) {
        professionsPage.value = 1
        debouncedGetProfessions()
    }
})
watch(() => selectedProfession.value, () => {
    emits('onData', selectedProfession.value)
})

const debouncedGetProfessions = () => {
    getProfessions()
}

function updateProfessionsPage(res) {
    if (res.data.links.next) professionsPage.value += 1
    else professionsPage.value = 0
}

async function getProfessions() {
    loading.value = true

    await axios
    .get(route('professions.get', {name: professionsSearch.value, page: professionsPage.value}))
    .then((res) => {
        console.log(res)
        if (professionsPage.value > 1) {
            professions.value = [...professions.value, ...res.data.data]
            updateProfessionsPage(res)
            return
        }

        professions.value = [...res.data.data]
        updateProfessionsPage(res)
    })
    .catch((err) => {
        console.log(err)
    })
    .finally(() => {
        loading.value = false
    })
}

function setProfessions() {
    professions.value = [...props.loadedProfessions]

    if (props.selectedProfession)
        selectedProfession.value = {...props.selectedProfession}
}

function addProfessionToSelected(newProfession) {
    selectedProfession.value = {...newProfession}
}

function removeProfessionFromSelected() {
    selectedProfession.value = null
}
</script>

<template>
    <div v-bind="$attrs" class="overflow-hidden shadow-sm sm:rounded-lg my-8">
        <div class="p-6 text-gray-900">
            <div>
                <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">professions</div>
                <div class="text-sm text-justify text-gray-600 mb-4">
                    This is a way to set your profession on this platform. This goes a long way to indicate to a potential client what it is to expect from you. <em class="font-bold">You can only select one profession.</em>
                </div>
                <div class="text-sm text-center capitalize mb-2 font-bold">select</div>
                <div class="text-sm text-center mb-2 text-gray-600">double click/tap to a profession to see options.</div>
                <div class="p-2 flex justify-start items-start flex-col overflow-hidden my-2 mb-4">
                    <div
                        class="mb-4 rounded text-sm p-2 min-w-[200px] text-white bg-blue-400 text-center"
                    >
                        <div>did not find what you are looking for?</div>
                        <div
                            @click="() => showModal = true"
                            title="create a profession"
                            class="my-2 py-1 px-2 rounded bg-blue-600 text-blue-200 transition duration-75 cursor-pointer hover:text-white hover:bg-blue-800 w-fit mx-auto"
                        >create it</div>
                    </div>
                    <div class="w-full p-2 flex justify-start items-center overflow-hidden overflow-x-auto" v-if="professions.length">
                        <PreferenceItem
                            v-for="c in professions"
                            :key="c.id"
                            :item="c"
                            @select-item="(data) => addProfessionToSelected(data)"
                        />
                        <div 
                            v-if="professionsPage != 0"
                            class="p-2 text-gray-500 transition duration-75 cursor-pointer hover:text-gray-700"
                            @click="() => getProfessions()"
                            title="get more professions"
                        >...</div>
                    </div>
                    <div v-else class="w-full text-center text-sm text-gray-600">no professions</div>
                </div>
                <div
                    class="w-full text-center text-green-700 mt-8 mb-2"
                    v-if="loading"
                >getting{{ professionsPage < 2 ? '' : ' more' }} professions...</div>
                <div class="mb-8">
                    <TextInput
                        id="name"
                        type="text"
                        class="mt-1 block w-full max-w-[500px]"
                        v-model="professionsSearch"
                        placeholder="search for professions"
                    />
                </div>

                <div class="text-sm text-center capitalize mb-2 font-bold">selected profession</div>
                <div class="p-2 flex justify-start items-center overflow-hidden overflow-x-auto my-2">
                    
                    <div
                        v-if="selectedProfession"
                        class="capitalize mr-3 rounded relative text-sm p-2 w-fit text-gray-700 bg-gray-300 select-none cursor-pointer text-center"
                    >
                        {{ selectedProfession.name }}
                        <div 
                            @click="removeProfessionFromSelected"
                            :title="`remove ${selectedProfession.name}`"
                            class="absolute -top-2 -right-2 text-sm flex justify-center items-center transition duration-75 text-center rounded-full
                                border-2 border-gray-800 bg-gray-300 text-gray-800 cursor-pointer w-6 h-6 hover:bg-gray-600 hover:text-white"
                        >x</div>
                    </div>
                    <div v-else class="w-full text-center text-sm text-gray-600">no selected professions</div>
                </div>
            </div>
        </div>
    </div>

    <CreateProfessionModal
        v-if="showModal"
        :show="showModal"
        @close-modal="() => showModal = false"
        :addedby-id="addedby.id"
        :addedby-type="addedby.type"
        @after-creating="(data) => {
            professions = [data, ...professions]
        }"
    />
</template>