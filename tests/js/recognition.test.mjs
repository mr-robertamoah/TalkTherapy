// TT-7.11d2/SCRUM-474: resources/js/Support/recognition.js -- run with `npm run test:js`.
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import {
    consentPill,
    decisionPill,
    recognitionAlerts,
    recognitionEventLabel,
    STAR_CAP_FIELDS,
    waiverPrefill,
    waiverStatusPill,
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

// TT-7.11e2/SCRUM-477
describe('waiver prefill', () => {
    it('covers the next calendar month in UTC, with a reason naming the recognition month', () => {
        assert.deepEqual(waiverPrefill('October 2026', new Date('2026-11-22T09:30:00Z')), {
            starts_at: '2026-12-01T00:00',
            ends_at: '2027-01-01T00:00',
            reason: 'Counsellor of the month, October 2026',
        })
    })

    it('rolls December over into January of the next year', () => {
        const prefill = waiverPrefill('December 2026', new Date('2026-12-15T12:00:00Z'))

        assert.equal(prefill.starts_at, '2027-01-01T00:00')
        assert.equal(prefill.ends_at, '2027-02-01T00:00')
        assert.equal(prefill.reason, 'Counsellor of the month, December 2026')
    })

    it('goes by the UTC date, not the browser\'s', () => {
        // 23:30 on 31 January UTC is already 1 February in a UTC+1 browser -- still January in UTC.
        assert.equal(waiverPrefill('January 2027', new Date('2027-01-31T23:30:00Z')).starts_at, '2027-02-01T00:00')
        assert.equal(waiverPrefill('January 2027', new Date('2027-02-01T00:00:00Z')).starts_at, '2027-03-01T00:00')
    })
})

describe('waiver status pill', () => {
    it('labels each status, with a fallback', () => {
        assert.deepEqual(waiverStatusPill('upcoming'), { label: 'upcoming', tone: 'blue' })
        assert.deepEqual(waiverStatusPill('active'), { label: 'active', tone: 'green' })
        assert.deepEqual(waiverStatusPill('ended'), { label: 'ended', tone: 'gray' })
        assert.deepEqual(waiverStatusPill('cancelled'), { label: 'cancelled', tone: 'amber' })
        assert.deepEqual(waiverStatusPill('odd'), { label: 'odd', tone: 'gray' })
        assert.equal(waiverStatusPill(null).label, 'unknown')
    })
})
