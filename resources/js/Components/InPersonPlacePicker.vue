<script setup>
import { onBeforeUnmount, onMounted, ref, unref, watch, watchEffect } from 'vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import InPersonNote from '@/Components/InPersonNote.vue';
import useLocation from '@/Composables/useLocation';
import useMap from '@/Composables/useMap';

// SCRUM-443: where an in-person session meets -- a pin on the map and a written landmark (both required). Shared by
// creating, editing and proposing a session; render it only while in-person is chosen (it loads the map when mounted).
const props = defineProps({
    // The map element's id (Google Maps uses it as the map id) -- unique per form.
    mapId: { type: String, required: true },
    errors: { type: Object, default: () => ({}) },
    anonymous: { type: Boolean, default: false },
    // A saved place ({ lat, lng }) to open on, with its pin -- editing a session. Without one, the map opens on the
    // browser's current location.
    initialPlace: { type: Object, default: null },
})

const lat = defineModel('lat', { default: '' })
const lng = defineModel('lng', { default: '' })
const landmark = defineModel('landmark', { default: '' })

const { getCurrentLocation, currentLocation } = useLocation()
const { initMap, initPlaces, createMap, mapDetails, addMarker, map, markerPosition, setMarkerPosition } = useMap()

const mapElement = ref(null)
const landmarkInput = ref(null)

// Read once: the map opens on the place as it was when the picker appeared.
const initial = [props.initialPlace?.lat, props.initialPlace?.lng].every((v) => v !== null && v !== '' && Number.isFinite(parseFloat(v)))
    ? { lat: props.initialPlace.lat, lng: props.initialPlace.lng }
    : null
let initialShown = false

onMounted(() => {
    initMap()
    initSearch()
    if (!initial) getCurrentLocation()
})

watchEffect(() => {
    if (!mapDetails.value.Map || !mapDetails.value.Marker || !mapElement.value) return

    if (!initial) {
        createMap(mapElement.value, currentLocation.value)
        return
    }

    if (initialShown) return
    initialShown = true
    createMap(mapElement.value, initial)
    addMarker(unref(map), initial)
})

watch(() => [markerPosition.value.lat, markerPosition.value.lng], () => {
    lat.value = markerPosition.value.lat ?? ''
    lng.value = markerPosition.value.lng ?? ''

    // A pin moved some other way (map click, drag, current location) makes an offered landmark stale.
    if (suggestedLandmark.value && !samePin(suggestedFor, markerPosition.value)) suggestedLandmark.value = ''
})

// "current location": the browser answers asynchronously, so the pin moves once it has.
let wantCurrent = false

function applyCurrentLocation() {
    const { lat: currentLat, lng: currentLng } = currentLocation.value
    if (!map.value || !currentLat || !currentLng) return false

    map.value.setCenter({ lat: parseFloat(currentLat), lng: parseFloat(currentLng) })
    addMarker(unref(map), currentLocation.value)

    return true
}

watch(() => [currentLocation.value.lat, currentLocation.value.lng], () => {
    if (wantCurrent && applyCurrentLocation()) wantCurrent = false
}, { flush: 'post' })

function useCurrentLocation() {
    getCurrentLocation()
    wantCurrent = !applyCurrentLocation()
}

// SCRUM-444: place search (Google Places autocomplete, biased to Ghana). 'loading' until the library answers, then
// 'ready', or 'unavailable' (no key, the library didn't load, or the Places API isn't enabled on the key) -- the box
// is then hidden and the map and landmark work as before. No alert: search is a convenience.
const searchState = ref('loading')
const searchContainer = ref(null)
// A place chosen while the landmark already had text: offered, not forced.
const suggestedLandmark = ref('')
// The pin the offered landmark belongs to.
let suggestedFor = null
// A place chosen before the map was ready -- its pin is drawn when the map appears.
let pendingPin = null
let searchElement = null
let unmounted = false
// Request errors that mean search can't work with this key (vs. a passing network error worth a retry).
const PERMANENT_ERROR = /ApiNotActivated|ApiTargetBlocked|RefererNotAllowed|InvalidKey|MissingKey|ExpiredKey|ProjectDenied|BillingNotEnabled|PERMISSION_DENIED|REQUEST_DENIED|auth/i
const searchError = ref('')

const GHANA = { north: 11.2, south: 4.7, east: 1.2, west: -3.3 }

async function initSearch() {
    const PlaceAutocompleteElement = await initPlaces()
    if (unmounted) return
    if (!PlaceAutocompleteElement || !searchContainer.value) {
        searchState.value = 'unavailable'
        return
    }

    try {
        searchElement = new PlaceAutocompleteElement({ locationBias: GHANA })
    } catch {
        searchState.value = 'unavailable'
        return
    }

    searchElement.id = `${props.mapId}-search`
    searchElement.setAttribute('aria-labelledby', `${props.mapId}-search-label`)
    searchElement.classList.add('w-full')
    searchElement.style.colorScheme = 'light'

    searchElement.addEventListener('gmp-select', onSelect)
    // Google sends these when a request is denied (e.g. ApiNotActivatedMapError on the first search when the Places
    // API isn't enabled on the key) and gives no detail to tell a passing failure apart, so search is switched off.
    searchElement.addEventListener('gmp-error', searchFailed)
    searchElement.addEventListener('gmp-requesterror', searchFailed)

    searchContainer.value.appendChild(searchElement)
    searchState.value = 'ready'
}

function onSelect(event) {
    choosePlace(event.placePrediction?.toPlace())
}

function removeSearchElement() {
    if (!searchElement) return

    searchElement.removeEventListener('gmp-select', onSelect)
    searchElement.removeEventListener('gmp-error', searchFailed)
    searchElement.removeEventListener('gmp-requesterror', searchFailed)
    searchElement.remove()
    searchElement = null
}

function searchFailed() {
    // Don't strand keyboard focus on an element that's about to go.
    const hadFocus = searchElement && (document.activeElement === searchElement || searchElement.contains(document.activeElement))
    if (hadFocus) landmarkInput.value?.focus()

    searchState.value = 'unavailable'
    searchError.value = ''
    removeSearchElement()
}

async function choosePlace(place) {
    if (!place) return
    searchError.value = ''

    try {
        await place.fetchFields({ fields: ['displayName', 'formattedAddress', 'location'] })
    } catch (error) {
        if (PERMANENT_ERROR.test(`${error?.name ?? ''} ${error?.code ?? ''} ${error?.message ?? ''}`)) searchFailed()
        else searchError.value = "Search didn't work just now. Try again, or pick the place on the map."
        return
    }

    const location = place.location
    if (!location || unmounted) return

    const chosen = { lat: location.lat(), lng: location.lng() }

    if (map.value) showPin(chosen)
    else {
        pendingPin = chosen
        setMarkerPosition(chosen)
    }

    const name = placeLabel(place)
    if (!name) return

    if (!String(landmark.value ?? '').trim()) {
        landmark.value = name
        suggestedLandmark.value = ''
    } else if (landmark.value !== name) {
        suggestedFor = chosen
        suggestedLandmark.value = name
    } else {
        suggestedLandmark.value = ''
    }
}

function showPin(place) {
    map.value.setCenter(place)
    map.value.setZoom(17)
    addMarker(unref(map), place)
}

function samePin(a, b) {
    return !!a && !!b && String(a.lat) === String(b.lat) && String(a.lng) === String(b.lng)
}

// The map appeared after a place was chosen (it opens on the saved pin or the current location first).
watch(map, () => {
    if (!map.value || !pendingPin) return

    const place = pendingPin
    pendingPin = null
    showPin(place)
}, { flush: 'post' })

onBeforeUnmount(() => {
    unmounted = true
    removeSearchElement()
})

// The place's name, or its address when the name is too short for a landmark; at most 255 characters.
function placeLabel(place) {
    const name = [place.displayName, place.formattedAddress]
        .map((v) => String(v ?? '').trim())
        .find((v) => v.length >= 3)

    return name ? name.slice(0, 255) : ''
}

function acceptSuggestedLandmark() {
    landmark.value = suggestedLandmark.value
    suggestedLandmark.value = ''
}

function focusSearch() {
    searchElement?.focus()
}
</script>

<template>
    <div class="w-full flex flex-col">
        <!-- SCRUM-444: find the place by name; the map click and "current location" still work. -->
        <div v-show="searchState !== 'unavailable'" class="mb-2">
            <label
                :id="`${mapId}-search-label`"
                :for="`${mapId}-search`"
                class="block font-medium text-sm text-gray-700"
                @click="focusSearch"
            >Search for the place</label>
            <div ref="searchContainer" class="mt-1 w-full"></div>
            <p v-if="searchState === 'loading'" class="text-xs text-gray-500 mt-1">Loading search…</p>
            <p v-if="searchError" class="text-sm text-red-600 mt-1" role="alert">{{ searchError }}</p>
        </div>
        <p v-if="searchState === 'unavailable'" class="text-sm text-gray-600 mb-2" role="status">
            Search isn't available; pick the place on the map.
        </p>
        <div v-else class="text-gray-600 text-sm mb-2">Or click anywhere on the map to drop the pin.</div>
        <div class="w-full h-[300px] bg-blue-200" ref="mapElement" :id="mapId"></div>
        <div class="flex justify-start items-center flex-col">
            <TextInput
                placeholder="longitude"
                type="number"
                :id="`${mapId}-lng`"
                class="my-2 w-full"
                v-model="lng"
                disabled
                required
            />
            <InputError :message="errors.lng" class="mt-2" />

            <TextInput
                placeholder="latitude"
                type="number"
                :id="`${mapId}-lat`"
                class="my-2 w-full"
                v-model="lat"
                disabled
                required
            />
            <InputError :message="errors.lat" class="mt-2" />

            <div class="flex justify-end w-full">
                <button
                    type="button"
                    @click="useCurrentLocation"
                    :class="[
                        (currentLocation.lat == lat && currentLocation.lng == lng)
                        ? 'bg-blue-700 text-blue-100'
                        : 'text-blue-700 bg-blue-100 hover:bg-blue-700 hover:text-blue-100'
                    ]"
                    class="mt-2 text-sm py-1 px-2 rounded w-fit transition duration-75"
                >current location</button>
            </div>

            <!-- A pin alone doesn't find the room -- the place is described too (required). -->
            <div class="w-full">
                <InputLabel :for="`${mapId}-landmark`" value="Where exactly? (required)" class="mt-2" />
                <TextInput
                    placeholder="For example: Room 3, 2nd floor, opposite the bank"
                    :id="`${mapId}-landmark`"
                    ref="landmarkInput"
                    class="my-2 w-full"
                    v-model="landmark"
                    minlength="3"
                    maxlength="255"
                    required
                    @input="suggestedLandmark = ''"
                />
                <div v-if="suggestedLandmark" class="flex flex-wrap items-center gap-2 text-sm text-gray-700 mb-2" role="status">
                    <span>Use “{{ suggestedLandmark }}” as the description?</span>
                    <button
                        type="button"
                        class="text-blue-700 bg-blue-100 hover:bg-blue-700 hover:text-blue-100 py-1 px-2 rounded transition duration-75"
                        @click="acceptSuggestedLandmark"
                    >Use it</button>
                    <button
                        type="button"
                        class="text-gray-700 bg-gray-100 hover:bg-gray-200 py-1 px-2 rounded transition duration-75"
                        @click="suggestedLandmark = ''"
                    >Keep mine</button>
                </div>
                <InputError :message="errors.landmark" class="mt-2" />
                <InPersonNote :in-person="true" :anonymous="anonymous" :show-adults-only="false" />
            </div>
        </div>
    </div>
</template>
