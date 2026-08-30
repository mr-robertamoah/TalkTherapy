<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import TextInput from '@/Components/TextInput.vue';

// Generic search-and-select control backing the org admin dashboard's invite/add flows
// (CounsellorsSection.vue, MembersSection.vue, AdminsSection.vue -- SCRUM-172), replacing raw
// numeric-id text inputs. Reuses the same already-live search endpoints AddGuardianModal.vue/
// DiscussionModal.vue/GroupTherapyFormModal.vue already search against (api.users/api.counsellors)
// -- just as a lighter, list-styled dropdown matching this dashboard's own table-based look
// rather than those flows' card-based UserComponent/CounsellorComponent display.
const props = defineProps({
    // Declared explicitly (rather than left to fall through) so it lands on the inner TextInput
    // -- and from there, since TextInput doesn't declare its own `id` prop, on the actual
    // `<input>` -- instead of on this component's own wrapper `<div>`, which would silently break
    // any `<InputLabel for="...">` pairing at every call site (reviewer-caught).
    id: {
        type: String,
        default: undefined,
    },
    searchRoute: {
        type: String,
        required: true,
    },
    searchParam: {
        type: String,
        default: 'like',
    },
    placeholder: {
        type: String,
        default: 'search',
    },
    getLabel: {
        type: Function,
        default: (item) => item.fullName ?? item.name ?? item.username,
    },
    getSubLabel: {
        type: Function,
        default: (item) => item.username,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
})

const selected = defineModel({ default: null })

const root = ref(null)
const query = ref('')
const results = ref([])
const searching = ref(false)
const open = ref(false)

watch(query, () => {
    if (!query.value) {
        results.value = []
        open.value = false
        return
    }

    debouncedSearch()
})

const debouncedSearch = _.debounce(search, 400)

// Incrementing token guards against an earlier, slower request's response landing after a
// later one's and clobbering it -- the debounce only rate-limits when requests are fired, not
// the order they resolve in (reviewer-caught).
let searchToken = 0

async function search() {
    const token = ++searchToken
    searching.value = true

    await axios.get(route(props.searchRoute, { [props.searchParam]: query.value }))
        .then((res) => {
            if (token !== searchToken) return
            results.value = res.data.data ?? []
            open.value = true
        })
        .catch(() => {
            if (token !== searchToken) return
            results.value = []
            open.value = false
        })
        .finally(() => {
            if (token === searchToken) searching.value = false
        })
}

function select(item) {
    selected.value = item
    query.value = ''
    results.value = []
    open.value = false
}

function clearSelection() {
    selected.value = null
    query.value = ''
    results.value = []
}

// Closes the results dropdown on an outside click -- there's no cancel/submit interaction
// inside the dropdown itself that should keep it open, unlike the input which reopens it
// on typing.
function onDocumentClick(event) {
    if (!open.value) return
    if (root.value && !root.value.contains(event.target)) open.value = false
}

onMounted(() => document.addEventListener('click', onDocumentClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick))
</script>

<template>
    <div ref="root" class="relative">
        <div v-if="selected" class="flex items-center justify-between border border-gray-300 rounded-md shadow-sm px-3 py-2 bg-gray-50">
            <div class="text-sm text-gray-900">
                {{ getLabel(selected) }}
                <span v-if="getSubLabel(selected)" class="text-gray-500">({{ getSubLabel(selected) }})</span>
            </div>
            <button type="button" @click="clearSelection" class="text-xs text-indigo-600 hover:underline" :disabled="disabled">change</button>
        </div>

        <template v-else>
            <TextInput
                :id="id"
                v-model="query"
                type="text"
                class="block w-full"
                :placeholder="placeholder"
                :disabled="disabled"
                @focus="() => { if (results.length) open = true }"
            />

            <div v-if="open" class="absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded-md shadow-lg max-h-56 overflow-y-auto">
                <div v-if="searching" class="px-3 py-2 text-sm text-gray-500">searching...</div>
                <template v-else-if="results.length">
                    <div
                        v-for="item in results"
                        :key="item.id"
                        @click="() => select(item)"
                        class="px-3 py-2 text-sm text-gray-900 hover:bg-indigo-50 cursor-pointer"
                    >
                        {{ getLabel(item) }}
                        <span v-if="getSubLabel(item)" class="text-gray-500">({{ getSubLabel(item) }})</span>
                    </div>
                </template>
                <div v-else class="px-3 py-2 text-sm text-gray-500">no results</div>
            </div>
        </template>
    </div>
</template>
