<template>
    <div>
        <template v-if="type == 'session'">
            <SessionBadge :session="item"/>
        </template>
        <template v-if="type == 'topic'"></template>
    </div>
</template>

<script setup>
import SessionBadge from './SessionBadge.vue'

const props = defineProps({
    item: {
        default: null
    },
    type: {
        default: '',
        type: String
    }
})
</script>