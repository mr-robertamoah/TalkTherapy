// SCRUM-368/370: one owner for a channel that several components listen on at once -- a
// counsellor's `counsellors.{id}` (AuthenticatedLayout and the therapy page), a session's
// `sessions.{id}` (the chat, its video call, and every session badge), a discussion's
// `discussions.{id}` presence channel (its chat page and every DiscussionModal on a therapy page).
// None of them may simply leave it: that would silence the others. Each caller gets its own
// handle, adds its handlers through it, and on release removes exactly those; the channel is left
// only when the last handle is released -- correct whichever order Inertia mounts and unmounts
// components in. Callers acquire once they know what to listen to, and release on unmount.

const NOTIFICATION_EVENT = '.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated'

const holders = new Map()

function membersOf(subscription) {
    const members = subscription?.members?.members ?? {}
    return Object.keys(members).map((id) => members[id])
}

export function acquireChannel(name, { presence = false } = {}) {
    if (!name) return null

    const fullName = `${presence ? 'presence' : 'private'}-${name}`
    holders.set(fullName, (holders.get(fullName) ?? 0) + 1)

    const channel = () => (presence ? Echo.join(name) : Echo.private(name))
    const listeners = [] // [event, callback], removed with stopListening
    const bindings = [] // [pusherEvent, wrapper], presence member callbacks
    let released = false

    function bind(pusherEvent, wrapper) {
        bindings.push([pusherEvent, wrapper])
        channel().subscription?.bind(pusherEvent, wrapper)
    }

    const handle = {
        listen(event, callback) {
            if (released) return handle
            listeners.push([event, callback])
            channel().listen(event, callback)
            return handle
        },
        notification(callback) {
            return handle.listen(NOTIFICATION_EVENT, callback)
        },
        listenForWhisper(event, callback) {
            return handle.listen(`.client-${event}`, callback)
        },
        whisper(event, data) {
            if (!released) channel().whisper(event, data)
            return handle
        },
        // Presence only. Echo's own here() only fires on the subscription succeeding, which has
        // already happened when a second holder joins -- so a late here() is called straight away
        // with the current members.
        here(callback) {
            if (released || !presence) return handle
            const subscription = channel().subscription
            bind('pusher:subscription_succeeded', (data) => callback(membersOf({ members: data })))
            if (subscription?.subscribed) callback(membersOf(subscription))
            return handle
        },
        // Presence only: this browser's own member id on the channel (SCRUM-357: a per-channel key,
        // never a user id). Null until the subscription has succeeded.
        me() {
            // Not after release: channel() would subscribe again with nobody left to leave it.
            if (released || !presence) return null
            return channel().subscription?.members?.me?.id ?? null
        },
        joining(callback) {
            if (!released && presence) bind('pusher:member_added', (member) => callback(member.info))
            return handle
        },
        leaving(callback) {
            if (!released && presence) bind('pusher:member_removed', (member) => callback(member.info))
            return handle
        },
        release() {
            if (released) return
            released = true

            const current = channel()
            listeners.forEach(([event, callback]) => current.stopListening(event, callback))
            bindings.forEach(([pusherEvent, wrapper]) => current.subscription?.unbind(pusherEvent, wrapper))
            listeners.length = 0
            bindings.length = 0

            const remaining = (holders.get(fullName) ?? 1) - 1
            if (remaining > 0) {
                holders.set(fullName, remaining)
            } else {
                holders.delete(fullName)
                Echo.leaveChannel(fullName)
            }
        },
    }

    return handle
}

export function acquireCounsellorChannel(counsellorId) {
    return counsellorId ? acquireChannel(`counsellors.${counsellorId}`) : null
}
