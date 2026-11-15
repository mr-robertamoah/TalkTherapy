import { onUnmounted, watch } from 'vue'

// SCRUM-441: one scroll lock for every dialog (Modal and MiniModal). The page scrolls again only when the last open
// dialog closes -- a dialog opened over another (the journal's delete over its day) used to unlock the page behind
// the one still open.
let openDialogs = 0

export default function useScrollLock(isOpen) {
    let locked = false

    function set(open) {
        if (open === locked) return
        locked = open
        openDialogs = Math.max(0, openDialogs + (open ? 1 : -1))
        document.body.style.overflow = openDialogs ? 'hidden' : null
    }

    watch(isOpen, (open) => set(!!open), { immediate: true })
    onUnmounted(() => set(false))
}
