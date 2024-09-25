<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { onBeforeMount, ref, watch, watchEffect } from 'vue';
import InputError from '@/Components/InputError.vue';
import PreferenceItem from '@/Components/PreferenceItem.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Alert from '@/Components/Alert.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextBox from '@/Components/TextBox.vue';
import useAlert from '@/Composables/useAlert';
import useErrorHandler from '@/Composables/useErrorHandler';
import Checkbox from '@/Components/Checkbox.vue';
import StyledLink from '@/Components/StyledLink.vue';
import HelpButton from '@/Components/HelpButton.vue';
import useGuidedTours from '@/Composables/useGuidedTours';


const { PAGES } = useGuidedTours()
const form = useForm({
    selectedCases: [],
    selectedLanguages: [],
    selectedReligions: [],
    anonymous: false
})

const preferenceChanged = ref(false)
const loader = ref({
    show: false, type: ''
})
const modalData = ref({
    show: false, type: ''
})
const caseData = ref({
    name: '', description: ''
})
const caseErrors = ref({
    name: '', description: ''
})
const casesSearch = ref('')
const casesPage = ref(0)
const cases = ref([])
const selectedCases = ref([])
const languageData = ref({
    name: '', about: ''
})
const languageErrors = ref({
    name: '', about: ''
})
const languagesSearch = ref('')
const languagesPage = ref(0)
const languages = ref([])
const selectedLanguages = ref([])
const religionData = ref({
    name: '', about: ''
})
const religionErrors = ref({
    name: '', about: ''
})
const religionsSearch = ref('')
const religionsPage = ref(0)
const religions = ref([])
const selectedReligions = ref([])

const { alertData, clearAlertData, setAlertData } = useAlert()
const { setErrorData, clearError } = useErrorHandler()

onBeforeMount(() => {
    setCasesAndLanguages()
})

watch(() => casesSearch.value.length, () => {
    if (casesSearch.value.length && casesPage.value != 1) {
        casesPage.value = 1
        debouncedGetCases()
    }
})
watch(() => languagesSearch.value.length, () => {
    if (languagesSearch.value.length && languagesPage.value != 1) {
        languagesPage.value = 1
        debouncedGetLanguages()
    }
})
watch(() => religionsSearch.value.length, () => {
    if (religionsSearch.value.length && religionsPage.value != 1) {
        religionsPage.value = 1
        debouncedGetReligions()
    }
})
watch(() => caseData.value.name, () => {
    if (
        caseData.value.name?.length &&
        caseErrors.value.name?.length
    ) clearError(caseErrors, 'name')
})
watch(() => languageData.value.name, () => {
    if (
        languageData.value.name?.length &&
        languageErrors.value.name?.length
    ) clearError(languageErrors, 'name')
})
watch(() => religionData.value.name, () => {
    if (
        religionData.value.name?.length &&
        religionErrors.value.name?.length
    ) clearError(religionErrors, 'name')
})

function setCasesAndLanguages() {
    const props = usePage().props
    cases.value = [...props.loadedCases.data]
    languages.value = [...props.loadedLanguages.data]
    religions.value = [...props.loadedReligions.data]

    const settings = usePage().props?.auth?.user?.settings
    if (!settings) return

    if (typeof settings.anonymous == 'boolean') {
        form.anonymous = settings.anonymous
    }

    if (props.cases?.data?.length)
        selectedCases.value = [...props.cases.data]
    if (props.languages?.data?.length)
        selectedLanguages.value = [...props.languages.data]
    if (props.religions?.data?.length)
        selectedReligions.value = [...props.religions.data]
}

const debouncedGetCases = () => {
    getCases()
}

const debouncedGetLanguages = () => {
    getLanguages()
}

const debouncedGetReligions = () => {
    getReligions()
}

async function getCases() {
    loader.value.type = 'cases'
    loader.value.show = true

    await axios
    .get(route('cases.get', {name: casesSearch.value, page: casesPage.value}))
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
        clearLoader()
    })
}

function updateCasesPage(res) {
    if (res.data.links.next) casesPage.value += 1
    else casesPage.value = 0
}

async function getLanguages() {
    loader.value.type = 'lang'
    loader.value.show = true

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
        clearLoader()
    })
}

async function getReligions() {
    loader.value.type = 'religion'
    loader.value.show = true

    await axios
    .get(route('religions.get', {name: religionsSearch.value, page: religionsPage.value}))
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
        clearLoader()
    })
}

function clearLoader() {
    loader.value.type = ''
    loader.value.show = false
}

function updateLanguagesPage(res) {
    if (res.data.links.next) languagesPage.value += 1
    else languagesPage.value = 0
}

function updateReligionsPage(res) {
    if (res.data.links.next) religionsPage.value += 1
    else religionsPage.value = 0
}
 
function submitPreferences() {
    loader.value.type = 'preference'
    loader.value.show = true

    form.selectedCases = selectedCases.value.map(c => c.id)
    form.selectedLanguages = selectedLanguages.value.map(l => l.id)
    form.selectedReligions = selectedReligions.value.map(r => r.id)

    form.post(route(`preferences.set`), {
        onFinish: () => form.reset('anonymous', 'selectedCases', 'selectedLanguages', 'selectedReligions'),
    })
}
 
async function createCase() {
    loader.value.type = 'create case'
    loader.value.show = true

    await axios
    .post(route(`therapy-cases.create`), {
        ...caseData.value
    })
    .then((res) => {
        console.log(res)
        cases.value = [res.data.case, ...cases.value]
        closeModal()
    })
    .catch((err) => {
        console.log(err, err.response?.status)
        if (err.response?.status == 422) {
            setErrorData(caseErrors, err.response.data.errors, ['name', 'description'])
            return
        }

        setAlertData({
            message: 'Something unfortunate happened. Please try again later.',
            type: 'failed',
            show: true,
        })
    })
    .finally(() => {
        clearLoader()
    })
}
 
 async function createLanguage() {
     loader.value.type = 'create language'
     loader.value.show = true
 
     await axios
     .post(route(`languages.create`), {
         ...languageData.value
     })
     .then((res) => {
        console.log(res)
        languages.value = [res.data.language, ...languages.value]
        closeModal()
     })
     .catch((err) => {
         console.log(err)
        if (err.response?.status == 422) {
            setErrorData(languageErrors, err.response.data.errors, ['name', 'about'])
            return
        }

         setAlertData({
             message: 'Something unfortunate happened. Please try again later.',
             type: 'failed',
             show: true,
         })
     })
     .finally(() => {
         clearLoader()
     })
 }
 
 async function createReligion() {
     loader.value.type = 'create religion'
     loader.value.show = true
 
     await axios
     .post(route(`religions.create`), {
         ...religionData.value
     })
     .then((res) => {
        console.log(res)
        religions.value = [res.data.religion, ...religions.value]
        closeModal()
     })
     .catch((err) => {
         console.log(err)
        if (err.response?.status == 422) {
            setErrorData(religionErrors, err.response.data.errors, ['name', 'about'])
            return
        }

         setAlertData({
             message: 'Something unfortunate happened. Please try again later.',
             type: 'failed',
             show: true,
         })
     })
     .finally(() => {
         clearLoader()
     })
 }
 
function showModal(type) {
    modalData.value.type = type
    modalData.value.show = true
}
 
function closeModal() {
    if (modalData.value.type.includes('case')) clearCaseData()
    else if (modalData.value.type.includes('religion')) clearReligionData()
    else clearLanguageData()
    modalData.value.type = ''
    modalData.value.show = false
}

function clearCaseData() {
    caseData.value.name = ''
    caseData.value.description = ''
}

function clearLanguageData() {
    languageData.value.name = ''
    languageData.value.about = ''
}

function clearReligionData() {
    religionData.value.name = ''
    religionData.value.about = ''
}

function checkPreferences() {
    preferenceChanged.value = false

    const props = usePage().props
    if (props.auth?.user.settings?.anonymous !== form.anonymous) {
        preferenceChanged.value = true
        return
    }

    let data = props.cases?.data?.map(c => c.id)
    if (
        !selectedCases.value.length && data?.length ||
        selectedCases.value.length && !data?.length ||
        selectedCases.value.length !== !data?.length
    ) {
        preferenceChanged.value = true
        return
    }

    selectedCases.value.forEach(c => {
        if (data.includes(c)) return
        preferenceChanged.value = true
    })

    if (preferenceChanged.value) return

    data = props.languages?.data?.map(c => c.id)
    if (
        !selectedLanguages.value.length && data?.length ||
        selectedLanguages.value.length && !data?.length ||
        selectedLanguages.value.length !== !data?.length
    ) {
        preferenceChanged.value = true
        return
    }

    selectedLanguages.value.forEach(c => {
        if (data.includes(c)) return
        preferenceChanged.value = true
    })

    if (preferenceChanged.value) return
    
    data = props.religions?.data?.map(c => c.id)
    if (
        !selectedReligions.value.length && data?.length ||
        selectedReligions.value.length && !data?.length ||
        selectedReligions.value.length !== !data?.length
    ) {
        preferenceChanged.value = true
        return
    }

    selectedReligions.value.forEach(c => {
        if (data.includes(c)) return
        preferenceChanged.value = true
    })
}

function addCaseToSelected(newCase) {
    selectedCases.value = [...selectedCases.value.filter((c) => c.id !== newCase.id), newCase]
    checkPreferences()
}

function removeCaseFromSelected(oldCase) {
    selectedCases.value = [...selectedCases.value.filter((c) => c.id !== oldCase.id)]
    checkPreferences()
}

function addLanguageToSelected(newLanguage) {
    selectedLanguages.value = [...selectedLanguages.value.filter((c) => c.id !== newLanguage.id), newLanguage]
    checkPreferences()
}

function removeLanguageFromSelected(oldLanguage) {
    selectedLanguages.value = [...selectedLanguages.value.filter((c) => c.id !== oldLanguage.id)]
    checkPreferences()
}

function addReligionToSelected(newReligion) {
    selectedReligions.value = [...selectedReligions.value.filter((c) => c.id !== newReligion.id), newReligion]
    checkPreferences()
}

function removeReligionFromSelected(oldReligion) {
    selectedReligions.value = [...selectedReligions.value.filter((c) => c.id !== oldReligion.id)]
    checkPreferences()
}

</script>

<template>
    <Head title="Preferences" />

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Preferences</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 my-4 flex justify-end">
                <HelpButton
                    title="get help on Preference Page"
                    :page="PAGES.preference"
                    class="mr-4"
                    :user="$page.props.auth?.user"
                />
            </div>
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg my-8 p-6">
                    <StyledLink :href="route('profile.show')" class="float-right my-2 mr-2" title="go to profile" :text="'skip'"/>
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">Set your preferences</div>
                    <div class="text-sm text-justify text-gray-600 mb-4">
                        You can set your preferences now or skip to your profile. You can always set them at any time.
                    </div>
                </div>

                <div id="preference-anon-id" class="relative"></div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg my-8">
                    <div class="p-6 text-gray-900">
                        <div>
                            <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">Anonymity</div>
                            <div class="text-sm text-justify text-gray-600 mb-4">
                                Most people feel more open about their issues when they stay anonymous. Once you set this preference, you will be automatically anonymous in every therapy you engage in, unless you change this preference or specifically unset the anonymous attribute of the therapy.
                            </div>
                            <div class="text-sm text-center capitalize mb-2 font-bold">select preferences</div>
                            
                            <div class="block mt-4">
                                <label class="flex items-center">
                                    <Checkbox name="anonymous" @change="() => checkPreferences()" v-model:checked="form.anonymous" />
                                    <span class="ms-2 text-sm text-gray-600">Automatically stay anonymous</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg my-8">
                    <div id="preference-cases-id" class="relative"></div>
                    <div class="p-6 text-gray-900">
                        <div>
                            <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">cases</div>
                            <div class="text-sm text-justify text-gray-600 mb-4">
                                Selected case preferences will help us get you the most helpful posted therapies as well as counsellors with the expertise of handling your preferred cases.
                            </div>
                            <div class="text-sm text-center capitalize mb-2 font-bold">select preferences</div>
                            <div class="text-sm text-center mb-2 text-gray-600">double click/tap to a case to see options.</div>
                            <div class="p-2 flex justify-start items-center overflow-hidden overflow-x-auto my-2 mb-4">
                                <div
                                    class="mr-3 rounded text-sm p-2 min-w-[200px] text-white bg-blue-400 text-center"
                                >
                                    <div>did not find what you are looking for?</div>
                                    <div
                                        @click="() => showModal('cases')"
                                        title="create a case"
                                        class="my-2 py-1 px-2 rounded bg-blue-600 text-blue-200 transition duration-75 cursor-pointer hover:text-white hover:bg-blue-800 w-fit mx-auto"
                                    >create it</div>
                                </div>
                                <template v-if="cases.length">
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
                                </template>
                                <div v-else class="w-full text-center text-sm text-gray-600">no cases</div>
                            </div>
                            <div
                                class="w-full text-center text-green-700 mt-8 mb-2"
                                v-if="loader.show && loader.type == 'cases'"
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

                            <div class="text-sm text-center capitalize mb-2 font-bold">selected preferences</div>
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
                                <div v-else class="w-full text-center text-sm text-gray-600">no preferred cases</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg my-8">
                    <div id="preference-languages-id" class="relative"></div>
                    <div class="p-6 text-gray-900">
                        <div>
                            <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">languages</div>
                            <div class="text-sm text-justify text-gray-600 mb-4">
                                Selected language preferences will help us connect you to therapies with sessions in that language as well as counsellors conversant with these languages.
                            </div>
                            <div class="text-sm text-center capitalize mb-2 font-bold">select preferences</div>
                            <div class="text-sm text-center mb-2 text-gray-600">double click/tap to a language to see options.</div>
                            <div class="p-2 flex justify-start items-center overflow-hidden overflow-x-auto my-2 mb-4">
                                <div
                                    class="mr-3 rounded text-sm p-2 min-w-[200px] text-white bg-blue-400 text-center"
                                >
                                    <div>did not find what you are looking for?</div>
                                    <div
                                        @click="() => showModal('languages')"
                                        title="create a language"
                                        class="my-2 py-1 px-2 rounded bg-blue-600 text-blue-200 transition duration-75 cursor-pointer hover:text-white hover:bg-blue-800 w-fit mx-auto"
                                    >create it</div>
                                </div>
                                <template v-if="languages.length">
                                    <PreferenceItem
                                        v-for="language in languages"
                                        :key="language.id"
                                        :item="language"
                                        @select-item="(data) => addLanguageToSelected(data)"
                                    />
                                    <div 
                                        v-if="languagesPage != 0"
                                        class="p-2 text-gray-500 transition duration-75 cursor-pointer hover:text-gray-700"
                                        @click="() => getLanguages()"
                                        title="get more languages"
                                    >...</div>
                                </template>
                                <div v-else class="w-full text-center text-sm text-gray-600">no languages</div>
                            </div>
                            <div
                                class="w-full text-center text-green-700 mt-8 mb-2"
                                v-if="loader.show && loader.type == 'languages'"
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

                            <div class="text-sm text-center capitalize mb-2 font-bold">selected preferences</div>
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
                                <div v-else class="w-full text-center text-sm text-gray-600">no preferred languages</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg my-8">
                    <div id="preference-religions-id" class="relative"></div>
                    <div class="p-6 text-gray-900">
                        <div>
                            <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">religions</div>
                            <div class="text-sm text-justify text-gray-600 mb-4">
                                Some people feel more confident and find it easier when interacting with persons with whom they have similar religious inclinations. This preference helps us to connect you to persons with similar inclinations.
                            </div>
                            <div class="text-sm text-center capitalize mb-2 font-bold">select preferences</div>
                            <div class="text-sm text-center mb-2 text-gray-600">double click/tap to a religion to see options.</div>
                            <div class="p-2 flex justify-start items-center overflow-hidden overflow-x-auto my-2 mb-4">
                                <div
                                    class="mr-3 rounded text-sm p-2 min-w-[200px] text-white bg-blue-400 text-center"
                                >
                                    <div>did not find what you are looking for?</div>
                                    <div
                                        @click="() => showModal('religions')"
                                        title="create a religion"
                                        class="my-2 py-1 px-2 rounded bg-blue-600 text-blue-200 transition duration-75 cursor-pointer hover:text-white hover:bg-blue-800 w-fit mx-auto"
                                    >create it</div>
                                </div>
                                <template v-if="religions.length">
                                    <PreferenceItem
                                        v-for="religion in religions"
                                        :key="religion.id"
                                        :item="religion"
                                        @select-item="(data) => addReligionToSelected(data)"
                                    />
                                    <div 
                                        v-if="religionsPage != 0"
                                        class="p-2 text-gray-500 transition duration-75 cursor-pointer hover:text-gray-700"
                                        @click="() => getReligions()"
                                        title="get more religions"
                                    >...</div>
                                </template>
                                <div v-else class="w-full text-center text-sm text-gray-600">no religions</div>
                            </div>
                            <div
                                class="w-full text-center text-green-700 mt-8 mb-2"
                                v-if="loader.show && loader.type == 'religions'"
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

                            <div class="text-sm text-center capitalize mb-2 font-bold">selected preferences</div>
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
                                <div v-else class="w-full text-center text-sm text-gray-600">no preferred religions</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="preference-action-id" class="relative"></div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg my-8 p-4 flex justify-end">
                    <PrimaryButton
                        class="ms-4" 
                        :class="{ 'opacity-25': (loader.show && loader.type == 'preference') }" 
                        :disabled="loader.type == 'preference' || !preferenceChanged"
                        @click="submitPreferences"
                    >
                        set preferences
                    </PrimaryButton>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>

    <Modal
        :show="modalData.show"
        @close="closeModal"
    >
        <div class="p-4">

            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >Create {{ modalData.type == 'cases' ? 'Case' : (modalData.type == 'religions' ? 'Religion' : 'Language') }}</div>
                <hr>
            </div>
            <div
                v-if="modalData.type == 'cases'"
            >
                <div 
                    class="text-green-700 bg-green-300 rounded my-2 transition duration-100 text-center w-[80%] py-1 px-2 mx-auto"
                    :class="[(loader.show && loader.type.includes('create')) ? 'visible' : 'invisible']"
                >creating case...</div>
                <form 
                    @submit.prevent="createCase"
                >

                    <div class="mt-4 mx-auto max-w-[400px]">
                        <InputLabel for="name" value="Name" />

                        <TextInput
                            id="name"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="caseData.name"
                            required
                            autofocus
                        />

                        <InputError class="mt-2" :message="caseErrors.name" />
                    </div>

                    <div class="mt-4 mx-auto max-w-[400px]">
                        <InputLabel for="description" value="Description" />

                        <TextBox
                            id="description"
                            class="mt-1 block w-full"
                            v-model="caseData.description"
                        />

                        <InputError class="mt-2" :message="caseErrors.description" />
                    </div>

                    <div class="flex items-center justify-end mt-4">

                        <PrimaryButton class="ms-4" :class="{ 'opacity-25': loader.show }" :disabled="loader.type == 'create case'">
                            create
                        </PrimaryButton>
                    </div>
                </form>
            </div>

            <div
                v-if="modalData.type == 'languages'"
            >
                <div 
                    class="text-green-700 bg-green-300 rounded my-2 transition duration-100 text-center w-[80%] py-1 px-2 mx-auto"
                    :class="[(loader.show && loader.type.includes('create')) ? 'visible' : 'invisible']"
                >creating language...</div>
                <form 
                    @submit.prevent="createLanguage"
                >

                    <div class="mt-4 mx-auto max-w-[400px]">
                        <InputLabel for="name" value="Name" />

                        <TextInput
                            id="name"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="languageData.name"
                            required
                        />

                        <InputError class="mt-2" :message="languageErrors.name" />
                    </div>

                    <div class="mt-4 mx-auto max-w-[400px]">
                        <InputLabel for="about" value="About" />

                        <TextBox
                            id="about"
                            class="mt-1 block w-full"
                            v-model="languageData.about"
                        />

                        <InputError class="mt-2" :message="languageErrors.about" />
                    </div>

                    <div class="flex items-center justify-end mt-4">

                        <PrimaryButton class="ms-4" :class="{ 'opacity-25': loader.show }" :disabled="loader.type == 'create language'">
                            create
                        </PrimaryButton>
                    </div>
                </form>
            </div>
            
            <div
                v-if="modalData.type == 'religions'"
            >
                <div 
                    class="text-green-700 bg-green-300 rounded my-2 transition duration-100 text-center w-[80%] py-1 px-2 mx-auto"
                    :class="[(loader.show && loader.type.includes('create')) ? 'visible' : 'invisible']"
                >creating religion...</div>
                <form 
                    @submit.prevent="createReligion"
                >

                    <div class="mt-4 mx-auto max-w-[400px]">
                        <InputLabel for="name" value="Name" />

                        <TextInput
                            id="name"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="religionData.name"
                            required
                        />

                        <InputError class="mt-2" :message="religionErrors.name" />
                    </div>

                    <div class="mt-4 mx-auto max-w-[400px]">
                        <InputLabel for="about" value="About" />

                        <TextBox
                            id="about"
                            class="mt-1 block w-full"
                            v-model="religionData.about"
                        />

                        <InputError class="mt-2" :message="religionErrors.about" />
                    </div>

                    <div class="flex items-center justify-end mt-4">

                        <PrimaryButton class="ms-4" :class="{ 'opacity-25': loader.show }" :disabled="loader.type == 'create religion'">
                            create
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </Modal>
</template>