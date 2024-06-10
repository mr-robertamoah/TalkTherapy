<template>
    <div class="min-w-[200px] sm:min-w-[300px]">
        <template v-if="type == 'session'">
            <SessionBadge 
                :session="item" 
                :therapy="therapy"
                :loaded-topics="loadedTopics"
                :is-active="isActive"
                :has-actions="hasActions"
                :show-details="showDetails"
                :listen="listen"
                :loaded-topics-page="loadedTopicsPage"
                @on-update="(data) => emits('onUpdate', data)"
                @on-delete="(data) => emits('onDelete', data)"
                @on-message-created="(data) => emits('onMessageCreated', data)"
                class="w-full"
            />
        </template>
        <template v-if="type == 'topic'">
            <TopicBadge 
                :topic="item" 
                :therapy="therapy"
                :has-actions="hasActions"
                :show-details="showDetails"
                :loaded-sessions="loadedSessions"
                :loaded-sessions-page="loadedSessionsPage"
                @on-update="(data) => emits('onUpdate', data)"
                @on-delete="(data) => emits('onDelete', data)"
                @on-message-created="(data) => emits('onMessageCreated', data)"
                class="w-full"
            />
        </template>
    </div>
</template>

<script setup>
import TopicBadge from './TopicBadge.vue'
import SessionBadge from './SessionBadge.vue'

const emits = defineEmits(['onUpdate', 'onDelete', 'onMessageCreated'])

const props = defineProps({
    item: {
        default: null
    },
    therapy: {
        default: null
    },
    isActive: {
        default: false
    },
    listen: {
        default: false
    },
    hasActions: {
        default: true
    },
    showDetails: {
        default: true
    },
    type: {
        default: '',
        type: String
    },
    loadedTopics: {
        default: []
    },
    loadedTopicsPage: {
        default: 0
    },
    loadedSessions: {
        default: []
    },
    loadedSessionsPage: {
        default: 0
    },
})
</script>