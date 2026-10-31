import axios from 'axios'

// SCRUM-195: message broadcasts go to a shared channel, so they never carry a confidential
// message's content (see MessageBroadcastTrait). Its recipient gets that redacted shape like
// everyone else -- this swaps it for the full message, fetched through api.messages.show, which
// applies the same per-viewer rules as a page load (so asking is always safe). A masked anonymous
// recipient can't be matched by id, so a masked confidential message is always asked about.
// Anything else is returned untouched, and a failed fetch falls back to the redacted message.
export default async function resolveBroadcastMessage(message, viewerId) {
    const mightBeForViewer = viewerId != null
        && (message?.toUserId == viewerId || message?.recipientMasked)
    const isRedacted = message?.confidential && !('content' in message)

    if (!isRedacted || !mightBeForViewer) return message

    try {
        const response = await axios.get(route('api.messages.show', message.id))
        return response.data?.data ?? message
    } catch (err) {
        return message
    }
}

// Handles one channel's broadcasts strictly in arrival order, even when one of them waits on a
// fetch -- otherwise a later message could overtake an earlier confidential one and be inserted
// above it. Usage: const enqueue = createBroadcastMessageQueue(userId);
//                  enqueue(data.message, (message) => addMessage(message))
export function createBroadcastMessageQueue(viewerId) {
    let chain = Promise.resolve()

    return (message, handle) => {
        chain = chain
            .then(() => resolveBroadcastMessage(message, viewerId))
            .then(handle)
            .catch(() => {})
    }
}
