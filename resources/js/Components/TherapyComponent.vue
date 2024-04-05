<template>
    <div
        class="relative w-full"
    >
        <div class="text-gray-600 font-bold capitalize">Filter By</div>
    
        <div class="mt-4">
            <label class="flex items-center mb-2">
                <Checkbox v-model:checked="filters.sessions"></Checkbox>
                <span class="text-gray-600 ml-2">Sessions</span>
            </label>
            <label class="flex items-center">
                <Checkbox v-model:checked="filters.topics"></Checkbox>
                <span class="text-gray-600 ml-2">Topics</span>
            </label>
        </div>
        <FormLoader class="mx-auto relative" :show="loading" :text="`getting ${computedCurrentFilter}...`"/>
    </div>
    <div class="my-2 w-full h-1 rounded bg-stone-400"></div>
    <div v-bind="$attrs" class="min-h-[500px] relative flex flex-col items-center justify-center">
        <div v-if="therapy.status == 'PENDING'"
            class="flex absolute top-0 left-0 w-full h-full bg-gray-500 justify-center items-center text-gray-600 text-sm bg-opacity-35 rounded z-[1]">
            therapy is still pending
        </div>
        <div 
            class="rounded-lg bg-stone-100 w-full min-h-[50px] p-2 overflow-hidden overflow-x-auto mb-2 transition duration-200"
            :class="[computedFiltered ? 'opacity-100 translate-y-0' : 'opacity-20 -translate-y-5']"
            v-if="computedFiltered"
        >
            <PrimaryButton class="mr-2" @click="clickedCreateTopic">create topic</PrimaryButton>
            <template v-if="computedFilteredItems.length">
                <TherapyFilterItem
                    v-for="(item, idx) in computedFilteredItems"
                    :key="idx"
                    :item="item"
                    :type="computedCurrentFilter"
                    class="w-[60%] mr-2"
                    @dblclick="() => clickedFilterItem(item)"
                />
            </template>
            <div v-if="computedFilteredItems.length && computedPages && !loading">
                <div
                    title="get more"
                    class="text-gray-600 text-lg cursor-pointer p-2 align-middle hover:border-gray-600 border rounded-sm border-transparent w-fit h-[30px] flex justify-center items-center">
                    <div>...</div>
                </div>
            </div>
        </div>
        <div class="rounded-lg min-h-[400px] bg-stone-200 h-full w-full shrink mb-2">
            <div 
                :title="`double click to deselect ${computedCurrentFilter}.`"
                @click="deselectItem"
                class="text-sm text-gray-600 capitalize select-none cursor-pointer w-[90%] mx-auto rounded-b p-2 text-center font-bold tracking-wide bg-white"
                v-if="computedSelectedItem"
            >{{ computedSelectedItem.name }}</div>

        </div>
        <div class="rounded-lg bg-stone-100 w-full p-2" v-if="computedCanSendMessage">
            <div class="w-[90%] mx-auto min-h-10 flex justify-center items-start space-x-2">
                <TextBox
                    rows="3"
                    class="w-full shrink"
                    v-model="message"
                />
                <div class="flex justify-end space-x-2 items-start">
                    <PaperplaneIcon class="w-8 cursor-pointer p-1 h-8 rotate-45" />
                    <PaperclipIcon class="w-8 cursor-pointer p-1 h-8"
                        @click="() => showAttachmentIcons = true"
                    />
                </div>
            </div>
        </div>
        <div
            @click.self="() => showAttachmentIcons = false"
            :class="[showAttachmentIcons ? 'opacity-100 visible z-[1]' : 'opacity-0 invisible -z-[1]']" 
            class="w-full top-0 absolute transition-all duration-100 right-0 h-full bg-gray-600 bg-opacity-30 flex justify-center items-center">
            <div class="w-[80%] bg-white min-h-32 rounded flex justify-center items-center space-x-2 flex-wrap">
                <CameraIcon @click="clickedIcon('camera')" class="w-8 cursor-pointer p-1 h-8" />
                <MicrophoneIcon @click="clickedIcon('microphone')" class="w-8 cursor-pointer p-1 h-8" />
                <FileIcon @click="clickedIcon('file')" class="w-8 cursor-pointer p-1 h-8" />
            </div>
        </div>

        <input type="file" name="messageFiles" ref="messageFilesInput" class="hidden" id="messageFiles" multiple accept="image/*">
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { default as _ } from 'lodash';
import TextBox from './TextBox.vue';
import PaperplaneIcon from '@/Icons/PaperplaneIcon.vue';
import PaperclipIcon from '@/Icons/PaperclipIcon.vue';
import TherapyFilterItem from './TherapyFilterItem.vue';
import CameraIcon from '@/Icons/CameraIcon.vue';
import FileIcon from '@/Icons/FileIcon.vue';
import MicrophoneIcon from '@/Icons/MicrophoneIcon.vue';
import useAuth from '@/Composables/useAuth';
import Checkbox from '@/Components/Checkbox.vue';
import FormLoader from './FormLoader.vue';

const { goToLogin } = useAuth()

const props = defineProps({
    therapy: {
        default: null
    },
    newTopic: {
        default: null
    },
    newSession: {
        default: null
    },
    isParticipant: {
        default: false,
        type: Boolean
    }
})

const showAttachmentIcons = ref(false)
const loading = ref(false)
const pages = ref({
    message: 1,
    session: 1,
    topic: 1,
})
const message = ref('')
const selectedSession = ref(null)
const selectedTopic = ref(null)
const files = ref(null)
const sessions = ref([])
const messages = ref({

})
const topics = ref([])
const filters = ref({
    sessions: false,
    topics: false
})

watch(() => filters.value.sessions, () => {
    if (filters.value.sessions && filters.value.topics)
        filters.value.topics = !filters.value.sessions
})
watch(() => filters.value.topics, () => {
    if (filters.value.topics && filters.value.sessions)
        filters.value.sessions = !filters.value.topics
})
watch(() => filters.value.sessions, () => {
    if (pages.value.session == 1 && filters.value.sessions) {
        debouncedSessionsGet()
    }
})
watch(() => props.newSession?.id, () => {
    if (props.newSession?.id) sessions.value = [props.newSession, ...sessions.value]
})
watch(() => props.newTopic?.id, () => {
    if (props.newTopic?.id) topics.value = [props.newTopic, ...topics.value]
})

const computedCanSendMessage = computed(() => {
    return props.isParticipant && selectedSession.value?.status == 'IN_SESSION'
})
const computedFiltered = computed(() => {
    return ['session', 'topic'].includes(computedCurrentFilter.value)
})
const computedPages = computed(() => {
    return computedFiltered.value ? pages.value[computedCurrentFilter.value] : 0
})
const computedFilteredItems = computed(() => {
    return computedCurrentFilter.value == 'session'
        ? sessions.value 
        : (computedCurrentFilter.value == 'topic' ? sessions.value : [])
})
const computedCurrentFilter = computed(() => {
    return filters.value.sessions
        ? 'session' 
        : ( filters.value.topics ? 'topic' : '' )
})
const computedSelectedItem = computed(() => {
    return filters.value.sessions
        ? selectedSession.value 
        : ( filters.value.topics ? selectedTopic.value : null )
})

const debouncedSessionsGet = _.debounce(() => {
    getSessions()
}, 500)

const debouncedTopicsGet = _.debounce(() => {
    getTopics()
}, 500)

function clickedCreateTopic() {
    
}

function deselectItem() {
    if (computedCurrentFilter.value == 'session')
        selectedSession.value = null
    
    if (computedCurrentFilter.value == 'topic')
        selectedTopic.value = null
}

function clickedFilterItem(item) {
    if (computedCurrentFilter.value == 'session')
        selectedSession.value = item

    if (computedCurrentFilter.value == 'topic')
        selectedTopic.value = item
}

async function getMessages() {
    loading.value = true

    await axios
        .get(route('therapies.messages.get', {
            therapyId: props.therapy?.id,
            page: page.value
        }))
        .then((res) => {
            console.log(res)
        })
        .catch((err) => {
            console.log(err)
        })
        .finally(() => {
            loading.value = false
        })
}

async function getSessions() {
    loading.value = true

    await axios
        .get(route('api.sessions.get', {
            therapyId: props.therapy?.id,
            page: pages.value.session
        }))
        .then((res) => {
            console.log(res)
            updatePage(res, 'session')
            if (pages.value.session > 1) {
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

async function getTopics() {
    loading.value = true

    await axios
        .get(route('api.topics.get', {
            therapyId: props.therapy?.id,
            page: pages.value.session
        }))
        .then((res) => {
            console.log(res)
            updatePage(res, 'topic')
            if (pages.value.topic > 1) {
                topics.value = [...topics.value, ...res.data.data]
                return
            }

            topics.value = [...res.data.data]
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)
        })
        .finally(() => {
            loading.value = false
        })
}

function updatePage(res, key) {
    if (res.data.links.next) pages.value[key] = pages.value[key] + 1
    else pages.value[key] = 0
}

function setPages(num) {
    pages.value.session = num
    pages.value.session = num
    pages.value.message = num
}

function clickedIcon(type) {
    
}
</script>