import { ref } from "vue";

export default function useAlert() {
    
    const DEFAULT_TIME = 50000

    const AlertType = {
        failed: 'failed',
        success: 'success',
    }

    const alertData = ref({
        show: false,
        type: '',
        message: '',
        time: 2000,
        data: {}
    })

    const clearAlertData = () => {
        alertData.value.show = false
        alertData.value.type = ''
        alertData.value.message = ''
        alertData.value.time = 2000
    }

    const setAlertData = ({ show = true, type = 'success', message = '', time = DEFAULT_TIME}) => {
        alertData.value.type = type
        alertData.value.message = message
        alertData.value.time = time
        alertData.value.show = show
    }

    const setSuccessAlertData = ({ message = '', time = DEFAULT_TIME, data = {} }) => {
        alertData.value.type = 'success'
        alertData.value.message = message
        alertData.value.time = time
        alertData.value.show = true
        alertData.value.data = data
    }

    const setFailedAlertData = ({ message = '', time = DEFAULT_TIME, data = {} }) => {
        alertData.value.type = 'failed'
        alertData.value.message = message
        alertData.value.time = time
        alertData.value.show = true
        alertData.value.data = data
    }

    return { alertData, clearAlertData, setAlertData, setFailedAlertData, setSuccessAlertData, AlertType }
}