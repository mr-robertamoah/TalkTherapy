import { ref, computed, nextTick, unref } from "vue";

export default function useMessage() {
    
    const message = ref({
        files: [],
        deletedFiles: [],
        content: '',
        confidential: false,
        fromCounsellor: false,
        counsellorAvatar: '',
        type: 'NORMAL',
        status: 'sending',
        replying: null,
        topicId: '',
        forType: '',
        forId: '',
        toType: '',
        toId: '',
        fromType: '',
        fromId: '',
        fromUserId: '',
        toUserId: '',
        id: null,
    })
    const files = ref([])
    const messageArea = ref(null)
    const deletedFiles = ref([])
    const showAttachmentIcons = ref(false)
    const replyingMessage = ref(null)
    const messageFilesInput = ref(null)
    const mediaCaptureData = ref({
        show: false,
        type: ''
    })
    
    const computedHasMessage = computed(() => {
        return message.value.content || files.value?.length || deletedFiles.value?.length
    })

    function changeFile(e) {
        if (e.target.files?.length) {
            files.value = [...e.target.files, ...(files.value ?? [])]
            messageFilesInput.value.value = ''
        }
    
        showAttachmentIcons.value = false
    }

    function clickedIcon(type) {
        if (type == 'file') {
            messageFilesInput.value.click()
        }
        else if (type == 'microphone') {
            showMediaCapture('audio')
        }
        else if (type == 'camera') {
            showMediaCapture('image')
        }
    
        showAttachmentIcons.value = false
    }

    function resetMessage() {
        files.value = []
        deletedFiles.value = []
        message.value.files = []
        message.value.deletedFiles = []
        message.value.id = null
        message.value.content = ''
        message.value.confidential = false
        message.value.type = 'NORMAL'
        message.value.replying = null
        message.value.topicId = ''
        message.value.fromUserId = ''
        message.value.toUserId = ''
        message.value.forType = ''
        message.value.forId = ''
        message.value.toType = ''
        message.value.toId = ''
        message.value.fromType = ''
        message.value.fromId = ''
        message.value.status = 'sending'
        message.value.fromCounsellor = false
        message.value.counsellorAvatar = ''
    }

    function removeUploadFile(file, idx) {
        if (file.id) {
            deletedFiles.value = [file, ...deletedFiles.value]
        }
    
        files.value.splice(idx, 1)
    }

    function showMediaCapture(type) {
        mediaCaptureData.value.type = type
        mediaCaptureData.value.show = true
    }

    function closeMediaCapture() {
        mediaCaptureData.value.type = ''
        mediaCaptureData.value.show = false
    }

    function updateMessage(action = null) {
        message.value.status = 'updating'
        if (replyingMessage.value?.id)
            message.value.replying = replyingMessage.value
        else
            message.value.replying = null
        
        if (files.value?.length)
            message.value.files = [...unref(files)]
        
        if (deletedFiles.value?.length)
            message.value.deletedFiles = [...deletedFiles.value.map(f => f.id)]
    
        if (action)
            action(message.value)
        
        resetMessage()
    }

    async function scrollToMessageId(id) {
        if (!id) return
        
        await nextTick()
    
        const div = document.getElementById(`message_${id}`)
    
        if (div) div.scrollIntoView({ behavior: 'smooth', inline: 'nearest', block: 'nearest'})
    }

    function sendMessage({
        itemType = 'Session', item = null, topic = null, addNewMessage = null,
        from = null, to = null, action = null
    }) {
        if (message.value?.id) {
            updateMessage(action)
            return
        }
    
        if (!item?.id) return
    
        message.value.forType = itemType
        message.value.forId = item.id
    
        if (replyingMessage.value?.id)
            message.value.replying = replyingMessage.value
        
        if (files.value)
            message.value.files = [...unref(files)]
    
        if (topic?.id)
            message.value.topicId = topic.id
    
        if (from) {
            message.value.fromType = from.type
            message.value.fromId = from.id
            message.value.fromUserId = from.userId
            message.value.fromCounsellor = from.isCounsellor
            if (from.avatar)
                message.value.counsellorAvatar = from.avatar
        }
    
        if (to) {
            message.value.toType = to.type
            message.value.toId = to.id
            message.value.toUserId = to.userId
        }
    
        if (addNewMessage)
            addNewMessage(message.value)
        
        resetMessage()
    }

    function selectForUpdate(data) {
        message.value = {...message.value, ...data}
        message.value.replying = null
        message.value.deletedFiles = []

        replyingMessage.value = data.replying
        files.value = data.files
        deletedFiles.value = []
    }

    function removeReply() {
        message.value.replying = null
    }

    function selectAsReply(data) {
        message.value.replying = { ...data }
    }

    async function scrollToBottom() {
        await nextTick()
    
        if (messageArea.value) messageArea.value.scrollTop = messageArea.value.scrollHeight
    }

    return { 
        message, files, deletedFiles, showAttachmentIcons, messageFilesInput,
        computedHasMessage, mediaCaptureData, replyingMessage, messageArea,
        changeFile , clickedIcon, removeUploadFile, scrollToMessageId,
        showMediaCapture, closeMediaCapture, resetMessage, removeReply,
        sendMessage, updateMessage, selectForUpdate, selectAsReply,
        scrollToBottom
    }
}