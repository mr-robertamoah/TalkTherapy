// TT-3.3c/SCRUM-460: resources/js/Video/audioOnlyPolicy.js -- run with `npm run test:js`.
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import {
    AUTO_PAUSE,
    AUTO_PAUSE_POOR_MS,
    AUTO_RESTORE,
    AUTO_RESTORE_GOOD_MS,
    MAX_AUTO_RESTORES,
    MAX_NETWORK_PAUSE_TEXT,
    NETWORK_PAUSE_TOPIC,
    OVERLAY_AUDIO_ONLY,
    OVERLAY_CAMERA_OFF,
    OVERLAY_NETWORK,
    autoVideoDecision,
    dailyRemoteVideoOn,
    decodeNetworkPauseText,
    isVideoAllowed,
    networkPauseMessage,
    parseNetworkPauseMessage,
    remoteNetworkPauseChanged,
    remoteVideoChanged,
    tileOverlay,
} from '../../resources/js/Video/audioOnlyPolicy.js'

// A person with their camera on, allowed to send video, nothing paused.
const sending = { cameraOn: true, cameraWanted: true, videoAllowed: true, pausedByApp: false, restoresUsed: 0 }
// The same person after the app paused their camera.
const paused = { ...sending, cameraOn: false, pausedByApp: true }

describe('isVideoAllowed', () => {
    it('allows a counsellor, a 1:1 client or a group creator who can send video', () => {
        assert.equal(isVideoAllowed({ receiveOnly: false, canSendAudio: true, canSendVideo: true }), true)
    })

    it('allows a receive-only member only while they hold a grant that includes video', () => {
        assert.equal(isVideoAllowed({ receiveOnly: true, canSendAudio: true, canSendVideo: true }), true)
        assert.equal(isVideoAllowed({ receiveOnly: true, canSendAudio: false, canSendVideo: true }), false)
        assert.equal(isVideoAllowed({ receiveOnly: true, canSendAudio: false, canSendVideo: false }), false)
    })

    it('never allows the anonymous creator, a minor at "watch and listen", or anything malformed', () => {
        assert.equal(isVideoAllowed({ receiveOnly: false, canSendAudio: true, canSendVideo: false }), false)
        assert.equal(isVideoAllowed({ receiveOnly: true, canSendAudio: true, canSendVideo: false }), false)
        assert.equal(isVideoAllowed(null), false)
        assert.equal(isVideoAllowed(undefined), false)
        assert.equal(isVideoAllowed({}), false)
    })
})

describe('automatic pause', () => {
    it('pauses at once on a very weak connection', () => {
        assert.deepEqual(autoVideoDecision({ ...sending, quality: 'bad' }), { action: AUTO_PAUSE, inMs: 0 })
    })

    it('waits out a weak connection for 10 s first', () => {
        assert.deepEqual(autoVideoDecision({ ...sending, quality: 'poor', qualityForMs: 0 }), { action: AUTO_PAUSE, inMs: AUTO_PAUSE_POOR_MS })
        assert.deepEqual(autoVideoDecision({ ...sending, quality: 'poor', qualityForMs: 4000 }), { action: AUTO_PAUSE, inMs: 6000 })
        assert.deepEqual(autoVideoDecision({ ...sending, quality: 'poor', qualityForMs: 15000 }), { action: AUTO_PAUSE, inMs: 0 })
        assert.equal(AUTO_PAUSE_POOR_MS, 10000)
    })

    it('does nothing on a good or unknown reading', () => {
        for (const quality of ['good', 'unknown', 'warning', undefined]) {
            assert.equal(autoVideoDecision({ ...sending, quality, qualityForMs: 60000 }).action, null)
        }
    })

    it('leaves a camera the person turned off alone, with no notice', () => {
        assert.equal(autoVideoDecision({ ...sending, cameraOn: false, cameraWanted: false, quality: 'bad' }).action, null)
    })

    it('leaves a camera that was never allowed alone', () => {
        assert.equal(autoVideoDecision({ ...sending, cameraOn: false, cameraWanted: false, videoAllowed: false, quality: 'bad' }).action, null)
        assert.equal(autoVideoDecision({ ...sending, videoAllowed: false, quality: 'bad' }).action, null)
    })

    it('does nothing in the person\'s own audio-only mode', () => {
        assert.equal(autoVideoDecision({ ...sending, audioOnly: true, quality: 'bad' }).action, null)
    })

    it('does not undo the person turning video back on until the connection changes', () => {
        assert.equal(autoVideoDecision({ ...sending, autoPauseSuspended: true, quality: 'bad' }).action, null)
    })

    it('never pauses twice', () => {
        assert.equal(autoVideoDecision({ ...paused, quality: 'bad' }).action, null)
    })
})

describe('automatic restore', () => {
    it('restores after 30 s of a good connection', () => {
        assert.deepEqual(autoVideoDecision({ ...paused, quality: 'good', qualityForMs: 0 }), { action: AUTO_RESTORE, inMs: AUTO_RESTORE_GOOD_MS })
        assert.deepEqual(autoVideoDecision({ ...paused, quality: 'good', qualityForMs: 29000 }), { action: AUTO_RESTORE, inMs: 1000 })
        assert.deepEqual(autoVideoDecision({ ...paused, quality: 'good', qualityForMs: 45000 }), { action: AUTO_RESTORE, inMs: 0 })
        assert.equal(AUTO_RESTORE_GOOD_MS, 30000)
    })

    it('waits while the connection is anything but good', () => {
        for (const quality of ['poor', 'bad', 'unknown']) {
            assert.equal(autoVideoDecision({ ...paused, quality, qualityForMs: 60000 }).action, null)
        }
    })

    it('only restores a camera the app paused -- never one the person turned off', () => {
        assert.equal(autoVideoDecision({ ...paused, pausedByApp: false, quality: 'good', qualityForMs: 60000 }).action, null)
        assert.equal(autoVideoDecision({ ...paused, cameraWanted: false, quality: 'good', qualityForMs: 60000 }).action, null)
    })

    it('never restores once the server no longer allows video', () => {
        assert.equal(autoVideoDecision({ ...paused, videoAllowed: false, quality: 'good', qualityForMs: 60000 }).action, null)
    })

    it('never restores in audio-only mode', () => {
        assert.equal(autoVideoDecision({ ...paused, audioOnly: true, quality: 'good', qualityForMs: 60000 }).action, null)
    })

    it('stops restoring after two bounces in a call', () => {
        assert.equal(MAX_AUTO_RESTORES, 2)
        assert.equal(autoVideoDecision({ ...paused, restoresUsed: 1, quality: 'good', qualityForMs: 60000 }).action, AUTO_RESTORE)
        assert.equal(autoVideoDecision({ ...paused, restoresUsed: 2, quality: 'good', qualityForMs: 60000 }).action, null)
        assert.equal(autoVideoDecision({ ...paused, restoresUsed: 5, quality: 'good', qualityForMs: 60000 }).action, null)
    })

    it('still pauses after the cap -- only the restore is capped', () => {
        assert.equal(autoVideoDecision({ ...sending, restoresUsed: 2, quality: 'bad' }).action, AUTO_PAUSE)
    })

    it('treats a missing or nonsense duration as just started', () => {
        assert.deepEqual(autoVideoDecision({ ...paused, quality: 'good', qualityForMs: NaN }), { action: AUTO_RESTORE, inMs: AUTO_RESTORE_GOOD_MS })
        assert.deepEqual(autoVideoDecision({ ...paused, quality: 'good', qualityForMs: -5 }), { action: AUTO_RESTORE, inMs: AUTO_RESTORE_GOOD_MS })
        assert.equal(autoVideoDecision().action, null)
    })
})

describe('network-pause data message', () => {
    it('carries only a boolean, on its own topic', () => {
        assert.deepEqual(networkPauseMessage(true), { ttNetworkPause: true })
        assert.deepEqual(networkPauseMessage(false), { ttNetworkPause: false })
        assert.deepEqual(networkPauseMessage('yes'), { ttNetworkPause: false })
        assert.equal(NETWORK_PAUSE_TOPIC, 'tt-net-pause')
    })

    it('round-trips', () => {
        assert.equal(parseNetworkPauseMessage(networkPauseMessage(true)), true)
        assert.equal(parseNetworkPauseMessage(networkPauseMessage(false)), false)
        assert.equal(decodeNetworkPauseText(JSON.stringify(networkPauseMessage(true))), true)
        assert.equal(decodeNetworkPauseText(JSON.stringify(networkPauseMessage(false))), false)
    })

    it('ignores anything that is not exactly the message', () => {
        const malformed = [
            null, undefined, true, 'true', 1, [], [true],
            {},
            { ttNetworkPause: 'true' },
            { ttNetworkPause: 1 },
            { ttNetworkPause: null },
            { ttNetworkPause: true, participantKey: 'someone-else' },
            { ttNetworkPause: true, age: 15 },
            { other: true },
            Object.assign(Object.create({ inherited: 1 }), { ttNetworkPause: true }),
            new Date(),
        ]
        for (const data of malformed) assert.equal(parseNetworkPauseMessage(data), null, JSON.stringify(data))
    })

    it('accepts a prototype-less object (structured clone can produce one)', () => {
        assert.equal(parseNetworkPauseMessage(Object.assign(Object.create(null), { ttNetworkPause: true })), true)
    })

    it('ignores bad text', () => {
        for (const text of [null, undefined, '', 'not json', '{', 'true', '{"ttNetworkPause":"true"}', 42]) {
            assert.equal(decodeNetworkPauseText(text), null, String(text))
        }
        assert.equal(decodeNetworkPauseText(`{"ttNetworkPause":true${' '.repeat(MAX_NETWORK_PAUSE_TEXT)}}`), null)
        assert.equal(decodeNetworkPauseText('{"__proto__":{"x":1},"ttNetworkPause":true}'), null)
    })
})

describe('other people\'s tiles', () => {
    it('reads Daily remote video track states', () => {
        for (const state of ['playable', 'loading', 'sendable']) assert.equal(dailyRemoteVideoOn(state), true)
        for (const state of ['off', 'blocked']) assert.equal(dailyRemoteVideoOn(state), false)
        for (const state of ['interrupted', undefined, null, 'new-thing']) assert.equal(dailyRemoteVideoOn(state), null)
    })

    it('clears the network label once that person\'s video comes back', () => {
        let entry = remoteNetworkPauseChanged(undefined, true)
        assert.deepEqual(entry, { videoOff: false, networkPaused: true })

        // The message can arrive before the track goes off -- a still-on report keeps the label.
        entry = remoteVideoChanged(entry, true)
        assert.deepEqual(entry, { videoOff: false, networkPaused: true })

        entry = remoteVideoChanged(entry, false)
        assert.deepEqual(entry, { videoOff: true, networkPaused: true })

        entry = remoteVideoChanged(entry, true)
        assert.deepEqual(entry, { videoOff: false, networkPaused: false })
    })

    it('clears the network label on the person\'s own "back" message', () => {
        const entry = remoteNetworkPauseChanged({ videoOff: true, networkPaused: true }, false)
        assert.deepEqual(entry, { videoOff: true, networkPaused: false })
    })

    it('picks the tile overlay, the network reason first', () => {
        assert.equal(tileOverlay({ networkPaused: true, videoOff: true }), OVERLAY_NETWORK)
        assert.equal(tileOverlay({ networkPaused: true, videoOff: false }), OVERLAY_NETWORK)
        assert.equal(tileOverlay({ videoOff: true }), OVERLAY_CAMERA_OFF)
        assert.equal(tileOverlay({ receivingVideo: false }), OVERLAY_AUDIO_ONLY)
        assert.equal(tileOverlay({ videoOff: true, receivingVideo: false }), OVERLAY_CAMERA_OFF)
        assert.equal(tileOverlay({}), null)
        assert.equal(tileOverlay(), null)
    })
})
