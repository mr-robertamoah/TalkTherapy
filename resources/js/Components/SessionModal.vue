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

            <div class="overflow-hidden overflow-y-auto h-[70vh] w-[90%] mx-auto md:w-[70%] px-2 py-4">
                <div v-if="session.about" class="p-4 rounded bg-gray-200 shadow-sm min-h-[100px]">
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">about</div>
                    <div class="mt-2 text-sm text-gray-600 text-justify">{{ session.about }}</div>
                </div>

                <div class="p-4 rounded bg-gray-200 my-4 shadow-sm min-h-[100px]">
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">details</div>
                    <NameAndValue :name="'Type'" :value="session.type"/>
                    <NameAndValue :name="'Payment Type'" :value="session.paymentType"/>
                    <NameAndValue :name="'Status'" :value="session.status"/>
                </div>

                <div class="p-4 rounded bg-gray-200 my-4 shadow-sm min-h-[100px]">
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">cases</div>
                    
                    <div class="p-2 flex justify-start items-center overflow-hidden overflow-x-auto my-2">
                                
                        <template v-if="session.cases?.length">
                            <PreferenceItem
                                v-for="c in session.cases"
                                :key="c.id"
                                :item="c"
                                :show-actions="false"
                            />
                        </template>
                        <div v-else class="w-full text-center text-sm text-gray-600">no cases</div>
                    </div>
                </div>

                <div class="p-4 rounded bg-gray-200 my-4 shadow-sm min-h-[100px]">
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">topics</div>
                    
                    <div class="p-2 flex justify-start items-center overflow-hidden overflow-x-auto my-2">
                                
                        <template v-if="session.topics?.length">
                            <PreferenceItem
                                v-for="c in session.topics"
                                :key="c.id"
                                :item="c"
                                :show-actions="false"
                            />
                        </template>
                        <div v-else class="w-full text-center text-sm text-gray-600">no topics</div>
                    </div>
                </div>

                <div class="p-4 rounded bg-gray-200 my-4 shadow-sm min-h-[100px]" v-if="session.lng && session.lat">
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">location</div>
                    <div :id="`session_${session.id}`" class="w-full h-[200px]">

                    </div>
                    <div class="text-end text-gray-600 text-sm my-2" v-if="session.landmark">landmark: {{ session.landmark }}</div>
                </div>

                <div class="p-4 rounded bg-gray-200 my-4 shadow-sm min-h-[100px]">
                    <div class="w-full text-justify capitalize mt-4 mb-1 text-lg font-medium text-gray-900">dates</div>
                    <div>
                        <div class="text-gray-600 text-sm tracking-wide flex justify-center items-center flex-col space-y-3 py-2">
                            <div class="">{{ 
                                session.status == 'PENDING' 
                                    ? 'Is to start on '
                                    : (session.status == 'FAILED' ? 'Was to start on ' : 'Started on')
                            }} <span class="text-gray-700 font-bold">{{ (new Date(session.startTime)).toGMTString() }}</span></div>
                            <div class="">{{ 
                                ['PENDING', 'IN_SESSION_CONFIRMATION', 'IN_SESSION'].includes(session.status)
                                    ? 'Is to end on '
                                    : (['FAILED', 'ABANDONED'].includes(session.status) ? 'Was to end on ' : 'Ended on')
                            }} <span class="text-gray-700 font-bold">{{ (new Date(session.endTime)).toGMTString() }}</span></div>
                        </div>
                        <div class="text-end text-gray-600 text-sm my-2">created {{ toDiffForHumans(session.createdAt) }}</div>
                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import useLocalDateTimed from '@/Composables/useLocalDateTime';
import useLocation from '@/Composables/useLocation';
import useMap from '@/Composables/useMap';
import Modal from './Modal.vue';
import NameAndValue from './NameAndValue.vue';
import PreferenceItem from './PreferenceItem.vue';

const { addMarker, createMap, setMarkerPosition, markerPosition } = useMap();
const { currentLocation, getCurrentLocation } = useLocation();
const { toDiffForHumans } = useLocalDateTimed()

const emits = defineEmits(['close'])

const props = defineProps({
    show: {
        default: false,
        type: Boolean
    },
    session: {
        default: null
    }
})

function closeModal() {
    emits('close')
}
</script>