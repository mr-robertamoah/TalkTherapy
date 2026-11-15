<script setup>
// SCRUM-50 (TT-8.2): "What we hold about you" -- the facts come from GetYourDataAction (counts, yes/no, dates, the
// person's own profile values); the words about what, why, how long and who can see it live here so they can be
// changed after the legal read (SCRUM-364). The periods are the privacy policy's own (RetentionPeriods).
import { computed, nextTick, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import TextInput from '@/Components/TextInput.vue'
import YourDataSection from './Partials/YourDataSection.vue'

const props = defineProps({
    data: { type: Object, required: true },
    retentionDays: { type: Object, required: true },
    // SCRUM-432: the same sentence the copy of their data gives (YourDataWording).
    otherRecords: { type: String, required: true },
})

// SCRUM-432: a copy of their data -- password each time, and the two-factor code when it's on. Fetched (not a form
// post) so a wrong password shows here, without leaving the page.
const downloadOpen = ref(false)
const downloading = ref(false)
const downloaded = ref(false)
const downloadForm = ref({ current_password: '', code: '' })
const downloadErrors = ref({})
const passwordInput = ref(null)
const downloadButton = ref(null)

function openDownload() {
    downloadOpen.value = true
    downloaded.value = false
    nextTick(() => passwordInput.value?.focus())
}

function closeDownload() {
    downloadOpen.value = false
    downloadForm.value = { current_password: '', code: '' }
    downloadErrors.value = {}
    // QA: back to the button that opened it.
    nextTick(() => downloadButton.value?.$el?.focus?.() ?? downloadButton.value?.focus?.())
}

async function download() {
    downloading.value = true
    downloadErrors.value = {}

    try {
        const response = await axios.post(route('profile.your-data.download'), downloadForm.value, { responseType: 'blob' })
        const name = /filename="?([^";]+)"?/.exec(response.headers['content-disposition'] ?? '')?.[1] ?? 'talktherapy-your-data.json'
        const url = URL.createObjectURL(response.data)
        const link = document.createElement('a')
        link.href = url
        link.download = name
        document.body.appendChild(link)
        link.click()
        link.remove()
        setTimeout(() => URL.revokeObjectURL(url), 1000)
        closeDownload()
        downloaded.value = true
    } catch (error) {
        const status = error.response?.status
        let body = {}
        try { body = JSON.parse(await error.response?.data?.text?.()) } catch { body = {} }

        if (status === 422) {
            downloadErrors.value = Object.fromEntries(Object.entries(body.errors ?? {}).map(([key, messages]) => [key, messages[0]]))
        } else if (status === 429) {
            downloadErrors.value = { form: 'You have asked for a copy too many times. Please try again in an hour.' }
        } else {
            downloadErrors.value = { form: 'Something went wrong. Please try again later.' }
        }
    } finally {
        downloading.value = false
    }
}

const contactHref = computed(() => `${route('privacy')}#contact`)
const profile = (anchor) => `${route('profile.show')}#${anchor}`

function plural(count, one, many) {
    return `${count} ${count === 1 ? one : many}`
}

function date(iso) {
    if (!iso) return ''
    return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })
}

// A date of birth is a calendar day, not an instant: never shift it by the viewer's time zone.
function day(value) {
    if (!value) return ''
    return new Date(`${value}T00:00:00Z`).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric', timeZone: 'UTC' })
}

function money(currency, minorUnits) {
    return `${currency} ${(minorUnits / 100).toFixed(2)}`
}

function words(value) {
    if (!value) return ''
    const text = String(value).replace(/_/g, ' ').toLowerCase()
    return text.charAt(0).toUpperCase() + text.slice(1)
}

function isPast(iso) {
    return iso && new Date(iso) <= new Date()
}

const requestStatus = {
    PENDING: 'waiting for a check',
    ACCEPTED: 'accepted',
    REJECTED: 'turned down',
}

const verification = {
    verified: 'verified',
    unverified: 'not verified yet',
    lapsed: 'ended',
    revoked: 'revoked',
}

// What an administrator opened (AdminContentAccess resources the person owns).
const opened = {
    therapy: 'Your therapy page and background story',
    session: "A session's details",
    session_messages: 'The messages in one of your sessions',
    topic_messages: 'The messages in one of your topics',
    message: 'A message you sent',
    message_replies: 'The replies to a message you sent',
    message_attachment: 'A file attached to a message you sent',
    license_document: 'Your licence or ID document',
    identity_document: 'Your age or identity document',
}

const account = computed(() => props.data.account)
const therapies = computed(() => props.data.therapies)
const counsellor = computed(() => props.data.counsellor)
</script>

<template>
    <Head title="What we hold about you" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">What we hold about you</h2>
        </template>

        <div class="py-10 bg-gray-50 min-h-screen">
            <div class="mx-auto w-full max-w-3xl px-4 space-y-6">
                <div class="bg-white shadow-sm border border-gray-200 rounded-lg p-6 text-sm text-gray-700 space-y-3">
                    <p>
                        This page lists the information TalkTherapy keeps about you, why we keep it, how long for, and who
                        can see it. It only ever shows your own information.
                    </p>
                    <p>
                        To ask us to correct or delete your data,
                        <a :href="contactHref" class="text-indigo-700 underline hover:text-indigo-900">contact us</a>.
                        Our <Link :href="route('privacy')" class="text-indigo-700 underline hover:text-indigo-900">privacy policy</Link>
                        explains all of this in more detail.
                    </p>
                    <p class="text-xs text-gray-500">This page is awaiting legal review, and its wording may change.</p>
                </div>

                <!-- SCRUM-432 -->
                <section id="download" aria-labelledby="download-title" class="bg-white shadow-sm border border-gray-200 rounded-lg p-6 text-sm text-gray-700">
                    <h2 id="download-title" class="text-lg font-semibold text-gray-900">Download a copy</h2>
                    <p class="mt-2">
                        A file (JSON) with what this page lists, in more detail: your profile, therapies, groups and
                        sessions (times and status), the messages you sent, your journal, your payments, and when the team opened your
                        content. It has only what is yours -- never anyone else's messages. Files you attached are listed
                        by name, not included.<template v-if="counsellor"> Messages you sent as a counsellor are about your
                        clients, so they are counted, not included.</template>
                    </p>

                    <p v-if="!data.security.passwordSet" class="mt-3">
                        Your account has no password, so we can't check it's you here.
                        <a :href="contactHref" class="text-indigo-700 underline hover:text-indigo-900">Contact us</a> for a copy.
                    </p>
                    <p v-else-if="account.pendingEmail" class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-amber-900">
                        A new email address is waiting on your account. You can download a copy once that change is
                        finished or stopped.
                    </p>
                    <template v-else>
                        <p v-if="downloaded" role="status" class="mt-3 rounded-md border border-green-200 bg-green-50 p-3 text-green-900">
                            Your copy has downloaded. We've let you know on your account{{ account.emailVerified ? ' and by email' : '' }}.
                        </p>

                        <div v-if="!downloadOpen" class="mt-4">
                            <PrimaryButton ref="downloadButton" type="button" @click="openDownload">Download a copy</PrimaryButton>
                        </div>

                        <form v-else class="mt-4 max-w-sm space-y-4" @submit.prevent="download">
                            <p>To keep it safe, confirm it's you{{ data.security.twoFactorEnabled ? ' with your password and a two-step sign-in code' : ' with your password' }}. We'll let you know on your account{{ account.emailVerified ? ' and by email' : '' }} each time a copy is downloaded.</p>
                            <div>
                                <InputLabel for="download-password" value="Password" />
                                <TextInput id="download-password" ref="passwordInput" v-model="downloadForm.current_password" type="password" class="mt-1 block w-full" autocomplete="current-password" required />
                                <InputError class="mt-2" :message="downloadErrors.current_password" />
                            </div>
                            <div v-if="data.security.twoFactorEnabled">
                                <InputLabel for="download-code" value="Two-step sign-in code (or a recovery code)" />
                                <TextInput id="download-code" v-model="downloadForm.code" type="text" class="mt-1 block w-full" autocomplete="one-time-code" required />
                                <InputError class="mt-2" :message="downloadErrors.code" />
                            </div>
                            <InputError :message="downloadErrors.form" />
                            <div class="flex items-center gap-3">
                                <PrimaryButton :disabled="downloading" :class="{ 'opacity-25': downloading }">{{ downloading ? 'Preparing…' : 'Download' }}</PrimaryButton>
                                <SecondaryButton type="button" :disabled="downloading" @click="closeDownload">Cancel</SecondaryButton>
                            </div>
                        </form>
                    </template>
                </section>

                <YourDataSection id="account" title="Your account and profile" :link="{ href: profile('profile-info-id'), label: 'Change your profile' }">
                    <ul class="space-y-1">
                        <li>Name: {{ account.name || 'not set' }}</li>
                        <li>Username: {{ account.username || 'not set' }}</li>
                        <li>
                            Email: {{ account.email || 'not set' }}
                            <span v-if="account.email" class="text-gray-500">({{ account.emailVerified ? 'confirmed' : 'not confirmed yet' }})</span>
                        </li>
                        <li v-if="account.pendingEmail">A new email address waiting to be confirmed: {{ account.pendingEmail }}</li>
                        <li>Date of birth: {{ account.dob ? day(account.dob) : 'not set' }}</li>
                        <li>Gender: {{ account.gender ? words(account.gender) : 'not set' }}</li>
                        <li>Country: {{ account.country || 'not set' }}</li>
                        <li>Profile photo: {{ account.hasAvatar ? 'yes' : 'none' }}</li>
                    </ul>
                    <template #why>To run your account, show your profile, and check age where it matters (for example, whether you need a guardian).</template>
                    <template #kept>
                        While your account is open. If you delete it, these details are erased {{ retentionDays.closedAccount }} days after it is closed.
                    </template>
                    <template #who>
                        You and administrators. Others see your name or username where you take part, unless you take part anonymously.
                    </template>
                </YourDataSection>

                <YourDataSection id="security" title="Signing in and security" :link="{ href: profile('profile-password-id'), label: 'Security settings' }">
                    <ul class="space-y-1">
                        <li>Password: {{ data.security.passwordSet ? 'set (stored only in scrambled form)' : 'not set' }}</li>
                        <li>Two-step sign-in: {{ data.security.twoFactorEnabled ? 'on' : 'off' }}</li>
                        <li v-if="data.security.twoFactorResetDueAt">
                            A reset of your two-step sign-in is scheduled for {{ date(data.security.twoFactorResetDueAt) }}.
                        </li>
                    </ul>
                    <template #why>To keep your account yours.</template>
                    <template #kept>While your account is open.</template>
                    <template #who>Nobody can see your password, including the TalkTherapy team.</template>
                </YourDataSection>

                <YourDataSection id="therapies" title="Your therapies">
                    <ul class="space-y-1">
                        <li>{{ plural(therapies.active + therapies.anonymousActive, 'therapy', 'therapies') }} going on, and {{ therapies.ended + therapies.anonymousEnded }} ended.</li>
                        <li v-if="therapies.anonymousActive || therapies.anonymousEnded">
                            Of these, {{ therapies.anonymousActive + therapies.anonymousEnded }} anonymous
                            ({{ therapies.anonymousActive }} going on). Your counsellor sees you as anonymous in these.
                        </li>
                        <li v-if="therapies.deleted">
                            {{ plural(therapies.deleted, 'deleted therapy', 'deleted therapies') }}, still held.
                        </li>
                    </ul>
                    <template #why>To run your therapy with your counsellor.</template>
                    <template #kept>
                        While your account is open. If you close it, therapies you took part in stay part of your counsellor's history.
                    </template>
                    <template #who>
                        You and your counsellor, who may ask other counsellors for advice in a case discussion. A guardian of someone under 18 is told about their therapies. Administrators can open a therapy, and every time they do it is recorded (below).
                    </template>
                </YourDataSection>

                <YourDataSection id="sessions" title="Sessions">
                    <p>{{ plural(data.sessions.count, 'session', 'sessions') }} in your therapies, and in groups while you were in them.</p>
                    <template #why>To schedule and hold your sessions, and to say where an in-person one takes place.</template>
                    <template #kept>
                        While your account is open.<template v-if="retentionDays.sessionPin"> An in-person session's exact map pin is deleted
                        {{ retentionDays.sessionPin }} days after the session ends; its written landmark is kept.</template>
                    </template>
                    <template #who>
                        The people in that therapy or group, and administrators (recorded).
                    </template>
                </YourDataSection>

                <YourDataSection id="messages" title="Messages you sent">
                    <p>
                        {{ plural(data.messages.sent, 'message', 'messages') }}<template v-if="data.messages.sentAsCounsellor !== null">, and
                        {{ plural(data.messages.sentAsCounsellor, 'message', 'messages') }} as a counsellor in sessions</template>.
                    </p>
                    <!-- SCRUM-436: counted on their own line, never shown or given. -->
                    <p v-if="data.messages.inCaseDiscussions" class="mt-1">
                        As a counsellor you also wrote {{ plural(data.messages.inCaseDiscussions, 'message', 'messages') }} in case discussions with other counsellors.
                    </p>
                    <template #why>So the people you talk to can read them.</template>
                    <template #kept>
                        While your account is open. After you close it, they stay in other people's conversations, shown as from a "Deleted user".
                    </template>
                    <template #who>
                        The people in the conversation. A message marked confidential can only be read by the person you sent it to.
                    </template>
                </YourDataSection>

                <!-- SCRUM-36 -->
                <YourDataSection id="journal" title="Your journal" :link="{ href: route('profile.journal'), label: 'Open your journal' }">
                    <p>
                        {{ plural(data.journal.entries, 'entry', 'entries') }}<template v-if="data.journal.since">, the first written on {{ day(data.journal.since) }}</template>.
                    </p>
                    <template #why>So you can keep track of how you're doing. It's yours, and only there because you wrote it.</template>
                    <template #kept>Until you delete it, or close your account -- then it's deleted straight away.</template>
                    <template #who>Only you. Not your counsellor, a guardian, an organisation or the TalkTherapy team -- it is stored encrypted and there is no way to open it in the app but yours.</template>
                </YourDataSection>

                <!-- SCRUM-37 -->
                <YourDataSection v-if="data.checkIns.answered" id="check-ins" title="Check-ins you answered">
                    <p>{{ plural(data.checkIns.answered, 'check-in', 'check-ins') }} (short questionnaires your counsellor sent).</p>
                    <template #why>So you and your counsellor can see how things change over time.</template>
                    <template #kept>While your account is open. When it is erased, your answers and scores are deleted; your counsellor's record keeps that one was sent, and whether an answer needed their attention.</template>
                    <template #who>You and that therapy's counsellor. Not a guardian, an organisation or the TalkTherapy team.</template>
                </YourDataSection>

                <YourDataSection id="groups" title="Groups and organisations">
                    <ul class="space-y-1">
                        <li>
                            In {{ plural(data.groups.current, 'group', 'groups') }} now<template v-if="data.groups.anonymous"> ({{ data.groups.anonymous }} anonymously)</template>;
                            left {{ data.groups.left }}; started {{ data.groups.started }}.
                        </li>
                        <li v-for="(membership, index) in data.organizations.memberships" :key="`member-${index}-${membership.name}-${membership.status}`">
                            Member of {{ membership.name }} ({{ words(membership.status) }})
                        </li>
                        <li v-for="(organization, index) in data.organizations.administered" :key="`admin-${index}-${organization.name}-${organization.role}`">
                            {{ words(organization.role) }} of {{ organization.name }}
                        </li>
                    </ul>
                    <template #why>To run the groups you join and the organisations you belong to.</template>
                    <template #kept>While your account is open.</template>
                    <template #who>
                        The members and counsellors of a group see what you share there (not your name, if you joined anonymously). An organisation's administrators see who its members are.
                    </template>
                </YourDataSection>

                <YourDataSection id="guardianship" title="Guardians and wards" :link="{ href: profile('profile-guardianship-id'), label: 'Manage guardians' }">
                    <ul class="space-y-1">
                        <li>{{ plural(data.guardianship.guardians, 'guardian', 'guardians') }} looking after your account.</li>
                        <li>{{ plural(data.guardianship.wards, 'person', 'people') }} you look after as a guardian.</li>
                    </ul>
                    <template #why>Someone under 18 needs a guardian for some things, such as video calls.</template>
                    <template #kept>While the link is in place.</template>
                    <template #who>
                        You and the other person in the link. A guardian is told about, and can approve, parts of their ward's use of TalkTherapy.
                    </template>
                </YourDataSection>

                <YourDataSection id="payments" title="Payments">
                    <ul class="space-y-1">
                        <li v-if="!data.payments.transactions.length">No payments.</li>
                        <li v-for="row in data.payments.transactions" :key="`paid-${row.currency}`">
                            {{ plural(row.count, 'payment', 'payments') }} in {{ row.currency }}, {{ money(row.currency, row.paidAmount) }} of it paid.
                        </li>
                        <template v-if="data.payments.payouts">
                            <li v-if="!data.payments.payouts.length">No payouts to you as a counsellor.</li>
                            <li v-for="row in data.payments.payouts" :key="`payout-${row.currency}`">
                                {{ plural(row.count, 'payout', 'payouts') }} to you in {{ row.currency }}, {{ money(row.currency, row.paidAmount) }} of it paid out.
                            </li>
                        </template>
                    </ul>
                    <template #why>To take payments and pay counsellors, and because the law asks us to keep payment records.</template>
                    <template #kept>Payment records are kept even after an account is closed.</template>
                    <template #who>
                        You, the counsellor you paid, administrators, and Paystack, which handles card payments (we never see your full card number).
                    </template>
                </YourDataSection>

                <YourDataSection id="documents" title="Documents">
                    <ul class="space-y-2">
                        <li v-if="!data.documents.identity.length && !(data.documents.licenses || []).length">No documents.</li>
                        <li v-for="(check, index) in data.documents.identity" :key="`identity-${index}`">
                            Age or identity check sent {{ date(check.submittedAt) }} ({{ requestStatus[check.status] ?? words(check.status) }}).
                            <template v-if="!check.hasDocument">No document is held.</template>
                            <template v-else-if="check.deletesAt">
                                The document {{ isPast(check.deletesAt) ? 'is due to be deleted now' : `will be deleted on ${date(check.deletesAt)}` }}.
                            </template>
                            <template v-else>The document is kept until the check is decided.</template>
                        </li>
                        <li v-for="(license, index) in data.documents.licenses || []" :key="`license-${index}`">
                            {{ license.isNationalId ? 'National ID' : 'Licence' }}<template v-if="license.authority && !license.isNationalId"> from {{ license.authority }}</template>
                            ({{ license.validated ? 'checked' : 'not checked' }}).
                            <template v-if="license.documentsRemovedAt">Its document was deleted on {{ date(license.documentsRemovedAt) }}.</template>
                            <template v-else-if="!license.hasDocument">No document is held.</template>
                            <template v-else-if="license.deletesAt">
                                Its document {{ isPast(license.deletesAt) ? 'is due to be deleted now' : `will be deleted on ${date(license.deletesAt)}` }}.
                            </template>
                            <template v-else>Its document is kept as the record of your verification.</template>
                        </li>
                    </ul>
                    <template #why>To check ages, and to verify counsellors.</template>
                    <template #kept>
                        Identity documents: {{ retentionDays.identityDocument }} days after the check is decided. Counsellor documents:
                        {{ retentionDays.licenseRejected }} days after a turned-down verification; a national ID {{ retentionDays.nationalId }}
                        days after an accepted one; an upload never sent for verification, {{ retentionDays.licenseAbandoned }} days after upload.
                        A record that the document existed is kept.
                    </template>
                    <template #who>You and administrators (recorded).</template>
                </YourDataSection>

                <YourDataSection id="notifications" title="Notifications" :link="{ href: profile('profile-notifications-id'), label: 'Change your choices' }">
                    <p>
                        {{ plural(data.notifications.count, 'notification', 'notifications') }}<template v-if="data.notifications.counsellorCount !== null">,
                        and {{ data.notifications.counsellorCount }} on your counsellor profile</template>.
                    </p>
                    <!-- SCRUM-40 -->
                    <p v-if="data.notifications.switchedOff.length" class="mt-1">
                        You switched off:
                        <template v-for="(choice, i) in data.notifications.switchedOff" :key="choice.kind">{{ i ? '; ' : '' }}{{ choice.kind.toLowerCase() }} ({{ [choice.email && 'email', choice.inApp && 'in the app'].filter(Boolean).join(' and ') }})</template>.
                    </p>
                    <template #why>To tell you about things that need you.</template>
                    <template #kept>
                        <template v-if="data.notifications.retentionDays">Deleted {{ data.notifications.retentionDays }} days after they were sent.</template>
                        <template v-else>For the period you choose in your profile.</template>
                        A "check in" message from the TalkTherapy team is kept until you dismiss it.
                    </template>
                    <template #who>Only you.</template>
                </YourDataSection>

                <YourDataSection id="team-access" title="When the TalkTherapy team opened your content">
                    <p v-if="!data.teamAccess.total">No administrator has opened your therapies, messages or documents.</p>
                    <template v-else>
                        <p>
                            Administrators opened your content {{ plural(data.teamAccess.total, 'time', 'times') }}.
                            <template v-if="data.teamAccess.total > data.teamAccess.latest.length">The latest {{ data.teamAccess.latest.length }}:</template>
                        </p>
                        <ul class="mt-2 divide-y divide-gray-100 border border-gray-100 rounded-md">
                            <li v-for="(access, index) in data.teamAccess.latest" :key="index" class="flex flex-wrap justify-between gap-2 px-3 py-2">
                                <span>{{ opened[access.resource] ?? 'Your content' }}</span>
                                <span class="text-gray-500">{{ date(access.at) }}</span>
                            </li>
                        </ul>
                    </template>
                    <p class="mt-2 text-gray-600">
                        Not listed: opening a group's page, urgent help chats, and an administrator looking at who an urgent
                        help request or a safety concern is about (or the reason for a concern). The record doesn't say why
                        someone opened your therapy or messages, so those opens are listed whatever the reason.
                    </p>
                    <template #why>To keep TalkTherapy safe, and so every look is accounted for.</template>
                    <template #kept>Permanently. These records can't be changed or deleted.</template>
                    <template #who>Senior administrators. We don't show which administrator looked.</template>
                </YourDataSection>

                <YourDataSection v-if="counsellor" id="counsellor" title="As a counsellor">
                    <ul class="space-y-1">
                        <li>
                            Your counsellor profile, {{ verification[counsellor.verification] ?? counsellor.verification }}<template
                                v-if="counsellor.verification === 'verified' && counsellor.verificationExpiresAt"> until {{ date(counsellor.verificationExpiresAt) }}</template>.
                        </li>
                        <li>{{ plural(counsellor.clients, 'person', 'people') }} you have counselled in individual therapies.</li>
                    </ul>
                    <template #why>To show your profile to people looking for help, and to verify you.</template>
                    <template #kept>
                        Your profile is removed {{ retentionDays.counsellorProfile }} days after you delete it. Your verification history is kept permanently.
                    </template>
                    <template #who>Everyone can see your profile. Your verification history is seen only by administrators.</template>
                </YourDataSection>

                <div class="bg-white shadow-sm border border-gray-200 rounded-lg p-6 text-sm text-gray-700">
                    <h2 class="text-lg font-semibold text-gray-900">Other records</h2>
                    <p class="mt-2">
                        {{ otherRecords }} To ask about any of these,
                        <a :href="contactHref" class="text-indigo-700 underline hover:text-indigo-900">contact us</a>.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
