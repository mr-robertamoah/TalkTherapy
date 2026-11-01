import axios from 'axios'

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

export default async function resolveBroadcastMessage(message) {
    if (!message?.withheld) return message ?? null

    for (let attempt = 0; ; attempt++) {
        try {
            const response = await axios.get(route('api.messages.show', message.id))
            return response.data?.data ?? null
        } catch (err) {
            const status = err?.response?.status
            if ([401, 403, 404].includes(status) || attempt >= RETRY_DELAYS_MS.length) return null

            const retryAfter = Number(err?.response?.headers?.['retry-after'])
            await wait(retryAfter > 0 ? Math.min(retryAfter * 1000, 10000) : RETRY_DELAYS_MS[attempt])
        }
    }
}

// Handles one channel's broadcasts strictly in arrival order, even though each waits on a fetch --
// otherwise a later message could overtake an earlier one and be inserted above it. A message the
// viewer may not see is dropped. Usage: const enqueue = createBroadcastMessageQueue();
//                                       enqueue(data.message, (message) => addMessage(message))
export function createBroadcastMessageQueue() {
    let chain = Promise.resolve()

    return (message, handle) => {
        chain = chain
            .then(() => resolveBroadcastMessage(message))
            .then((resolved) => resolved && handle(resolved))
            .catch(() => {})
    }
}
