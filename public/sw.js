// SCRUM-399: TalkTherapy's service worker -- web push for urgent help rings ONLY. It caches nothing and handles
// no page requests: authenticated pages must never be stored on a (possibly shared) device. The words shown are
// fixed and generic -- nothing about the person ever travels through the push services.
const RING_TEXT = 'Someone needs urgent help. Open to see if you can answer.'
const CLOSED_TEXT = 'That request has been answered or has closed.'

self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()))

self.addEventListener('push', (event) => {
    let data = {}
    try {
        data = event.data ? event.data.json() : {}
    } catch (e) {
        data = {}
    }
    const tag = typeof data.tag === 'string' ? data.tag : 'help'

    if (data.type === 'withdraw') {
        // Only where the ring is still showing: replace it (same tag) quietly, then clear it -- the request no
        // longer needs anyone. A device that already dismissed it shows nothing (the browser tolerates the odd
        // silent push).
        event.waitUntil(self.registration.getNotifications({ tag }).then((showing) => {
            if (!showing.length) return undefined
            return self.registration.showNotification('TalkTherapy', { body: CLOSED_TEXT, tag, silent: true, icon: '/icon-192.png' })
                .then(() => new Promise((resolve) => setTimeout(resolve, 4000)))
                .then(() => self.registration.getNotifications({ tag }))
                .then((notifications) => notifications.forEach((notification) => notification.close()))
        }))
        return
    }

    event.waitUntil(self.registration.showNotification('TalkTherapy', {
        body: RING_TEXT,
        tag,
        renotify: true,
        requireInteraction: true,
        icon: '/icon-192.png',
    }))
})

// Opening it brings up the app, which checks with the server whether they're still waiting (the ring dialog).
self.addEventListener('notificationclick', (event) => {
    event.notification.close()
    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
        const open = windows.find((client) => new URL(client.url).origin === self.location.origin)
        if (open) {
            await open.focus()
            open.postMessage({ type: 'help-push-opened' })
            return
        }
        await self.clients.openWindow('/counsellor/dashboard')
    })())
})
