export default function useErrorHandler() {

    function setErrorData(formErrors, errors, keys) {
        keys.forEach(key => {
            if (!errors.hasOwnProperty(key)) return

            if (typeof errors[key] == 'string') {
                formErrors.value[key] = errors[key]
            }

            if (typeof errors[key] == 'object' && errors[key].length) {
                formErrors.value[key] = errors[key][0]
            }
        });
    }

    function clearErrorData(formErrors, keys) {
        keys.forEach(key => {
            clearError(formErrors, key)
        });
    }

    function clearError(formErrors, key) {
        if (!formErrors.value?.hasOwnProperty(key)) return

        formErrors.value[key] = ''
    }

    return { clearError, clearErrorData, setErrorData }
}