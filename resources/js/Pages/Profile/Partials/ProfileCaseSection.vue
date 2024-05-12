<script setup>
import CreateCaseModal from '@/Components/CreateCaseModal.vue';
import PreferenceItem from '@/Components/PreferenceItem.vue';
import TextInput from '@/Components/TextInput.vue';
import { watch } from 'vue';
import { onBeforeMount, ref } from 'vue';

const props = defineProps({
    loadedCases: {
        default: []
    },
    selectedCases: {
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
const casesSearch = ref('')
const casesPage = ref(0)
const cases = ref([])
const selectedCases = ref([])

onBeforeMount(() => {
    setCases()
})
watch(() => casesSearch.value.length, () => {
    if (casesSearch.value.length && casesPage.value != 1) {
        casesPage.value = 1
        debouncedGetCases()
    }
})
watch(() => selectedCases.value.length, () => {
    emits('onData', selectedCases.value)
})

const debouncedGetCases = () => {
    getCases()
}

function updateCasesPage(res) {
    if (res.data.links.next) casesPage.value += 1
    else casesPage.value = 0
}

async function getCases() {
    loading.value = true

    await axios
    .get(`therapy-cases?name=${casesSearch.value}&page=${casesPage.value}`)
    .then((res) => {
        console.log(res)
        if (casesPage.value > 1) {
            cases.value = [...cases.value, ...res.data.data]
            updateCasesPage(res)
            return
        }

        cases.value = [...res.data.data]
        updateCasesPage(res)
    })
    .catch((err) => {
        console.log(err)
    })
    .finally(() => {
        loading.value = false
    })
}

function setCases() {
    cases.value = [...props.loadedCases]

    if (props.selectedCases.length)
        selectedCases.value = [...props.selectedCases]

    if (!cases.value.length) getCases()
}

function addCaseToSelected(newCase) {
    selectedCases.value = [...selectedCases.value.filter((c) => c.id !== newCase.id), newCase]
}

function removeCaseFromSelected(oldCase) {
    selectedCases.value = [...selectedCases.value.filter((c) => c.id !== oldCase.id)]
}
</script>

<template>
    <div v-bind="$attrs" class="overflow-hidden shadow-sm sm:rounded-lg my-8">
        <div class="p-6 text-gray-900">
            <div>
                <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">cases</div>
                <div class="text-sm text-justify text-gray-600 mb-4">
                    Selected case settings will help us connect you to potential clients that require professionals with the expertise of handling your selected cases.
                </div>
                <div class="text-sm text-center capitalize mb-2 font-bold">select</div>
                <div class="text-sm text-center mb-2 text-gray-600">double click/tap to a case to see options.</div>
                <div class="p-2 flex justify-start items-start flex-col overflow-hidden my-2 mb-4">
                    <div
                        class="mb-4 rounded text-sm p-2 min-w-[200px] text-white bg-blue-400 text-center"
                    >
                        <div>did not find what you are looking for?</div>
                        <div
                            @click="() => showModal = true"
                            title="create a case"
                            class="my-2 py-1 px-2 rounded bg-blue-600 text-blue-200 transition duration-75 cursor-pointer hover:text-white hover:bg-blue-800 w-fit mx-auto"
                        >create it</div>
                    </div>
                    <div class="w-full p-2 flex justify-start items-center overflow-hidden overflow-x-auto" v-if="cases.length">
                        <PreferenceItem
                            v-for="c in cases"
                            :key="c.id"
                            :item="c"
                            @select-item="(data) => addCaseToSelected(data)"
                        />
                        <div 
                            v-if="casesPage != 0"
                            class="p-2 text-gray-500 transition duration-75 cursor-pointer hover:text-gray-700"
                            @click="() => getCases()"
                            title="get more cases"
                        >...</div>
                    </div>
                    <div v-else class="w-full text-center text-sm text-gray-600">no cases</div>
                </div>
                <div
                    class="w-full text-center text-green-700 mt-8 mb-2"
                    v-if="loading"
                >getting{{ casesPage < 2 ? '' : ' more' }} cases...</div>
                <div class="mb-8">
                    <TextInput
                        id="name"
                        type="text"
                        class="mt-1 block w-full max-w-[500px]"
                        v-model="casesSearch"
                        placeholder="search for cases"
                    />
                </div>

                <div class="text-sm text-center capitalize mb-2 font-bold">selected cases</div>
                <div class="p-2 flex justify-start items-center overflow-hidden overflow-x-auto my-2">
                    
                    <template v-if="selectedCases.length">
                        <div
                            v-for="c in selectedCases"
                            :key="c.id"
                            class="capitalize mr-3 rounded relative text-sm p-2 w-fit text-gray-700 bg-gray-300 select-none cursor-pointer text-center"
                        >
                            {{ c.name }}
                            <div 
                                @click="() => removeCaseFromSelected(c)"
                                :title="`remove ${c.name}`"
                                class="absolute -top-2 -right-2 text-sm flex justify-center items-center transition duration-75 text-center rounded-full
                                    border-2 border-gray-800 bg-gray-300 text-gray-800 cursor-pointer w-6 h-6 hover:bg-gray-600 hover:text-white"
                            >x</div>
                        </div>
                    </template>
                    <div v-else class="w-full text-center text-sm text-gray-600">no selected cases</div>
                </div>
            </div>
        </div>
    </div>

    <CreateCaseModal
        v-if="showModal"
        :show="showModal"
        @close-modal="() => showModal = false"
        :addedby-id="addedby.id"
        :addedby-type="addedby.type"
        @after-creating="(data) => {
            cases = [data, ...cases]
        }"
    />
</template>