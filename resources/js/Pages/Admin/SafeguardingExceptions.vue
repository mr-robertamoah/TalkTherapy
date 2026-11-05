<script setup>
import { ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import Alert from '@/Components/Alert.vue'
import Pagination from '@/Components/Pagination.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import SafeguardingExceptionTermsFields from '@/Components/SafeguardingExceptionTermsFields.vue'
import useAlert from '@/Composables/useAlert'

// SCRUM-381: minors whose therapy is paused because they have no guardian, counsellors' requests for
// a safeguarding exception, and every exception granted (the audit trail). Every administrator sees
// this page; only the super administrator grants, renews or ends an exception.

defineProps({
    exceptions: { type: Object, required: true },
    // SCRUM-384: paged ({ data, meta }, `pausedPage`), like the exceptions (`page`).
    pausedMinors: { type: Object, required: true },
    // SCRUM-384: counsellors' requests still waiting although the minor is no longer paused.
    noLongerNeeded: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    safeguardingExceptionMaxDays: { type: Number, required: true },
})

const { alertData, clearAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()

// { kind: 'grant' | 'renew' | 'end', wardId, exceptionId, requestId }
const open = ref(null)
const grantForm = useForm({ reason: '', endsAt: '' })
const endForm = useForm({ reason: '' })
const responding = ref(false)

const statusClasses = {
    active: 'bg-green-100 text-green-800',
    expired: 'bg-gray-100 text-gray-700',
    revoked: 'bg-red-100 text-red-800',
    renewed: 'bg-blue-100 text-blue-800',
}

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : '--'
}

// useForm makes the data of a successful submission its new defaults, so reset() alone would bring
// back the last reason sent: set empty defaults first.
function start(kind, target = {}, reason = '') {
    grantForm.defaults({ reason: '', endsAt: '' }).reset()
    endForm.defaults({ reason: '' }).reset()
    grantForm.clearErrors()
    endForm.clearErrors()
    grantForm.reason = reason
    open.value = { kind, ...target }
}

function isOpen(kind, key, value) {
    return open.value?.kind === kind && open.value?.[key] === value
}

function options(message) {
    return {
        preserveScroll: true,
        onSuccess: () => {
            open.value = null
            setSuccessAlertData({ message })
        },
        onError: (errors) => setFailedAlertData({ message: errors.alert ?? Object.values(errors)[0] ?? 'Something went wrong.' }),
    }
}

function grant(wardId, message) {
    grantForm.post(route('admin.safeguarding_exceptions.grant', { ward: wardId }), options(message))
}

function end(exceptionId) {
    endForm.post(route('admin.safeguarding_exceptions.end', { exception: exceptionId }), options('Safeguarding exception ended.'))
}

// A counsellor's request is answered through the requests endpoint, so it is linked to the exception.
async function respond(request, response) {
    responding.value = true
    const payload = response === 'accepted' ? { response, reason: grantForm.reason, endsAt: grantForm.endsAt } : { response }

    await axios.post(route('requests.respond', { requestId: request.id }), payload)
        .then(() => {
            open.value = null
            setSuccessAlertData({ message: response === 'accepted' ? 'Safeguarding exception granted.' : 'Request rejected.' })
            router.reload({ preserveScroll: true })
        })
        .catch((err) => setFailedAlertData({ message: err.response?.data?.error ?? 'Something unfortunate happened. Please try again shortly.' }))

    responding.value = false
}

function goToPage(url) {
    if (url) router.get(url, {}, { preserveScroll: true })
}
</script>

<template>
    <Head title="Safeguarding exceptions" />

    <AuthenticatedLayout>
        <div class="my-8 w-full sm:w-[90%] md:w-[75%] mx-auto space-y-8 px-4 sm:px-0">
            <div>
                <div class="text-2xl font-bold text-gray-900">Safeguarding exceptions</div>
                <div class="w-16 h-1 bg-blue-600 mt-2"></div>
                <div class="mt-2 text-sm text-gray-600">
                    A minor whose account has no guardian has their therapy paused. An exception lets them carry on for up
                    to {{ safeguardingExceptionMaxDays }} days (video calls still need a guardian's consent). The minor and their counsellors are told the
                    end date, never the reason.
                    <template v-if="!canManage"> Only the super administrator can grant, renew or end one.</template>
                </div>
            </div>

            <section class="bg-white shadow-sm border border-gray-200 sm:rounded-lg p-6 space-y-3">
                <div class="text-lg font-semibold text-gray-900">Paused now</div>
                <div v-if="pausedMinors.data.length === 0" class="text-sm text-gray-500 italic">Nobody with an open therapy is paused for a missing guardian.</div>

                <div v-for="minor in pausedMinors.data" :key="minor.key" class="border border-gray-200 rounded-md p-4 text-sm space-y-3">
                    <div v-if="minor.involved" class="text-gray-600 italic">
                        You're involved with this client (you counsel them, or asked for this), so you can't see or decide it here.
                    </div>
                    <template v-else>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-gray-900">{{ minor.name }}</span>
                            <span v-if="minor.username" class="text-gray-500">@{{ minor.username }}</span>
                            <span class="text-gray-500">· {{ minor.openTherapies }} therapy, {{ minor.groupMemberships }} group</span>
                            <span v-if="minor.noDob" class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">no date of birth</span>
                        </div>

                        <div v-for="request in minor.requests" :key="request.id" class="bg-gray-50 border border-gray-200 rounded-md p-3 space-y-2">
                            <div class="text-gray-700">
                                <span class="font-medium text-gray-900">{{ request.counsellor }}</span> asked for an exception in
                                {{ request.isGroup ? 'the group' : 'the therapy' }} "{{ request.therapy }}", until {{ formatDate(request.proposedEndsAt) }}.
                            </div>
                            <div v-if="request.reason" class="whitespace-pre-line text-gray-900">{{ request.reason }}</div>

                            <div v-if="minor.canManage && !isOpen('approve', 'requestId', request.id)" class="flex flex-wrap gap-2">
                                <PrimaryButton :disabled="responding" @click="start('approve', { requestId: request.id }, request.reason ?? '')">approve</PrimaryButton>
                                <DangerButton :disabled="responding" @click="respond(request, 'rejected')">reject</DangerButton>
                            </div>
                            <div v-if="isOpen('approve', 'requestId', request.id)" class="space-y-2">
                                <SafeguardingExceptionTermsFields
                                    v-model:reason="grantForm.reason"
                                    v-model:ends-at="grantForm.endsAt"
                                    :id-prefix="`approve-${request.id}`"
                                    :initial-ends-at="request.proposedEndsAt ?? ''"
                                    :max-days="safeguardingExceptionMaxDays"
                                    reason-label="Reason (kept here, never shown to the client)"
                                    end-label="Exception ends"
                                />
                                <div class="flex gap-2">
                                    <PrimaryButton :disabled="responding || !grantForm.reason.trim() || !grantForm.endsAt" @click="respond(request, 'accepted')">grant exception</PrimaryButton>
                                    <SecondaryButton @click="open = null">cancel</SecondaryButton>
                                </div>
                            </div>
                        </div>

                        <div v-if="minor.canManage && !isOpen('grant', 'wardId', minor.id)">
                            <SecondaryButton @click="start('grant', { wardId: minor.id })">grant an exception</SecondaryButton>
                        </div>
                        <div v-if="isOpen('grant', 'wardId', minor.id)" class="space-y-2">
                            <SafeguardingExceptionTermsFields
                                v-model:reason="grantForm.reason"
                                v-model:ends-at="grantForm.endsAt"
                                :id-prefix="`grant-${minor.id}`"
                                :max-days="safeguardingExceptionMaxDays"
                                reason-label="Reason (kept here, never shown to the client)"
                                end-label="Exception ends"
                            />
                            <div class="flex gap-2">
                                <PrimaryButton :disabled="grantForm.processing || !grantForm.reason.trim() || !grantForm.endsAt" @click="grant(minor.id, 'Safeguarding exception granted.')">grant</PrimaryButton>
                                <SecondaryButton @click="open = null">cancel</SecondaryButton>
                            </div>
                        </div>
                    </template>
                </div>

                <Pagination :meta="pausedMinors.meta" @navigate="goToPage" />
            </section>

            <section v-if="noLongerNeeded.length" class="bg-white shadow-sm border border-gray-200 sm:rounded-lg p-6 space-y-3">
                <div>
                    <div class="text-lg font-semibold text-gray-900">Requests no longer needed</div>
                    <div class="mt-1 text-sm text-gray-600">
                        These counsellors' requests are still waiting, but the client isn't paused any more, so they can't be
                        approved.<template v-if="canManage"> Reject them to close them and let the counsellor know.</template>
                    </div>
                </div>

                <div v-for="request in noLongerNeeded" :key="request.key" class="border border-gray-200 rounded-md p-4 text-sm space-y-2">
                    <div v-if="request.involved" class="text-gray-600 italic">
                        You're involved with this client (you counsel them, or asked for this), so you can't see or decide it here.
                    </div>
                    <template v-else>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-gray-900">{{ request.client }}</span>
                            <span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">{{ request.why }}</span>
                        </div>
                        <div class="text-gray-700">
                            <span class="font-medium text-gray-900">{{ request.counsellor }}</span> asked for an exception in
                            {{ request.isGroup ? 'the group' : 'the therapy' }} "{{ request.therapy }}", until {{ formatDate(request.proposedEndsAt) }}.
                        </div>
                        <div v-if="request.reason" class="whitespace-pre-line text-gray-900">{{ request.reason }}</div>
                        <div v-if="request.canManage">
                            <DangerButton :disabled="responding" @click="respond(request, 'rejected')">reject</DangerButton>
                        </div>
                    </template>
                </div>
            </section>

            <section class="bg-white shadow-sm border border-gray-200 sm:rounded-lg p-6 space-y-3">
                <div class="text-lg font-semibold text-gray-900">Exceptions</div>
                <div v-if="exceptions.data.length === 0" class="text-sm text-gray-500 italic">No safeguarding exceptions yet.</div>

                <div v-for="exception in exceptions.data" :key="exception.id" class="border border-gray-200 rounded-md p-4 text-sm space-y-3">
                    <div v-if="exception.involved" class="flex flex-wrap items-center gap-2">
                        <span class="px-2 py-0.5 rounded text-xs font-medium capitalize" :class="statusClasses[exception.status]">{{ exception.status }}</span>
                        <span class="text-gray-600 italic">You're involved with this client, so another administrator handles it. Started {{ formatDate(exception.startsAt) }}.</span>
                    </div>
                    <template v-else>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="px-2 py-0.5 rounded text-xs font-medium capitalize" :class="statusClasses[exception.status]">{{ exception.status }}</span>
                            <span class="font-medium text-gray-900">{{ exception.ward.name }}</span>
                            <span v-if="exception.ward.username" class="text-gray-500">@{{ exception.ward.username }}</span>
                            <span v-if="exception.requestId" class="text-gray-500">· asked for by a counsellor</span>
                        </div>

                        <dl class="grid grid-cols-1 sm:grid-cols-[8rem_1fr] gap-x-4 gap-y-1">
                            <dt class="text-gray-500">From</dt><dd>{{ formatDate(exception.startsAt) }}</dd>
                            <dt class="text-gray-500">Until</dt><dd>{{ formatDate(exception.endsAt) }}</dd>
                            <dt class="text-gray-500">Granted by</dt><dd>{{ exception.grantedBy }}</dd>
                            <dt class="text-gray-500">Reason</dt><dd class="whitespace-pre-line">{{ exception.reason ?? '(removed with the closed account)' }}</dd>
                            <template v-if="exception.ended">
                                <dt class="text-gray-500">{{ exception.ended.kind === 'renewed' ? 'Renewed' : 'Ended early' }}</dt>
                                <dd>{{ formatDate(exception.ended.at) }} by {{ exception.ended.by }}<template v-if="exception.ended.reason">: {{ exception.ended.reason }}</template></dd>
                            </template>
                            <template v-if="exception.remindedAt">
                                <dt class="text-gray-500">Reminder sent</dt><dd>{{ formatDate(exception.remindedAt) }}</dd>
                            </template>
                        </dl>

                        <div v-if="exception.canManage && !isOpen('renew', 'exceptionId', exception.id) && !isOpen('end', 'exceptionId', exception.id)" class="flex flex-wrap gap-2">
                            <SecondaryButton @click="start('renew', { exceptionId: exception.id, wardId: exception.ward.id })">renew</SecondaryButton>
                            <DangerButton @click="start('end', { exceptionId: exception.id })">end early</DangerButton>
                        </div>

                        <div v-if="isOpen('renew', 'exceptionId', exception.id)" class="space-y-2">
                            <SafeguardingExceptionTermsFields
                                v-model:reason="grantForm.reason"
                                v-model:ends-at="grantForm.endsAt"
                                :id-prefix="`renew-${exception.id}`"
                                :max-days="safeguardingExceptionMaxDays"
                                reason-label="Why renew it? (a new reason)"
                                end-label="New end"
                            />
                            <div class="flex gap-2">
                                <PrimaryButton :disabled="grantForm.processing || !grantForm.reason.trim() || !grantForm.endsAt" @click="grant(exception.ward.id, 'Safeguarding exception renewed.')">renew</PrimaryButton>
                                <SecondaryButton @click="open = null">cancel</SecondaryButton>
                            </div>
                        </div>

                        <div v-if="isOpen('end', 'exceptionId', exception.id)" class="space-y-2">
                            <InputLabel :for="`end-${exception.id}`" value="Why end it now? If they still have no guardian, their therapy pauses again." />
                            <textarea :id="`end-${exception.id}`" v-model="endForm.reason" rows="2" maxlength="2000" class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"></textarea>
                            <div class="flex gap-2">
                                <DangerButton :disabled="endForm.processing || !endForm.reason.trim()" @click="end(exception.id)">end</DangerButton>
                                <SecondaryButton @click="open = null">cancel</SecondaryButton>
                            </div>
                        </div>
                    </template>
                </div>

                <Pagination :meta="exceptions.meta" @navigate="goToPage" />
            </section>
        </div>
    </AuthenticatedLayout>

    <Alert :show="alertData.show" :type="alertData.type" :message="alertData.message" :time="alertData.time" @close="clearAlertData" />
</template>
