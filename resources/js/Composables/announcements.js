import axios from 'axios'

// SCRUM-368: "announce, then fetch" broadcasts (was resolveBroadcastMessage.js, from when only
// messages worked this way).

// SCRUM-366/SCRUM-271: message broadcasts only announce a message -- its id and (masked) sender id,
// never its content (see MessageBroadcastTrait). Each page fetches the message itself from
// api.messages.show, which applies the same per-viewer rules as a page load: confidential redaction,
// anonymity masking, the strict payment gate, and suspension. A deleted-for-everyone broadcast
// carries no content and is used as it is.
//  - refused (403/404, or 401 signed out): the viewer may not see it -- nothing is shown (null);
//  - a temporary failure (429, 5xx, network): retried twice with a short backoff (honouring
//    Retry-After), so a busy chat or a blip doesn't silently lose a real message.
const RETRY_DELAYS_MS = [1000, 4000]

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

// SCRUM-367: shared by every "announce, then fetch" broadcast (messages, sessions).
export async function fetchAnnounced(url) {
    for (let attempt = 0; ; attempt++) {
        try {
            const response = await axios.get(url)
            return response.data?.data ?? null
        } catch (err) {
            const status = err?.response?.status
            if ([401, 403, 404].includes(status) || attempt >= RETRY_DELAYS_MS.length) return null

            const retryAfter = Number(err?.response?.headers?.['retry-after'])
            await wait(retryAfter > 0 ? Math.min(retryAfter * 1000, 10000) : RETRY_DELAYS_MS[attempt])
        }
    }
}

export async function resolveAnnouncedMessage(message) {
    if (!message?.withheld) return message ?? null

    return fetchAnnounced(route('api.messages.show', message.id))
}

// SCRUM-367: session broadcasts carry only the session's id ({ sessionId }) -- fetched like a
// message, so a viewer who has lost access (suspended, unpaid) gets null.
export function resolveAnnouncedSession(sessionId) {
    return sessionId ? fetchAnnounced(route('api.sessions.show', sessionId)) : Promise.resolve(null)
}

// Handles one channel's broadcasts strictly in arrival order, even though each waits on a fetch --
// otherwise a later message could overtake an earlier one and be inserted above it. A message the
// viewer may not see is dropped. Usage: const enqueue = createAnnouncementQueue();
//                                       enqueue(data.message, (message) => addMessage(message))
// Pass another resolver for other announcements, e.g. createAnnouncementQueue(resolveAnnouncedSession)
// and enqueue(sessionId, ...).
export function createAnnouncementQueue(resolve = resolveAnnouncedMessage) {
    let chain = Promise.resolve()

    return (message, handle) => {
        chain = chain
            .then(() => resolve(message))
            .then((resolved) => resolved && handle(resolved))
            .catch(() => {})
    }
}
