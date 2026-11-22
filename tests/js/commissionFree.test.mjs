// TT-7.10b/SCRUM-468: resources/js/Support/commissionFree.js -- run with `npm run test:js`.
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { commissionFreeNoticeText, formatUtc } from '../../resources/js/Support/commissionFree.js'

describe('commission-free notice', () => {
    it('shows both ends in UTC, labelled GMT', () => {
        assert.equal(
            commissionFreeNoticeText({ startsAt: '2026-12-10T00:00:00Z', endsAt: '2026-12-17T09:30:00Z' }),
            'No platform commission on payments made between 10 Dec 2026, 00:00 GMT and 17 Dec 2026, 09:30 GMT',
        )
    })

    it('converts a time given with an offset to UTC', () => {
        assert.equal(formatUtc('2026-12-10T02:00:00+02:00'), '10 Dec 2026, 00:00 GMT')
    })

    it('handles a period with no end', () => {
        assert.equal(
            commissionFreeNoticeText({ startsAt: '2026-12-10T00:00:00Z', endsAt: null }),
            'No platform commission on payments made from 10 Dec 2026, 00:00 GMT',
        )
    })

    it('words a counsellor\'s own period as theirs', () => {
        assert.equal(
            commissionFreeNoticeText({ startsAt: '2026-12-01T00:00:00Z', endsAt: '2027-01-01T00:00:00Z', scope: 'personal' }),
            'No platform commission on your payments between 1 Dec 2026, 00:00 GMT and 1 Jan 2027, 00:00 GMT',
        )
        assert.equal(
            commissionFreeNoticeText({ startsAt: '2026-12-01T00:00:00Z', endsAt: null, scope: 'personal' }),
            'No platform commission on your payments from 1 Dec 2026, 00:00 GMT',
        )
    })

    it('words a platform-wide period, with or without a scope, for everyone', () => {
        assert.equal(
            commissionFreeNoticeText({ startsAt: '2026-12-10T00:00:00Z', endsAt: '2026-12-17T09:30:00Z', scope: 'platform' }),
            'No platform commission on payments made between 10 Dec 2026, 00:00 GMT and 17 Dec 2026, 09:30 GMT',
        )
    })

    it('is nothing without a period', () => {
        assert.equal(commissionFreeNoticeText(null), null)
        assert.equal(formatUtc(null), '')
        assert.equal(formatUtc('not a date'), '')
    })
})
