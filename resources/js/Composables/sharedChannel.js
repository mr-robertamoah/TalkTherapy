// SCRUM-368: one owner for a private channel that several components listen on at once (e.g. a
// counsellor's `counsellors.{id}`: AuthenticatedLayout, and a therapy page via useTherapyState).
// None of them may simply leave it: that would silence the others. Each caller gets its own handle,
// adds its handlers through it, and on release removes exactly those; the channel is left only when
// the last handle is released -- correct whichever order Inertia mounts and unmounts pages in.
// Callers acquire once their user is known and release in their own onBeforeUnmount.

const NOTIFICATION_EVENT = '.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated'

const holders = new Map()

export function acquireChannel(name) {
    if (!name) return null

    holders.set(name, (holders.get(name) ?? 0) + 1)

    const handlers = []
    let released = false

    const handle = {
        listen(event, callback) {
            if (released) return handle
            handlers.push([event, callback])
            Echo.private(name).listen(event, callback)
            return handle
        },
        notification(callback) {
            return handle.listen(NOTIFICATION_EVENT, callback)
        },
        release() {
            if (released) return
            released = true

            const channel = Echo.private(name)
            handlers.forEach(([event, callback]) => channel.stopListening(event, callback))
            handlers.length = 0

            const remaining = (holders.get(name) ?? 1) - 1
            if (remaining > 0) {
                holders.set(name, remaining)
            } else {
                holders.delete(name)
                Echo.leave(name)
            }
        },
    }

    return handle
}

export function acquireCounsellorChannel(counsellorId) {
    return counsellorId ? acquireChannel(`counsellors.${counsellorId}`) : null
}
