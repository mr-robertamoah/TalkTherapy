<script setup>
import CreateReligionModal from '@/Components/CreateReligionModal.vue';
import PreferenceItem from '@/Components/PreferenceItem.vue';
import TextInput from '@/Components/TextInput.vue';
import { watch } from 'vue';
import { onBeforeMount, ref } from 'vue';

const props = defineProps({
    loadedReligions: {
        default: []
    },
    selectedReligions: {
        default: []
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
const religionsSearch = ref('')
const religionsPage = ref(0)
const religions = ref([])
const selectedReligions = ref([])

onBeforeMount(() => {
    setReligions()
})
watch(() => religionsSearch.value.length, () => {
    if (religionsSearch.value.length && religionsPage.value != 1) {
        religionsPage.value = 1
        debouncedGetReligions()
    }
})
watch(() => selectedReligions.value.length, () => {
    emits('onData', selectedReligions.value)
})

const debouncedGetReligions = () => {
    getReligions()
}

function updateReligionsPage(res) {
    if (res.data.links.next) religionsPage.value += 1
    else religionsPage.value = 0
}

async function getReligions() {
    loading.value = true

    await axios
    .get(`religions?name=${religionsSearch.value}&page=${religionsPage.value}`)
    .then((res) => {
        console.log(res)
        if (religionsPage.value > 1) {
            religions.value = [...religions.value, ...res.data.data]
            updateReligionsPage(res)
            return
        }

        religions.value = [...res.data.data]
        updateReligionsPage(res)
    })
    .catch((err) => {
        console.log(err)
    })
    .finally(() => {
        loading.value = false
    })
}

function setReligions() {
    religions.value = [...props.loadedReligions]

    if (props.selectedReligions.length)
        selectedReligions.value = [...props.selectedReligions]
}

function addReligionToSelected(newReligion) {
    selectedReligions.value = [...selectedReligions.value.filter((c) => c.id !== newReligion.id), newReligion]
}

function removeReligionFromSelected(oldReligion) {
    selectedReligions.value = [...selectedReligions.value.filter((c) => c.id !== oldReligion.id)]
}
</script>

<template>
    <div v-bind="$attrs" class="overflow-hidden shadow-sm sm:rounded-lg my-8">
        <div class="p-6 text-gray-900">
            <div>
                <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">religions</div>
                <div class="text-sm text-justify text-gray-600 mb-4">
                    Some people feel more confident and find it easier when interacting with persons with whom they have similar religious inclinations. This settings helps us to connect you to persons with similar inclinations.
                </div>
                <div class="text-sm text-center capitalize mb-2 font-bold">select</div>
                <div class="p-2 flex justify-start items-start flex-col overflow-hidden my-2 mb-4">
                    <div
                        class="mb-4 rounded text-sm p-2 min-w-[200px] text-white bg-blue-400 text-center"
                    >
                        <div>did not find what you are looking for?</div>
                        <div
                            @click="() => showModal = true"
                            title="create a religion"
                            class="my-2 py-1 px-2 rounded bg-blue-600 text-blue-200 transition duration-75 cursor-pointer hover:text-white hover:bg-blue-800 w-fit mx-auto"
                        >create it</div>
                    </div>
                    <div class="w-full p-2 flex justify-start items-center overflow-hidden overflow-x-auto" v-if="religions.length">
                        <PreferenceItem
                            v-for="c in religions"
                            :key="c.id"
                            :item="c"
                            @select-item="(data) => addReligionToSelected(data)"
                        />
                        <div 
                            v-if="religionsPage != 0"
                            class="p-2 text-gray-500 transition duration-75 cursor-pointer hover:text-gray-700"
                            @click="() => getReligions()"
                            title="get more religions"
                        >...</div>
                    </div>
                    <div v-else class="w-full text-center text-sm text-gray-600">no religions</div>
                </div>
                <div
                    class="w-full text-center text-green-700 mt-8 mb-2"
                    v-if="loading"
                >getting{{ religionsPage < 2 ? '' : ' more' }} religions...</div>
                <div class="mb-8">
                    <TextInput
                        id="name"
                        type="text"
                        class="mt-1 block w-full max-w-[500px]"
                        v-model="religionsSearch"
                        placeholder="search for religions"
                    />
                </div>

                <div class="text-sm text-center capitalize mb-2 font-bold">selected religions</div>
                <div class="p-2 flex justify-start items-center overflow-hidden overflow-x-auto my-2">
                    
                    <template v-if="selectedReligions.length">
                        <div
                            v-for="l in selectedReligions"
                            :key="l.id"
                            class="capitalize mr-3 rounded relative text-sm p-2 w-fit text-gray-700 bg-gray-300 select-none cursor-pointer text-center"
                        >
                            {{ l.name }}
                            <div 
                                @click="() => removeReligionFromSelected(l)"
                                :title="`remove ${l.name}`"
                                class="absolute -top-2 -right-2 text-sm flex justify-center items-center transition duration-75 text-center rounded-full
                                    border-2 border-gray-800 bg-gray-300 text-gray-800 cursor-pointer w-6 h-6 hover:bg-gray-600 hover:text-white"
                            >x</div>
                        </div>
                    </template>
                    <div v-else class="w-full text-center text-sm text-gray-600">no selected religions</div>
                </div>
            </div>
        </div>
    </div>

    <CreateReligionModal
        v-if="showModal"
        :show="showModal"
        @close-modal="() => showModal = false"
        :addedby-id="addedby.id"
        :addedby-type="addedby.type"
        @after-creating="(data) => {
            religions = [data, ...religions]
        }"
    />
</template>