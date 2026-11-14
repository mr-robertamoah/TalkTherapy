// SCRUM-423: a page whose session has ended (signed out here or in another tab, or ended from another device by a
// password or two-factor change or a reset -- EndStaleSessions) leaves for the sign-in page and closes its live
// connection. Reverb can't close a person's connections from the server (SCRUM-386), so the page has to.
//
// Only for a page that was shown to someone signed in: a guest page's 401 ("sign in to like this") is left to its
// own handler. Once per page load, however many requests fail together.

let signedIn = false
let userId = null
let ended = false
let lastCheck = 0

const CHECK_GAP_MS = 15000

function disconnectLive() {
    try {
        window.Echo?.disconnect()
    } catch {
        // Already closed.
    }
}

// The session is gone: close the live connection and load the sign-in page afresh (a full load, as for /suspended,
// so nothing of this page lives on). The path is route('login'); Ziggy isn't loaded when bootstrap.js uses this.
export function handleSessionEnded() {
    if (!signedIn || ended) return
    ended = true
    disconnectLive()
    window.location.assign('/login?session=ended')
}

// A failed request on a signed-in page: 401 means the session has ended. 419 ("page expired") usually does too -- the
// CSRF token went with the session -- but not always (another tab signing in rotates it), so ask first.
export function handleRefusal(status) {
    if (status === 401) handleSessionEnded()
    else if (status === 419) checkSession({ force: true })
}

// Ask the server whether this page is still signed in; an ended session answers 401, which the axios interceptor
// (bootstrap.js) turns into handleSessionEnded. `force` for a nudge from the server; otherwise at most every 15s.
export function checkSession({ force = false } = {}) {
    if (!signedIn || ended) return
    const now = Date.now()
    if (!force && now - lastCheck < CHECK_GAP_MS) return
    lastCheck = now
    // As the person this page was shown to: on a shared browser someone else may have signed in since (security review).
    window.axios.get(window.route('session.check'), { params: { user: userId } }).catch(() => {})
}

// This browser's other tabs (they share its cookie): signing out here tells them to check at once. Not through the
// server -- that would reach the account's other devices too and keep their idle sessions alive (security review).
const tabs = typeof BroadcastChannel !== 'undefined' ? new BroadcastChannel('talktherapy-session') : null

export function announceSignOut() {
    tabs?.postMessage('signed-out')
}

function follow(page) {
    const wasSignedIn = signedIn
    const wasUser = userId
    signedIn = !!page?.props?.auth?.user
    userId = page?.props?.auth?.user?.id ?? null
    // Signed out, or someone else now signed in: nothing of the previous person's should keep arriving. Close the
    // live connection and load the new page afresh (code review: a closed connection otherwise stays closed after
    // signing in again in the same tab, and the old person's subscriptions would come back with it).
    if (wasSignedIn && (!signedIn || userId !== wasUser)) {
        disconnectLive()
        window.location.assign(page.url)
    }
}

// Called once from app.js with the first page, then follows every visit.
export function watchSession(router, initialPage) {
    follow(initialPage)
    router.on('navigate', (event) => follow(event.detail.page))

    // A page visit (not an axios call) refused because the session ended: a bare 401, or Laravel's "page expired"
    // (419) instead of the next page.
    router.on('invalid', (event) => {
        const status = event.detail.response?.status
        if (!signedIn) return
        if (status === 401) {
            event.preventDefault()
            handleSessionEnded()
        } else if (status === 419) {
            // Leave Laravel's "page expired" showing (QA: while still signed in, a quietly dropped save told them
            // nothing) and check: an ended session goes on to sign-in.
            checkSession({ force: true })
        }
    })

    tabs?.addEventListener('message', (event) => {
        if (event.data === 'signed-out') checkSession({ force: true })
    })

    // Coming back to a tab that sat in the background.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') checkSession()
    })
}
