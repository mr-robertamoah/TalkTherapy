<script setup>
import CreateLicensingAuthorityModal from '@/Components/CreateLicensingAuthorityModal.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { onBeforeMount, watch, watchEffect } from 'vue';
import { ref } from 'vue';

const props = defineProps({
    start: {
        default: false
    },
    addedby: {
        default: {
            type: '',
            id: 0
        }
    },
    errors: {
        default: {
            number: '',
            file: ''
        }
    },
})

const emits = defineEmits(['onData'])

const inputFile = ref(null)
const loading = ref(false)
const showModal = ref(false)
const search = ref('')
const page = ref(0)
const selectedAuthority = ref(null)
const licensingAuthorities = ref([])
const data = ref({
    number: '',
    file: null,
    licensingAuthorityId: null
})

onBeforeMount(() => {
    if (props.start) {
        page.value = 1
        search.value = ''
        debouncedGet()
        return
    }

    // reset page
})
watch(() => data.value.licensingAuthorityId, () => {
    emits('onData', data.value)
})
watch(() => data.value.number, () => {
    emits('onData', data.value)
})
watch(() => data.value.file, () => {
    emits('onData', data.value)
})
watch(() => selectedAuthority.value, () => {
    data.value.licensingAuthorityId = selectedAuthority.value?.id

    if (!selectedAuthority.value) {
        data.value.number = ''
        data.value.file = null
    }
})

const debouncedGet = () => {
    getOtherLicensingAuthorities()
}

function changeFile(e) {
    if (e.target.files?.length) {
        data.value.file = e.target.files[0]
        inputFile.value.value = ''
    }
    
}

function updatePage(res) {
    if (res.data.links.next) page.value += 1
    else page.value = 0
}

async function getOtherLicensingAuthorities() {
    loading.value = true

    await axios
    .get(route('licensing_authorities', {name: search.value, page: page.value}))
    .then((res) => {
        console.log(res)
        if (page.value > 1) {
            licensingAuthorities.value = [...licensingAuthorities.value, ...res.data.data]
            updatePage(res)
            return
        }

        licensingAuthorities.value = [...res.data.data]
        updatePage(res)
    })
    .catch((err) => {
        console.log(err)
    })
    .finally(() => {
        loading.value = false
    })
}
</script>

<template>
    <div v-bind="$attrs" class="overflow-hidden shadow-sm sm:rounded-lg my-8">
        <div class="p-6 text-gray-900">
            <div>
                <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">Licensing Authorities</div>
                <div class="text-sm text-justify text-gray-600 mb-4">
                    You pick or create a licensing authority with which you have the authorization to offer counselling services. These authorities can be govermental, associations, non-profits, religious bodies, etc. When you create one and add a license, we will verify both the authority and the license.
                </div>
                <div class="text-sm text-center capitalize mb-2 font-bold">select authority</div>
                <div class="p-2 flex justify-start items-start flex-col overflow-hidden my-2 mb-4">
                    <div
                        class="mb-4 rounded text-sm p-2 min-w-[200px] text-white bg-blue-400 text-center"
                    >
                        <div>did not find your licensing authority?</div>
                        <div
                            @click="() => showModal = true"
                            title="create a case"
                            class="my-2 py-1 px-2 rounded bg-blue-600 text-blue-200 transition duration-75 cursor-pointer hover:text-white hover:bg-blue-800 w-fit mx-auto"
                        >create it</div>
                    </div>
                    <div class="w-full p-2 flex justify-start items-center overflow-hidden overflow-x-auto" v-if="licensingAuthorities.length">
                        <div
                            v-for="c in licensingAuthorities"
                            :key="c.id"
                            @click="() => {
                                selectedAuthority = {...c}
                            }"
                            class="mr-3 rounded text-sm p-2 min-w-[100px] text-gray-700 bg-gray-300 select-none transition duration-75 cursor-pointer hover:bg-gray-600 hover:text-white text-center"
                        >
                            <div>{{ c.name }}</div>
                            <div class="capitalize w-full text-justify text-xs ">{{ c.about }}</div>
                            <div class="w-full text-end text-xs text-gray-500">type . {{ c.type }}</div>
                            <div class="w-full text-end text-xs text-gray-500">license type . {{ c.licenseType }}</div>
                        </div>
                        <div 
                            v-if="page != 0"
                            class="p-2 text-gray-500 transition duration-75 cursor-pointer hover:text-gray-700"
                            @click="() => getCases()"
                            title="get more licensing authorities"
                        >...</div>
                    </div>
                    <div v-else class="w-full text-center text-sm text-gray-600">no authorities available</div>
                </div>
                <div
                    class="w-full text-center text-green-700 mt-8 mb-2"
                    v-if="loading"
                >getting{{ page < 2 ? '' : ' more' }} authorities...</div>
                <div class="mb-8">
                    <TextInput
                        id="name"
                        type="text"
                        class="mt-1 block w-full max-w-[500px]"
                        v-model="search"
                        placeholder="search for licensing authorities"
                    />
                </div>

                <div class="text-sm text-center capitalize mb-2 font-bold">selected authority</div>
                <div class="p-2 my-2">
                    
                    <template v-if="selectedAuthority">
                        <div
                            class="capitalize mr-3 rounded relative text-sm p-2 min-w-[100px] max-w-[350px] text-gray-700 bg-gray-300 select-none cursor-pointer text-center"
                        >
                            {{ selectedAuthority.name }}
                            <div 
                                @click="() => selectedAuthority = null"
                                :title="`remove ${selectedAuthority.name}`"
                                class="absolute -top-2 -right-2 text-sm flex justify-center items-center transition duration-75 text-center rounded-full
                                    border-2 border-gray-800 bg-gray-300 text-gray-800 cursor-pointer w-6 h-6 hover:bg-gray-600 hover:text-white"
                            >x</div>
                        </div>

                        <div>

                            <div class="w-full mt-4 mx-auto max-w-[400px]">
                                <input @change="changeFile" accept="image/*,application/pdf,application/docx" type="file" name="national_id" id="national_id" class="hidden" ref="inputFile">
                                <InputLabel  for="number" value="Licensing Number" />

                                <TextInput
                                    id="number"
                                    type="text"
                                    class="mt-1 block w-full"
                                    v-model="data.number"
                                    autofocus
                                    v-if="['BOTH', 'NUMBER'].includes(selectedAuthority.licenseType)"
                                />

                                <div class="flex items-center justify-end my-4" v-if="['BOTH', 'FILE'].includes(selectedAuthority.licenseType)">
                                    <div v-if="data.file" class="text-gray-600 text-sm mr-2">{{ data.file.name }}</div>
                                    <PrimaryButton @click.prevent="() => {
                                        inputFile.click()
                                    }">
                                        {{data.file ? 'change file' : 'add file'}}
                                    </PrimaryButton>
                                </div>
                                
                                <InputError class="mt-2" :message="errors?.number ?? ''" />
                                <InputError class="mt-2" :message="errors?.file ?? ''" />
                            </div>
                        </div>
                    </template>
                    <div v-else class="w-full text-center text-sm text-gray-600">no selected authority</div>
                </div>
            </div>
        </div>
    </div>

    <CreateLicensingAuthorityModal
        v-if="showModal"
        :show="showModal"
        @close-modal="() => showModal = false"
        :addedby-id="addedby.id"
        :addedby-type="addedby.type"
        @after-creating="(data) => {
            licensingAuthorities = [data, ...licensingAuthorities]
        }"
    />
</template>