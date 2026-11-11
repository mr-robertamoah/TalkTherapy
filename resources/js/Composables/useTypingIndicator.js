// SCRUM-68: "who is typing" in a chat with several people -- a group therapy's session chat and a
// discussion. Whispered on the chat's PRESENCE channel, because there Reverb stamps every whisper with the
// sender's own member id (config/reverb.php: accept_client_events_from = members), which a browser can't
// forge. The name shown is that member's name from the channel's member list -- what the server's
// authorisation gave them, a masked one for an anonymous group member (SCRUM-379) -- never anything in the
// whisper. The whisper itself carries no identity at all: only what to match it to (a group's session).
//
// One instance per chat. attach() each time a new channel handle is acquired (its listeners go with the
// handle on release); typing() while the person types, stopped() when they send; reset() on switching
// chats; dispose() on unmount.
import { computed, ref } from 'vue'
import _ from 'lodash'

const WHISPER_EVERY_MS = 2000
const FORGET_AFTER_MS = 5000

export function typingLabel(names) {
    if (!names.length) return ''
    if (names.length === 1) return `${names[0]} is typing…`
    if (names.length === 2) return `${names[0]} and ${names[1]} are typing…`
    const others = names.length - 2
    return `${names[0]}, ${names[1]} and ${others} ${others === 1 ? 'other' : 'others'} are typing…`
}

export default function useTypingIndicator({ payload = () => ({}), accepts = () => true } = {}) {
    // member id -> { name, timer }, in the order they started typing.
    const typists = ref(new Map())
    let handle = null

    const label = computed(() => typingLabel([...typists.value.values()].map((typist) => typist.name)))

    function forget(id) {
        const typist = typists.value.get(id)
        if (!typist) return
        clearTimeout(typist.timer)
        const next = new Map(typists.value)
        next.delete(id)
        typists.value = next
    }

    function heard(id, stopped) {
        if (stopped) return forget(id)

        const known = typists.value.get(id)
        if (known) clearTimeout(known.timer)

        const next = new Map(typists.value)
        next.set(id, {
            name: known?.name ?? handle?.member(id)?.name ?? 'Someone',
            timer: setTimeout(() => forget(id), FORGET_AFTER_MS),
        })
        typists.value = next
    }

    function attach(channelHandle) {
        reset()
        handle = channelHandle
        if (!handle) return

        handle.listenForWhisper('typing', (data, metadata) => {
            const id = metadata?.user_id
            // Unstamped (not from a member), another chat's, or this person's own (another tab of theirs).
            if (!id || !accepts(data ?? {}) || id === handle?.me()) return
            heard(id, !!data?.stopped)
        })
        // Gone from the chat: not typing any more.
        handle.leaving(() => {
            typists.value.forEach((typist, id) => { if (!handle?.member(id)) forget(id) })
        })
    }

    // Not before the channel is joined (me() is null until then): Reverb refuses a whisper from a
    // connection not yet on the channel.
    const typing = _.throttle(() => {
        if (!handle?.me()) return
        handle.whisper('typing', { ...payload() })
    }, WHISPER_EVERY_MS, { leading: true, trailing: true })

    // Sent their message: the others stop showing them at once, not after the timeout.
    function stopped() {
        typing.cancel()
        if (handle?.me()) handle.whisper('typing', { ...payload(), stopped: true })
    }

    function reset() {
        typists.value.forEach((typist) => clearTimeout(typist.timer))
        typists.value = new Map()
    }

    function dispose() {
        typing.cancel()
        reset()
        handle = null
    }

    return { label, attach, typing, stopped, reset, dispose }
}
