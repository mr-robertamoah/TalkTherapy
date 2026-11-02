import { ref, computed, watch, watchEffect, onBeforeUnmount } from 'vue'
import { usePage } from '@inertiajs/vue3'
import useAlert from '@/Composables/useAlert'
import useEnums from '@/Composables/useEnums'
import { createAnnouncementQueue, resolveAnnouncedSession } from '@/Composables/announcements'
import { acquireCounsellorChannel } from '@/Composables/sharedChannel'
import { minutesUntilWindow } from '@/Composables/countdown'

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
    // The header counts down to the active session, or to the active discussion when there is no
    // session still running.
    function timedItem() {
        const finished = [SessionStatusEnum.failed, SessionStatusEnum.abandoned, SessionStatusEnum.held]
        const session = activeSession.value && !finished.includes(activeSession.value.status) ? activeSession.value : null

        return session ?? activeDiscussion.value
    }

    function setTimers() {
        const value = timedItem()
        if (!value) return

        Object.assign(timer.value, minutesUntilWindow(value.startTime, value.endTime), { set: true })
    }

    // SCRUM-369: now that sessions and discussions change live, recount when the item being timed
    // changes -- e.g. the discussion header kept the session's countdown after the session ended.
    watch(
        () => [timedItem()?.id, timedItem() === activeSession.value],
        (now, before) => {
            if (!timer.value.set || (now[0] == before?.[0] && now[1] == before?.[1])) return
            clearInterval(interval.value)
            timer.value.set = false
            if (timedItem()) startTimer()
        }
    )
    
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
    let counsellorChannel = null

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
                    updateSessionOrTopic(session)
                    startTimer()
                })
            })
            .listen(`.session.updated`, (data) => refreshSession(data.sessionId))
            .listen(`.session.topic.set`, (data) => refreshSession(data.sessionId))
            .listen(`.session.topic.unset`, () => {
                if (activeSession.value) {
                    activeSession.value.currentTopic = null
                }
            })

        // SCRUM-367: discussions are counsellors-only, so they arrive on this counsellor's own
        // channel, never the therapy's -- only those about this therapy are handled here. The
        // channel is shared with AuthenticatedLayout (SCRUM-368: see sharedChannel.js).
        counsellorChannel?.release()
        counsellorChannel = acquireCounsellorChannel(usePage().props.auth.user?.counsellor?.id)
        counsellorChannel
            ?.listen('.discussion.started', onDiscussionStarted)
            .listen('.discussion.updated', onDiscussionUpdated)
    }

    const enqueueSession = createAnnouncementQueue(resolveAnnouncedSession)

    // SCRUM-369: a session shown on this page -- the active one, or a card in "Most Recent
    // Sessions" -- is refetched when it changes, and every place showing it is updated, so the
    // card no longer says "in session" after the session was abandoned.
    function refreshSession(sessionId) {
        if (!sessionId) return
        const shown = activeSession.value?.id == sessionId || recentSessions.value.some((s) => s.id == sessionId)
        if (!shown) return
        enqueueSession(sessionId, (session) => updateSessionOrTopic(session))
    }

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

        counsellorChannel?.release()
        counsellorChannel = null
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