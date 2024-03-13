import { ref } from "vue";

export default function useModal() {
    
    const modalData = ref({
        show: false, type: ''
    })
 
    function showModal(type) {
        modalData.value.type = type
        modalData.value.show = true
    }
     
    function closeModal(fn = null) {
        if (fn && typeof fn == 'function') fn()
        modalData.value.type = ''
        modalData.value.show = false
    }

    return { modalData, showModal, closeModal }
}