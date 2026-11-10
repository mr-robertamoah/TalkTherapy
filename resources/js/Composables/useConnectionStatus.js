import { ref } from 'vue'

// Surfaces Echo/Reverb's underlying websocket connection state to the UI. The pusher-js client
// that Echo wraps (Reverb is Pusher-protocol-compatible) already retries the connection
// automatically -- this composable doesn't implement any reconnection logic itself, it only
// reports drops/recoveries so the user isn't left wondering why messages stopped arriving.
//
// Callers pass in their own onDisconnected/onReconnected callbacks (typically wired to that
// component's own useAlert() setters) rather than this composable calling useAlert() itself,
// since useAlert() is a factory that returns a fresh, unshared alertData ref on every call --
// calling it internally here would update an alertData instance not bound to the caller's
// visible <Alert> component.
export default function useConnectionStatus({ onDisconnected, onReconnected } = {}) {
    const isDown = ref(false)
    let handlers = null

    function bindConnectionStatus() {
        const connection = window.Echo?.connector?.pusher?.connection
        if (!connection || handlers) return

        handlers = {
            unavailable: () => handleDown(),
            disconnected: () => handleDown(),
            connected: () => handleUp(),
        }

        connection.bind('unavailable', handlers.unavailable)
        connection.bind('disconnected', handlers.disconnected)
        connection.bind('connected', handlers.connected)
    }

    function unbindConnectionStatus() {
        const connection = window.Echo?.connector?.pusher?.connection
        if (!connection || !handlers) return

        connection.unbind('unavailable', handlers.unavailable)
        connection.unbind('disconnected', handlers.disconnected)
        connection.unbind('connected', handlers.connected)
        handlers = null
    }

    function handleDown() {
        // Guards against firing repeatedly for the same state (e.g. 'unavailable' followed by
        // 'disconnected' without ever reconnecting in between).
        if (isDown.value) return

        isDown.value = true
        onDisconnected?.()
    }

    function handleUp() {
        // Only alert on recovery if we'd actually alerted on a drop -- a 'connected' event also
        // fires for the very first connection, which isn't a "recovery" worth alerting about.
        if (!isDown.value) return

        isDown.value = false
        onReconnected?.()
    }

    return { bindConnectionStatus, unbindConnectionStatus }
}
