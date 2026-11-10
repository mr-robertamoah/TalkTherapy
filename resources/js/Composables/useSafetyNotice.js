// SCRUM-32 (TT-4.3c) / SCRUM-39 (TT-5.4c): the persistent safety notice's state, shared by its dialog
// (SafetyNotice, at the app root) and the notification bell, which opens the same dialog for a safety
// notice instead of a link. Module-level, so it survives page visits. Only "I've read this" dismisses a
// notice (the list never marks one read).
import { ref } from 'vue'
import axios from 'axios'
import { safetyNoticeDismissed } from '@/Composables/useNotifications'

const notice = ref(null) // { id, message }

async function load({ enabled }) {
    if (!enabled) return
    await axios.get(route('safety_notice.current')).then((res) => { notice.value = res.data.notice }).catch(() => {})
}

// From the bell: the notice the person clicked (its message is the list's own text).
function open({ id, message }) {
    notice.value = { id, message }
}

async function dismiss() {
    const id = notice.value?.id
    notice.value = null
    if (id) await axios.post(route('safety_notice.dismiss', { notice: id })).catch(() => {})
    safetyNoticeDismissed(id)
}

// Escape, a click outside or "Get help now" only put it aside: it comes back on the next page.
function putAside() {
    notice.value = null
}

export default function useSafetyNotice() {
    return { notice, load, open, dismiss, putAside }
}
