import { ref, computed, watch, watchEffect, onBeforeUnmount } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { differenceInMinutes, parseISO } from 'date-fns'
import useAlert from '@/Composables/useAlert'
import useEnums from '@/Composables/useEnums'

export default function useTherapyState(therapy, therapyType = 'individual') {
    const { SessionStatusEnum, DiscussionStatusEnum } = useEnums()
    const { setSuccessAlertData } = useAlert()
    
    // Reactive state
    const activeSession = ref(null)
    const activeDiscussion = ref(null)
    const recentSessions = ref([])
    const recentTopics = ref([])
    const discussions = ref({ page: 1, data: [] })
    const onlineParticipants = ref([])
    const listening = ref(false)
    const interval = ref(null)
    
    // Timer state
    const timer = ref({
        beforeStart: 0,
        beforeEnd: 0,
        set: false,
    })
    
    const userId = computed(() => usePage().props.auth.user?.id)
    
    const computedTherapy = computed(() => {
        return therapy.value?.data ? therapy.value.data : therapy.value
    })
    
    const computedIsUser = computed(() => {
        if (therapyType === 'group') {
            return (userId.value == computedTherapy.value.addedby?.id && computedTherapy.value.addedby?.isUser) ||
                   (userId.value == computedTherapy.value.addedby?.userId && computedTherapy.value.addedby?.isCounsellor)
        }
        return userId.value == computedTherapy.value.user?.id
    })
    
    const computedIsCounsellor = computed(() => {
        if (therapyType === 'group') {
            const counsellors = computedTherapy.value.counsellors || []
            return !!counsellors.find((c) => c.userId == userId.value)
        }
        return userId.value == computedTherapy.value.counsellor?.userId
    })
    
    const computedIsParticipant = computed(() => {
        return computedIsUser.value || computedIsCounsellor.value
    })
    
    const computedIsInSession = computed(() => {
        let session = activeSession?.value ?? computedTherapy.value?.activeSession
        
        return (
            [
                SessionStatusEnum.inSession,
                SessionStatusEnum.inSessionConfirmation,
                SessionStatusEnum.heldConfirmation,
            ].includes(session?.status) ||
            (session?.status == SessionStatusEnum.pending && timer.value.beforeStart < 5)
        )
    })
    
    // Timer functions
    function setTimers() {
        let now = new Date()
        let offset = now.getTimezoneOffset()
        let value = activeSession.value ? activeSession.value : activeDiscussion.value
        
        timer.value.beforeStart = differenceInMinutes(parseISO(value.startTime), now) + offset
        timer.value.beforeEnd = differenceInMinutes(parseISO(value.endTime), now) + offset
        timer.value.set = true
    }
    
    function resetTimer() {
        timer.value.beforeEnd = 0
        timer.value.beforeStart = 0
    }
    
    function startTimer() {
        if (timer.value.set) return
        
        resetTimer()
        setTimers()
        
        interval.value = setInterval(() => {
            timer.value.beforeStart -= 1
            timer.value.beforeEnd -= 1
        }, 60000)
    }
    
    // WebSocket listening
    function listenToTherapy(currentTherapy) {
        const channelName = therapyType === 'group' 
            ? `groupTherapies.${currentTherapy.id}`
            : `therapies.${currentTherapy.id}`
            
        Echo.join(channelName)
            .here((users) => {
                onlineParticipants.value = [...users]
                
                let otherUser = users.find((u) => userId.value !== u.id)
                if (!otherUser) return
                
                setSuccessAlertData({
                    message: `${otherUser.name} is already online.`,
                    time: 4000,
                })
            })
            .joining((user) => {
                let found = onlineParticipants.value.find((u) => u.id == user.id)
                if (found) return
                
                onlineParticipants.value.push(user)
                setSuccessAlertData({
                    message: `${user.name} just came online.`,
                    time: 4000,
                })
            })
            .leaving((user) => {
                onlineParticipants.value.splice(
                    onlineParticipants.value.findIndex((u) => u.id == user.id),
                    1
                )
                setSuccessAlertData({
                    message: `${user.name} just went offline.`,
                    time: 4000,
                })
            })
            .listen(`.session.started`, (data) => {
                activeSession.value = data.session
                startTimer()
            })
            .listen(`.session.updated`, (data) => {
                if (activeSession.value?.id == data.session.id) {
                    activeSession.value = data.session
                }
            })
            .listen(`.session.topic.set`, (data) => {
                if (activeSession.value) {
                    activeSession.value.currentTopic = data.topic
                }
            })
            .listen(`.session.topic.unset`, (data) => {
                if (activeSession.value) {
                    activeSession.value.currentTopic = null
                }
            })
            .listen(`.discussion.started`, (data) => {
                activeDiscussion.value = data.discussion
                startTimer()
            })
            .listen(`.discussion.updated`, (data) => {
                discussions.value.data.splice(
                    discussions.value.data.findIndex((d) => d.id == data.discussion.id),
                    1,
                    data.discussion
                )
                
                if (activeDiscussion.value?.id == data.discussion.id) {
                    activeDiscussion.value.status = data.discussion.status
                }
            })
    }
    
    // Session/Topic management
    function updateSessionOrTopic(item) {
        if (item.isSession) {
            if (activeSession.value?.id == item.id) {
                activeSession.value = item
            }
            
            const sessionIndex = recentSessions.value.findIndex((session) => session.id == item.id)
            if (sessionIndex !== -1) {
                recentSessions.value.splice(sessionIndex, 1, item)
            }
            return
        }
        
        const topicIndex = recentTopics.value.findIndex((topic) => topic.id == item.id)
        if (topicIndex !== -1) {
            recentTopics.value.splice(topicIndex, 1, item)
        }
    }
    
    function deleteSessionOrTopic(item) {
        if (item.isSession) {
            const sessionIndex = recentSessions.value.findIndex((session) => session.id == item.id)
            if (sessionIndex !== -1) {
                recentSessions.value.splice(sessionIndex, 1)
            }
            return
        }
        
        const topicIndex = recentTopics.value.findIndex((topic) => topic.id == item.id)
        if (topicIndex !== -1) {
            recentTopics.value.splice(topicIndex, 1)
        }
    }
    
    function addSessionOrTopic(item) {
        if (item.isSession) {
            recentSessions.value = [item, ...recentSessions.value]
            return
        }
        
        recentTopics.value = [item, ...recentTopics.value]
    }
    
    // Cleanup
    onBeforeUnmount(() => {
        timer.value.set = false
        
        if (interval.value) {
            clearInterval(interval.value)
        }
        
        let currentTherapy = computedTherapy.value
        if (!userId.value || !currentTherapy.id) return
        
        const channelName = therapyType === 'group' 
            ? `groupTherapies.${currentTherapy.id}`
            : `therapies.${currentTherapy.id}`
            
        Echo.leave(channelName)
    })
    
    return {
        // State
        activeSession,
        activeDiscussion,
        recentSessions,
        recentTopics,
        discussions,
        onlineParticipants,
        listening,
        timer,
        
        // Computed
        computedTherapy,
        computedIsUser,
        computedIsCounsellor,
        computedIsParticipant,
        computedIsInSession,
        userId,
        
        // Methods
        startTimer,
        resetTimer,
        listenToTherapy,
        updateSessionOrTopic,
        deleteSessionOrTopic,
        addSessionOrTopic,
    }
}