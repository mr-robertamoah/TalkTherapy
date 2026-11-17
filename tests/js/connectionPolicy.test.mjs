// TT-3.3b/SCRUM-459: resources/js/Video/connectionPolicy.js -- run with `npm run test:js`
// (plain node:test, no extra dependencies).
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import {
    CHIME_RECOVERABLE_STATUS_CODES,
    DAILY_RECOVERABLE_ERROR_TYPES,
    QUALITY_ANNOUNCE_COOLDOWN_MS,
    RECONNECT_DELAYS_MS,
    RECONNECT_WINDOW_MS,
    chimeQuality,
    classifyChimeStop,
    classifyDailyStop,
    dailyQuality,
    normalizeQuality,
    normalizeStop,
    qualityAnnouncement,
    qualityLabel,
    reconnectDelay,
    shouldRetryJoin,
} from '../../resources/js/Video/connectionPolicy.js'

const root = fileURLToPath(new URL('../../', import.meta.url))

describe('connection quality', () => {
    it('maps Daily network states onto the shared scale', () => {
        assert.equal(dailyQuality('good'), 'good')
        assert.equal(dailyQuality('warning'), 'poor')
        assert.equal(dailyQuality('bad'), 'bad')
        assert.equal(dailyQuality('unknown'), 'unknown')
        assert.equal(dailyQuality(undefined), 'unknown')
        assert.equal(dailyQuality('very-low'), 'unknown')
    })

    it('maps Chime observer callbacks onto the shared scale', () => {
        assert.equal(chimeQuality('connectionDidBecomeGood'), 'good')
        assert.equal(chimeQuality('connectionDidBecomePoor'), 'poor')
        assert.equal(chimeQuality('connectionDidSuggestStopVideo'), 'bad')
        assert.equal(chimeQuality('audioVideoDidStop'), 'unknown')
    })

    it('reads anything off the scale as unknown', () => {
        for (const q of ['good', 'poor', 'bad', 'unknown']) assert.equal(normalizeQuality(q), q)
        for (const q of ['warning', '', null, undefined, 3, 'constructor']) assert.equal(normalizeQuality(q), 'unknown')
    })

    it('labels quality in words, never "connecting"', () => {
        assert.equal(qualityLabel('good'), 'good')
        assert.equal(qualityLabel('poor'), 'weak')
        assert.equal(qualityLabel('bad'), 'very weak')
        assert.equal(qualityLabel('unknown'), 'unknown')
        assert.equal(qualityLabel('warning'), 'unknown')
    })
})

describe('quality announcements', () => {
    const long = QUALITY_ANNOUNCE_COOLDOWN_MS

    it('says nothing for a healthy start, an unknown reading or no change', () => {
        assert.equal(qualityAnnouncement({ announced: 'good', next: 'good', sinceLastMs: Infinity }), null)
        assert.equal(qualityAnnouncement({ announced: 'good', next: 'unknown', sinceLastMs: Infinity }), null)
        assert.equal(qualityAnnouncement({ announced: 'poor', next: 'poor', sinceLastMs: long }), null)
        assert.equal(qualityAnnouncement({ announced: 'unknown', next: 'good', sinceLastMs: Infinity }), null)
    })

    it('announces getting worse and recovering', () => {
        assert.equal(qualityAnnouncement({ announced: 'good', next: 'poor', sinceLastMs: Infinity }), 'Your video connection is weak.')
        assert.equal(qualityAnnouncement({ announced: 'poor', next: 'bad', sinceLastMs: long }), 'Your video connection is very weak.')
        assert.equal(qualityAnnouncement({ announced: 'bad', next: 'poor', sinceLastMs: long }), 'Your video connection has improved but is still weak.')
        assert.equal(qualityAnnouncement({ announced: 'poor', next: 'good', sinceLastMs: long }), 'Your video connection is good again.')
    })

    it('stays quiet within the cooldown, so a flapping connection is not spammed', () => {
        assert.equal(qualityAnnouncement({ announced: 'poor', next: 'good', sinceLastMs: long - 1 }), null)
        assert.equal(qualityAnnouncement({ announced: 'good', next: 'bad', sinceLastMs: 0 }), null)
    })
})

describe('Daily stop classification', () => {
    it('rejoins only the allow-listed types', () => {
        assert.deepEqual([...DAILY_RECOVERABLE_ERROR_TYPES], ['connection-error', 'exp-token'])
        for (const type of DAILY_RECOVERABLE_ERROR_TYPES) {
            const stop = classifyDailyStop(type)
            assert.equal(stop.recoverable, true)
            assert.equal(stop.outcome, 'reconnect')
        }
    })

    it('lands every other fatal type in an existing terminal state', () => {
        assert.equal(classifyDailyStop('ejected').outcome, 'removed')
        assert.equal(classifyDailyStop('exp-room').outcome, 'ended')
        assert.equal(classifyDailyStop('no-room').outcome, 'ended')
        assert.equal(classifyDailyStop('end-of-life').outcome, 'ended')
        assert.equal(classifyDailyStop('meeting-full').outcome, 'error')
        assert.equal(classifyDailyStop('meeting-full').message, 'This video call is full.')
        for (const type of ['ejected', 'exp-room', 'no-room', 'end-of-life', 'meeting-full', 'not-allowed', 'nbf-room', 'nbf-token']) {
            assert.equal(classifyDailyStop(type).recoverable, false, type)
        }
    })

    it('treats an unknown or missing type as terminal', () => {
        for (const type of ['something-new', undefined, null, '', 'toString', '__proto__']) {
            const stop = classifyDailyStop(type)
            assert.equal(stop.recoverable, false)
            assert.equal(stop.outcome, 'error')
            assert.ok(stop.message.length > 0)
        }
    })

    it('only names types the installed daily-js actually has', { skip: !existsSync(`${root}node_modules/@daily-co/daily-js/index.d.ts`) }, () => {
        const dts = readFileSync(`${root}node_modules/@daily-co/daily-js/index.d.ts`, 'utf8')
        const union = dts.match(/export type DailyFatalErrorType =([^;]+);/)
        assert.ok(union, 'DailyFatalErrorType not found')
        const types = [...union[1].matchAll(/'([^']+)'/g)].map((m) => m[1])
        for (const type of [...DAILY_RECOVERABLE_ERROR_TYPES, 'ejected', 'exp-room', 'no-room', 'end-of-life', 'meeting-full']) {
            assert.ok(types.includes(type), `${type} is not a DailyFatalErrorType`)
        }
    })
})

describe('Chime stop classification', () => {
    it('rejoins only the allow-listed status codes', () => {
        assert.deepEqual([...CHIME_RECOVERABLE_STATUS_CODES], [
            'AudioInternalServerError',
            'AudioServiceUnavailable',
            'SignalingInternalServerError',
            'SignalingChannelClosedUnexpectedly',
            'ICEGatheringTimeoutWorkaround',
            'ConnectionHealthReconnect',
            'TaskFailed',
            'NoAttendeePresent',
        ])
        for (const code of CHIME_RECOVERABLE_STATUS_CODES) {
            assert.equal(classifyChimeStop(code).recoverable, true, code)
        }
    })

    it('lands terminal codes in an existing terminal state', () => {
        assert.equal(classifyChimeStop('AudioAttendeeRemoved').outcome, 'removed')
        assert.equal(classifyChimeStop('MeetingEnded').outcome, 'ended')
        assert.equal(classifyChimeStop('TURNCredentialsForbidden').outcome, 'ended')
        assert.equal(classifyChimeStop('AudioJoinedFromAnotherDevice').outcome, 'error')
        assert.match(classifyChimeStop('AudioJoinedFromAnotherDevice').message, /another tab or device/)
        assert.equal(classifyChimeStop('AudioCallAtCapacity').message, 'This video call is full.')
        for (const code of ['Left', 'AudioAuthenticationRejected', 'AudioDisconnected', 'SignalingBadRequest',
            'SignalingRequestFailed', 'RealtimeApiFailed', 'IncompatibleSDP', 'EncodedTransformManagerFailed']) {
            const stop = classifyChimeStop(code)
            assert.equal(stop.recoverable, false, code)
            assert.equal(stop.outcome, 'error', code)
        }
    })

    it('treats an unknown or missing code as terminal', () => {
        for (const code of ['SomeFutureCode', undefined, 14, 'hasOwnProperty']) {
            assert.equal(classifyChimeStop(code).recoverable, false)
            assert.equal(classifyChimeStop(code).outcome, 'error')
        }
    })

    const chimePath = `${root}node_modules/amazon-chime-sdk-js/build/meetingsession/MeetingSessionStatus.js`

    it('only names codes the installed SDK has, and never one it treats as terminal', { skip: !existsSync(chimePath) }, () => {
        const require = createRequire(import.meta.url)
        const { MeetingSessionStatusCode } = require(`${root}node_modules/amazon-chime-sdk-js/build/meetingsession/MeetingSessionStatusCode.js`)
        const { default: MeetingSessionStatus } = require(chimePath)

        for (const name of [...CHIME_RECOVERABLE_STATUS_CODES, 'AudioAttendeeRemoved', 'MeetingEnded', 'TURNCredentialsForbidden', 'AudioJoinedFromAnotherDevice', 'AudioCallAtCapacity']) {
            assert.equal(typeof MeetingSessionStatusCode[name], 'number', `${name} is not a MeetingSessionStatusCode`)
        }
        for (const name of CHIME_RECOVERABLE_STATUS_CODES) {
            assert.equal(new MeetingSessionStatus(MeetingSessionStatusCode[name]).isTerminal(), false, `${name} is terminal in the SDK`)
        }
    })
})

describe('normalizeStop', () => {
    it('passes a classified recoverable stop through', () => {
        const stop = classifyDailyStop('connection-error')
        assert.deepEqual(normalizeStop(stop), stop)
    })

    it('treats an unclassified value as a terminal error, never a reconnect', () => {
        for (const raw of [undefined, null, new Error('boom'), { recoverable: 'yes' }, { outcome: 'reconnect' }, { recoverable: true, outcome: 'ended' }]) {
            const stop = normalizeStop(raw)
            assert.equal(stop.recoverable, false)
            assert.notEqual(stop.outcome, 'reconnect')
        }
        assert.equal(normalizeStop(new Error('boom')).outcome, 'error')
    })

    it('keeps a terminal ended/removed outcome', () => {
        assert.equal(normalizeStop(classifyChimeStop('MeetingEnded')).outcome, 'ended')
        assert.equal(normalizeStop(classifyDailyStop('ejected')).outcome, 'removed')
    })
})

describe('reconnect schedule', () => {
    it('is three attempts at 2s, 5s and 10s within a 60s window', () => {
        assert.deepEqual([...RECONNECT_DELAYS_MS], [2000, 5000, 10000])
        assert.equal(RECONNECT_WINDOW_MS, 60000)
        assert.equal(reconnectDelay(0, 0), 2000)
        assert.equal(reconnectDelay(1, 2500), 5000)
        assert.equal(reconnectDelay(2, 8000), 10000)
    })

    it('stops after the third attempt', () => {
        assert.equal(reconnectDelay(3, 0), null)
        assert.equal(reconnectDelay(-1, 0), null)
        assert.equal(reconnectDelay(1.5, 0), null)
    })

    it('never schedules an attempt past the window', () => {
        assert.equal(reconnectDelay(1, 55000), null)
        assert.equal(reconnectDelay(2, 50000), null)
        assert.equal(reconnectDelay(2, 49999), 10000)
        assert.equal(reconnectDelay(0, 60000), null)
    })
})

describe('retrying a failed rejoin', () => {
    it('retries network failures, 5xx and 409', () => {
        assert.equal(shouldRetryJoin({ httpStatus: null }), true)
        assert.equal(shouldRetryJoin({}), true)
        assert.equal(shouldRetryJoin(), true)
        assert.equal(shouldRetryJoin({ httpStatus: 409 }), true)
        for (const s of [500, 502, 503, 504, 599]) assert.equal(shouldRetryJoin({ httpStatus: s }), true, String(s))
    })

    it('never retries payment, consent, refusal or validation', () => {
        for (const s of [400, 401, 402, 403, 404, 419, 422, 429, 600]) assert.equal(shouldRetryJoin({ httpStatus: s }), false, String(s))
        assert.equal(shouldRetryJoin({ httpStatus: 503, consentRequired: true }), false)
        assert.equal(shouldRetryJoin({ httpStatus: null, consentRequired: true }), false)
    })
})
