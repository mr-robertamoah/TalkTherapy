import { ref } from "vue";

export default function useAlert() {
    

    const alertData = ref({
        show: false,
        type: '',
        message: '',
        time: 2000,
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

    return { alertData, clearAlertData, setAlertData }
}