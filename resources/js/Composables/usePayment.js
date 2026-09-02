import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import axios from 'axios'

const STATUS_MESSAGES = {
    SUCCESS: 'Your payment was successful.',
    FAILED: 'Your payment failed. Please try again.',
    // Neither the backend nor this banner ever treats ABANDONED/PENDING as a permanent failure --
    // an async webhook can still resolve it to SUCCESS after the user lands back here.
    ABANDONED: 'We have not yet confirmed your payment. If you completed checkout, this may update shortly -- otherwise, feel free to try again.',
    PENDING: 'We have not yet confirmed your payment. If you completed checkout, this may update shortly -- otherwise, feel free to try again.',
}

// Read-only wording for the counsellor-facing status indicator (never terminal-failure-sounding
// for ABANDONED/PENDING, matching the client-facing banner's wording above).
const STATUS_LABELS = {
    SUCCESS: 'Paid',
    FAILED: 'Payment failed',
}

function paymentStatusLabel(status) {
    return STATUS_LABELS[status] ?? 'Awaiting payment'
}

// SCRUM-222/TT-7.4-retry: FAILED and ABANDONED are both non-terminal (STATUS_MESSAGES above
// already treats them as recoverable) -- a client whose last attempt landed in either status
// should see distinct "try payment again" copy on the pay button, not the same generic "pay now"
// wording shown before any attempt at all. Shared here (like paymentStatusLabel above) since both
// TherapyPaymentDetails.vue and UnifiedTherapy.vue's session-actions modal need the identical check.
function isRetryStatus(status) {
    return status === 'FAILED' || status === 'ABANDONED'
}

// Owns the initiate/redirect/status/dismiss logic shared by TherapyPaymentDetails.vue (PER_THERAPY
// pay action) and UnifiedTherapy.vue's session-actions modal (PER_SESSION pay action), so neither
// embeds this logic itself and the other reuses it. Group therapy is explicitly unsupported here --
// TT-7.4d, blocked on a per-member payment model that doesn't exist yet.
export default function usePayment(therapy, therapyType = 'individual') {
    const initiating = ref(false)
    const statusDismissed = ref(false)

    const computedTherapy = computed(() => therapy.value?.data ? therapy.value.data : therapy.value)

    const transactionStatus = computed(() => {
        if (statusDismissed.value) return null
        return usePage().props.transactionStatus ?? null
    })

    const statusBannerType = computed(() => transactionStatus.value === 'SUCCESS' ? 'success' : 'failed')

    const statusBannerMessage = computed(() => STATUS_MESSAGES[transactionStatus.value] ?? '')

    function dismissStatus() {
        statusDismissed.value = true
    }

    function canPayForTherapy(isParticipant, isCounsellor) {
        return therapyType !== 'group' &&
            computedTherapy.value?.paymentType === 'PAID' &&
            computedTherapy.value?.paymentData?.per === 'PER_THERAPY' &&
            computedTherapy.value?.paymentStatus !== 'SUCCESS' &&
            isParticipant && !isCounsellor
    }

    function canPayForSession(session, isParticipant, isCounsellor) {
        return therapyType !== 'group' &&
            computedTherapy.value?.paymentData?.per === 'PER_SESSION' &&
            session?.paymentType === 'PAID' &&
            session?.paymentStatus !== 'SUCCESS' &&
            isParticipant && !isCounsellor
    }

    async function initiate(routeName, routeParam) {
        initiating.value = true

        try {
            const { data } = await axios.post(route(routeName, routeParam))
            window.location.href = data.authorizationUrl
        } catch (err) {
            initiating.value = false
            throw new Error(
                err.response?.data?.message ||
                'Something unfortunate happened while starting your payment. Please try again later.'
            )
        }
    }

    function payForTherapy() {
        return initiate('transactions.initiate.therapy', computedTherapy.value.id)
    }

    function payForSession(session) {
        return initiate('transactions.initiate.session', session.id)
    }

    return {
        initiating,
        transactionStatus,
        statusBannerType,
        statusBannerMessage,
        dismissStatus,
        canPayForTherapy,
        canPayForSession,
        payForTherapy,
        payForSession,
        paymentStatusLabel,
        isRetryStatus,
        // SCRUM-221/TT-7.5a: exported directly (not just via payForTherapy/payForSession) for
        // PaymentRequiredBanner.vue -- it only ever has a bare therapy id (never the full
        // resource this composable's other callers pass in), so it calls this the same way
        // payForTherapy()/payForSession() do internally, rather than reimplementing it.
        initiate,
    }
}
