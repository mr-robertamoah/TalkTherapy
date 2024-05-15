<template>
    <div>
        <div v-if="mainPost?.content">{{ mainPost.content }}</div>
    </div>
</template>

<script setup>
import { ref, watchEffect } from 'vue';


const emits = defineEmits(['updated', 'deleted'])

const props = defineProps({
    post: {
        default: null
    }
})

watchEffect(() => {
    if (props.post?.id)
        mainPost.value = {...props.post}
})

const mainPost = ref(null)

</script>