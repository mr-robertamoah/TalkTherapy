// SCRUM-389: a counsellor offering to help with a therapy or a group (and a client or a group's
// creator asking counsellors in) -- one place for the request and its refusal message. The server
// decides who may (EnsureCanSendTherapyAssistanceRequestAction / ...GroupTherapy...).
import axios from 'axios'

export function offerToHelp(kind, id, counsellorIds = []) {
    const url = kind === 'group'
        ? route('group.therapies.assist', { groupTherapyId: id })
        : route('therapies.assist', { therapyId: id })

    return axios.post(url, counsellorIds.length ? { counsellorIds } : {})
}

// The server's reason for a refusal, else a plain fallback.
export function refusalMessage(err, fallback = 'Something unfortunate happened. Please try again shortly.') {
    if (err?.response?.status === 429)
        return 'Too many requests. Please try again in a minute.'

    if (err?.response?.status === 422 || err?.response?.status === 403)
        return err.response.data?.message ?? err.response.data?.error ?? fallback

    return fallback
}
