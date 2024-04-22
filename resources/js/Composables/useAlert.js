import { ref } from "vue";

export default function useAlert() {
    
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

    const setAlertData = ({ show = true, type = 'success', message = '', time = 2000}) => {
        alertData.value.type = type
        alertData.value.message = message
        alertData.value.time = time
        alertData.value.show = show
    }

    const setSuccessAlertData = ({ message = '', time = 2000, data = {} }) => {
        alertData.value.type = 'success'
        alertData.value.message = message
        alertData.value.time = time
        alertData.value.show = true
        alertData.value.data = data
    }

    const setFailedAlertData = ({ message = '', time = 2000, data = {} }) => {
        alertData.value.type = 'failed'
        alertData.value.message = message
        alertData.value.time = time
        alertData.value.show = true
        alertData.value.data = data
    }

    return { alertData, clearAlertData, setAlertData, setFailedAlertData, setSuccessAlertData, AlertType }
}