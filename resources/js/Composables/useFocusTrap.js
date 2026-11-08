// SCRUM-394: keyboard focus stays inside an open dialog (Tab and Shift+Tab wrap around), moves into it
// when it opens, and goes back to whatever opened it when it closes. Only the dialog opened last traps
// focus -- and (isTop) closes on Escape -- so a dialog opened from another works as expected.
// Limitation: content a dialog teleports elsewhere (an Alert toast, a popover) counts as outside it, so
// Tab from there returns into the dialog; a nested Modal/MiniModal is fine (it has its own trap).
import { nextTick, onUnmounted, watch } from 'vue'

const FOCUSABLE = ['a[href]', 'button:not([disabled])', 'input:not([disabled]):not([type="hidden"])', 'select:not([disabled])', 'textarea:not([disabled])', '[tabindex]']
    .map((selector) => `${selector}:not([tabindex="-1"])`).join(', ')

// The open dialogs, last opened last.
const stack = []

function focusables(container) {
    return [...container.querySelectorAll(FOCUSABLE)].filter((el) => el.getClientRects().length > 0)
}

export default function useFocusTrap(containerRef, show) {
    let opener = null
    const entry = {}
    const isTop = () => stack[stack.length - 1] === entry

    function onKeydown(event) {
        if (event.key !== 'Tab' || !isTop()) return
        const container = containerRef.value
        if (!container) return

        const items = focusables(container)
        if (!items.length) {
            event.preventDefault()
            container.focus()
            return
        }

        const first = items[0]
        const last = items[items.length - 1]
        // The dialog itself focused (a click on its text) counts as "not on an item" (review).
        const onItem = container.contains(document.activeElement) && document.activeElement !== container
        if (event.shiftKey && (document.activeElement === first || !onItem)) {
            event.preventDefault()
            last.focus()
        } else if (!event.shiftKey && (document.activeElement === last || !onItem)) {
            event.preventDefault()
            first.focus()
        }
    }

    function open() {
        opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
        stack.push(entry)
        document.addEventListener('keydown', onKeydown)
        nextTick(() => {
            const container = containerRef.value
            if (!container || container.contains(document.activeElement)) return
            // An autofocus field first, else the first focusable, else the dialog itself.
            const items = focusables(container)
            const target = items.find((el) => el.hasAttribute('autofocus')) ?? items[0] ?? container
            target.focus()
        })
    }

    function close() {
        const at = stack.indexOf(entry)
        if (at === -1) return
        stack.splice(at, 1)
        document.removeEventListener('keydown', onKeydown)
        // Back to the opener, if it's still on the page.
        if (opener && document.body.contains(opener)) opener.focus()
        opener = null
    }

    watch(show, (isOpen) => (isOpen ? open() : close()), { immediate: true })
    onUnmounted(close)

    return { isTop }
}
