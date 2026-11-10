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
const page = ref(1)
const hasMore = ref(false)
const activeIndex = ref(-1)

watch(query, () => {
    if (!query.value) {
        results.value = []
        open.value = false
        hasMore.value = false
        activeIndex.value = -1
        return
    }

    debouncedSearch()
})

const debouncedSearch = _.debounce(() => runSearch(true), 400)

// Incrementing token guards against an earlier, slower request's response landing after a
// later one's and clobbering it -- the debounce only rate-limits when requests are fired, not
// the order they resolve in (reviewer-caught).
let searchToken = 0

// `reset` distinguishes a fresh search (replaces results, back to page 1) from "load more"
// (appends the next page to what's already shown) -- both endpoints paginate server-side
// (SCRUM-177), so a common search term can otherwise exceed the first page with no way to
// reach the rest. `activeIndex` is only reset here on `reset`, not on every `results` change --
// appending a page shouldn't discard an in-progress keyboard highlight on an already-visible row
// (reviewer-caught).
async function runSearch(reset) {
    const token = ++searchToken
    if (reset) {
        page.value = 1
        activeIndex.value = -1
    }
    searching.value = true

    await axios.get(route(props.searchRoute, { [props.searchParam]: query.value, page: page.value }))
        .then((res) => {
            if (token !== searchToken) return
            results.value = reset ? (res.data.data ?? []) : [...results.value, ...(res.data.data ?? [])]
            hasMore.value = !!res.data.links?.next
            open.value = true
        })
        .catch(() => {
            if (token !== searchToken) return
            if (reset) {
                results.value = []
                hasMore.value = false
                open.value = false
            } else {
                // A load-more failure shouldn't hide the already-fetched, still-good first page
                // (reviewer-caught) -- roll the page back so a retry re-fetches the page that
                // failed instead of silently skipping it.
                page.value -= 1
            }
        })
        .finally(() => {
            if (token === searchToken) searching.value = false
        })
}

// Guards against two rapid load-more clicks firing overlapping requests -- without this, a
// second click before the first resolves increments `page` again and (via the searchToken
// guard above) discards the first response entirely once it lands, silently skipping a page of
// results with no way to recover it (reviewer-caught, confirmed via trace).
function loadMore() {
    if (searching.value) return
    page.value += 1
    runSearch(false)
}

function select(item) {
    selected.value = item
    query.value = ''
    results.value = []
    hasMore.value = false
    open.value = false
    activeIndex.value = -1
}

function clearSelection() {
    selected.value = null
    query.value = ''
    results.value = []
    hasMore.value = false
    activeIndex.value = -1
}

// Arrow-key/Enter selection for keyboard-only use -- result rows are otherwise mouse-only
// (reviewer-caught). Enter only acts when a row is actively highlighted via the arrow keys,
// so plain Enter in the search box (no row highlighted yet) still behaves like a normal text
// field inside the surrounding <form>.
function onKeydown(event) {
    if (event.key === 'Escape') {
        open.value = false
        return
    }

    if (!open.value || !results.value.length) return

    if (event.key === 'ArrowDown') {
        event.preventDefault()
        activeIndex.value = Math.min(activeIndex.value + 1, results.value.length - 1)
    } else if (event.key === 'ArrowUp') {
        event.preventDefault()
        activeIndex.value = Math.max(activeIndex.value - 1, 0)
    } else if (event.key === 'Enter' && activeIndex.value >= 0) {
        event.preventDefault()
        select(results.value[activeIndex.value])
    }
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
                role="combobox"
                aria-haspopup="listbox"
                :aria-expanded="open"
                @focus="() => { if (results.length) open = true }"
                @keydown="onKeydown"
            />

            <div v-if="open" role="listbox" class="absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded-md shadow-lg max-h-56 overflow-y-auto">
                <div v-if="searching && !results.length" class="px-3 py-2 text-sm text-gray-500">searching...</div>
                <template v-else-if="results.length">
                    <div
                        v-for="(item, idx) in results"
                        :key="item.id"
                        role="option"
                        :aria-selected="idx === activeIndex"
                        tabindex="-1"
                        @click="() => select(item)"
                        @mouseenter="() => activeIndex = idx"
                        class="px-3 py-2 text-sm text-gray-900 cursor-pointer"
                        :class="idx === activeIndex ? 'bg-indigo-50' : ''"
                    >
                        {{ getLabel(item) }}
                        <span v-if="getSubLabel(item)" class="text-gray-500">({{ getSubLabel(item) }})</span>
                    </div>
                    <div
                        v-if="hasMore"
                        @click="loadMore"
                        class="px-3 py-2 text-sm text-center"
                        :class="searching ? 'text-gray-400 pointer-events-none' : 'text-indigo-600 hover:bg-indigo-50 cursor-pointer'"
                    >
                        {{ searching ? 'loading...' : 'load more' }}
                    </div>
                </template>
                <div v-else class="px-3 py-2 text-sm text-gray-500">no results</div>
            </div>
        </template>
    </div>
</template>
