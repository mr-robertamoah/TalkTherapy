<script setup>
import { onMounted, ref, unref, watch, watchEffect } from 'vue';
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
const { initMap, createMap, mapDetails, addMarker, map, markerPosition } = useMap()

const mapElement = ref(null)

// Read once: the map opens on the place as it was when the picker appeared.
const initial = [props.initialPlace?.lat, props.initialPlace?.lng].every((v) => v !== null && v !== '' && Number.isFinite(parseFloat(v)))
    ? { lat: props.initialPlace.lat, lng: props.initialPlace.lng }
    : null
let initialShown = false

onMounted(() => {
    initMap()
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

watch(() => markerPosition.value.lat || markerPosition.value.lng, () => {
    lat.value = markerPosition.value.lat ?? ''
    lng.value = markerPosition.value.lng ?? ''
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
</script>

<template>
    <div class="w-full flex flex-col">
        <div class="text-gray-600 text-sm mb-2 text-center">Pick the place on the map by clicking anywhere on it.</div>
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
                    class="my-2 w-full"
                    v-model="landmark"
                    minlength="3"
                    maxlength="255"
                    required
                />
                <InputError :message="errors.landmark" class="mt-2" />
                <InPersonNote :in-person="true" :anonymous="anonymous" :show-adults-only="false" />
            </div>
        </div>
    </div>
</template>
