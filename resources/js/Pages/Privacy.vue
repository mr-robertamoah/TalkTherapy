<script setup>
import { Head, Link } from '@inertiajs/vue3'
import ApplicationLogo from '@/Components/ApplicationLogo.vue'

// SCRUM-361: what TalkTherapy does with what you share, written to match what the app actually
// does (SCRUM-338/340/359/360). A plain layout, not AuthenticatedLayout: it must be readable by
// anyone, including a suspended account. Not yet reviewed by a lawyer -- see the notice below.
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
                    <h1 class="text-2xl font-bold text-gray-900">Privacy</h1>
                    <div class="w-16 h-1 bg-blue-600 mt-2"></div>
                    <p class="mt-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-amber-900">
                        This page describes how TalkTherapy handles your information today. It has not yet been reviewed
                        by a lawyer and will be replaced by a full privacy policy before TalkTherapy launches.
                    </p>
                </header>

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

                <section id="documents" class="space-y-2">
                    <h2 class="text-lg font-semibold text-gray-900">Verification documents</h2>
                    <ul class="list-disc pl-5 space-y-1">
                        <li>Identity documents for age or identity checks are deleted 30 days after the check is decided.</li>
                        <li>When a counsellor's verification is turned down, their documents are deleted 30 days later.</li>
                        <li>When it is accepted, the national ID scan and number are deleted 30 days later. The professional licence is kept as the record of what verified them.</li>
                        <li>Documents uploaded but never sent for verification are deleted 30 days after upload.</li>
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
            </article>
        </div>
    </div>
</template>
