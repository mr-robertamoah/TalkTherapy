<template>
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class="p-4">

            <div class="w-full mt-2 mb-4">
                <div
                    class="w-fit capitalize mx-auto text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-500 bg-clip-text text-transparent mb-2"
                >{{ session.name }}</div>
                <hr>
            </div>

            <div class="overflow-hidden overflow-y-auto h-[70vh] w-[95%] mx-auto px-4 py-6 space-y-6">
                <!-- About Section -->
                <div v-if="session.about" class="bg-gradient-to-br from-gray-50 to-gray-100 p-6 rounded-xl shadow-md border border-gray-200">
                    <div class="flex items-center mb-4">
                        <div class="w-2 h-8 bg-gray-600 rounded-full mr-3"></div>
                        <h3 class="text-xl font-semibold text-gray-800 capitalize">About</h3>
                    </div>
                    <p class="text-gray-700 leading-relaxed text-justify">{{ session.about }}</p>
                </div>

                <!-- Details Section -->
                <div class="bg-gradient-to-br from-stone-50 to-stone-100 p-6 rounded-xl shadow-md border border-stone-200">
                    <div class="flex items-center mb-4">
                        <div class="w-2 h-8 bg-stone-600 rounded-full mr-3"></div>
                        <h3 class="text-xl font-semibold text-gray-800 capitalize">Details</h3>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-white p-4 rounded-lg shadow-sm">
                            <span class="text-sm font-medium text-gray-500">Type</span>
                            <p class="text-lg font-semibold text-gray-800 capitalize">{{ session.type }}</p>
                        </div>
                        <div class="bg-white p-4 rounded-lg shadow-sm">
                            <span class="text-sm font-medium text-gray-500">Payment Type</span>
                            <p class="text-lg font-semibold text-gray-800 capitalize">{{ session.paymentType }}</p>
                        </div>
                        <div class="bg-white p-4 rounded-lg shadow-sm md:col-span-2">
                            <span class="text-sm font-medium text-gray-500">Status</span>
                            <p class="text-lg font-semibold text-gray-800 capitalize">{{ session.status }}</p>
                        </div>
                    </div>
                </div>

                <!-- SCRUM-446: an in-person session's attendance -- the register for its counsellor, the viewer's own mark
                     for a client or member. -->
                <SessionAttendanceSection v-if="session.type === 'IN_PERSON'" :session="session" @updated="(updated) => emits('updated', updated)" />

                <!-- Cases Section -->
                <div class="bg-gradient-to-br from-slate-50 to-slate-100 p-6 rounded-xl shadow-md border border-slate-200">
                    <div class="flex items-center mb-4">
                        <div class="w-2 h-8 bg-slate-600 rounded-full mr-3"></div>
                        <h3 class="text-xl font-semibold text-gray-800 capitalize">Cases</h3>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <template v-if="session.cases?.length">
                            <div v-for="c in session.cases" :key="c.id" 
                                 class="bg-white px-4 py-2 rounded-lg shadow-sm border border-slate-200 hover:shadow-md transition-shadow">
                                <span class="text-gray-700 font-medium capitalize">{{ c.name }}</span>
                                <p v-if="c.about" class="text-sm text-gray-500 mt-1">{{ c.about }}</p>
                            </div>
                        </template>
                        <div v-else class="w-full text-center py-8 text-gray-500">No cases assigned</div>
                    </div>
                </div>

                <!-- Topics Section -->
                <div class="bg-gradient-to-br from-zinc-50 to-zinc-100 p-6 rounded-xl shadow-md border border-zinc-200">
                    <div class="flex items-center mb-4">
                        <div class="w-2 h-8 bg-zinc-600 rounded-full mr-3"></div>
                        <h3 class="text-xl font-semibold text-gray-800 capitalize">Topics</h3>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <template v-if="session.topics?.length">
                            <div v-for="c in session.topics" :key="c.id" 
                                 class="bg-white px-4 py-2 rounded-lg shadow-sm border border-zinc-200 hover:shadow-md transition-shadow">
                                <span class="text-gray-700 font-medium capitalize">{{ c.name }}</span>
                                <p v-if="c.description" class="text-sm text-gray-500 mt-1">{{ c.description }}</p>
                            </div>
                        </template>
                        <div v-else class="w-full text-center py-8 text-gray-500">No topics assigned</div>
                    </div>
                </div>

                <!-- Location Section -->
                <!-- SCRUM-447 (review): the landmark stays after the pin is cleared, so the section shows for either. -->
                <div v-if="(session.lng && session.lat) || session.landmark" class="bg-gradient-to-br from-neutral-50 to-neutral-100 p-6 rounded-xl shadow-md border border-neutral-200">
                    <div class="flex items-center mb-4">
                        <div class="w-2 h-8 bg-neutral-600 rounded-full mr-3"></div>
                        <h3 class="text-xl font-semibold text-gray-800 capitalize">Location</h3>
                    </div>
                    <div v-if="session.lng && session.lat" :id="`session_${session.id}`" class="w-full h-[200px] bg-white rounded-lg shadow-sm mb-3"></div>
                    <p v-if="session.landmark" class="text-sm text-gray-600 font-medium">Landmark: {{ session.landmark }}</p>
                    <!-- SCRUM-444: directions, only from a pin the server sent (it hides it from people who can't see it). -->
                    <a
                        v-if="directionsUrl"
                        :href="directionsUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-block mt-3 text-sm font-medium text-blue-700 bg-blue-100 hover:bg-blue-700 hover:text-blue-100 py-1 px-2 rounded transition duration-75 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >Open in Google Maps<span class="sr-only"> (opens in a new tab)</span></a>
                </div>

                <!-- Schedule Section -->
                <div class="bg-gradient-to-br from-gray-50 to-gray-100 p-6 rounded-xl shadow-md border border-gray-200">
                    <div class="flex items-center mb-4">
                        <div class="w-2 h-8 bg-gray-600 rounded-full mr-3"></div>
                        <h3 class="text-xl font-semibold text-gray-800 capitalize">Schedule</h3>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-white p-4 rounded-lg shadow-sm">
                            <span class="text-sm font-medium text-gray-500">Start Time</span>
                            <p class="text-gray-700 font-medium mt-1">
                                {{ session.status == 'PENDING' ? 'Scheduled to start' : (session.status == 'FAILED' ? 'Was to start' : 'Started') }}
                            </p>
                            <p class="text-lg font-semibold text-gray-800">{{ (new Date(session.startTime)).toLocaleString() }}</p>
                        </div>
                        <div class="bg-white p-4 rounded-lg shadow-sm">
                            <span class="text-sm font-medium text-gray-500">End Time</span>
                            <p class="text-gray-700 font-medium mt-1">
                                {{ ['PENDING', 'IN_SESSION_CONFIRMATION', 'IN_SESSION'].includes(session.status) ? 'Scheduled to end' : (['FAILED', 'ABANDONED'].includes(session.status) ? 'Was to end' : 'Ended') }}
                            </p>
                            <p class="text-lg font-semibold text-gray-800">{{ (new Date(session.endTime)).toLocaleString() }}</p>
                        </div>
                    </div>
                    <div v-if="session.createdAt" class="mt-4 pt-4 border-t border-gray-200">
                        <span class="text-sm text-gray-500">Created {{ toDiffForHumans(session.createdAt) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import { computed } from 'vue';
import useLocalDateTimed from '@/Composables/useLocalDateTime';
import useLocation from '@/Composables/useLocation';
import useMap from '@/Composables/useMap';
import Modal from './Modal.vue';
import SessionAttendanceSection from './SessionAttendanceSection.vue';
import NameAndValue from './NameAndValue.vue';
import PreferenceItem from './PreferenceItem.vue';

const { addMarker, createMap, setMarkerPosition, markerPosition } = useMap();
const { currentLocation, getCurrentLocation } = useLocation();
const { toDiffForHumans } = useLocalDateTimed()

const emits = defineEmits(['close', 'updated'])

const props = defineProps({
    show: {
        default: false,
        type: Boolean
    },
    session: {
        default: null
    }
})

// SCRUM-444: no pin (none set, or cleared after 30 days) -- no link.
const directionsUrl = computed(() => {
    const lat = parseFloat(props.session?.lat)
    const lng = parseFloat(props.session?.lng)

    if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) return null

    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${lat},${lng}`)}`
})

function closeModal() {
    emits('close')
}
</script>