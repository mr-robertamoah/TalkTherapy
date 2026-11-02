import { ref, computed, watch, watchEffect, onBeforeUnmount } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { differenceInMinutes, parseISO } from 'date-fns'
import useAlert from '@/Composables/useAlert'
import useEnums from '@/Composables/useEnums'
import { createBroadcastMessageQueue, resolveBroadcastSession } from '@/Composables/resolveBroadcastMessage'

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
        // !!userId: a masked client's id is null (SCRUM-345), and a guest's undefined == null.
        return !!userId.value && userId.value == computedTherapy.value.user?.id
    })
    
    const computedIsCounsellor = computed(() => {
        if (therapyType === 'group') {
            const counsellors = computedTherapy.value.counsellors || []
            return !!counsellors.find((c) => c.userId == userId.value)
        }
        return userId.value == computedTherapy.value.counsellor?.userId
    })
    
    const computedIsParticipant = computed(() => {
        // For group therapy, also recognise a plain joined member (attached via the
        // group_therapy_user pivot, not just the creator/an assigned counsellor) -- mirrors the
        // GroupTherapy::isParticipant() fix on the backend (SCRUM-69/SCRUM-72).
        if (therapyType === 'group') {
            return computedIsUser.value || computedIsCounsellor.value || !!computedTherapy.value.isParticipant
        }
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
            
        // SCRUM-357: members are listed by a per-channel key, never a user id -- this browser's own
        // key is the channel's `members.me`, so each entry is marked with whether it's us.
        const presence = Echo.join(channelName)
        const ownPresenceId = () => presence.subscription?.members?.me?.id

        presence
            .here((users) => {
                onlineParticipants.value = users.map((u) => ({ ...u, isMe: u.id === ownPresenceId() }))
                
                let otherUser = onlineParticipants.value.find((u) => !u.isMe)
                if (!otherUser) return
                
                setSuccessAlertData({
                    message: `${otherUser.name} is already online.`,
                    time: 4000,
                })
            })
            .joining((user) => {
                let found = onlineParticipants.value.find((u) => u.id == user.id)
                if (found) return
                
                onlineParticipants.value.push({ ...user, isMe: false })
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
            // SCRUM-367: session broadcasts carry only the session's id ({ sessionId }); it is
            // fetched (and dropped if this viewer may no longer see it), in arrival order.
            .listen(`.session.started`, (data) => {
                enqueueSession(data.sessionId, (session) => {
                    activeSession.value = session
                    startTimer()
                })
            })
            .listen(`.session.updated`, (data) => refreshActiveSession(data.sessionId))
            .listen(`.session.topic.set`, (data) => refreshActiveSession(data.sessionId))
            .listen(`.session.topic.unset`, () => {
                if (activeSession.value) {
                    activeSession.value.currentTopic = null
                }
            })

        // SCRUM-367: discussions are counsellors-only, so they arrive on this counsellor's own
        // channel (which AuthenticatedLayout also uses), never the therapy's -- only those about
        // this therapy are handled here.
        const counsellorId = usePage().props.auth.user?.counsellor?.id
        if (counsellorId) {
            counsellorChannelName = `counsellors.${counsellorId}`
            Echo.private(counsellorChannelName)
                .listen('.discussion.started', onDiscussionStarted)
                .listen('.discussion.updated', onDiscussionUpdated)
        }
    }

    const enqueueSession = createBroadcastMessageQueue(resolveBroadcastSession)

    function refreshActiveSession(sessionId) {
        if (!sessionId || activeSession.value?.id != sessionId) return
        enqueueSession(sessionId, (session) => {
            if (activeSession.value?.id == session.id) activeSession.value = session
        })
    }
    let counsellorChannelName = null

    function isAboutThisTherapy(discussion) {
        const forType = therapyType === 'group' ? 'GroupTherapy' : 'Therapy'
        return discussion?.forType === forType && discussion?.forId == computedTherapy.value?.id
    }

    function onDiscussionStarted(data) {
        if (!isAboutThisTherapy(data.discussion)) return
        activeDiscussion.value = data.discussion
        startTimer()
    }

    function onDiscussionUpdated(data) {
        if (!isAboutThisTherapy(data.discussion)) return
        const index = discussions.value.data.findIndex((d) => d.id == data.discussion.id)
        if (index !== -1) discussions.value.data.splice(index, 1, data.discussion)

        if (activeDiscussion.value?.id == data.discussion.id) {
            activeDiscussion.value.status = data.discussion.status
        }
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

        // Not left: AuthenticatedLayout listens on the same counsellor channel.
        if (counsellorChannelName) {
            Echo.private(counsellorChannelName)
                .stopListening('.discussion.started', onDiscussionStarted)
                .stopListening('.discussion.updated', onDiscussionUpdated)
        }
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