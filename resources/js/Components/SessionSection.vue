<template>
    <div>
        <div class="text-sm text-center mb-2 font-bold">Select session(s)</div>
        <div class="p-2 flex justify-start items-start flex-col overflow-hidden my-2 mb-4">
            <div class="w-full p-2 flex justify-start items-center overflow-hidden overflow-x-auto">
                <PreferenceItem
                    v-for="c in sessions"
                    :key="c.id"
                    :item="c"
                    @select-item="(data) => addSessionToSelected(data)"
                    class="w-fit shrink-0"
                />
                <div 
                    v-if="page !== 0 && sessions.length"
                    class="p-2 text-gray-500 transition duration-75 cursor-pointer hover:text-gray-700"
                    @click="() => debouncedSessionsGet()"
                    title="get more cases"
                >...</div>
                <div 
                    v-if="!sessions.length"
                    class="p-2 text-gray-600 select-none text-sm w-full text-center"
                >no sessions</div>
            </div>
        </div>
        <div
            class="w-full text-center text-green-700 mt-8 mb-2"
            v-if="loading"
        >getting{{ page < 2 ? '' : ' more' }} sessions...</div>
        <div class="mb-8">
            <TextInput
                id="name"
                type="text"
                class="mt-1 block w-full max-w-[500px]"
                v-model="search"
                placeholder="search for sessions"
            />
        </div>
        <div class="text-sm text-center mb-2 font-bold">Selected sessions</div>
        <div class="p-2 flex justify-start items-center overflow-hidden overflow-x-auto my-2">
            
            <template v-if="selectedSessions.length">
                <div
                    v-for="c in selectedSessions"
                    :key="c.id"
                    class="capitalize mr-3 rounded relative text-sm w-fit p-2 min-w-[100px] text-gray-700 bg-gray-300 select-none cursor-pointer text-center"
                >
                    {{ c.name }}
                    <div 
                        @click="() => removeSessionFromSelected(c)"
                        :title="`remove ${c.name}`"
                        class="absolute -top-2 -right-2 text-sm flex justify-center items-center transition duration-75 text-center rounded-full
                            border-2 border-gray-800 bg-gray-300 text-gray-800 cursor-pointer w-6 h-6 hover:bg-gray-600 hover:text-white"
                    >x</div>
                </div>
            </template>
            <div v-else class="w-full text-center text-sm text-gray-600">no selected sessions</div>
        </div>
    </div>
</template>

<script setup>
import TextInput from '@/Components/TextInput.vue';
import { ref, watch, watchEffect } from "vue"
import PreferenceItem from './PreferenceItem.vue';


const emits = defineEmits(['onData'])

const props = defineProps({
    loadedSessions: {
        default: []
    },
    selectedSessions: {
        default: []
    },
    loadedSessionsPage: {
        default: 0
    },
    therapy: {
        default: null
    },
})

const sessions = ref([])
const loading = ref(false)
const page = ref(1)
const search = ref('')
const selectedSessions = ref([])

watchEffect(() => {
    if (props.loadedSessions.length)
        sessions.value = [...props.loadedSessions]

    if (props.selectedSessions?.length)
        selectedSessions.value = [...props.selectedSessions]
    
    page.value = props.loadedSessionsPage
})

watch(() => selectedSessions.value?.length, () => {
    emits('onData', selectedSessions.value)
})
watch(() => search.value, () => {
    if (search.value?.length) {
        page.value = 1
        debouncedSessionsGet()
    }
})

function addSessionToSelected(newSession) {
    selectedSessions.value = [...selectedSessions.value.filter((c) => c.id !== newSession.id), newSession]
}

function removeSessionFromSelected(oldSession) {
    selectedSessions.value = [...selectedSessions.value.filter((c) => c.id !== oldSession.id)]
}

const debouncedSessionsGet = _.debounce(() => {
    getSessions()
}, 500)

async function getSessions() {
    loading.value = true

    await axios
        .get(route('api.sessions.get', {
            therapyId: props.therapy?.id,
            page: page.value,
            name: search.value
        }))
        .then((res) => {
            console.log(res)
            updatePage(res)
            if (page.value > 1) {
                sessions.value = [...sessions.value, ...res.data.data]
                return
            }

            sessions.value = [...res.data.data]
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)
        })
        .finally(() => {
            loading.value = false
        })
}

function updatePage(res) {
    if (res.data.links.next) page.value += 1
    else page.value = 0
}
</script>