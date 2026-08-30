<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import Alert from '@/Components/Alert.vue';
import useAlert from '@/Composables/useAlert';
import MyAffiliationsSection from './Partials/MyAffiliationsSection.vue';
import MyOrganizationRequestQueueSection from './Partials/MyOrganizationRequestQueueSection.vue';
import BrowseProviderOrganizationsSection from './Partials/BrowseProviderOrganizationsSection.vue';

defineProps({
    affiliations: {
        type: Object,
        required: true,
    },
    requestQueue: {
        type: Object,
        required: true,
    },
})

const { alertData, clearAlertData, setAlertData } = useAlert()

// The three section Partials each own an independent useAlert() instance (it's deliberately
// non-singleton) -- without forwarding their alerts up to this single rendered <Alert>, those
// calls silently update state nothing displays. Mirrors Organization/Show.vue's own pattern.
function onChildAlert(alert) {
    setAlertData(alert)
}
</script>

<template>
    <Head title="My Organizations" />

    <AuthenticatedLayout>
        <div class="pt-4 pb-12">
            <div class="w-full sm:w-[90%] md:w-[85%] lg:w-[75%] mx-auto sm:px-6 lg:px-8">
                <div class="text-2xl sm:text-3xl font-bold text-gray-900 mb-2">My Organizations</div>
                <div class="w-16 h-1 bg-blue-600 mb-6"></div>
            </div>

            <div class="w-full sm:w-[90%] md:w-[85%] lg:w-[75%] mx-auto sm:px-6 lg:px-8">
                <MyAffiliationsSection ref="affiliationsSection" :initial-affiliations="affiliations" @alert="onChildAlert" />
            </div>

            <div class="w-full sm:w-[90%] md:w-[85%] lg:w-[75%] mx-auto sm:px-6 lg:px-8 mt-8">
                <MyOrganizationRequestQueueSection
                    ref="requestQueueSection"
                    :initial-requests="requestQueue"
                    @alert="onChildAlert"
                    @compensation-accepted="() => $refs.affiliationsSection?.reload()"
                />
            </div>

            <div class="w-full sm:w-[90%] md:w-[85%] lg:w-[75%] mx-auto sm:px-6 lg:px-8 mt-8">
                <BrowseProviderOrganizationsSection
                    @alert="onChildAlert"
                    @applied="() => $refs.requestQueueSection?.reload()"
                />
            </div>
        </div>
    </AuthenticatedLayout>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>
