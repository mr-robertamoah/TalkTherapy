<script setup>
import { Head, Link } from '@inertiajs/vue3'
import ApplicationLogo from '@/Components/ApplicationLogo.vue'

// SCRUM-364: the user's address for now; it moves to info@talktherapy.org later -- one place to change.
const contactEmail = 'robertamoah.dev@gmail.com'

// SCRUM-361/364: TalkTherapy's privacy policy, written to match what the app actually does
// (SCRUM-338/340/359/360). The controller and contact details are the user's (2026-11-01). A plain layout, not AuthenticatedLayout: it must be readable by
// anyone, including a suspended account. Not yet reviewed by a lawyer -- see the notice below.

// SCRUM-368: from config (routes/web.php), so the policy can't drift from what the app does.
defineProps({
    retentionDays: { type: Object, required: true },
})
</script>

<template>
    <Head title="Privacy" />

    <div class="min-h-screen bg-gray-100 py-10 px-4">
        <div class="mx-auto w-full max-w-3xl">
            <div class="flex items-center justify-between">
                <Link :href="route('home')" aria-label="TalkTherapy home">
                    <ApplicationLogo class="w-14 h-14 fill-current text-gray-500" />
                </Link>
                <Link :href="route('home')" class="text-sm text-gray-700 underline hover:text-gray-900">Back to TalkTherapy</Link>
            </div>

            <article class="mt-6 bg-white shadow-sm border border-gray-200 rounded-lg p-6 sm:p-8 space-y-8 text-sm text-gray-700 leading-relaxed">
                <header>
                    <h1 class="text-2xl font-bold text-gray-900">Privacy policy</h1>
                    <div class="w-16 h-1 bg-blue-600 mt-2"></div>
                    <p class="mt-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-amber-900">
                        This policy describes how TalkTherapy handles your information today. It has not yet been reviewed
                        by a lawyer, and may change before TalkTherapy launches.
                    </p>
                    <nav aria-label="On this page" class="mt-4">
                        <ul class="flex flex-wrap gap-x-4 gap-y-1 text-gray-700">
                            <li><a href="#who-we-are" class="underline hover:text-gray-900">Who we are</a></li>
                            <li><a href="#what-we-collect" class="underline hover:text-gray-900">What we collect</a></li>
                            <li><a href="#why" class="underline hover:text-gray-900">Why</a></li>
                            <li><a href="#sharing" class="underline hover:text-gray-900">Who sees it</a></li>
                            <li><a href="#confidential-messages" class="underline hover:text-gray-900">Confidential messages</a></li>
                            <li><a href="#urgent-help" class="underline hover:text-gray-900">Urgent help</a></li>
                            <li><a href="#safety-flags" class="underline hover:text-gray-900">Safety concerns</a></li>
                            <li><a href="#administrator-access" class="underline hover:text-gray-900">Administrators</a></li>
                            <li><a href="#retention" class="underline hover:text-gray-900">How long we keep it</a></li>
                            <li><a href="#cookies" class="underline hover:text-gray-900">Cookies</a></li>
                            <li><a href="#rights" class="underline hover:text-gray-900">Your rights</a></li>
                            <li><a href="#contact" class="underline hover:text-gray-900">Contact</a></li>
                        </ul>
                    </nav>
                </header>

                <section id="who-we-are" class="space-y-2">
                    <h2 class="text-lg font-semibold text-gray-900">Who we are</h2>
                    <p>
                        TalkTherapy is run by <strong>TalkTherapy GH</strong>, in Ghana. TalkTherapy GH decides how your
                        personal information is used and is responsible for it under Ghana's Data Protection Act, 2012
                        (Act 843). You can reach us at
                        <a :href="`mailto:${contactEmail}`" class="underline hover:text-gray-900">{{ contactEmail }}</a>.
                    </p>
                </section>

                <section id="what-we-collect" class="space-y-2">
                    <h2 class="text-lg font-semibold text-gray-900">What we collect</h2>
                    <ul class="list-disc pl-5 space-y-1">
                        <li><strong>Your account:</strong> your name, username, email address, password (stored only in scrambled form), country, gender and date of birth.</li>
                        <li><strong>Your preferences:</strong> the languages, religions and concerns you choose, so we can suggest counsellors and therapies. Concerns and religion can say something about your health and beliefs, so please only share what you are comfortable with.</li>
                        <li><strong>Your therapy:</strong> the therapies and groups you start or join, their sessions and topics, your messages and the files you attach, and the notes a counsellor keeps about sessions and messages.</li>
                        <li><strong>Counsellors:</strong> your profile, and the licence and national ID documents you upload to be verified.</li>
                        <li><strong>Age and identity checks:</strong> the documents you upload when asked to confirm your age or identity, and the details of a guardian who looks after a minor's account.</li>
                        <li><strong>Payments:</strong> what was paid, by whom and for what. Card payments are handled by Paystack; we never see or store your full card number.</li>
                        <li><strong>Technical information:</strong> the network address and time of your visits (deleted every day), and — when an administrator reads content — the device and network address they used.</li>
                    </ul>
                </section>

                <section id="why" class="space-y-2">
                    <h2 class="text-lg font-semibold text-gray-900">Why we use it</h2>
                    <ul class="list-disc pl-5 space-y-1">
                        <li><strong>To provide TalkTherapy</strong> — connecting you with counsellors, running therapies, sessions, chat and video, and handling payments — because you asked us to.</li>
                        <li><strong>With your consent</strong> for anything sensitive you choose to share, such as your concerns, religion, and what you say in therapy. You can stop sharing it at any time.</li>
                        <li><strong>To keep people safe</strong> — verifying counsellors, checking ages, reviewing complaints, suspending accounts, and keeping records of who accessed what.</li>
                        <li><strong>To meet our legal obligations</strong>, such as keeping payment records.</li>
                    </ul>
                    <p>We do not sell your information, and we do not use it for advertising.</p>
                </section>

                <section id="sharing" class="space-y-2">
                    <h2 class="text-lg font-semibold text-gray-900">Who sees it</h2>
                    <ul class="list-disc pl-5 space-y-1">
                        <li><strong>Your counsellors and the members of your groups</strong> see what you share in those therapies. In an anonymous therapy or group, your name is hidden from the others.</li>
                        <li>
                            <strong>Other counsellors your counsellor consults.</strong> A counsellor may open a case discussion
                            about your therapy with other counsellors on TalkTherapy, to get advice. These discussions
                            are between counsellors only, and you don't see them.
                        </li>
                        <li><strong>A guardian</strong> of a minor is told about, and can approve, parts of the minor's use of TalkTherapy.</li>
                        <li><strong>Administrators</strong>, as described below.</li>
                        <li>
                            <strong>Service providers who work for us:</strong> Paystack (payments), Daily or Amazon Chime (video
                            calls), an email delivery provider (emails), and the cloud servers TalkTherapy runs on. They may
                            only use your information to provide that service to us.
                        </li>
                        <li><strong>Bunny Fonts</strong>, which serves the typeface our pages use, receives your network address when a page loads.</li>
                        <li><strong>Authorities</strong>, only where the law requires it.</li>
                    </ul>
                </section>

                <section id="confidential-messages" class="space-y-2">
                    <h2 class="text-lg font-semibold text-gray-900">Confidential messages</h2>
                    <p>
                        When you mark a message as confidential, only you and the person you sent it to can read it. Other
                        people in the conversation can't, even in a group.
                    </p>
                    <p>There is one exception: a complaint. If someone reports a conversation, an administrator may read confidential messages, but only:</p>
                    <ul class="list-disc pl-5 space-y-1">
                        <li>while the report is open or being reviewed;</li>
                        <li>in the conversation the report is about;</li>
                        <li>messages sent or received by the person the report is about — nobody else's;</li>
                        <li>if the administrator is not part of the report themselves; and</li>
                        <li>after writing down why they need to.</li>
                    </ul>
                    <p>
                        Every such read is recorded: which administrator, which messages, when, the reason they gave, and the
                        device and network address they used. These records can't be changed or deleted, and are kept
                        permanently. When the review closes, the person who reported and the person the report is about
                        are told whether confidential messages were read. Neither is told which administrator reviewed it.
                    </p>
                </section>

                <!-- SCRUM-33 (TT-4.4c): the quick help chat. -->
                <section id="urgent-help" class="space-y-2">
                    <h2 class="text-lg font-semibold text-gray-900">Urgent help</h2>
                    <p>
                        When you tap "I need help now" and ask for a counsellor, we keep a record of your request: when you asked, your
                        country and whether you were under 18 at the time, the one-line note you wrote (if any), whether you asked
                        anonymously, which counsellor answered, and how it ended. The emergency numbers shown are public numbers;
                        looking them up isn't recorded against you.
                    </p>
                    <p>
                        Counsellors who are asked see only a short summary: your first name (or "Anonymous"), whether you are an adult
                        or under 18, your country, your languages and your note. The counsellor who answers and you can both read the
                        chat afterwards. It isn't shared with your guardian or anyone else, and if the counsellor invites you to a
                        regular therapy afterwards, the chat isn't copied into it.
                    </p>
                    <p>
                        Administrators look after urgent help requests, but don't see who asked unless one of them chooses to look,
                        and every such look is recorded, as below. If an administrator needs to read an urgent help chat's messages
                        -- for example, to look into a report -- they can, in the same way as other conversations, and each read
                        is recorded too.
                    </p>
                    <!-- SCRUM-398/399 -->
                    <p>
                        A counsellor who was asked, or who answered, can report a request they believe was a prank or abusive.
                        Administrators review it seeing only "Anonymous" (or your first name, if you didn't ask anonymously), your note
                        and what happened -- not who you are -- unless one of them chooses to look (recorded, as above), and
                        a request with a report under review is kept until the review is finished. Being reported doesn't stop you
                        asking for help, and you aren't told about a report; if an account is suspended because of one, its owner is
                        told in the usual way.
                    </p>
                    <p>
                        Counsellors can choose to be alerted on their devices when someone needs urgent help (a browser
                        notification). The alert only says that someone needs help. It is passed, encrypted, through the
                        notification service of the counsellor's browser or phone maker, and carries only a reference number,
                        nothing about you. For this, we keep the address and a label (such as "Chrome on Android") of each
                        device a counsellor turns alerts on for, until they turn it off or sign out there.
                    </p>
                </section>

                <!-- SCRUM-32 (TT-4.3c): counsellors' safety flags. Pending the legal read. -->
                <section id="safety-flags" class="space-y-2">
                    <h2 class="text-lg font-semibold text-gray-900">When a counsellor is worried about your safety</h2>
                    <p>
                        If your counsellor thinks you may need more care than therapy here can give -- for example specialist or
                        inpatient care -- they can raise a safety concern with the TalkTherapy team. They record how urgent it is,
                        why they think so (kept encrypted), whether they talked it through with you, and whether emergency services
                        were contacted.
                    </p>
                    <p>
                        Administrators are alerted without your name or the reason. One administrator takes it on; only they can see
                        who it is about and why, every such look is recorded, and what they do and decide is kept in a record that
                        can't be changed. Administrators who are close to you -- for example your guardian, or someone in the same
                        group -- are never shown it. The concern doesn't change anything else you see in TalkTherapy.
                    </p>
                    <p>
                        You may be sent a short message in TalkTherapy (never by email) making sure you know how to get help, without
                        the reason. If you are under 18, your guardian may be asked to check in with you, unless there is a safeguarding
                        reason not to; the administrator decides. The record of the concern is kept; if you close your account, the
                        written reasons and notes in it are erased.
                    </p>
                </section>

                <section id="administrator-access" class="space-y-2">
                    <h2 class="text-lg font-semibold text-gray-900">What administrators can see</h2>
                    <p>
                        To keep TalkTherapy safe, administrators can open any therapy or group — including its background
                        story — and read the messages and attachments in any therapy, group or discussion, except
                        confidential messages, covered above. They can also see the licence and identity documents people
                        upload to be verified.
                    </p>
                    <p>
                        Every time an administrator opens one of these without being part of it themselves, it is
                        recorded: which administrator, what they opened, when, and the device and network address they
                        used. Opening the same file again within a few minutes is recorded once. These records can't be changed or deleted, are kept permanently, and can be reviewed by
                        senior administrators. Messages in public therapies, which anyone can read, are not recorded.
                    </p>
                </section>

                <section id="retention" class="space-y-2">
                    <h2 class="text-lg font-semibold text-gray-900">How long we keep it</h2>
                    <ul class="list-disc pl-5 space-y-1">
                        <li>Your account, preferences and therapy content are kept while your account is open.</li>
                        <li>
                            Deleting your account closes it: it can no longer be used or seen by others. {{ retentionDays.closedAccount }} days after it
                            is closed, your personal details are erased: your name, username, email address, date of birth,
                            gender, country, preferences and profile photo, along with your notifications, any identity document
                            still waiting for a check, and your counsellor profile's contact details. If your account is suspended,
                            or a complaint about you is still open, at that point, this happens once that is over.
                        </li>
                        <li>
                            Messages you sent stay in the conversations of the people you sent them to, shown as from a
                            "Deleted user", and the therapies you took part in stay part of other people's history. If you want
                            those erased as well, email us and we will remove or anonymise what we can, except the records
                            below, which we keep to account for what happened.
                        </li>
                        <li>
                            A counsellor profile is removed permanently {{ retentionDays.counsellorProfile }} days after it is deleted, with its uploaded
                            verification documents. A record that a licence was verified (its issuer and number) is kept.
                        </li>
                        <li>Payment records, complaint and suspension records, and records of administrators' access are kept even after an account is closed.</li>
                        <li>Records of administrators reading content are kept permanently and can't be changed.</li>
                        <li>
                            A counsellor's verification history (when they were verified, reminded, re-verified, when it ended, and any
                            revocation with the reason given) is kept permanently and can't be changed, as a record of their professional
                            standing. It names licences only by reference, never their numbers or documents, and only administrators see it.
                        </li>
                        <li>
                            An urgent help chat's messages, your note and any administrator's note on the request are deleted
                            {{ retentionDays.helpChat }} days after the chat ended (later only while a complaint about it is still
                            open, or a safety concern about it is still being dealt with). A record that the request happened, without them, is kept.
                        </li>
                        <li v-if="retentionDays.notifications">
                            Your notifications are deleted {{ retentionDays.notifications }} days after they were sent (a
                            counsellor profile's follow its owner's choice). You can choose a shorter or longer period in your
                            profile. A "check in" support message from the TalkTherapy team that you haven't dismissed yet is
                            kept until you do. Closing your account deletes your notifications as described above.
                        </li>
                        <li>Verification and identity documents are deleted on the schedule below.</li>
                    </ul>
                </section>

                <section id="documents" class="space-y-2">
                    <h2 class="text-lg font-semibold text-gray-900">Verification documents</h2>
                    <ul class="list-disc pl-5 space-y-1">
                        <li>Identity documents for age or identity checks are deleted {{ retentionDays.identityDocument }} days after the check is decided.</li>
                        <li>When a counsellor's verification is turned down, their documents are deleted {{ retentionDays.licenseRejected }} days later.</li>
                        <li>When it is accepted, the national ID scan and number are deleted {{ retentionDays.nationalId }} days later. The professional licence is kept as the record of what verified them.</li>
                        <li>Documents uploaded but never sent for verification are deleted {{ retentionDays.licenseAbandoned }} days after upload.</li>
                    </ul>
                    <p>A record that the document existed is kept, but the file itself is gone.</p>
                </section>

                <section id="suspensions" class="space-y-2">
                    <h2 class="text-lg font-semibold text-gray-900">Complaints and suspensions</h2>
                    <p>
                        After a complaint is reviewed, a senior administrator may suspend the person it was about: either
                        their whole account, or only their work as a counsellor, for a set time or until it is lifted.
                        The person is told why, until when, and how to appeal once; the appeal is decided by a different
                        administrator. They are never told who reported them.
                    </p>
                    <ul class="list-disc pl-5 space-y-1">
                        <li>While suspended, you can't delete your own account. This keeps a suspension from being avoided by deleting the account and signing up again.</li>
                        <li>A suspended counsellor's payouts are held until the suspension ends, including one requested just before. Nothing they earned is taken away.</li>
                        <li>A suspended counsellor's clients are told the counsellor is unavailable — not why.</li>
                        <li>Suspension records, and what happened to them, are kept.</li>
                    </ul>
                </section>
                <section id="cookies" class="space-y-2">
                    <h2 class="text-lg font-semibold text-gray-900">Cookies</h2>
                    <p>
                        We only use the cookies TalkTherapy needs to work: one that keeps you signed in, one that protects
                        your forms from being submitted by other websites, and, if you tick "Remember me", one that keeps
                        you signed in between visits. We don't use advertising or tracking cookies.
                    </p>
                </section>

                <section id="rights" class="space-y-2">
                    <h2 class="text-lg font-semibold text-gray-900">Your rights</h2>
                    <p>Under Ghana's Data Protection Act, you can ask us to:</p>
                    <ul class="list-disc pl-5 space-y-1">
                        <li>tell you what personal information we hold about you, and give you a copy;</li>
                        <li>correct it if it is wrong;</li>
                        <li>delete it, unless we have to keep it (see above);</li>
                        <li>stop using it for a particular purpose.</li>
                    </ul>
                    <p>
                        Email us to ask. If you're not happy with our answer, you can complain to Ghana's Data Protection
                        Commission.
                    </p>
                </section>

                <section id="children" class="space-y-2">
                    <h2 class="text-lg font-semibold text-gray-900">Children</h2>
                    <p>
                        Someone under 18 needs a guardian on TalkTherapy for some things, such as video calls. Their
                        guardian is told about their therapies and suspensions, and can approve requests on their behalf.
                    </p>
                </section>

                <section id="contact" class="space-y-2">
                    <h2 class="text-lg font-semibold text-gray-900">Contact and changes</h2>
                    <p>
                        Questions about this policy or your information:
                        <a :href="`mailto:${contactEmail}`" class="underline hover:text-gray-900">{{ contactEmail }}</a>
                        (TalkTherapy GH, Ghana). If we change this policy in a way that matters, we'll say so on this page
                        before the change takes effect.
                    </p>
                </section>
            </article>
        </div>
    </div>
</template>
