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
// embeds this logic itself and the other reuses it.
export default function usePayment(therapy, therapyType = 'individual') {
    const initiating = ref(false)
    const statusDismissed = ref(false)
    const requestingRefund = ref(false)

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

    // SCRUM-242/TT-7.3b-k: a retainer-covered engagement (TT-7.3b-f1) never needs a client
    // payment -- orgRetainerCoverage (TherapyResource) is truthy for exactly that case, at the
    // therapy level, regardless of which session is in context.
    function isOrgRetainerCovered() {
        return !!computedTherapy.value?.orgRetainerCoverage
    }

    // TT-7.4d-b/SCRUM-259: an individual Therapy/Session's own `paymentStatus` field is already
    // scoped to the viewer (single-payer model -- see TherapyResource/GroupTherapyResource's own
    // comments on this), but a GroupTherapy/its Sessions' `paymentStatus` is "paid by ANY member".
    // `viewerPaymentStatus` is the per-viewer counterpart TT-7.4d-a/b added for exactly that case --
    // centralized here so every "have I paid" check below reads the right field for both shapes.
    function viewerScopedPaymentStatus(entity) {
        return therapyType === 'group' ? entity?.viewerPaymentStatus : entity?.paymentStatus
    }

    function canPayForTherapy(isParticipant, isCounsellor) {
        return computedTherapy.value?.paymentType === 'PAID' &&
            computedTherapy.value?.paymentData?.per === 'PER_THERAPY' &&
            viewerScopedPaymentStatus(computedTherapy.value) !== 'SUCCESS' &&
            !isOrgRetainerCovered() &&
            isParticipant && !isCounsellor
    }

    function canPayForSession(session, isParticipant, isCounsellor) {
        return computedTherapy.value?.paymentData?.per === 'PER_SESSION' &&
            session?.paymentType === 'PAID' &&
            viewerScopedPaymentStatus(session) !== 'SUCCESS' &&
            !isOrgRetainerCovered() &&
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

    // TT-7.4d-b/SCRUM-259: a GroupTherapy charge must POST to its own dedicated
    // transactions.initiate.group_therapy route (`/group-therapies/{groupTherapyId}/transactions`)
    // -- TransactionController::getFor() resolves the payable strictly by which route param is
    // present, so posting a GroupTherapy's id to the individual-Therapy route instead would either
    // 404 or, worse, resolve a completely different Therapy record that happens to share the same
    // id. This was unreachable before this ticket lifted usePayment.js's `therapyType !== 'group'`
    // exclusion, so no GroupTherapy id had ever reached this call before.
    function payForTherapy() {
        return initiate(
            therapyType === 'group' ? 'transactions.initiate.group_therapy' : 'transactions.initiate.therapy',
            computedTherapy.value.id
        )
    }

    function payForSession(session) {
        return initiate('transactions.initiate.session', session.id)
    }

    // TT-7.7b/SCRUM-250: `entity` is whatever exposes `paymentStatus`/`transactionId`/
    // `refundRequestStatus` -- the Therapy resource itself for PER_THERAPY, or a Session resource
    // for PER_SESSION -- mirrors canPayForTherapy/canPayForSession's own split by taking the
    // already-resolved object rather than re-deriving PER_THERAPY/PER_SESSION here.
    //
    // TT-7.7e/SCRUM-253 (found during Playwright QA): `!entity?.refundStatus` is required here --
    // without it, a transaction that's already been successfully refunded (refundStatus ===
    // 'SUCCESS') still showed "request a refund", since refundRequestStatus only reflects the
    // ASK-time request's own status and is never set for a refund created any other way (e.g. an
    // already-approved one). EnsureTransactionIsRefundEligibleAction already blocks a second
    // refund server-side, but the control shouldn't invite the client to try in the first place.
    // Unscoped `entity?.paymentStatus` read below is only correct because both call sites still
    // wrap this in a `therapyType !== 'group'` template guard (refunds are individual-Therapy-only
    // for this whole epic, see SessionResource's own comment) -- if a future ticket extends refunds
    // to a GroupTherapy, route this through viewerScopedPaymentStatus() (or an equivalent
    // viewer-scoped refund-eligibility field) first, or it will silently regress to showing one
    // member's refund eligibility based on a DIFFERENT member's payment.
    function canRequestRefund(entity, isParticipant, isCounsellor) {
        return isParticipant && !isCounsellor &&
            entity?.paymentStatus === 'SUCCESS' &&
            !entity?.refundRequestStatus &&
            !entity?.refundStatus &&
            !!entity?.transactionId
    }

    async function requestRefund(transactionId, reason) {
        requestingRefund.value = true

        try {
            const { data } = await axios.post(route('transactions.refund_request.store', transactionId), { reason })
            return data
        } catch (err) {
            throw new Error(
                err.response?.data?.message ||
                'Something unfortunate happened while submitting your refund request. Please try again later.'
            )
        } finally {
            requestingRefund.value = false
        }
    }

    return {
        initiating,
        requestingRefund,
        transactionStatus,
        statusBannerType,
        statusBannerMessage,
        dismissStatus,
        viewerScopedPaymentStatus,
        canPayForTherapy,
        canPayForSession,
        canRequestRefund,
        isOrgRetainerCovered,
        payForTherapy,
        payForSession,
        requestRefund,
        paymentStatusLabel,
        isRetryStatus,
        // SCRUM-221/TT-7.5a: exported directly (not just via payForTherapy/payForSession) for
        // PaymentRequiredBanner.vue -- it only ever has a bare therapy id (never the full
        // resource this composable's other callers pass in), so it calls this the same way
        // payForTherapy()/payForSession() do internally, rather than reimplementing it.
        initiate,
    }
}
