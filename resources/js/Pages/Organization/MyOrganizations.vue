<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import Alert from '@/Components/Alert.vue';
import useAlert from '@/Composables/useAlert';
import MyAffiliationsSection from './Partials/MyAffiliationsSection.vue';
import MyOrganizationRequestQueueSection from './Partials/MyOrganizationRequestQueueSection.vue';
import BrowseProviderOrganizationsSection from './Partials/BrowseProviderOrganizationsSection.vue';
import BrowseConsumerOrganizationsSection from './Partials/BrowseConsumerOrganizationsSection.vue';
import MyMembershipsSection from './Partials/MyMembershipsSection.vue';
import MyAdministeredOrganizationsSection from './Partials/MyAdministeredOrganizationsSection.vue';

defineProps({
    // null for a user with no Counsellor account (SCRUM-168) -- the counsellor-only sections
    // are omitted entirely for them, rather than shown empty.
    affiliations: {
        type: Object,
        default: null,
    },
    requestQueue: {
        type: Object,
        default: null,
    },
    memberships: {
        type: Object,
        required: true,
    },
    // Always present, independent of counsellor/member status (SCRUM-173) -- any user can
    // administer an org.
    administeredOrganizations: {
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
                <MyAdministeredOrganizationsSection :initial-administered-organizations="administeredOrganizations" />
            </div>

            <div v-if="affiliations" class="w-full sm:w-[90%] md:w-[85%] lg:w-[75%] mx-auto sm:px-6 lg:px-8 mt-8">
                <MyAffiliationsSection ref="affiliationsSection" :initial-affiliations="affiliations" @alert="onChildAlert" />
            </div>

            <div v-if="requestQueue" class="w-full sm:w-[90%] md:w-[85%] lg:w-[75%] mx-auto sm:px-6 lg:px-8 mt-8">
                <MyOrganizationRequestQueueSection
                    ref="requestQueueSection"
                    :initial-requests="requestQueue"
                    @alert="onChildAlert"
                    @compensation-accepted="() => $refs.affiliationsSection?.reload()"
                />
            </div>

            <div v-if="affiliations" class="w-full sm:w-[90%] md:w-[85%] lg:w-[75%] mx-auto sm:px-6 lg:px-8 mt-8">
                <BrowseProviderOrganizationsSection
                    @alert="onChildAlert"
                    @applied="() => $refs.requestQueueSection?.reload()"
                />
            </div>

            <div class="w-full sm:w-[90%] md:w-[85%] lg:w-[75%] mx-auto sm:px-6 lg:px-8 mt-8">
                <MyMembershipsSection :initial-memberships="memberships" @alert="onChildAlert" />
            </div>

            <!-- Always shown (SCRUM-169/TT-6.5c2): any user can self-apply as a plain member,
                 independent of having a Counsellor account, unlike the provider-side section above. -->
            <div class="w-full sm:w-[90%] md:w-[85%] lg:w-[75%] mx-auto sm:px-6 lg:px-8 mt-8">
                <BrowseConsumerOrganizationsSection @alert="onChildAlert" />
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
