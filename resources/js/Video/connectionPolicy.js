// TT-3.3b/SCRUM-459: every decision the video call makes about a weak or dropped connection, kept
// in one pure, dependency-free module (no Vue, no SDK, no axios) so it can be tested on its own
// (tests/js/connectionPolicy.test.mjs, plain `node --test`). The provider clients use it to turn
// their SDK's own events into the shared shapes below; useVideoSession.js uses it to decide whether
// and when to rejoin. Nothing here ever touches Session.status or attendance -- video never does.

// ---------------------------------------------------------------------------------------------
// Connection quality
// ---------------------------------------------------------------------------------------------

// The one quality scale both providers report on. 'unknown' is "no reading yet / unrecognised",
// never "still connecting" -- the panel shows it as such.
export const QUALITY_GOOD = 'good'
export const QUALITY_POOR = 'poor'
export const QUALITY_BAD = 'bad'
export const QUALITY_UNKNOWN = 'unknown'

const QUALITIES = [QUALITY_GOOD, QUALITY_POOR, QUALITY_BAD, QUALITY_UNKNOWN]

export function normalizeQuality(quality) {
    return QUALITIES.includes(quality) ? quality : QUALITY_UNKNOWN
}

// Daily's `network-quality-change` event carries networkState: 'good' | 'warning' | 'bad' |
// 'unknown' (daily-js 0.89 index.d.ts, DailyNetworkStats). Passing 'warning' straight through was
// SCRUM-459's bug: the panel didn't know it, so a weak connection read as "connecting…".
const DAILY_NETWORK_STATES = { good: QUALITY_GOOD, warning: QUALITY_POOR, bad: QUALITY_BAD }

export function dailyQuality(networkState) {
    return DAILY_NETWORK_STATES[networkState] ?? QUALITY_UNKNOWN
}

// Chime has no quality value, only AudioVideoObserver callbacks -- named here by callback.
const CHIME_QUALITY_CALLBACKS = {
    connectionDidBecomeGood: QUALITY_GOOD,
    connectionDidBecomePoor: QUALITY_POOR,
    connectionDidSuggestStopVideo: QUALITY_BAD,
}

export function chimeQuality(observerCallback) {
    return CHIME_QUALITY_CALLBACKS[observerCallback] ?? QUALITY_UNKNOWN
}

// In words, so the indicator never relies on its colour alone: "Connection: weak".
const QUALITY_LABELS = {
    [QUALITY_GOOD]: 'good',
    [QUALITY_POOR]: 'weak',
    [QUALITY_BAD]: 'very weak',
    [QUALITY_UNKNOWN]: 'unknown',
}

export function qualityLabel(quality) {
    return QUALITY_LABELS[normalizeQuality(quality)]
}

// A connection hovering on a threshold can flip every few seconds -- a screen reader is told at
// most once per this long, and nothing at all for a first reading or an unknown one.
export const QUALITY_ANNOUNCE_COOLDOWN_MS = 20000

// What to tell a screen reader about a quality change, or null for nothing. `announced` is the
// last quality the user was told about (QUALITY_GOOD before anything was said: a call that starts
// healthy needs no announcement); `sinceLastMs` is how long ago that was (Infinity if never).
export function qualityAnnouncement({ announced, next, sinceLastMs }) {
    const from = normalizeQuality(announced)
    const to = normalizeQuality(next)

    if (to === QUALITY_UNKNOWN || to === from) return null
    if (sinceLastMs < QUALITY_ANNOUNCE_COOLDOWN_MS) return null
    // Nothing was said about the unknown start, so a good first reading is no news either.
    if (from === QUALITY_UNKNOWN && to === QUALITY_GOOD) return null

    if (to === QUALITY_GOOD) return 'Your video connection is good again.'
    if (to === QUALITY_BAD) return 'Your video connection is very weak.'
    if (from === QUALITY_BAD) return 'Your video connection has improved but is still weak.'

    return 'Your video connection is weak.'
}

// ---------------------------------------------------------------------------------------------
// Unrequested stops
// ---------------------------------------------------------------------------------------------

// What an unrequested stop means for the call. Only OUTCOME_RECONNECT ever rejoins; the rest land
// in useVideoSession.js's existing status of the same name.
export const OUTCOME_RECONNECT = 'reconnect'
export const OUTCOME_ENDED = 'ended'
export const OUTCOME_REMOVED = 'removed'
export const OUTCOME_ERROR = 'error'

const GENERIC_STOP_MESSAGE = 'The video call stopped unexpectedly. You can try joining again.'
const ANOTHER_DEVICE_MESSAGE = 'You joined this video call somewhere else (another tab or device), so it stopped here.'
const CALL_FULL_MESSAGE = 'This video call is full.'

// An explicit ALLOW-list: a stop is recoverable only if it is named here. Anything else --
// including a type/code a future SDK version adds -- is terminal and never rejoined.
//
// Daily 'error' (fatal) types, DailyFatalErrorType in daily-js 0.89's index.d.ts:
//  - connection-error: the network went (Daily's own reconnect already gave up).
//  - exp-token: our meeting token expired; a rejoin mints a new one, or the server refuses if the
//    session is over (that refusal then lands in the error state).
export const DAILY_RECOVERABLE_ERROR_TYPES = Object.freeze(['connection-error', 'exp-token'])

// MeetingSessionStatusCode names (amazon-chime-sdk-js 3.32), as passed to audioVideoDidStop --
// which Chime only calls for these once its own reconnect has given up (reconnectTimeoutMs). All
// are non-terminal network/service failures a fresh attendee can recover from. Deliberately not
// MeetingSessionStatus.isTerminal(): that is the SDK's "I won't retry" list, not ours, and would
// silently widen if a new code were added. Left out on purpose: IncompatibleSDP (the browser,
// not the network) and EncodedTransformManagerFailed (local media processing).
export const CHIME_RECOVERABLE_STATUS_CODES = Object.freeze([
    'AudioInternalServerError',
    'AudioServiceUnavailable',
    'SignalingInternalServerError',
    'SignalingChannelClosedUnexpectedly',
    'ICEGatheringTimeoutWorkaround',
    'ConnectionHealthReconnect',
    'TaskFailed',
    'NoAttendeePresent',
])

// Terminal stops that map to a specific existing state rather than the generic error.
const DAILY_TERMINAL = {
    ejected: { outcome: OUTCOME_REMOVED },
    'exp-room': { outcome: OUTCOME_ENDED },
    'no-room': { outcome: OUTCOME_ENDED },
    'end-of-life': { outcome: OUTCOME_ENDED },
    'meeting-full': { outcome: OUTCOME_ERROR, message: CALL_FULL_MESSAGE },
}

const CHIME_TERMINAL = {
    AudioAttendeeRemoved: { outcome: OUTCOME_REMOVED },
    MeetingEnded: { outcome: OUTCOME_ENDED },
    TURNCredentialsForbidden: { outcome: OUTCOME_ENDED },
    AudioJoinedFromAnotherDevice: { outcome: OUTCOME_ERROR, message: ANOTHER_DEVICE_MESSAGE },
    AudioCallAtCapacity: { outcome: OUTCOME_ERROR, message: CALL_FULL_MESSAGE },
}

// The shape every client passes to onDisconnected (see useVideoSession.js's contract):
//   { recoverable, outcome, message, reason }
// `message` is for the error state only; `reason` is the provider's own type/code, for logs.
function classify(reason, recoverableList, terminalMap) {
    const key = typeof reason === 'string' ? reason : null

    if (key && recoverableList.includes(key)) {
        return { recoverable: true, outcome: OUTCOME_RECONNECT, message: '', reason: key }
    }

    const terminal = (key && Object.hasOwn(terminalMap, key)) ? terminalMap[key] : { outcome: OUTCOME_ERROR }

    return {
        recoverable: false,
        outcome: terminal.outcome,
        message: terminal.outcome === OUTCOME_ERROR ? (terminal.message ?? GENERIC_STOP_MESSAGE) : '',
        reason: key ?? 'unknown',
    }
}

// `type` is the Daily 'error' event's error.type.
export function classifyDailyStop(type) {
    return classify(type, DAILY_RECOVERABLE_ERROR_TYPES, DAILY_TERMINAL)
}

// `statusCodeName` is MeetingSessionStatusCode[status.statusCode()] -- the name, not the number,
// so the lists above read as the SDK's own docs do.
export function classifyChimeStop(statusCodeName) {
    return classify(statusCodeName, CHIME_RECOVERABLE_STATUS_CODES, CHIME_TERMINAL)
}

// Anything a client hands to onDisconnected that isn't a classified stop (an older client, an
// Error) is terminal -- never guessed into a reconnect.
export function normalizeStop(stop) {
    if (stop && stop.recoverable === true && stop.outcome === OUTCOME_RECONNECT) return stop

    const outcome = [OUTCOME_ENDED, OUTCOME_REMOVED].includes(stop?.outcome) ? stop.outcome : OUTCOME_ERROR

    return {
        recoverable: false,
        outcome,
        message: outcome === OUTCOME_ERROR ? (stop?.message || GENERIC_STOP_MESSAGE) : '',
        reason: stop?.reason ?? 'unknown',
    }
}

// ---------------------------------------------------------------------------------------------
// Rejoining after a recoverable stop
// ---------------------------------------------------------------------------------------------

// Up to three rejoins, each this long after the previous attempt (the first after the drop), all
// within one window. After that the call waits for the user's own "try again".
export const RECONNECT_DELAYS_MS = Object.freeze([2000, 5000, 10000])
export const RECONNECT_WINDOW_MS = 60000

// The wait before attempt number `attemptIndex` (0-based), or null when there is none left:
// all attempts used, or it would start after the window closes.
export function reconnectDelay(attemptIndex, elapsedMs) {
    if (!Number.isInteger(attemptIndex) || attemptIndex < 0 || attemptIndex >= RECONNECT_DELAYS_MS.length) return null

    const delay = RECONNECT_DELAYS_MS[attemptIndex]

    return elapsedMs + delay < RECONNECT_WINDOW_MS ? delay : null
}

// Whether a failed rejoin is worth another attempt. Only a network failure (no HTTP status), a
// server error (5xx) or "still joining you" (409) is. Everything else -- payment (402), refused or
// consent needed (403), validation (422), anything unrecognised -- is the server's answer, and
// retrying would only repeat it.
export function shouldRetryJoin({ httpStatus = null, consentRequired = false } = {}) {
    if (consentRequired) return false
    if (httpStatus == null) return true
    if (httpStatus === 409) return true

    return httpStatus >= 500 && httpStatus <= 599
}
