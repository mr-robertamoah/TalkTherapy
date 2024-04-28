<template>
    <div>
        {{ writtenMessage }}
    </div>
</template>

<script setup>
import { ref } from 'vue';


const props = defineProps({
    message: {
        type: String,
        default: ''
    },
    speed: {
        type: Number,
        default: 100
    }
})

const writtenMessage = ref('')
const messageIndex = ref(0)

function writeMessage() {
    if (messageIndex.value < props.message.length) {
        writtenMessage.value += props.message.charAt(messageIndex.value)
        messageIndex.value += 1
        setTimeout(writeMessage, props.speed)
        return
    }

    messageIndex.value = 0
    writtenMessage.value = ''
    setTimeout(writeMessage, props.speed)
}

writeMessage()

</script>

<style lang="scss" scoped>

</style>