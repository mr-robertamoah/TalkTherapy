<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Avatar from '@/Components/Avatar.vue';
import Alert from '@/Components/Alert.vue';
import useAlert from '@/Composables/useAlert';
import UpdateOrganizationForm from './Partials/UpdateOrganizationForm.vue';
import CounsellorsSection from './Partials/CounsellorsSection.vue';
import MembersSection from './Partials/MembersSection.vue';
import RequestQueueSection from './Partials/RequestQueueSection.vue';
import AdminsSection from './Partials/AdminsSection.vue';

const props = defineProps({
    organization: {
        type: Object,
        required: true,
    },
    counsellors: {
        type: Object,
        default: null,
    },
    members: {
        type: Object,
        default: null,
    },
    requestQueue: {
        type: Object,
        required: true,
    },
    admins: {
        type: Array,
        required: true,
    },
})

const { alertData, clearAlertData, setAlertData, setSuccessAlertData } = useAlert()

// A computed, not a one-time ref copy -- Inertia's PATCH does update props.organization
// reactively, but a `ref({ ...props.organization })` snapshot taken once at setup would never
// pick that up, leaving the dashboard showing stale data after a save until a hard reload.
const organization = computed(() => props.organization)
const showEditModal = ref(false)

function onOrganizationUpdated() {
    setSuccessAlertData({ message: 'Organization profile updated.' })
}

// The three section Partials each own an independent useAlert() instance (it's deliberately
// non-singleton) -- without forwarding their alerts up to this single rendered <Alert>, those
// calls silently update state nothing displays. Mirrors RequestBadge.vue's existing @alert ->
// parent-owned Alert pattern used elsewhere in this codebase.
function onChildAlert(alert) {
    setAlertData(alert)
}
</script>

<template>
    <Head title="Organization Dashboard" />

    <AuthenticatedLayout>
        <div class="pt-4 pb-12">
            <div class="w-full sm:w-[90%] md:w-[85%] lg:w-[75%] mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl border border-gray-100 sm:rounded-xl relative">
                    <div class="absolute top-4 right-4 sm:right-8">
                        <PrimaryButton @click="() => showEditModal = true">edit profile</PrimaryButton>
                    </div>
                    <div class="p-8">
                        <div class="flex items-center gap-4 mb-2">
                            <Avatar :size="64" :src="organization.logo ?? ''" :avatar-text="'logo'" :alt="`${organization.name} logo`" />
                            <div class="text-2xl sm:text-3xl font-bold text-gray-900">{{ organization.name }}</div>
                        </div>
                        <div class="w-16 h-1 bg-blue-600 mb-4"></div>

                        <div class="flex flex-wrap gap-2 mb-4">
                            <span class="text-xs font-semibold px-2 py-1 rounded-full" :class="organization.isVerified ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'">
                                {{ organization.isVerified ? 'Verified' : 'Verification pending' }}
                            </span>
                            <span v-if="organization.isProvider" class="text-xs font-semibold px-2 py-1 rounded-full bg-blue-100 text-blue-700">Provider</span>
                            <span v-if="organization.isConsumer" class="text-xs font-semibold px-2 py-1 rounded-full bg-purple-100 text-purple-700">Consumer</span>
                        </div>

                        <p v-if="organization.description" class="text-gray-700">{{ organization.description }}</p>
                        <p v-else class="text-gray-500 italic">No description added yet.</p>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-6 text-sm text-gray-600">
                            <div v-if="organization.legalName"><span class="font-semibold">Legal name:</span> {{ organization.legalName }}</div>
                            <div v-if="organization.registrationNumber"><span class="font-semibold">Registration #:</span> {{ organization.registrationNumber }}</div>
                            <div v-if="organization.email"><span class="font-semibold">Email:</span> {{ organization.email }}</div>
                            <div v-if="organization.phone"><span class="font-semibold">Phone:</span> {{ organization.phone }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="organization.isProvider" class="w-full sm:w-[90%] md:w-[85%] lg:w-[75%] mx-auto sm:px-6 lg:px-8 mt-8">
                <CounsellorsSection
                    :organization-id="organization.id"
                    :initial-counsellors="counsellors"
                    @alert="onChildAlert"
                    @invited="() => $refs.requestQueueSection?.reload()"
                />
            </div>

            <div v-if="organization.isConsumer" class="w-full sm:w-[90%] md:w-[85%] lg:w-[75%] mx-auto sm:px-6 lg:px-8 mt-8">
                <MembersSection
                    :organization-id="organization.id"
                    :initial-members="members"
                    @alert="onChildAlert"
                    @invited="() => $refs.requestQueueSection?.reload()"
                />
            </div>

            <div class="w-full sm:w-[90%] md:w-[85%] lg:w-[75%] mx-auto sm:px-6 lg:px-8 mt-8">
                <RequestQueueSection
                    ref="requestQueueSection"
                    :organization-id="organization.id"
                    :initial-requests="requestQueue"
                    @alert="onChildAlert"
                />
            </div>

            <div class="w-full sm:w-[90%] md:w-[85%] lg:w-[75%] mx-auto sm:px-6 lg:px-8 mt-8">
                <AdminsSection
                    :organization-id="organization.id"
                    :initial-admins="admins"
                    @alert="onChildAlert"
                />
            </div>
        </div>
    </AuthenticatedLayout>

    <UpdateOrganizationForm
        :organization="organization"
        :show="showEditModal"
        @close-modal="() => showEditModal = false"
        @updated="onOrganizationUpdated"
    />

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>
