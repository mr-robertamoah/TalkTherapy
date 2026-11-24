// TT-7.11f/SCRUM-476: resources/js/Support/recognitionConsent.js -- run with `npm run test:js`.
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import {
    badgeLabel,
    consentActionLabel,
    consentActions,
    consentDoneMessage,
    consentStateText,
    consentSummary,
} from '../../resources/js/Support/recognitionConsent.js'

const keys = (recognition) => consentActions(recognition).map((action) => action.key)

describe('the badge', () => {
    it('names the month', () => {
        assert.equal(badgeLabel('November 2026'), 'Counsellor of the month, November 2026')
        assert.equal(badgeLabel(null), 'Counsellor of the month')
    })
})

describe('consent actions', () => {
    it('answers a pending recognition through its request', () => {
        const actions = consentActions({ id: 1, consent: 'pending', consentRequestId: 9 })
        assert.deepEqual(actions.map((action) => [action.key, action.kind, action.value]), [
            ['accept', 'respond', 'accepted'],
            ['decline', 'respond', 'rejected'],
        ])
    })

    it('withdraws a pending one whose request is gone', () => {
        assert.deepEqual(keys({ consent: 'pending', consentRequestId: null }), ['withdraw'])
    })

    it('withdraws an accepted one and restores a declined or withdrawn one', () => {
        assert.deepEqual(keys({ consent: 'accepted' }), ['withdraw'])
        assert.equal(consentActions({ consent: 'accepted' })[0].value, 'withdraw')
        assert.deepEqual(keys({ consent: 'declined' }), ['restore'])
        assert.deepEqual(keys({ consent: 'withdrawn' }), ['restore'])
        assert.equal(consentActions({ consent: 'withdrawn' })[0].kind, 'consent')
    })

    it('offers nothing for anything else', () => {
        assert.deepEqual(keys({ consent: null }), [])
        assert.deepEqual(keys(null), [])
    })
})

describe('texts', () => {
    it('describes each state and each change', () => {
        for (const consent of ['pending', 'accepted', 'declined', 'withdrawn']) {
            assert.ok(consentStateText(consent).length > 0, consent)
        }
        assert.equal(consentStateText('nonsense'), '')
        assert.match(consentDoneMessage({ key: 'restore' }), /featured again/)
        assert.equal(consentDoneMessage(null), 'Saved.')
        assert.match(consentStateText('accepted'), /verified and in good standing/)
    })

    it('names the month on each button for screen readers', () => {
        const [withdraw] = consentActions({ consent: 'accepted' })
        assert.equal(consentActionLabel(withdraw, 'October 2026'), 'Stop featuring me for October 2026')
        assert.equal(consentActionLabel(withdraw, null), 'Stop featuring me')
        assert.equal(consentActionLabel(null, 'October 2026'), '')
    })

    it('summarises each state for Your Data', () => {
        assert.equal(consentSummary('accepted'), 'you agreed to be featured')
        assert.equal(consentSummary('withdrawn'), 'you stopped being featured')
        assert.equal(consentSummary('nonsense'), '')
    })
})
