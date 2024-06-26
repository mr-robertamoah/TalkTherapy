<script setup>
import CreateLanguageModal from '@/Components/CreateLanguageModal.vue';
import PreferenceItem from '@/Components/PreferenceItem.vue';
import TextInput from '@/Components/TextInput.vue';
import { watch } from 'vue';
import { onBeforeMount, ref } from 'vue';

const props = defineProps({
    loadedLanguages: {
        default: []
    },
    selectedLanguages: {
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
const languagesSearch = ref('')
const languagesPage = ref(0)
const languages = ref([])
const selectedLanguages = ref([])

onBeforeMount(() => {
    setLanguages()
})
watch(() => languagesSearch.value.length, () => {
    if (languagesSearch.value.length && languagesPage.value != 1) {
        languagesPage.value = 1
        debouncedGetLanguages()
    }
})
watch(() => selectedLanguages.value.length, () => {
    emits('onData', selectedLanguages.value)
})

const debouncedGetLanguages = () => {
    getLanguages()
}

function updateLanguagesPage(res) {
    if (res.data.links.next) languagesPage.value += 1
    else languagesPage.value = 0
}

async function getLanguages() {
    loading.value = true

    await axios
    .get(route('languages.get', {name: languagesSearch.value, page: languagesPage.value}))
    .then((res) => {
        console.log(res)
        if (languagesPage.value > 1) {
            languages.value = [...languages.value, ...res.data.data]
            updateLanguagesPage(res)
            return
        }

        languages.value = [...res.data.data]
        updateLanguagesPage(res)
    })
    .catch((err) => {
        console.log(err)
    })
    .finally(() => {
        loading.value = false
    })
}

function setLanguages() {
    languages.value = [...props.loadedLanguages]

    if (props.selectedLanguages.length)
        selectedLanguages.value = [...props.selectedLanguages]
}

function addLanguageToSelected(newLanguage) {
    selectedLanguages.value = [...selectedLanguages.value.filter((c) => c.id !== newLanguage.id), newLanguage]
}

function removeLanguageFromSelected(oldLanguage) {
    selectedLanguages.value = [...selectedLanguages.value.filter((c) => c.id !== oldLanguage.id)]
}
</script>

<template>
    <div v-bind="$attrs" class="overflow-hidden shadow-sm sm:rounded-lg my-8">
        <div class="p-6 text-gray-900">
            <div>
                <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">languages</div>
                <div class="text-sm text-justify text-gray-600 mb-4">
                    Selected language settings will help us connect you to potential clients that require professionals conversant with these languages.
                </div>
                <div class="text-sm text-center capitalize mb-2 font-bold">select</div>
                <div class="text-sm text-center mb-2 text-gray-600">double click/tap to a language to see options.</div>
                <div class="p-2 flex justify-start items-start flex-col overflow-hidden my-2 mb-4">
                    <div
                        class="mb-4 rounded text-sm p-2 min-w-[200px] text-white bg-blue-400 text-center"
                    >
                        <div>did not find what you are looking for?</div>
                        <div
                            @click="() => showModal = true"
                            title="create a language"
                            class="my-2 py-1 px-2 rounded bg-blue-600 text-blue-200 transition duration-75 cursor-pointer hover:text-white hover:bg-blue-800 w-fit mx-auto"
                        >create it</div>
                    </div>
                    <div class="w-full p-2 flex justify-start items-center overflow-hidden overflow-x-auto" v-if="languages.length">
                        <PreferenceItem
                            v-for="c in languages"
                            :key="c.id"
                            :item="c"
                            @select-item="(data) => addLanguageToSelected(data)"
                        />
                        <div 
                            v-if="languagesPage != 0"
                            class="p-2 text-gray-500 transition duration-75 cursor-pointer hover:text-gray-700"
                            @click="() => getLanguages()"
                            title="get more languages"
                        >...</div>
                    </div>
                    <div v-else class="w-full text-center text-sm text-gray-600">no languages</div>
                </div>
                <div
                    class="w-full text-center text-green-700 mt-8 mb-2"
                    v-if="loading"
                >getting{{ languagesPage < 2 ? '' : ' more' }} languages...</div>
                <div class="mb-8">
                    <TextInput
                        id="name"
                        type="text"
                        class="mt-1 block w-full max-w-[500px]"
                        v-model="languagesSearch"
                        placeholder="search for languages"
                    />
                </div>

                <div class="text-sm text-center capitalize mb-2 font-bold">selected languages</div>
                <div class="p-2 flex justify-start items-center overflow-hidden overflow-x-auto my-2">
                    
                    <template v-if="selectedLanguages.length">
                        <div
                            v-for="l in selectedLanguages"
                            :key="l.id"
                            class="capitalize mr-3 rounded relative text-sm p-2 w-fit text-gray-700 bg-gray-300 select-none cursor-pointer text-center"
                        >
                            {{ l.name }}
                            <div 
                                @click="() => removeLanguageFromSelected(l)"
                                :title="`remove ${l.name}`"
                                class="absolute -top-2 -right-2 text-sm flex justify-center items-center transition duration-75 text-center rounded-full
                                    border-2 border-gray-800 bg-gray-300 text-gray-800 cursor-pointer w-6 h-6 hover:bg-gray-600 hover:text-white"
                            >x</div>
                        </div>
                    </template>
                    <div v-else class="w-full text-center text-sm text-gray-600">no selected languages</div>
                </div>
            </div>
        </div>
    </div>

    <CreateLanguageModal
        v-if="showModal"
        :show="showModal"
        @close-modal="() => showModal = false"
        :addedby-id="addedby.id"
        :addedby-type="addedby.type"
        @after-creating="(data) => {
            languages = [data, ...languages]
        }"
    />
</template>