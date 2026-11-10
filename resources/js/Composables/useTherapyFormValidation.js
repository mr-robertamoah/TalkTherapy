import useEnums from '@/Composables/useEnums';

export default function useTherapyFormValidation() {
    const { PaymentTypeEnum, SessionTypeEnum } = useEnums()

    // Shared between UpdateIndividualTherapyFormModal.vue and UpdateGroupTherapyFormModal.vue's
    // updateTherapy() -- returns an error message string for the first failing check, or null
    // if therapyForm passes all of them. Does not touch either modal's divergent fields (group
    // counsellor selection/share-percentage, individual's simpler payment section).
    function validateTherapyForm(therapyForm) {
        if (!therapyForm.name) {
            return 'Name is required for a therapy.'
        }

        if (
            therapyForm.paymentType == PaymentTypeEnum.paid &&
            !(therapyForm.amount && therapyForm.currency && therapyForm.per)
        ) {
            return 'Amount, currency and per what? All of these are required since you selected PAID payment type.'
        }

        if (
            therapyForm.paymentType == PaymentTypeEnum.free &&
            !therapyForm.public
        ) {
            return 'FREE payment types requires that you set public to true.'
        }

        if (
            therapyForm.paymentType == PaymentTypeEnum.paid &&
            therapyForm.sessionType == SessionTypeEnum.once &&
            therapyForm.per !== 'PER_THERAPY'
        ) {
            return 'Since ONCE and PAID have been selected for session and payment types respectively, you must select per THERAPY.'
        }

        if (
            therapyForm.sessionType == SessionTypeEnum.periodic &&
            (!therapyForm.maxSessions || therapyForm.maxSessions < 2)
        ) {
            return 'Since PERIODIC has been selected for the session type, the maximum number of sessions must be at least 2.'
        }

        return null
    }

    return { validateTherapyForm }
}
