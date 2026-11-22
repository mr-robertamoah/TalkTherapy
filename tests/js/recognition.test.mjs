// TT-7.11d2/SCRUM-474: resources/js/Support/recognition.js -- run with `npm run test:js`.
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import {
    consentPill,
    decisionPill,
    recognitionAlerts,
    recognitionEventLabel,
    STAR_CAP_FIELDS,
} from '../../resources/js/Support/recognition.js'

describe('recognition alerts', () => {
    it('shows nothing for an eligible counsellor', () => {
        assert.deepEqual(recognitionAlerts({ verificationState: 'verified', suspended: false, openReport: false, deleted: false }), [])
        assert.deepEqual(recognitionAlerts(null), [])
    })

    it('lists every reason a decided counsellor is no longer eligible', () => {
        assert.deepEqual(
            recognitionAlerts({ verificationState: 'lapsed', suspended: true, openReport: true, deleted: true }).map((alert) => alert.label),
            ['deleted', 'suspended', 'open report', 'verification lapsed'],
        )
    })
})

describe('decision and consent pills', () => {
    it('labels an undecided row, and gives consent only once approved', () => {
        assert.equal(decisionPill(null).label, 'not decided')
        assert.equal(decisionPill('approved').tone, 'green')
        assert.deepEqual(decisionPill('skipped'), { label: 'skipped', tone: 'gray' })
        assert.equal(consentPill(null), null)
        assert.equal(consentPill('pending').label, 'consent pending')
        assert.deepEqual(consentPill('accepted'), { label: 'consent given', tone: 'green' })
        assert.deepEqual(consentPill('declined'), { label: 'consent declined', tone: 'gray' })
        assert.equal(consentPill('withdrawn').label, 'consent withdrawn')
    })
})

describe('event labels', () => {
    it('says what changed, and from what for a changed decision', () => {
        assert.equal(recognitionEventLabel({ event: 'approved', from: null }), 'approved')
        assert.equal(recognitionEventLabel({ event: 'skipped', from: 'approved' }), 'skipped (was approved)')
        assert.equal(recognitionEventLabel({ event: 'added', from: 'skipped' }), 'added by hand (was skipped)')
        assert.equal(recognitionEventLabel({ event: 'consent_withdrawn', from: 'accepted' }), 'withdrew consent')
    })

    it('falls back to the raw name for an event it does not know', () => {
        assert.equal(recognitionEventLabel({ event: 'something_new', from: 'approved' }), 'something_new')
        assert.equal(recognitionEventLabel(null), '')
    })
})

describe('star cap fields', () => {
    it('covers the six caps the server stores', () => {
        assert.deepEqual(
            STAR_CAP_FIELDS.map((field) => field.key),
            ['session', 'help_chat', 'discussion', 'post', 'session_per_client', 'session_per_group'],
        )
    })
})
