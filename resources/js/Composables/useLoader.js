import { ref } from "vue"

export default function useLoader() {
    const loader = ref({
        show: false, type: ''
    })

    function showLoader(type) {
        loader.value.type = type
        loader.value.show = true
    }

    function hideLoader() {
        loader.value.type = ''
        loader.value.show = false
    }

    return {
        loader, showLoader, hideLoader
    }
}