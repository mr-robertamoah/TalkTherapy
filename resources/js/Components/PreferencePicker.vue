<script setup>
// SCRUM-393: one picker for a kind of preference (cases, languages, religions) -- search first, then
// the matching items as toggle chips (a tick when chosen), "Load more" for the next page, the chosen
// ones listed underneath, and "Add it" when the search finds nothing that fits. Selection is the
// parent's (v-model); this only fetches and shows.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import TextInput from './TextInput.vue'

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    lookupRoute: { type: String, required: true },    // e.g. 'cases.get'
    singular: { type: String, required: true },       // e.g. 'case'
    plural: { type: String, required: true },         // e.g. 'cases'
    max: { type: Number, default: 20 },
    canAdd: { type: Boolean, default: true },
})

const emit = defineEmits(['update:modelValue', 'add'])

const search = ref('')
const items = ref([])
const page = ref(1)
const hasMore = ref(false)
const loading = ref(false)
const failed = ref(false)
let timer = null
let latest = 0

const selectedIds = computed(() => new Set(props.modelValue.map((item) => item.id)))
const full = computed(() => props.modelValue.length >= props.max)

async function load(more = false) {
    const ticket = ++latest
    loading.value = true
    failed.value = false
    const nextPage = more ? page.value + 1 : 1

    await axios.get(route(props.lookupRoute, { name: search.value.trim(), page: nextPage }))
        .then((res) => {
            if (ticket !== latest) return
            items.value = more ? [...items.value, ...res.data.data] : [...res.data.data]
            page.value = nextPage
            hasMore.value = Boolean(res.data.links?.next)
        })
        .catch(() => { if (ticket === latest) failed.value = true })
        .finally(() => { if (ticket === latest) loading.value = false })
}

watch(search, () => {
    clearTimeout(timer)
    timer = setTimeout(() => load(), 300)
})

onMounted(() => load())
onBeforeUnmount(() => clearTimeout(timer))

function toggle(item) {
    if (selectedIds.value.has(item.id)) {
        emit('update:modelValue', props.modelValue.filter((chosen) => chosen.id !== item.id))
        return
    }
    if (full.value) return
    emit('update:modelValue', [...props.modelValue, item])
}

// A newly added item goes to the top of the list (and is chosen, when there's room).
function added(item) {
    items.value = [item, ...items.value.filter((existing) => existing.id !== item.id)]
    if (!selectedIds.value.has(item.id) && !full.value) emit('update:modelValue', [...props.modelValue, item])
}

defineExpose({ added })

const chip = 'inline-flex items-center gap-1.5 min-h-[36px] rounded-full border px-3 py-1 text-sm capitalize transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-1'
</script>

<template>
    <div>
        <label :for="`preference-search-${plural}`" class="sr-only">Search {{ plural }}</label>
        <TextInput
            :id="`preference-search-${plural}`"
            v-model="search"
            type="search"
            maxlength="100"
            class="block w-full max-w-[500px]"
            :placeholder="`Search ${plural}`"
        />

        <div class="mt-3 flex flex-wrap gap-2" role="group" :aria-label="`${plural} to choose from`" :aria-busy="loading">
            <button
                v-for="item in items"
                :key="item.id"
                type="button"
                :class="[chip, selectedIds.has(item.id)
                    ? 'border-blue-600 bg-blue-600 text-white hover:bg-blue-700'
                    : 'border-gray-300 bg-white text-gray-800 hover:border-gray-400 hover:bg-gray-50',
                    !selectedIds.has(item.id) && full ? 'cursor-not-allowed opacity-50' : '']"
                :aria-pressed="selectedIds.has(item.id)"
                :disabled="!selectedIds.has(item.id) && full"
                :title="item.description ?? item.about ?? ''"
                @click="toggle(item)"
            >
                <svg v-if="selectedIds.has(item.id)" class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" />
                </svg>
                {{ item.name }}
            </button>

            <p v-if="!loading && !failed && !items.length" class="text-sm text-gray-600">
                No {{ plural }} match "{{ search.trim() }}".
            </p>
        </div>

        <p v-if="failed" class="mt-2 text-sm text-red-700" role="alert">
            Couldn't load {{ plural }}.
            <button type="button" class="font-medium underline" @click="load()">Try again</button>
        </p>
        <p v-else-if="loading" class="mt-2 text-sm text-gray-600" aria-live="polite">Loading {{ plural }}…</p>

        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
            <button v-if="hasMore && !loading" type="button" class="font-medium text-blue-700 underline" @click="load(true)">
                Load more {{ plural }}
            </button>
            <span v-if="canAdd" class="text-gray-600">
                Can't find it?
                <button type="button" class="font-medium text-blue-700 underline" @click="emit('add', search.trim())">Add a {{ singular }}</button>
            </span>
        </div>

        <div class="mt-5 border-t border-gray-100 pt-4">
            <div class="flex items-baseline justify-between gap-2">
                <h4 class="text-sm font-semibold text-gray-800">Your {{ plural }}</h4>
                <span class="text-xs text-gray-600">{{ modelValue.length }} of {{ max }}</span>
            </div>
            <p v-if="!modelValue.length" class="mt-2 text-sm text-gray-600">None chosen yet.</p>
            <ul v-else class="mt-2 flex flex-wrap gap-2">
                <li v-for="item in modelValue" :key="item.id" class="inline-flex items-center gap-1 rounded-full border border-gray-200 bg-gray-100 py-1 pl-3 pr-1 text-sm capitalize text-gray-800">
                    {{ item.name }}
                    <button
                        type="button"
                        class="inline-flex h-6 w-6 items-center justify-center rounded-full text-gray-600 hover:bg-gray-300 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                        :aria-label="`Remove ${item.name}`"
                        @click="toggle(item)"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M5.3 5.3a1 1 0 0 1 1.4 0L10 8.6l3.3-3.3a1 1 0 1 1 1.4 1.4L11.4 10l3.3 3.3a1 1 0 0 1-1.4 1.4L10 11.4l-3.3 3.3a1 1 0 0 1-1.4-1.4L8.6 10 5.3 6.7a1 1 0 0 1 0-1.4Z" />
                        </svg>
                    </button>
                </li>
            </ul>
            <p v-if="full" class="mt-2 text-xs text-gray-600">That's the most you can choose. Remove one to pick another.</p>
        </div>
    </div>
</template>
