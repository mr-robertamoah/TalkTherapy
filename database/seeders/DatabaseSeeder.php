<?php

namespace Database\Seeders;

use App\Enums\AdministratorTypeEnum;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorPayoutStatusEnum;
use App\Enums\CounsellorVerificationEventEnum;
use App\Enums\DiscussionStatusEnum;
use App\Enums\GenderEnum;
use App\Enums\LicensingTypeEnum;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationInvoiceStatusEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Enums\OrganizationMemberStatusEnum;
use App\Enums\RefundStatusEnum;
use App\Enums\RefundStatusSourceEnum;
use App\Enums\ReportOutcomeEnum;
use App\Enums\ReportStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\AdminContentAccess;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\CounsellorPayout;
use App\Models\CounsellorVerificationEvent;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\HowTo;
use App\Models\License;
use App\Models\LicensingAuthority;
use App\Models\Link;
use App\Models\Message;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationInvoiceLine;
use App\Models\OrganizationPaymentInstrument;
use App\Models\Refund;
use App\Models\Report;
use App\Models\ReportStatusHistory;
use App\Models\Request;
use App\Models\SafeguardingException;
use App\Models\Session;
use App\Models\Suspension;
use App\Models\Testimonial;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Support\CounsellorVerification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    // SCRUM-382: the one seeded account deliberately left without a date of birth.
    public const NO_DOB_DEMO_USERNAME = 'no_dob_demo_user';

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create super admin user
        $superAdmin = User::factory()->create([
            'username' => 'mr_robertamoah',
            'firstName' => 'Robert',
            'lastName' => 'Amoah',
            'email' => 'mr_robertamoah@yahoo.com',
            'password' => Hash::make(env('SUPER_PASSWORD', 'password')),
            'email_verified_at' => now(),
        ]);

        $superAdmin->administrator()->create([
            'verified_at' => now(),
            'type' => AdministratorTypeEnum::super->value,
        ]);

        // Create basic reference data
        $this->createLanguages($superAdmin);
        $this->createReligions($superAdmin);
        $this->createTherapyCases($superAdmin);
        $this->createProfessions($superAdmin);
        $this->createLicensingAuthorities($superAdmin);

        // Create demo users and counsellors
        $users = $this->createDemoUsers();
        $counsellors = $this->createDemoCounsellors($users);

        // Create demo therapies with sessions
        $this->createDemoTherapies($users, $counsellors);

        // Create demo group therapies
        $this->createDemoGroupTherapies($users, $counsellors);

        // Create demo discussions
        $this->createDemoDiscussions($counsellors);

        // Create deterministic "live" therapy/group therapy/discussion records for testing the
        // dedicated chat pages (SCRUM-20) -- the random data above only *might* produce an
        // in-session record on any given reseed, so these are named and always present.
        $this->createChatDemoData($users, $counsellors);

        // Create demo posts from counsellors
        $this->createDemoPosts($counsellors, $users);

        // SCRUM-134: dedicated accounts for testing counsellor account deletion -- kept separate
        // from the 6 main demo counsellors above since those are woven into therapies/group
        // therapies/discussions/chat demo data used by many other features.
        $this->createCounsellorDeletionDemoData();
        $this->createUnverifiedCounsellorDemoData();

        // SCRUM-157/158: dedicated PAID therapies (one PER_THERAPY, one PER_SESSION) for testing
        // the payment UI -- the random demo therapies above only *might* land on PAID, and never
        // deterministically pair the two payment models with a specific client/counsellor.
        $this->createPaymentDemoData();

        // SCRUM-165: a deterministic org-admin account + org exercising every section of the
        // new dashboard (both provider/consumer tables, an already-affiliated pending row, and
        // a pending request-queue item) -- the random demo data above never deterministically
        // produces an org admin to log in as.
        $this->createOrganizationDashboardDemoData();

        // TT-7.3b-followup/SCRUM-245: a deterministic billing-suspended org with a failed invoice
        // (payment instrument on file, so a retry can actually succeed) -- nothing above ever
        // produces a suspended org, so /administrator/organization-billing would otherwise always
        // show "no organizations are currently billing-suspended."
        $this->createBillingSuspendedOrganizationDemoData();

        // TT-7.6d/SCRUM-228: a deterministic counsellor with pending earnings but no payout
        // destination yet, for testing the onboarding-then-withdraw golden path on the counsellor
        // profile's new Payouts section -- nothing above deterministically produces a
        // CounsellorEarning row to withdraw.
        $this->createPayoutDemoData();

        // TT-7.7b/SCRUM-250: dedicated already-PAID (SUCCESS) therapies (one PER_THERAPY, one
        // PER_SESSION) for testing the refund-request UI -- nothing above deterministically
        // produces an already-successfully-paid engagement to request a refund for (the payment
        // demo data above is deliberately left unpaid, to test the Pay Now flow itself).
        $this->createRefundDemoData();

        // TT-7.4d-b/SCRUM-259: dedicated PAID GroupTherapies (one PER_THERAPY, one PER_SESSION)
        // with two members each -- one already paid, one not -- for testing that a group member's
        // own "pay now" control reflects THEIR OWN payment, not a co-member's. The random demo
        // group therapies above never deterministically produce a PAID group with more than one
        // member's payment state known in advance.
        $this->createGroupPaymentDemoData();

        // TT-7.5b-b6/SCRUM-270: a deterministic strict-gated GroupTherapy with an unpaid member
        // AND a late-joining member -- nothing above ever produces a group with strictPaymentGate
        // actually ON (createGroupPaymentDemoData()'s groups are trust-based/PAID-but-not-strict),
        // so there was previously no way to manually exercise the payment-required redirect/banner
        // or the late-joiner "free historical access" exemption without toggling a group's
        // settings by hand first.
        $this->createGroupStrictPaymentGateDemoData();

        // TT-3.1e-f/SCRUM-285: a deterministic minor client with a guardian, an individual
        // therapy already in PER_THERAPY consent mode, and an active session -- nothing above
        // deterministically produces a minor-client Therapy at all (User::factory()'s own default
        // leaves dob null, i.e. already a "minor" per isAdult(), but with no Guardianship and no
        // video_consent_mode set), so there was previously no way to exercise the Video Consent
        // tab, the guardian approve/revoke controls, or the consent-required video-join banner
        // without creating this relationship chain by hand first.
        $this->createGuardianVideoConsentDemoData();

        // TT-4.10e/SCRUM-294: the golden path (a minor WITH a guardian) is already reachable via
        // createGuardianVideoConsentDemoData()'s minor/guardian pair above, but there was no
        // seeded way to try the "no guardian exists -> any admin may respond" path without
        // hand-building a user and a Request via tinker first.
        $this->createDobChangeNoGuardianDemoData();

        // TT-4.10f/SCRUM-295: nor was there a seeded way to try "any one of a ward's multiple
        // guardians may respond, not just the one `to` names" without hand-building it first.
        $this->createDobChangeMultiGuardianDemoData();

        // TT-4.11e/SCRUM-306 closeout: no seeded demo user had a `dob` set at all, so trying the
        // age-verification golden path required hand-picking dobchange_demo_no_guardian (a
        // dobChange fixture, not built for this feature) or hand-building a user via tinker first.
        $this->createAgeVerificationDemoData();

        // TT-3.1f/SCRUM-279 closeout: every existing video-related seed (createGuardianVideoConsentDemoData)
        // is deliberately minor/consent-focused -- there was no plain, adult, no-consent-complexity
        // therapy+session for simply trying the base "join video" flow itself.
        $this->createVideoCallDemoData();
        $this->createGroupVideoCallDemoData();

        // SCRUM-340: reports at each review stage, on a group whose session holds confidential
        // messages, so /administrator/reports can be tried end to end (log in as the super admin).
        $this->createReportReviewDemoData($superAdmin);

        // SCRUM-359: a report ready to suspend from, a suspended counsellor, a suspended client with
        // an appeal waiting, and a suspension that has already ended.
        $this->createSuspensionDemoData();

        // SCRUM-360: a few admin read-log entries, so /administrator/content-accesses isn't empty.
        $this->createAdminReadLogDemoData($superAdmin);

        // SCRUM-367: two closed accounts, one past the retention period (erased by the next run of
        // the daily job, or `php artisan schedule:test`) and one closed yesterday (kept).
        $this->createClosedAccountDemoData();

        // SCRUM-288: a minor whose only guardian was removed -- their individual therapy and their
        // group are paused (read-only) until a guardian is added.
        $this->createPausedMinorDemoData();
        // SCRUM-381: a pending counsellor request for a safeguarding exception (for paused_minor), and
        // a second minor carrying on under one that ends in two days.
        $this->createSafeguardingExceptionDemoData($superAdmin);
        // SCRUM-387: pending counsellor and organisation verification requests, a failed payout and a
        // failed refund, for the admin area's queue and overview counts.
        $this->createAdminAreaDemoData($superAdmin);
        // SCRUM-387 (QA): testimonials and a how-to, an administrator who can be suspended, and a
        // super administrator who counsels a paused minor.
        $this->createAdminAreaQaDemoData($superAdmin);
        // SCRUM-73/383: a group to leave (an anonymous member and a minor member who can, and a
        // former member), with an active member link and counsellor link and someone to use each.
        $this->createLeaveGroupDemoData();
        // SCRUM-382: an adult who hasn't set a date of birth yet -- kept without one (below).
        $this->createNoDobDemoData();
        $this->giveDemoAdultsADateOfBirth();
        // SCRUM-31: every verified counsellor above gets an end date (as the backfill migration
        // does), then one counsellor in each renewal state.
        $this->giveVerifiedCounsellorsAnEndDate();
        $this->createCounsellorVerificationRenewalDemoData($superAdmin);
    }

    // SCRUM-31: the seeds above create counsellors with only verified_at, which now reads as ended
    // without an end date -- the same pass the backfill migration makes on a real database.
    private function giveVerifiedCounsellorsAnEndDate(): void
    {
        $expiresAt = now()->addMonths(CounsellorVerification::periodMonths());

        Counsellor::withTrashed()->whereNotNull('verified_at')->whereNull('verification_expires_at')->pluck('id')
            ->each(function (int $id) use ($expiresAt) {
                Counsellor::withTrashed()->whereKey($id)->update(['verification_expires_at' => $expiresAt]);
                CounsellorVerificationEvent::query()->create([
                    'counsellor_id' => $id,
                    'event' => CounsellorVerificationEventEnum::baseline,
                    'expires_at' => $expiresAt,
                    'dedupe_key' => "baseline:{$id}",
                ]);
            });
    }

    // SCRUM-31: renewal_due_counsellor (ends in 20 days, not reminded yet -- run the reminder sweep),
    // reverifying_counsellor (ends in 10 days, re-verification waiting with licences), lapsed_counsellor
    // (ended 3 days ago, still has an in-session client: renewal_demo_client) and revoked_counsellor
    // (revoked yesterday by the super admin, with a reason). Each with its history. Plus a public
    // therapy with no counsellor yet, by renewal_demo_client, to offer help on (refused for the
    // lapsed and revoked counsellors). Passwords: "password".
    private function createCounsellorVerificationRenewalDemoData(User $superAdmin): void
    {
        $counsellor = function (string $username, string $first, array $verification) {
            $user = User::factory()->create([
                'firstName' => $first,
                'lastName' => 'Renewal',
                'email' => str_replace('_', '.', $username).'@example.com',
                'username' => $username,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'gender' => GenderEnum::female->value,
                'dob' => now()->subYears(38)->toDateString(),
            ]);

            $counsellor = $user->counsellor()->create([
                'name' => "Dr. {$first} Renewal",
                'about' => 'Seeded counsellor for trying verification renewals (SCRUM-31).',
                'email' => $user->email,
                'phone' => fake()->phoneNumber(),
                'email_verified_at' => now(),
                'profession_id' => rand(1, 10),
                'contact_visible' => true,
            ]);
            $counsellor->forceFill(['verified_at' => now()->subYear(), ...$verification])->save();

            return $counsellor;
        };
        $event = fn (Counsellor $counsellor, CounsellorVerificationEventEnum $event, array $attributes = []) => CounsellorVerificationEvent::query()->create([
            'counsellor_id' => $counsellor->id, 'event' => $event, ...$attributes,
        ]);

        $due = $counsellor('renewal_due_counsellor', 'Due', ['verification_expires_at' => now()->addDays(20)]);
        $event($due, CounsellorVerificationEventEnum::verified, ['actor_user_id' => $superAdmin->id, 'expires_at' => $due->verification_expires_at, 'created_at' => now()->subYear()]);

        $reverifying = $counsellor('reverifying_counsellor', 'Reverifying', ['verification_expires_at' => now()->addDays(10)]);
        $event($reverifying, CounsellorVerificationEventEnum::verified, ['actor_user_id' => $superAdmin->id, 'expires_at' => $reverifying->verification_expires_at, 'created_at' => now()->subYear()]);
        $event($reverifying, CounsellorVerificationEventEnum::reminderSent, ['expires_at' => $reverifying->verification_expires_at, 'window_days' => 30, 'dedupe_key' => "reminder:{$reverifying->id}:30:{$reverifying->verification_expires_at->timestamp}", 'created_at' => now()->subDays(20)]);
        $licence = function (string $number, int $authorityId) use ($reverifying) {
            $license = new License(['number' => $number, 'validated' => false]);
            $license->licensing_authority_id = $authorityId;
            $license->addedby()->associate($reverifying);
            $license->for()->associate($reverifying);
            $license->save();

            return $license;
        };
        $authorities = LicensingAuthority::query()->orderBy('id')->limit(2)->pluck('id');
        $request = new Request([
            'type' => RequestTypeEnum::counsellor->value,
            'status' => RequestStatusEnum::pending->value,
            'data' => [
                'nationalIdLicense' => $licence('GHA-000333444-5', $authorities[0])->id,
                'otherLicense' => $licence('GPC-2025-0031', $authorities[1] ?? $authorities[0])->id,
            ],
        ]);
        $request->from()->associate($reverifying);
        $request->to()->associate($superAdmin);
        $request->for()->associate($reverifying);
        $request->save();

        $lapsed = $counsellor('lapsed_counsellor', 'Lapsed', ['verification_expires_at' => now()->subDays(3)]);
        $event($lapsed, CounsellorVerificationEventEnum::verified, ['actor_user_id' => $superAdmin->id, 'expires_at' => $lapsed->verification_expires_at, 'created_at' => now()->subYear()]);
        $event($lapsed, CounsellorVerificationEventEnum::lapsed, ['expires_at' => $lapsed->verification_expires_at, 'dedupe_key' => "lapsed:{$lapsed->id}:{$lapsed->verification_expires_at->timestamp}", 'created_at' => now()->subDays(3)]);
        $client = User::factory()->adult()->create([
            'firstName' => 'Renewal', 'lastName' => 'Client', 'email' => 'renewal.demo.client@example.com',
            'username' => 'renewal_demo_client', 'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);
        $client->addedTherapies()->create([
            'name' => 'Renewal Demo Therapy (counsellor\'s verification ended)',
            'background_story' => 'Seeded therapy that carries on after its counsellor\'s verification ended (SCRUM-31).',
            'counsellor_id' => $lapsed->id,
            'session_type' => 'Periodic',
            'payment_type' => 'FREE',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
        ]);

        $client->addedTherapies()->create([
            'name' => 'Renewal Demo Public Therapy (no counsellor yet)',
            'background_story' => 'Seeded public therapy looking for a counsellor -- a counsellor whose verification has ended or was revoked is told they can\'t take it on (SCRUM-31).',
            'session_type' => 'Once',
            'payment_type' => 'FREE',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => true,
            'status' => 'pending',
        ]);

        $revoked = $counsellor('revoked_counsellor', 'Revoked', ['verification_expires_at' => now()->addMonths(6), 'verification_revoked_at' => now()->subDay()]);
        $event($revoked, CounsellorVerificationEventEnum::verified, ['actor_user_id' => $superAdmin->id, 'expires_at' => $revoked->verification_expires_at, 'created_at' => now()->subYear()]);
        $event($revoked, CounsellorVerificationEventEnum::revoked, ['actor_user_id' => $superAdmin->id, 'expires_at' => $revoked->verification_expires_at, 'reason' => 'Seeded: the licensing board reported this licence number as not theirs.', 'created_at' => now()->subDay()]);
    }

    // SCRUM-73/383: "Leave Demo Group Therapy" -- private and PAID (each seeded member has paid),
    // needs approval to join (so a rejoin asks again), created by leave_demo_creator and run by leave_demo_counsellor. Current members:
    // leave_demo_anonymous_member (anonymous) and leave_demo_minor_member (15, guardian
    // leave_demo_guardian). leave_demo_former_member left three days ago, after an anonymous message.
    // One active member link (for leave_demo_invitee) and one active counsellor link (for
    // leave_demo_link_counsellor). All passwords: "password".
    public function createLeaveGroupDemoData(): void
    {
        $person = fn (string $username, string $first, string $last, array $extra = []) => User::factory()->adult()->create(array_merge([
            'firstName' => $first,
            'lastName' => $last,
            'email' => str_replace('_', '.', $username).'@example.com',
            'username' => $username,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'dob' => now()->subYears(30)->toDateString(),
        ], $extra));

        $counsellorOf = fn (User $user, string $name) => $user->counsellor()->create([
            'name' => $name,
            'about' => 'Seeded counsellor for the leave-a-group and group-link demo (SCRUM-73/383).',
            'email' => $user->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);

        $creator = $person('leave_demo_creator', 'Lena', 'Creator');
        $counsellor = $counsellorOf($person('leave_demo_counsellor', 'Leo', 'Counsellor'), 'Dr. Leo Counsellor');
        $linkCounsellor = $counsellorOf($person('leave_demo_link_counsellor', 'Lara', 'Linkcounsellor'), 'Dr. Lara Linkcounsellor');
        $anonymousMember = $person('leave_demo_anonymous_member', 'Ada', 'Hidden');
        $formerMember = $person('leave_demo_former_member', 'Felix', 'Former');
        $person('leave_demo_invitee', 'Ivy', 'Invitee');
        $guardian = $person('leave_demo_guardian', 'Gail', 'Guardian');
        $minor = $person('leave_demo_minor_member', 'Milo', 'Minor', ['dob' => now()->subYears(15)->toDateString()]);
        Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id, 'ward_was_minor_at_creation' => true]);

        $group = $creator->addedGroupTherapies()->create([
            'name' => 'Leave Demo Group Therapy',
            'about' => 'Seeded group for trying leaving a group, rejoining, and member/counsellor invitation links (SCRUM-73/383).',
            'session_type' => 'Periodic',
            // PAID: a FREE group must be public (EnsureTherapyDataIsValidAction), so editing a FREE
            // private one was refused. No strict payment gate, so a new member can still open it.
            'payment_type' => 'PAID',
            'payment_data' => self::LEAVE_DEMO_PAYMENT_DATA,
            'max_users' => 5,
            'max_counsellors' => 2,
            'allow_anyone' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'IN_SESSION',
            'client_was_minor_at_creation' => false,
        ]);
        $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

        $this->seedLeaveDemoMemberships($group, $anonymousMember, $minor, $formerMember);

        $session = $this->seedSession($counsellor, $group, [
            'name' => 'Leave Demo Session',
            'about' => 'Seeded earlier session, with a message from the member who has since left.',
            'start_time' => now()->subDays(4),
            'end_time' => now()->subDays(4)->addHour(),
            'type' => 'online',
            'status' => 'HELD',
        ]);

        foreach ([[$counsellor, 'Welcome, everyone.', false, 1], [$formerMember, 'Thanks -- this helped me a lot.', true, 3], [$anonymousMember, 'Glad to be here.', true, 5]] as [$from, $content, $masked, $minutes]) {
            $message = new Message(['content' => $content, 'type' => 'NORMAL', 'status' => 'SENT', 'confidential' => false]);
            $message->from()->associate($from);
            $message->for()->associate($session);
            $message->sender_anonymous = $masked;
            $message->created_at = $session->start_time->copy()->addMinutes($minutes);
            $message->save();
        }

        $this->seedLeaveDemoLinks($group, $creator);
    }

    public const LEAVE_DEMO_PAYMENT_DATA = ['amount' => 50, 'currency' => 'USD', 'per' => 'PER_THERAPY', 'sharePercentage' => 70];

    // The demo group's members as seeded: two current ones, and one who left three days ago (each
    // with a SUCCESS payment for the group). Also used to put a local demo back as it was.
    public function seedLeaveDemoMemberships(GroupTherapy $group, User $anonymousMember, User $minor, User $formerMember): void
    {
        $group->allMemberships()->syncWithoutDetaching([
            $anonymousMember->id => ['anonymous' => true, 'was_minor_at_join' => false, 'left_at' => null],
            $minor->id => ['anonymous' => false, 'was_minor_at_join' => true, 'left_at' => null],
            $formerMember->id => ['anonymous' => true, 'was_minor_at_join' => false, 'left_at' => now()->subDays(3)],
        ]);

        foreach ([$anonymousMember, $minor, $formerMember] as $member) {
            Transaction::query()->firstOrCreate(
                ['for_type' => GroupTherapy::class, 'for_id' => $group->id, 'user_id' => $member->id, 'status' => TransactionStatusEnum::success->value],
                ['reference' => 'leave_demo_'.fake()->unique()->uuid(), 'amount' => 5000, 'currency' => 'USD'],
            );
        }
    }

    // One active member link and one active counsellor link from the creator (each works once).
    public function seedLeaveDemoLinks(GroupTherapy $group, User $creator): void
    {
        foreach (['GROUP_THERAPY_PARTICIPANT', 'GROUP_THERAPY_COUNSELLOR'] as $type) {
            $link = new Link(['type' => $type, 'state' => 'ACTIVE', 'uuid' => (string) Str::uuid()]);
            $link->addedby()->associate($creator);
            $link->for()->associate($group);
            $link->save();
        }
    }

    // SCRUM-382: a verified adult-intent account with no date of birth and no therapy, so the "set your
    // date of birth before you can create a therapy" prompt can be tried (giveDemoAdultsADateOfBirth()
    // leaves this one without a dob).
    private function createNoDobDemoData(): void
    {
        User::factory()->create([
            'firstName' => 'NoDob',
            'lastName' => 'Demo',
            'email' => 'no.dob.demo.user@example.com',
            'username' => self::NO_DOB_DEMO_USERNAME,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'gender' => GenderEnum::female,
            'dob' => null,
        ]);
    }

    private function createPausedMinorDemoData(): void
    {
        $counsellorUser = User::factory()->adult()->create([
            'firstName' => 'Paused',
            'lastName' => 'DemoCounsellor',
            'email' => 'paused.minor.demo.counsellor@example.com',
            'username' => 'paused_minor_demo_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'gender' => GenderEnum::female,
        ]);

        $counsellor = $counsellorUser->counsellor()->create([
            'name' => 'Dr. Paused DemoCounsellor',
            'about' => 'Seeded counsellor of a therapy paused for a missing guardian (SCRUM-288).',
            'email' => $counsellorUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);

        // Fifteen, with no guardianship row: the guardian who linked them has since been removed.
        $minor = User::factory()->create([
            'firstName' => 'Jordan',
            'lastName' => 'Avery',
            'email' => 'paused.minor@example.com',
            'username' => 'paused_minor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'gender' => GenderEnum::male,
            'dob' => now()->subYears(15)->toDateString(),
        ]);

        $therapy = $minor->addedTherapies()->create([
            'name' => 'Paused Minor Demo Therapy',
            'background_story' => 'Seeded individual therapy of a minor with no guardian (SCRUM-288): readable, but every write is refused until a guardian is added.',
            'counsellor_id' => $counsellor->id,
            'session_type' => 'Once',
            'payment_type' => 'FREE',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
            // Bypasses CreateTherapyAction, so set explicitly (see createGuardianVideoConsentDemoData).
            'client_was_minor_at_creation' => true,
        ]);

        // History to read, and an upcoming session that stays booked but can't be started (Q2).
        $held = $this->seedSession($counsellor, $therapy, [
            'name' => 'Paused Minor Demo: first session',
            'about' => 'A past session with a few messages, readable while the therapy is paused.',
            'start_time' => now()->subDays(7),
            'end_time' => now()->subDays(7)->addHour(),
            'type' => 'online',
            'status' => 'held',
            'payment_type' => 'FREE',
        ]);
        foreach ([[$counsellor, $minor, 'Thanks for coming in today. How has this week been?'], [$minor, $counsellor, 'Better than last week, thank you.']] as $i => [$from, $to, $content]) {
            $from->sentMessages()->create([
                'content' => $content,
                'to_id' => $to->id,
                'to_type' => $to::class,
                'for_id' => $held->id,
                'for_type' => $held::class,
                'created_at' => now()->subDays(7)->addMinutes(5 + $i),
            ]);
        }
        $this->seedSession($counsellor, $therapy, [
            'name' => 'Paused Minor Demo: next session',
            'about' => 'An upcoming session: kept while paused, but it cannot be started.',
            'start_time' => now()->addDays(2),
            'end_time' => now()->addDays(2)->addHour(),
            'type' => 'online',
            'status' => 'pending',
            'payment_type' => 'FREE',
        ]);

        // A group the minor belongs to -- only they are paused there; the adult member carries on.
        $member = User::factory()->adult()->create([
            'firstName' => 'Paused',
            'lastName' => 'DemoGroupMember',
            'email' => 'paused.minor.demo.group.member@example.com',
            'username' => 'paused_minor_demo_group_member',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'gender' => GenderEnum::female,
        ]);

        $group = $counsellor->addedGroupTherapies()->create([
            'name' => 'Paused Minor Demo Group',
            'about' => 'Seeded group with a paused minor member (SCRUM-288) and an adult member who is not affected.',
            'session_type' => 'Once',
            'payment_type' => 'FREE',
            'max_users' => 10,
            'allow_anyone' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
        ]);
        $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE']);
        $group->users()->attach($minor->id, ['anonymous' => false, 'background_story' => 'test', 'was_minor_at_join' => true]);
        $group->users()->attach($member->id, ['anonymous' => false, 'background_story' => 'test', 'was_minor_at_join' => false]);
        $this->seedSession($counsellor, $group, [
            'name' => 'Paused Minor Demo Group Session',
            'about' => 'An in-progress group session: the adult member can write, the paused minor can only read.',
            'start_time' => now()->subMinutes(5),
            'end_time' => now()->addHour(),
            'type' => 'online',
            'status' => 'in_session',
            'payment_type' => 'FREE',
        ]);
    }

    // SCRUM-381: needs createPausedMinorDemoData() first (its counsellor, therapy and group).
    //  - paused_minor's counsellor has asked the super administrator for a safeguarding exception
    //    (pending: approve or reject it from the requests list or /administrator/safeguarding-exceptions);
    //  - safeguarding_exception_minor (15, no guardian) has a 1:1 with the same counsellor and is an
    //    ANONYMOUS member of "Paused Minor Demo Group", under an exception that ends in two days -- so
    //    the next hourly reminder sweep sends the 3-day heads-up, and the group counsellor sees them
    //    only as "Anonymous".
    public function createSafeguardingExceptionDemoData(User $superAdmin): void
    {
        $counsellorUser = User::query()->where('username', 'paused_minor_demo_counsellor')->firstOrFail();
        $counsellor = $counsellorUser->counsellor;
        $pausedMinor = User::query()->where('username', 'paused_minor')->firstOrFail();
        $pausedTherapy = Therapy::query()->where('name', 'Paused Minor Demo Therapy')->whereAddedby($pausedMinor)->firstOrFail();
        $group = GroupTherapy::query()->where('name', 'Paused Minor Demo Group')->whereAddedby($counsellor)->firstOrFail();

        $this->seedSafeguardingExceptionRequest($counsellor, $pausedMinor, $pausedTherapy);

        $minor = User::factory()->create([
            'firstName' => 'Casey',
            'lastName' => 'Morgan',
            'email' => 'safeguarding.exception.minor@example.com',
            'username' => 'safeguarding_exception_minor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'gender' => GenderEnum::female,
            'dob' => now()->subYears(15)->toDateString(),
        ]);

        $minor->addedTherapies()->create([
            'name' => 'Safeguarding Exception Demo Therapy',
            'background_story' => 'Seeded individual therapy of a minor with no guardian who carries on under a safeguarding exception (SCRUM-381).',
            'counsellor_id' => $counsellor->id,
            'session_type' => 'Once',
            'payment_type' => 'FREE',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
            'client_was_minor_at_creation' => true,
        ]);
        $group->users()->attach($minor->id, ['anonymous' => true, 'background_story' => 'test', 'was_minor_at_join' => true]);

        SafeguardingException::query()->create([
            'ward_id' => $minor->id,
            'granted_by' => $superAdmin->id,
            'reason' => 'Casey\'s guardian has passed away; the family is arranging a new guardianship. Therapy should not stop in the meantime.',
            'starts_at' => now()->subDays(12),
            'ends_at' => now()->addDays(2),
        ]);
    }

    // SCRUM-381: a counsellor's pending request for a safeguarding exception for a paused minor
    // ($pausedTherapy is the minor's therapy with them).
    private function seedSafeguardingExceptionRequest(Counsellor $counsellor, User $pausedMinor, Therapy $pausedTherapy): Request
    {
        $request = $counsellor->sentRequests()->create([
            'type' => RequestTypeEnum::safeguardingException->value,
            'status' => RequestStatusEnum::pending->value,
            'data' => [
                'reason' => 'Jordan is in the middle of work on anxiety before exams. Their guardian is unreachable for a few weeks; a new guardian is being arranged.',
                'proposedEndsAt' => now()->addDays(14)->toJSON(),
                'wardId' => $pausedMinor->id,
            ],
        ]);
        $request->for()->associate($pausedTherapy);
        $request->save();

        return $request;
    }

    // SCRUM-387: what the admin area's "Requests to resolve" queue and overview counts need that
    // nothing else seeds (the rest -- an age verification, a dob change with no guardian, a refund
    // request, a safeguarding exception request, open reports, a suspension appeal and a
    // billing-suspended organisation -- come from the demo data above):
    //  - admin_area_verify_counsellor (Dr. Verify Demo), with a pending counsellor verification
    //    request and two licences (no documents);
    //  - "Verify Demo Organisation" (owner admin_area_org_owner), unverified, with a pending
    //    organisation verification request;
    //  - admin_area_payout_counsellor, with a FAILED payout whose earning is still waiting to be
    //    paid again, and a FAILED refund for one of their client's payments.
    // Both verification requests are addressed to the first super admin, as the app addresses them.
    // All passwords: "password".
    public function createAdminAreaDemoData(User $superAdmin): void
    {
        $verifyUser = User::factory()->create([
            'firstName' => 'Verify',
            'lastName' => 'Demo',
            'email' => 'admin.area.verify.counsellor@example.com',
            'username' => 'admin_area_verify_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'dob' => now()->subYears(40)->toDateString(),
        ]);
        $verifyCounsellor = $verifyUser->counsellor()->create([
            'name' => 'Dr. Verify Demo',
            'about' => 'Seeded counsellor waiting on verification (SCRUM-387).',
            'email' => $verifyUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => null,
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);
        $licence = function (string $number, int $authorityId) use ($verifyCounsellor) {
            $license = new License(['number' => $number, 'validated' => false]);
            $license->licensing_authority_id = $authorityId;
            $license->addedby()->associate($verifyCounsellor);
            $license->for()->associate($verifyCounsellor);
            $license->save();

            return $license;
        };
        $authorities = LicensingAuthority::query()->orderBy('id')->limit(2)->pluck('id');
        $counsellorRequest = new Request([
            'type' => RequestTypeEnum::counsellor->value,
            'status' => RequestStatusEnum::pending->value,
            'data' => [
                'nationalIdLicense' => $licence('GHA-000111222-3', $authorities[0])->id,
                'otherLicense' => $licence('GPC-2026-0042', $authorities[1] ?? $authorities[0])->id,
            ],
        ]);
        $counsellorRequest->from()->associate($verifyCounsellor);
        $counsellorRequest->to()->associate($superAdmin);
        $counsellorRequest->for()->associate($verifyCounsellor);
        $counsellorRequest->save();

        $organization = Organization::factory()->create([
            'name' => 'Verify Demo Organisation',
            'legal_name' => 'Verify Demo Organisation Ltd',
            'registration_number' => 'REG-VERIFY-387',
            'is_provider' => true,
            'verified_at' => null,
        ]);
        $owner = User::factory()->create([
            'firstName' => 'Org',
            'lastName' => 'Owner',
            'email' => 'admin.area.org.owner@example.com',
            'username' => 'admin_area_org_owner',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'dob' => now()->subYears(38)->toDateString(),
        ]);
        $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
        $organizationRequest = new Request([
            'type' => RequestTypeEnum::organization->value,
            'status' => RequestStatusEnum::pending->value,
            'data' => ['registrationNumber' => $organization->registration_number, 'legalName' => $organization->legal_name],
        ]);
        $organizationRequest->from()->associate($organization);
        $organizationRequest->to()->associate($superAdmin);
        $organizationRequest->for()->associate($organization);
        $organizationRequest->save();

        $payoutUser = User::factory()->create([
            'firstName' => 'Payout',
            'lastName' => 'Failed',
            'email' => 'admin.area.payout.counsellor@example.com',
            'username' => 'admin_area_payout_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'dob' => now()->subYears(45)->toDateString(),
        ]);
        $payoutCounsellor = $payoutUser->counsellor()->create([
            'name' => 'Dr. Payout Failed',
            'about' => 'Seeded counsellor whose last payout failed (SCRUM-387).',
            'email' => $payoutUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);
        $client = User::query()->where('username', 'payout_demo_client')->firstOrFail();
        $therapy = $client->addedTherapies()->create([
            'name' => 'Failed Payout Demo Therapy',
            'background_story' => 'Seeded PAID therapy whose transaction backs a failed payout and a failed refund (SCRUM-387).',
            'counsellor_id' => $payoutCounsellor->id,
            'session_type' => 'ONCE',
            'payment_type' => 'PAID',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'IN_SESSION',
            'payment_data' => ['amount' => 120, 'currency' => 'GHS', 'per' => 'PER_THERAPY'],
        ]);
        $transaction = Transaction::query()->create([
            'for_type' => $therapy::class,
            'for_id' => $therapy->id,
            'user_id' => $client->id,
            'reference' => 'admin_area_demo_'.fake()->unique()->uuid(),
            'amount' => 12000,
            'currency' => 'GHS',
            'status' => TransactionStatusEnum::success->value,
        ]);
        $payout = CounsellorPayout::query()->create([
            'counsellor_id' => $payoutCounsellor->id,
            'initiated_by_id' => $payoutUser->id,
            'reference' => 'payout_admin_area_demo_'.fake()->unique()->uuid(),
            'amount' => 10800,
            'currency' => 'GHS',
            'status' => CounsellorPayoutStatusEnum::failed->value,
        ]);
        // Back to pending after the failure, still pointing at the failed payout until a later one
        // claims it (RecordCounsellorPayoutStatusAction).
        CounsellorEarning::query()->create([
            'transaction_id' => $transaction->id,
            'counsellor_id' => $payoutCounsellor->id,
            'counsellor_payout_id' => $payout->id,
            'gross_amount' => 12000,
            'fee_amount' => 1200,
            'net_amount' => 10800,
            'currency' => 'GHS',
            'status' => CounsellorEarningStatusEnum::pending->value,
        ]);
        $refund = Refund::query()->create([
            'transaction_id' => $transaction->id,
            'requested_by_id' => $client->id,
            'reference' => 'refund_admin_area_demo_'.fake()->unique()->uuid(),
            'amount' => 2000,
            'currency' => 'GHS',
            'reason' => 'Seeded failed refund (SCRUM-387).',
            'status' => RefundStatusEnum::failed->value,
        ]);
        // What the refund requests page shows as why it failed.
        $refund->statusHistories()->create([
            'status' => RefundStatusEnum::failed->value,
            'source' => RefundStatusSourceEnum::webhook->value,
            'message' => 'Seeded: the payment provider declined the refund (insufficient merchant balance).',
        ]);
    }

    // SCRUM-387 (QA): what the admin area's QA found no seed for. Needs createSuspensionDemoData()
    // and createPausedMinorDemoData() first. All passwords: "password".
    //  - two testimonials (one in use on the home page, one not) and a how-to with two steps, so
    //    Content > Testimonials and How-tos aren't empty;
    //  - suspendable_demo_admin, a normal administrator named in a resolved report (suspension
    //    recommended) by suspension_ready_counsellor -- an administrator the super admin can suspend;
    //  - involved_demo_super_admin, a second super administrator who is also a counsellor of
    //    "Paused Minor Demo Group" (paused_minor is a member) -- so paused_minor's safeguarding
    //    request shows to them only as one they are involved in, and they can't decide it.
    // Public so it can be added to an existing local database (see documentation/seeded-data.md).
    public function createAdminAreaQaDemoData(User $superAdmin): void
    {
        $person = fn (string $username, string $first, string $last) => User::factory()->create([
            'firstName' => $first,
            'lastName' => $last,
            'email' => str_replace('_', '.', $username).'@example.com',
            'username' => $username,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'dob' => now()->subYears(35)->toDateString(),
        ]);

        // Content.
        $client = User::query()->where('username', 'suspension_demo_client')->firstOrFail();
        foreach ([
            ['Talking to a counsellor every week helped me get through my exams. I could stay anonymous until I was ready.', true],
            ['Booking a session was simple, and my counsellor always checked in before we started.', false],
        ] as [$content, $use]) {
            $testimonial = new Testimonial(['content' => $content, 'use' => $use]);
            $testimonial->addedby()->associate($client);
            $testimonial->save();
        }

        $howTo = HowTo::query()->create([
            'name' => 'Answering a request',
            'description' => 'How to accept or reject a request someone has sent you.',
            'page' => 'Check Requests',
            'user_id' => $superAdmin->id,
        ]);
        foreach (['Open your requests' => 'Choose Requests from the account menu to see what is waiting on you.', 'Accept or reject' => 'Read the request, then choose accept or reject. The sender is told either way.'] as $name => $description) {
            $howTo->howToSteps()->create([
                'name' => $name,
                'description' => $description,
                'user_id' => $superAdmin->id,
                'position' => $howTo->howToSteps()->count() + 1,
            ]);
        }

        // An administrator who can be suspended (only a super administrator can't be).
        $admin = $person('suspendable_demo_admin', 'Suspendable', 'DemoAdmin');
        $admin->administrator()->create(['type' => AdministratorTypeEnum::normal->value, 'verified_at' => now()]);
        $counsellor = User::query()->where('username', 'suspension_ready_counsellor')->firstOrFail()->counsellor;
        $therapy = $admin->addedTherapies()->create([
            'name' => 'Suspension Demo Therapy (administrator to suspend)',
            'background_story' => 'Seeded therapy whose client is an administrator, for trying suspending one (SCRUM-387).',
            'counsellor_id' => $counsellor->id,
            'session_type' => 'Periodic',
            'payment_type' => 'FREE',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
        ]);
        $report = new Report([
            'description' => '[Suspension demo: administrator to suspend] My client was abusive in our sessions.',
            'data' => ['userId' => $admin->id],
        ]);
        $report->addedby()->associate($counsellor->user);
        $report->reportable()->associate($therapy);
        $report->save();
        $report->forceFill([
            'status' => ReportStatusEnum::resolved,
            'outcome' => ReportOutcomeEnum::suspension_recommended,
            'resolution_note' => 'Seeded: the messages broke the community rules.',
            'resolved_at' => now()->subHour(),
            'created_at' => now()->subDays(2),
        ])->save();

        // A super administrator who counsels a paused minor.
        $involved = $person('involved_demo_super_admin', 'Involved', 'DemoSuperAdmin');
        $involved->administrator()->create(['type' => AdministratorTypeEnum::super->value, 'verified_at' => now()]);
        $involvedCounsellor = $involved->counsellor()->create([
            'name' => 'Dr. Involved DemoSuperAdmin',
            'about' => 'Seeded super administrator who also counsels a paused minor (SCRUM-387).',
            'email' => $involved->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);
        $group = GroupTherapy::query()
            ->where('name', 'Paused Minor Demo Group')
            ->whereAddedby(User::query()->where('username', 'paused_minor_demo_counsellor')->firstOrFail()->counsellor)
            ->firstOrFail();
        $group->counsellors()->attach($involvedCounsellor->id, ['state' => 'ACTIVE']);
    }

    private function createClosedAccountDemoData(): void
    {
        foreach ([
            ['closed_account_demo_old', 'ClosedOld', config('user.closed_account_retention_days') + 1],
            ['closed_account_demo_recent', 'ClosedRecent', 1],
        ] as [$username, $firstName, $daysAgo]) {
            $user = User::factory()->create([
                'firstName' => $firstName,
                'lastName' => 'Demo',
                'username' => $username,
                'email' => "{$username}@example.com",
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]);
            $user->delete();
            $user->forceFill(['deleted_at' => now()->subDays($daysAgo)])->saveQuietly();
        }
    }

    private function createLanguages($user)
    {
        $user->addedLanguages()->createMany([
            ['name' => 'English'],
            ['name' => 'French'],
            ['name' => 'Twi'],
            ['name' => 'Ewe'],
            ['name' => 'Ga'],
            ['name' => 'Spanish'],
            ['name' => 'German'],
            ['name' => 'Mandarin'],
            ['name' => 'Arabic'],
            ['name' => 'Portuguese'],
        ]);
    }

    private function createReligions($user)
    {
        $user->addedReligions()->createMany([
            ['name' => 'Christianity'],
            ['name' => 'Islam'],
            ['name' => 'Traditional'],
            ['name' => 'Atheist'],
            ['name' => 'Judaism'],
            ['name' => 'Buddhism'],
            ['name' => 'Hinduism'],
            ['name' => 'Agnostic'],
        ]);
    }

    private function createTherapyCases($user)
    {
        $user->addedTherapyCases()->createMany([
            ['name' => 'Anxiety Disorders', 'description' => 'General anxiety, panic attacks, social anxiety'],
            ['name' => 'Depression', 'description' => 'Major depressive disorder, seasonal depression'],
            ['name' => 'Relationship Issues', 'description' => 'Couple therapy, family conflicts, communication problems'],
            ['name' => 'Trauma and PTSD', 'description' => 'Post-traumatic stress, childhood trauma, abuse recovery'],
            ['name' => 'Addiction Recovery', 'description' => 'Substance abuse, behavioral addictions'],
            ['name' => 'Grief and Loss', 'description' => 'Bereavement, loss of loved ones, life transitions'],
            ['name' => 'Stress Management', 'description' => 'Work stress, life pressures, burnout'],
            ['name' => 'Self-Esteem Issues', 'description' => 'Low confidence, self-worth, body image'],
            ['name' => 'Anger Management', 'description' => 'Anger control, emotional regulation'],
            ['name' => 'Eating Disorders', 'description' => 'Anorexia, bulimia, binge eating'],
            ['name' => 'Sleep Disorders', 'description' => 'Insomnia, sleep anxiety, sleep hygiene'],
            ['name' => 'Academic/Career Counseling', 'description' => 'Study stress, career transitions, performance anxiety'],
        ]);
    }

    private function createProfessions($user)
    {
        $user->addedProfessions()->createMany([
            ['name' => 'Clinical Psychologist'],
            ['name' => 'Licensed Clinical Social Worker'],
            ['name' => 'Marriage and Family Therapist'],
            ['name' => 'Licensed Professional Counselor'],
            ['name' => 'Psychiatrist'],
            ['name' => 'Addiction Counselor'],
            ['name' => 'Trauma Specialist'],
            ['name' => 'Child Psychologist'],
            ['name' => 'Cognitive Behavioral Therapist'],
            ['name' => 'Art Therapist'],
        ]);
    }

    private function createLicensingAuthorities($user)
    {
        $user->addedLicensingAuthorities()->createMany([
            ['name' => 'National Identification Authority', 'license_type' => LicensingTypeEnum::both->value],
            ['name' => 'Ghana Psychology Council', 'license_type' => LicensingTypeEnum::file->value],
            ['name' => 'American Psychological Association', 'license_type' => LicensingTypeEnum::file->value],
            ['name' => 'British Psychological Society', 'license_type' => LicensingTypeEnum::file->value],
            ['name' => 'National Association of Social Workers', 'license_type' => LicensingTypeEnum::file->value],
        ]);
    }

    private function createDemoUsers()
    {
        $users = collect();

        // Create diverse demo users
        $userData = [
            ['firstName' => 'Sarah', 'lastName' => 'Johnson', 'email' => 'sarah.johnson@example.com', 'gender' => GenderEnum::female],
            ['firstName' => 'Michael', 'lastName' => 'Chen', 'email' => 'michael.chen@example.com', 'gender' => GenderEnum::male],
            ['firstName' => 'Emma', 'lastName' => 'Williams', 'email' => 'emma.williams@example.com', 'gender' => GenderEnum::female],
            ['firstName' => 'David', 'lastName' => 'Brown', 'email' => 'david.brown@example.com', 'gender' => GenderEnum::male],
            ['firstName' => 'Lisa', 'lastName' => 'Anderson', 'email' => 'lisa.anderson@example.com', 'gender' => GenderEnum::female],
            ['firstName' => 'James', 'lastName' => 'Wilson', 'email' => 'james.wilson@example.com', 'gender' => GenderEnum::male],
            ['firstName' => 'Maria', 'lastName' => 'Garcia', 'email' => 'maria.garcia@example.com', 'gender' => GenderEnum::female],
            ['firstName' => 'John', 'lastName' => 'Davis', 'email' => 'john.davis@example.com', 'gender' => GenderEnum::male],
            ['firstName' => 'Amy', 'lastName' => 'Taylor', 'email' => 'amy.taylor@example.com', 'gender' => GenderEnum::female],
            ['firstName' => 'Robert', 'lastName' => 'Miller', 'email' => 'robert.miller@example.com', 'gender' => GenderEnum::male],
        ];

        foreach ($userData as $data) {
            $user = User::factory()->create([
                'firstName' => $data['firstName'],
                'lastName' => $data['lastName'],
                'email' => $data['email'],
                'username' => strtolower($data['firstName'].'_'.$data['lastName']),
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'gender' => $data['gender']->value,
            ]);

            $users->push($user);
        }

        return $users;
    }

    private function createDemoCounsellors($users)
    {
        $counsellors = collect();

        // Make some users counsellors
        $counsellorUsers = $users->take(6); // First 6 users become counsellors

        $counsellorData = [
            ['name' => 'Dr. Sarah Johnson', 'about' => 'Specialized in anxiety and depression with 10 years of experience. I believe in creating a safe space for healing.'],
            ['name' => 'Dr. Michael Chen', 'about' => 'Trauma specialist focusing on PTSD and childhood trauma. Bilingual therapist with cultural sensitivity.'],
            ['name' => 'Dr. Emma Williams', 'about' => 'Marriage and family therapist helping couples and families build stronger relationships.'],
            ['name' => 'Dr. David Brown', 'about' => 'Addiction counselor with expertise in substance abuse and behavioral addictions recovery.'],
            ['name' => 'Dr. Lisa Anderson', 'about' => 'Child psychologist specializing in developmental issues and family dynamics.'],
            ['name' => 'Dr. James Wilson', 'about' => 'Cognitive behavioral therapist focusing on mood disorders and anxiety management.'],
        ];

        foreach ($counsellorUsers as $index => $user) {
            $counsellor = $user->counsellor()->create([
                'name' => $counsellorData[$index]['name'],
                'about' => $counsellorData[$index]['about'],
                'email' => $user->email,
                'phone' => fake()->phoneNumber(),
                'verified_at' => now(),
                'email_verified_at' => now(),
                'profession_id' => rand(1, 10),
                'contact_visible' => true,
            ]);

            // Attach random cases, languages, and religions
            $counsellor->cases()->attach([1, 2, 3, rand(4, 12)]);
            $counsellor->languages()->attach([1, rand(2, 5)]);
            $counsellor->religions()->attach([rand(1, 4)]);

            $counsellors->push($counsellor);
        }

        return $counsellors;
    }

    private function createDemoTherapies($users, $counsellors)
    {
        $nonCounsellorUsers = $users->skip(6); // Users who are not counsellors

        foreach ($nonCounsellorUsers as $user) {
            // Create 1-2 therapies per user
            $therapyCount = rand(1, 2);

            for ($i = 0; $i < $therapyCount; $i++) {
                $counsellor = $counsellors->random();

                $therapy = $user->addedTherapies()->create([
                    'name' => fake()->sentence(4),
                    'background_story' => fake()->paragraph(3),
                    'counsellor_id' => $counsellor->id,
                    'session_type' => ['Once', 'Periodic'][rand(0, 1)],
                    'payment_type' => ['FREE', 'PAID'][rand(0, 1)],
                    'allow_in_person' => rand(0, 1),
                    'anonymous' => rand(0, 1),
                    'public' => rand(0, 1),
                    'status' => 'pending',
                    'payment_data' => [
                        'amount' => rand(50, 200),
                        'currency' => 'USD',
                        'per' => 'session',
                    ],
                ]);

                // Attach therapy cases
                $therapy->cases()->attach([rand(1, 12), rand(1, 12)]);

                // Create therapy topics
                $topicCount = rand(2, 4);
                for ($j = 0; $j < $topicCount; $j++) {
                    $therapy->topics()->create([
                        'name' => fake()->sentence(3),
                        'description' => fake()->paragraph(2),
                        'counsellor_id' => $counsellor->id,
                    ]);
                }

                // Create sessions with some being held
                $this->createTherapySessions($therapy, $counsellor);
            }
        }
    }

    private function createTherapySessions($therapy, $counsellor)
    {
        $sessionCount = rand(2, 5);

        for ($i = 0; $i < $sessionCount; $i++) {
            $startTime = fake()->dateTimeBetween('-30 days', '+30 days');
            $endTime = (clone $startTime)->modify('+1 hour');

            $statuses = ['pending', 'held', 'in_session', 'abandoned'];
            $status = $statuses[array_rand($statuses)];

            $session = $this->seedSession($counsellor, $therapy, [
                'name' => 'Session '.($i + 1).' - '.fake()->words(3, true),
                'about' => fake()->paragraph(2),
                'start_time' => $startTime,
                'end_time' => $endTime,
                'type' => ['online', 'in_person'][rand(0, 1)],
                'status' => $status,
            ]);

            // Create some messages for held sessions
            if ($status === 'held') {
                $this->createSessionMessages($session, $therapy, $counsellor);
            }
        }
    }

    private function createSessionMessages($session, $therapy, $counsellor)
    {
        $messageCount = rand(5, 15);

        for ($i = 0; $i < $messageCount; $i++) {
            $isFromCounsellor = rand(0, 1);
            $from = $isFromCounsellor ? $counsellor : $therapy->addedby;
            $to = $isFromCounsellor ? $therapy->addedby : $counsellor;

            $from->sentMessages()->create([
                'content' => fake()->sentence(rand(5, 15)),
                'to_id' => $to->id,
                'to_type' => $to::class,
                'for_id' => $session->id,
                'for_type' => $session::class,
                'created_at' => fake()->dateTimeBetween($session->start_time, $session->end_time),
            ]);
        }
    }

    private function createDemoGroupTherapies($users, $counsellors)
    {
        $groupTherapies = [];

        // Create 3-5 group therapies
        for ($i = 0; $i < rand(3, 5); $i++) {
            $creator = $users->random();

            $groupTherapy = $creator->addedGroupTherapies()->create([
                'name' => fake()->sentence(4),
                'about' => fake()->paragraph(3),
                'session_type' => ['Once', 'Periodic'][rand(0, 1)],
                'payment_type' => ['FREE', 'PAID'][rand(0, 1)],
                'max_users' => rand(5, 15),
                'allow_anyone' => rand(0, 1),
                'anonymous' => rand(0, 1),
                'public' => rand(0, 1),
                'status' => 'pending',
                // SCRUM-380: valid payment settings, so a seeded paid group can be saved through its edit
                // form. `per` was 'session' (not a real value), and with no sharePercentage the rule that
                // a user-created group gives counsellors at least 70% refused every save.
                'payment_data' => [
                    'amount' => rand(30, 100),
                    'currency' => 'USD',
                    'per' => 'PER_SESSION',
                    'shareEqually' => false,
                    'sharePercentage' => rand(70, 90),
                ],
            ]);

            // Attach cases
            $groupTherapy->cases()->attach([rand(1, 12), rand(1, 12)]);

            // Add participants (users)
            $participants = $users->random(rand(3, 8));
            foreach ($participants as $participant) {
                $groupTherapy->users()->attach($participant->id, [
                    'anonymous' => fake()->boolean(),
                    'background_story' => fake()->paragraph(2),
                ]);
            }

            // Add counsellors
            $groupCounsellors = $counsellors->random(rand(1, 3));
            foreach ($groupCounsellors as $counsellor) {
                $groupTherapy->counsellors()->attach($counsellor->id, [
                    'state' => 'ACTIVE',
                ]);
            }

            $groupTherapies[] = $groupTherapy;
        }

        return $groupTherapies;
    }

    private function createDemoDiscussions($counsellors)
    {
        // Create discussions between counsellors
        for ($i = 0; $i < rand(3, 6); $i++) {
            $creator = $counsellors->random();
            $therapy = Therapy::inRandomOrder()->first();

            if (! $therapy) {
                continue;
            }

            $startTime = fake()->dateTimeBetween('-7 days', '+7 days');
            $endTime = fake()->dateTimeBetween($startTime, '+14 days');

            $discussion = $creator->addedDiscussions()->create([
                'name' => 'Discussion: '.fake()->sentence(4),
                'description' => fake()->paragraph(2),
                'for_id' => $therapy->id,
                'for_type' => $therapy::class,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'status' => ['pending', 'in_session', 'held'][rand(0, 2)],
            ]);

            // Add participating counsellors
            $participants = $counsellors->except($creator->id)->random(rand(1, 3));
            foreach ($participants as $participant) {
                $discussion->counsellors()->attach($participant->id);
            }

            // Create some discussion messages
            if ($discussion->status === 'held') {
                $this->createDiscussionMessages($discussion, $counsellors);
            }
        }
    }

    private function createDiscussionMessages($discussion, $counsellors)
    {
        $participants = collect([$discussion->addedby])
            ->merge($discussion->counsellors);

        $messageCount = rand(10, 25);

        for ($i = 0; $i < $messageCount; $i++) {
            $sender = $participants->random();

            $sender->sentMessages()->create([
                'content' => fake()->sentence(rand(8, 20)),
                'for_id' => $discussion->id,
                'for_type' => $discussion::class,
                'created_at' => fake()->dateTimeBetween($discussion->start_time, $discussion->end_time ?: now()),
            ]);
        }
    }

    private function createChatDemoData($users, $counsellors)
    {
        $client = $users->firstWhere('username', 'maria_garcia');
        throw_if(! $client, new RuntimeException('Chat demo seeder: expected seed user "maria_garcia" not found — did createDemoUsers() run first?'));

        $counsellor = $counsellors->first(fn ($c) => $c->name === 'Dr. Sarah Johnson');
        throw_if(! $counsellor, new RuntimeException('Chat demo seeder: expected seed counsellor "Dr. Sarah Johnson" not found — did createDemoUsers() run first?'));

        $secondCounsellor = $counsellors->first(fn ($c) => $c->name === 'Dr. Michael Chen');
        throw_if(! $secondCounsellor, new RuntimeException('Chat demo seeder: expected seed counsellor "Dr. Michael Chen" not found — did createDemoUsers() run first?'));

        // For SCRUM-71 (anonymity masking): a member who opted into per-member anonymity even
        // though the group itself defaults to non-anonymous -- exercises the per-member half of
        // GroupTherapy::isAnonymousFor()'s OR logic independently of the group-level flag.
        $anonymousParticipant = $users->firstWhere('username', 'john_davis');
        throw_if(! $anonymousParticipant, new RuntimeException('Chat demo seeder: expected seed user "john_davis" not found — did createDemoUsers() run first?'));

        // Individual therapy with a live "in session" session, for testing the individual
        // therapy chat page in "active session" mode (log in as maria_garcia or
        // sarah_johnson and visit /therapies/{id}/chat).
        $therapy = $client->addedTherapies()->create([
            'name' => 'Chat Demo Individual Therapy',
            'background_story' => 'Seeded therapy with a live session, for testing the therapy chat page.',
            'counsellor_id' => $counsellor->id,
            'session_type' => 'Once',
            'payment_type' => 'FREE',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
        ]);

        $this->seedSession($counsellor, $therapy, [
            'name' => 'Chat Demo Live Session',
            'about' => 'Seeded live session for testing the therapy chat page.',
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->addHour(),
            'type' => 'online',
            'status' => 'in_session',
        ]);

        // Group therapy with a live "in session" session, for testing the group therapy chat
        // page (log in as maria_garcia or sarah_johnson and visit /group-therapies/{id}/chat).
        $groupTherapy = $client->addedGroupTherapies()->create([
            'name' => 'Chat Demo Group Therapy',
            'about' => 'Seeded group therapy with a live session, for testing the group therapy chat page.',
            'session_type' => 'Once',
            'payment_type' => 'FREE',
            'max_users' => 10,
            'allow_anyone' => true,
            'anonymous' => false,
            'public' => true,
            'status' => 'in_session',
        ]);

        $groupTherapy->users()->attach($client->id, [
            'anonymous' => false,
            'background_story' => 'Seeded participant for the chat demo group therapy.',
        ]);
        $groupTherapy->users()->attach($anonymousParticipant->id, [
            'anonymous' => true,
            'background_story' => 'Seeded anonymous participant for exercising SCRUM-71 anonymity masking.',
        ]);
        $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE']);
        // SCRUM-198: a second active counsellor on the same live group session, so
        // cross-counsellor session-notes isolation (SCRUM-197 AC7) is browser-verifiable via
        // /group-therapies/{id}/chat, not just Pest -- log in as sarah_johnson or michael_chen
        // and confirm neither ever sees the other's private notes on this session.
        $groupTherapy->counsellors()->attach($secondCounsellor->id, ['state' => 'ACTIVE']);

        $groupSession = $this->seedSession($counsellor, $groupTherapy, [
            'name' => 'Chat Demo Group Live Session',
            'about' => 'Seeded live session for testing the group therapy chat page.',
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->addHour(),
            'type' => 'online',
            'status' => 'in_session',
        ]);

        // A message from the anonymous participant -- viewed by anyone other than
        // john_davis, MessageResource should mask its sender (null `fromUserId`); viewed by
        // john_davis themselves, it should show their own real id.
        $anonymousParticipant->sentMessages()->create([
            'content' => 'This message is from an anonymous group member and should show a masked sender to everyone except this member (SCRUM-71 test data).',
            'for_id' => $groupSession->id,
            'for_type' => $groupSession::class,
            'created_at' => now()->subMinutes(5),
        ]);

        $this->createGroupTherapyMembershipRequestDemoData($users);

        // Discussion in IN_SESSION status between two known counsellors, with existing
        // messages, for testing the discussion chat page (log in as sarah_johnson or
        // michael_chen and visit /discussions/{id}/chat).
        $discussion = $counsellor->addedDiscussions()->create([
            'name' => 'Chat Demo Discussion',
            'description' => 'Seeded discussion in IN_SESSION status for testing the discussion chat page.',
            'for_id' => $therapy->id,
            'for_type' => $therapy::class,
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->addHour(),
            'status' => 'in_session',
        ]);

        $discussion->counsellors()->attach($secondCounsellor->id);

        $this->createDiscussionMessages($discussion, collect([$counsellor, $secondCounsellor]));

        // SCRUM-379: a PENDING discussion its owner (sarah_johnson) can reach and edit through the UI,
        // on the same Chat Demo therapy -- the random seeded discussions sit on therapies their owner
        // may not counsel, so none could be updated by hand. Three days out, so the 30-minute start rule
        // and the "about to start" sweeps never get in the way.
        $editable = $counsellor->addedDiscussions()->create([
            'name' => 'Editable Demo Discussion',
            'description' => 'Seeded PENDING discussion for trying the update-discussion form.',
            'for_id' => $therapy->id,
            'for_type' => $therapy::class,
            'start_time' => now()->addDays(3)->setTime(10, 0),
            'end_time' => now()->addDays(3)->setTime(10, 40),
            'status' => DiscussionStatusEnum::pending->value,
        ]);

        $editable->counsellors()->attach($secondCounsellor->id);
    }

    // SCRUM-72: a group therapy with `allow_anyone = false` and a deterministic PENDING
    // membership join request, so the accept/reject UI is manually verifiable without having to
    // trigger the join flow through the UI first. Log in as `maria_garcia` (the creator) to see
    // and respond to the pending request, or as `amy_taylor` (the requester) to see "you have a
    // pending request to join".
    private function createGroupTherapyMembershipRequestDemoData($users)
    {
        $creator = $users->firstWhere('username', 'maria_garcia');
        throw_if(! $creator, new RuntimeException('Membership request demo seeder: expected seed user "maria_garcia" not found — did createDemoUsers() run first?'));

        $requester = $users->firstWhere('username', 'amy_taylor');
        throw_if(! $requester, new RuntimeException('Membership request demo seeder: expected seed user "amy_taylor" not found — did createDemoUsers() run first?'));

        $groupTherapy = $creator->addedGroupTherapies()->create([
            'name' => 'Membership Request Demo Group Therapy',
            'about' => 'Seeded group therapy that requires a request to join, for testing the SCRUM-72 membership request accept/reject flow.',
            'session_type' => 'Periodic',
            'payment_type' => 'FREE',
            'max_users' => 10,
            'allow_anyone' => false,
            'anonymous' => false,
            'public' => true,
            'status' => 'pending',
        ]);

        $request = $requester->sentRequests()->create([
            'data' => ['anonymous' => false],
            'type' => RequestTypeEnum::groupTherapyMembership->value,
            'status' => RequestStatusEnum::pending->value,
        ]);
        $request->to()->associate($creator);
        $request->for()->associate($groupTherapy);
        $request->save();
    }

    private function createDemoPosts($counsellors, $users)
    {
        $postTopics = [
            'Understanding Anxiety: Signs and Management',
            'Building Healthy Relationships',
            'Coping with Depression: Daily Strategies',
            'The Importance of Mental Health Breaks',
            'Mindfulness Techniques for Stress Relief',
            'Setting Boundaries in Your Personal Life',
            'Recognizing Trauma Responses',
            'Self-Care Is Not Selfish',
            'Communication Skills for Better Relationships',
            'Dealing with Grief and Loss',
            'Managing Work-Life Balance',
            'Understanding Your Emotions',
            'Building Self-Esteem and Confidence',
            'Healthy Sleep Habits for Mental Wellness',
            'The Power of Gratitude Practice',
        ];

        $counsellorUsers = $counsellors->map(fn ($counsellor) => $counsellor->user);

        // Create posts from counsellors
        foreach ($counsellors as $counsellor) {
            $numberOfPosts = rand(2, 5);

            for ($i = 0; $i < $numberOfPosts; $i++) {
                $topic = fake()->randomElement($postTopics);

                $post = $counsellor->user->addedPosts()->create([
                    'content' => "**{$topic}**\n\n".$this->generatePostContent($topic),
                    'addedby_type' => $counsellor->user::class,
                    'addedby_id' => $counsellor->user->id,
                    'created_at' => fake()->dateTimeBetween('-3 months', 'now'),
                ]);

                // Add some likes from regular users
                $likers = $users->where('id', '!=', $counsellor->user->id)->random(rand(2, 8));
                foreach ($likers as $liker) {
                    $post->likes()->create([
                        'user_id' => $liker->id,
                    ]);
                }

                // Add some comments from users
                $commenters = $users->where('id', '!=', $counsellor->user->id)->random(rand(1, 4));
                foreach ($commenters as $commenter) {
                    $post->comments()->create([
                        'content' => fake()->paragraph(rand(1, 3)),
                        'user_id' => $commenter->id,
                        'created_at' => fake()->dateTimeBetween($post->created_at, 'now'),
                    ]);
                }
            }
        }

        // Create a few posts from regular users as well
        $regularUsers = $users->whereNotIn('id', $counsellorUsers->pluck('id'));
        foreach ($regularUsers->random(3) as $user) {
            $personalTopics = [
                'My Journey with Therapy',
                'Finding Hope in Dark Times',
                'What I Wish I Knew About Mental Health',
                'Support Groups Have Changed My Life',
                'Celebrating Small Victories',
            ];

            $post = $user->addedPosts()->create([
                'content' => '**'.fake()->randomElement($personalTopics)."**\n\n".fake()->paragraphs(rand(2, 4), true),
                'addedby_type' => $user::class,
                'addedby_id' => $user->id,
                'created_at' => fake()->dateTimeBetween('-2 months', 'now'),
            ]);

            // Add likes and comments from counsellors and other users
            $likers = $users->where('id', '!=', $user->id)->random(rand(3, 6));
            foreach ($likers as $liker) {
                $post->likes()->create([
                    'user_id' => $liker->id,
                ]);
            }
        }
    }

    private function generatePostContent($topic)
    {
        $contentMap = [
            'Understanding Anxiety' => "Anxiety is a normal human emotion, but when it becomes overwhelming, it can significantly impact our daily lives. Recognizing the signs early is crucial for effective management.\n\nCommon symptoms include persistent worry, restlessness, difficulty concentrating, and physical symptoms like rapid heartbeat or sweating. The good news is that anxiety is highly treatable through various approaches including therapy, mindfulness practices, and lifestyle changes.\n\nSome practical strategies include deep breathing exercises, regular physical activity, maintaining a consistent sleep schedule, and limiting caffeine intake. Remember, seeking professional help is a sign of strength, not weakness.",

            'Building Healthy Relationships' => "Healthy relationships are built on mutual respect, trust, and open communication. They require effort from all parties involved and contribute significantly to our overall mental well-being.\n\nKey components include setting clear boundaries, practicing active listening, expressing feelings honestly, and showing appreciation for one another. It's important to remember that healthy relationships aren't perfect – they involve navigating conflicts constructively and growing together.\n\nIf you find yourself in toxic relationship patterns, consider seeking support to develop healthier communication skills and boundary-setting techniques.",

            'Coping with Depression' => "Depression affects millions of people and can make even simple daily tasks feel overwhelming. Understanding that depression is a medical condition, not a personal failing, is the first step toward healing.\n\nDaily strategies that can help include maintaining a routine, getting adequate sleep, engaging in physical activity, and staying connected with supportive people. Even small accomplishments should be celebrated.\n\nProfessional treatment, including therapy and sometimes medication, can be incredibly effective. Remember that recovery is possible, and you don't have to face this alone.",

            'default' => "Mental health is just as important as physical health, yet it's often overlooked or stigmatized. Taking care of our emotional and psychological well-being should be a priority for everyone.\n\nThis means paying attention to our thoughts, feelings, and behaviors, and seeking help when we need it. There's no shame in talking to a therapist, counselor, or trusted friend about what you're going through.\n\nRemember that everyone's mental health journey is unique. What works for one person may not work for another, and that's okay. The important thing is to keep trying and to be patient with yourself as you navigate your path to wellness.",
        ];

        foreach ($contentMap as $key => $content) {
            if (str_contains($topic, $key)) {
                return $content;
            }
        }

        return $contentMap['default'];
    }

    // SCRUM-288: a missing date of birth counts as a minor (user's decision, 2026-11-03), so a client
    // with no dob and no guardian has their therapy paused. Every seeded persona without a dob is meant
    // to be an adult (the minors are seeded with one), so they get an adult dob here, last -- otherwise
    // maria_garcia and most other demo clients would be paused and their demos unusable.
    private function giveDemoAdultsADateOfBirth(): void
    {
        User::withTrashed()->whereNull('dob')
            ->where('username', '!=', self::NO_DOB_DEMO_USERNAME)
            ->update(['dob' => now()->subYears(30)->toDateString()]);
    }

    // SCRUM-301: a counsellor who hasn't been verified yet, with everything the verification form
    // checks first (an adult with a gender, a counsellor email and phone, the email verified), so its
    // "request verification" form -- now requiring the licence document -- can be tried. Every other
    // seeded counsellor is already verified, so none of them shows that form.
    private function createUnverifiedCounsellorDemoData(): void
    {
        $user = User::factory()->create([
            'firstName' => 'Unverified',
            'lastName' => 'Counsellor',
            'email' => 'unverified.counsellor@example.com',
            'username' => 'unverified_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'gender' => GenderEnum::female->value,
            'dob' => now()->subYears(35)->toDateString(),
        ]);

        $user->counsellor()->create([
            'name' => 'Dr. Unverified Counsellor',
            'about' => 'Seeded counsellor awaiting verification, for trying the verification request (SCRUM-301).',
            'email' => $user->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => null,
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);
    }

    // SCRUM-134: two dedicated counsellor accounts for testing the account-deletion feature --
    // one with no blocking state (deletion should succeed) and one with an in-session therapy
    // (deletion should be rejected by EnsureCanDeleteCounsellorAction's eligibility gate).
    private function createCounsellorDeletionDemoData(): void
    {
        $deletableUser = User::factory()->create([
            'firstName' => 'Deletable',
            'lastName' => 'Counsellor',
            'email' => 'deletable.counsellor@example.com',
            'username' => 'deletable_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $deletableUser->counsellor()->create([
            'name' => 'Dr. Deletable Counsellor',
            'about' => 'Seeded counsellor with no pending sessions, therapies, or affiliations -- account deletion should succeed for this account (SCRUM-134).',
            'email' => $deletableUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);

        $blockedUser = User::factory()->create([
            'firstName' => 'Blocked',
            'lastName' => 'Counsellor',
            'email' => 'blocked.counsellor@example.com',
            'username' => 'blocked_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $blockedCounsellor = $blockedUser->counsellor()->create([
            'name' => 'Dr. Blocked Counsellor',
            'about' => 'Seeded counsellor with an in-session therapy -- account deletion should be rejected for this account (SCRUM-134).',
            'email' => $blockedUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);

        $blockedCounsellorClient = User::factory()->create([
            'firstName' => 'Blocked',
            'lastName' => 'CounsellorClient',
            'email' => 'blocked.counsellor.client@example.com',
            'username' => 'blocked_counsellor_client',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $blockedCounsellorClient->addedTherapies()->create([
            'name' => 'Counsellor Deletion Demo Therapy',
            'background_story' => 'Seeded in-session therapy, kept in_session on purpose so Dr. Blocked Counsellor cannot be deleted (SCRUM-134).',
            'counsellor_id' => $blockedCounsellor->id,
            'session_type' => 'Once',
            'payment_type' => 'FREE',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
        ]);
    }

    private function createPaymentDemoData(): void
    {
        $client = User::factory()->create([
            'firstName' => 'Payment',
            'lastName' => 'DemoClient',
            'email' => 'payment.demo.client@example.com',
            'username' => 'payment_demo_client',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $counsellorUser = User::factory()->create([
            'firstName' => 'Payment',
            'lastName' => 'DemoCounsellor',
            'email' => 'payment.demo.counsellor@example.com',
            'username' => 'payment_demo_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $counsellor = $counsellorUser->counsellor()->create([
            'name' => 'Dr. Payment DemoCounsellor',
            'about' => 'Seeded counsellor for testing the payment UI (SCRUM-157/158).',
            'email' => $counsellorUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);

        // PER_THERAPY: the whole therapy is charged once, via TherapyPaymentDetails.vue's "pay now".
        $client->addedTherapies()->create([
            'name' => 'Payment Demo Therapy (Per Therapy)',
            'background_story' => 'Seeded PAID, PER_THERAPY therapy for testing the client Pay Now action (SCRUM-157).',
            'counsellor_id' => $counsellor->id,
            'session_type' => 'Once',
            'payment_type' => 'PAID',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'pending',
            'payment_data' => [
                'amount' => 150,
                'currency' => 'USD',
                'per' => 'PER_THERAPY',
            ],
        ]);

        // PER_SESSION: each session is charged individually, via the session-actions modal's
        // "pay now" once that session is active.
        $perSessionTherapy = $client->addedTherapies()->create([
            'name' => 'Payment Demo Therapy (Per Session)',
            'background_story' => 'Seeded PAID, PER_SESSION therapy for testing the per-session Pay Now action (SCRUM-157).',
            'counsellor_id' => $counsellor->id,
            'session_type' => 'Periodic',
            'payment_type' => 'PAID',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
            'payment_data' => [
                'amount' => 50,
                'currency' => 'USD',
                'per' => 'PER_SESSION',
            ],
        ]);

        // Kept within 5 minutes of "now" so it's immediately the therapy's activeSession -- the
        // Pay Now control in the session-actions modal only appears once a session is active
        // (mirrors how "start session"/"end session" already work).
        $this->seedSession($counsellor, $perSessionTherapy, [
            'name' => 'Payment Demo Session',
            'about' => 'Seeded PAID session, immediately active so its Pay Now control is reachable without waiting.',
            'start_time' => now()->addMinutes(2),
            'end_time' => now()->addMinutes(62),
            'type' => 'online',
            'status' => 'pending',
            'payment_type' => 'PAID',
        ]);
    }

    private function createRefundDemoData(): void
    {
        $client = User::factory()->create([
            'firstName' => 'Refund',
            'lastName' => 'DemoClient',
            'email' => 'refund.demo.client@example.com',
            'username' => 'refund_demo_client',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $counsellorUser = User::factory()->create([
            'firstName' => 'Refund',
            'lastName' => 'DemoCounsellor',
            'email' => 'refund.demo.counsellor@example.com',
            'username' => 'refund_demo_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $counsellor = $counsellorUser->counsellor()->create([
            'name' => 'Dr. Refund DemoCounsellor',
            'about' => 'Seeded counsellor for testing the refund-request UI (SCRUM-250).',
            'email' => $counsellorUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);

        // PER_THERAPY: already SUCCESS -- TherapyPaymentDetails.vue's "request a refund" control
        // is reachable immediately, without needing a real Paystack checkout first.
        $perTherapyTherapy = $client->addedTherapies()->create([
            'name' => 'Refund Demo Therapy (Per Therapy)',
            'background_story' => 'Seeded PAID, PER_THERAPY therapy with a SUCCESS transaction, for testing the client refund-request action (SCRUM-250).',
            'counsellor_id' => $counsellor->id,
            'session_type' => 'Once',
            'payment_type' => 'PAID',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
            'payment_data' => [
                'amount' => 120,
                'currency' => 'USD',
                'per' => 'PER_THERAPY',
            ],
        ]);

        Transaction::query()->create([
            'for_type' => $perTherapyTherapy::class,
            'for_id' => $perTherapyTherapy->id,
            'user_id' => $client->id,
            'reference' => 'refund_demo_'.fake()->unique()->uuid(),
            'amount' => 12000,
            'currency' => 'USD',
            'status' => TransactionStatusEnum::success->value,
        ]);

        // PER_SESSION: an already-SUCCESS, immediately-active session -- the session-actions
        // modal's own "request a refund" control is reachable the same way.
        $perSessionTherapy = $client->addedTherapies()->create([
            'name' => 'Refund Demo Therapy (Per Session)',
            'background_story' => 'Seeded PAID, PER_SESSION therapy with a SUCCESS session transaction, for testing the client refund-request action (SCRUM-250).',
            'counsellor_id' => $counsellor->id,
            'session_type' => 'Periodic',
            'payment_type' => 'PAID',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
            'payment_data' => [
                'amount' => 40,
                'currency' => 'USD',
                'per' => 'PER_SESSION',
            ],
        ]);

        // Kept within 5 minutes of "now", mirroring createPaymentDemoData()'s own identical
        // precedent, so it's immediately the therapy's activeSession.
        $session = $this->seedSession($counsellor, $perSessionTherapy, [
            'name' => 'Refund Demo Session',
            'about' => 'Seeded PAID, already-SUCCESS session, immediately active so its refund-request control is reachable without waiting.',
            'start_time' => now()->addMinutes(2),
            'end_time' => now()->addMinutes(62),
            'type' => 'online',
            'status' => 'pending',
            'payment_type' => 'PAID',
        ]);

        Transaction::query()->create([
            'for_type' => $session::class,
            'for_id' => $session->id,
            'user_id' => $client->id,
            'reference' => 'refund_demo_'.fake()->unique()->uuid(),
            'amount' => 4000,
            'currency' => 'USD',
            'status' => TransactionStatusEnum::success->value,
        ]);

        // TT-7.7c/SCRUM-251 (reviewer finding): a third, dedicated therapy/transaction with an
        // already-PENDING refund `Request` on it -- without this, the admin review queue
        // (/administrator/refund-requests) would be empty until someone manually submits a
        // refund request through the UI first, which CLAUDE.md's own seeding convention says to
        // avoid. Kept separate from the two therapies above (still meant for exercising the
        // client-side "ask" flow itself) rather than pre-filling their own requests.
        $adminQueueTherapy = $client->addedTherapies()->create([
            'name' => 'Refund Demo Therapy (Pending Admin Review)',
            'background_story' => 'Seeded PAID, PER_THERAPY therapy with an already-PENDING refund request, for testing the admin review queue (SCRUM-251).',
            'counsellor_id' => $counsellor->id,
            'session_type' => 'Once',
            'payment_type' => 'PAID',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
            'payment_data' => [
                'amount' => 80,
                'currency' => 'USD',
                'per' => 'PER_THERAPY',
            ],
        ]);

        $adminQueueTransaction = Transaction::query()->create([
            'for_type' => $adminQueueTherapy::class,
            'for_id' => $adminQueueTherapy->id,
            'user_id' => $client->id,
            'reference' => 'refund_demo_'.fake()->unique()->uuid(),
            'amount' => 8000,
            'currency' => 'USD',
            'status' => TransactionStatusEnum::success->value,
        ]);

        $refundRequest = $client->sentRequests()->create([
            'data' => ['reason' => 'I was charged twice for the same therapy session by mistake.'],
            'type' => RequestTypeEnum::refund->value,
            'status' => RequestStatusEnum::pending->value,
        ]);
        $refundRequest->for()->associate($adminQueueTransaction);
        $refundRequest->save();

        // TT-7.7e/SCRUM-253: a fourth, dedicated therapy already carrying a SUCCESS Refund --
        // without this, the "Refunded" label (both the client's and counsellor's own payment-
        // status indicator) would be unreachable without manually driving a request all the way
        // through TT-7.7c's approve action first, which CLAUDE.md's own seeding convention says
        // to avoid. `paymentStatus` deliberately stays SUCCESS (refunds never mutate it -- see
        // TT-7.7a's decision-log entry), so this also doubles as a regression check that the UI
        // reads refundStatus, not a stale paymentStatus, to decide what to show.
        $refundedTherapy = $client->addedTherapies()->create([
            'name' => 'Refund Demo Therapy (Refunded)',
            'background_story' => 'Seeded PAID, PER_THERAPY therapy that has already been successfully refunded, for testing the "Refunded" payment-status label (SCRUM-253).',
            'counsellor_id' => $counsellor->id,
            'session_type' => 'Once',
            'payment_type' => 'PAID',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
            'payment_data' => [
                'amount' => 100,
                'currency' => 'USD',
                'per' => 'PER_THERAPY',
            ],
        ]);

        $refundedTransaction = Transaction::query()->create([
            'for_type' => $refundedTherapy::class,
            'for_id' => $refundedTherapy->id,
            'user_id' => $client->id,
            'reference' => 'refund_demo_'.fake()->unique()->uuid(),
            'amount' => 10000,
            'currency' => 'USD',
            'status' => TransactionStatusEnum::success->value,
        ]);

        Refund::query()->create([
            'transaction_id' => $refundedTransaction->id,
            'requested_by_id' => $client->id,
            'reference' => 'refund_demo_'.fake()->unique()->uuid(),
            'amount' => 10000,
            'currency' => 'USD',
            'reason' => 'Seeded already-successful refund.',
            'status' => RefundStatusEnum::success->value,
        ]);

        // SCRUM-368: a PUBLIC paid therapy -- its "Paid" status shows to refund_demo_client,
        // refund_demo_counsellor and admins, never to anyone else browsing it.
        $publicTherapy = $client->addedTherapies()->create([
            'name' => 'Refund Demo Therapy (Public)',
            'background_story' => 'Seeded PUBLIC, PAID, PER_THERAPY therapy with a SUCCESS transaction (SCRUM-368).',
            'counsellor_id' => $counsellor->id,
            'session_type' => 'Once',
            'payment_type' => 'PAID',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => true,
            'status' => 'in_session',
            'payment_data' => ['amount' => 50, 'currency' => 'USD', 'per' => 'PER_THERAPY'],
        ]);

        Transaction::query()->create([
            'for_type' => $publicTherapy::class,
            'for_id' => $publicTherapy->id,
            'user_id' => $client->id,
            'reference' => 'refund_demo_'.fake()->unique()->uuid(),
            'amount' => 5000,
            'currency' => 'USD',
            'status' => TransactionStatusEnum::success->value,
        ]);
    }

    private function createGroupPaymentDemoData(): void
    {
        $memberPaid = User::factory()->create([
            'firstName' => 'GroupPayment',
            'lastName' => 'DemoMemberPaid',
            'email' => 'group.payment.demo.member.paid@example.com',
            'username' => 'group_payment_demo_member_paid',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $memberUnpaid = User::factory()->create([
            'firstName' => 'GroupPayment',
            'lastName' => 'DemoMemberUnpaid',
            'email' => 'group.payment.demo.member.unpaid@example.com',
            'username' => 'group_payment_demo_member_unpaid',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $counsellorUser = User::factory()->create([
            'firstName' => 'GroupPayment',
            'lastName' => 'DemoCounsellor',
            'email' => 'group.payment.demo.counsellor@example.com',
            'username' => 'group_payment_demo_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $counsellor = $counsellorUser->counsellor()->create([
            'name' => 'Dr. GroupPayment DemoCounsellor',
            'about' => 'Seeded counsellor for testing the group-therapy per-member payment UI (SCRUM-259).',
            'email' => $counsellorUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);

        // PER_THERAPY: memberPaid has already paid their own flat per-head share; memberUnpaid has
        // not -- logging in as either exercises the viewer-scoped "Paid"/"pay now" split introduced
        // by TT-7.4d-a/b (each member must see THEIR OWN status, not whichever member paid first).
        $groupTherapy = $memberPaid->addedGroupTherapies()->create([
            'name' => 'Group Payment Demo (Per Therapy)',
            'about' => 'Seeded PAID, PER_THERAPY group therapy for testing the per-member Pay Now action (SCRUM-259).',
            'session_type' => 'Once',
            'payment_type' => 'PAID',
            'max_users' => 10,
            'allow_anyone' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'pending',
            'payment_data' => [
                'amount' => 100,
                'currency' => 'USD',
                'per' => 'PER_THERAPY',
            ],
        ]);
        $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE']);
        $groupTherapy->users()->attach($memberPaid->id, ['anonymous' => false, 'background_story' => 'test']);
        $groupTherapy->users()->attach($memberUnpaid->id, ['anonymous' => false, 'background_story' => 'test']);

        Transaction::query()->create([
            'for_type' => $groupTherapy::class,
            'for_id' => $groupTherapy->id,
            'user_id' => $memberPaid->id,
            'reference' => 'group_payment_demo_'.fake()->unique()->uuid(),
            'amount' => 10000,
            'currency' => 'USD',
            'status' => TransactionStatusEnum::success->value,
        ]);

        // PER_SESSION: same paid/unpaid pairing, but on an immediately-active session instead of
        // the group as a whole -- mirrors createPaymentDemoData()'s own identical precedent so the
        // session-actions modal's Pay Now control is reachable without waiting.
        $perSessionGroupTherapy = $memberPaid->addedGroupTherapies()->create([
            'name' => 'Group Payment Demo (Per Session)',
            'about' => 'Seeded PAID, PER_SESSION group therapy for testing the per-member Pay Now action (SCRUM-259).',
            'session_type' => 'Periodic',
            'payment_type' => 'PAID',
            'max_users' => 10,
            'allow_anyone' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
            'payment_data' => [
                'amount' => 50,
                'currency' => 'USD',
                'per' => 'PER_SESSION',
            ],
        ]);
        $perSessionGroupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE']);
        $perSessionGroupTherapy->users()->attach($memberPaid->id, ['anonymous' => false, 'background_story' => 'test']);
        $perSessionGroupTherapy->users()->attach($memberUnpaid->id, ['anonymous' => false, 'background_story' => 'test']);

        $session = $this->seedSession($counsellor, $perSessionGroupTherapy, [
            'name' => 'Group Payment Demo Session',
            'about' => 'Seeded PAID session, immediately active so its per-member Pay Now control is reachable without waiting.',
            'start_time' => now()->addMinutes(2),
            'end_time' => now()->addMinutes(62),
            'type' => 'online',
            'status' => 'pending',
            'payment_type' => 'PAID',
        ]);

        Transaction::query()->create([
            'for_type' => $session::class,
            'for_id' => $session->id,
            'user_id' => $memberPaid->id,
            'reference' => 'group_payment_demo_'.fake()->unique()->uuid(),
            'amount' => 5000,
            'currency' => 'USD',
            'status' => TransactionStatusEnum::success->value,
        ]);
    }

    private function createGroupStrictPaymentGateDemoData(): void
    {
        $counsellorUser = User::factory()->create([
            'firstName' => 'GroupStrictGate',
            'lastName' => 'DemoCounsellor',
            'email' => 'group.strict.gate.demo.counsellor@example.com',
            'username' => 'group_strict_gate_demo_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $counsellor = $counsellorUser->counsellor()->create([
            'name' => 'Dr. GroupStrictGate DemoCounsellor',
            'about' => 'Seeded counsellor for testing the GroupTherapy strict-payment-gate/late-joiner UI (SCRUM-270).',
            'email' => $counsellorUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);

        $memberUnpaid = User::factory()->create([
            'firstName' => 'GroupStrictGate',
            'lastName' => 'DemoMemberUnpaid',
            'email' => 'group.strict.gate.demo.member.unpaid@example.com',
            'username' => 'group_strict_gate_demo_member_unpaid',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $lateJoiner = User::factory()->create([
            'firstName' => 'GroupStrictGate',
            'lastName' => 'DemoLateJoiner',
            'email' => 'group.strict.gate.demo.late.joiner@example.com',
            'username' => 'group_strict_gate_demo_late_joiner',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        // strictPaymentGate ON, allowFreeHistoricalAccess ON (the default) -- logging in as
        // memberUnpaid demonstrates the payment-required redirect/banner at page load
        // (TT-7.5b-b2); logging in as lateJoiner (joined well after the group's own creation and
        // its "old" session below) demonstrates that old content stays free while a session dated
        // after their join still requires payment (TT-7.5b-b3's late-joiner exemption).
        $groupTherapy = $counsellorUser->addedGroupTherapies()->create([
            'name' => 'Group Strict Payment Gate Demo',
            'about' => 'Seeded strict-gated PAID group therapy for testing the payment-required redirect and late-joiner free-historical-access exemption (SCRUM-270).',
            'session_type' => 'Periodic',
            'payment_type' => 'PAID',
            'max_users' => 10,
            'allow_anyone' => true,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
            'payment_data' => [
                'amount' => 100,
                'currency' => 'USD',
                'per' => 'PER_THERAPY',
                'strictPaymentGate' => true,
                'allowFreeHistoricalAccess' => true,
            ],
        ]);
        $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE']);
        $groupTherapy->users()->attach($memberUnpaid->id, [
            'anonymous' => false, 'background_story' => 'test',
        ]);
        // Joined 5 days ago -- well after the group itself and its "old" session below, so their
        // own join date sits strictly between the two seeded sessions.
        $groupTherapy->users()->attach($lateJoiner->id, [
            'anonymous' => false, 'background_story' => 'test', 'created_at' => now()->subDays(5),
        ]);

        // Predates lateJoiner's own join -- stays visible to them for free per
        // allowFreeHistoricalAccess, even though they've never paid.
        $this->seedSession($counsellor, $groupTherapy, [
            'name' => 'Group Strict Gate Demo Session (Before Late Joiner)',
            'about' => 'Seeded session dated before the late joiner\'s own join date -- stays free for them under allowFreeHistoricalAccess.',
            'start_time' => now()->subDays(20),
            'end_time' => now()->subDays(20)->addHour(),
            'type' => 'online',
            'status' => 'held',
            'payment_type' => 'PAID',
        ]);

        // Postdates lateJoiner's own join -- requires payment for them, same as memberUnpaid.
        $this->seedSession($counsellor, $groupTherapy, [
            'name' => 'Group Strict Gate Demo Session (After Late Joiner)',
            'about' => 'Seeded session dated after the late joiner\'s own join date -- requires payment for them, same as any other unpaid member.',
            'start_time' => now()->subDay(),
            'end_time' => now()->subDay()->addHour(),
            'type' => 'online',
            'status' => 'held',
            'payment_type' => 'PAID',
        ]);
    }

    private function createGuardianVideoConsentDemoData(): void
    {
        $minor = User::factory()->create([
            'firstName' => 'VideoConsent',
            'lastName' => 'DemoMinor',
            'email' => 'video.consent.demo.minor@example.com',
            'username' => 'video_consent_demo_minor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'dob' => now()->subYears(15)->toDateString(),
        ]);

        // SCRUM-288: adult -- a guardian with no date of birth doesn't count (Guardianship::scopeActive).
        $guardian = User::factory()->adult()->create([
            'firstName' => 'VideoConsent',
            'lastName' => 'DemoGuardian',
            'email' => 'video.consent.demo.guardian@example.com',
            'username' => 'video_consent_demo_guardian',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        // TT-4.10a/SCRUM-290: this seeder bypasses RespondToGuardianshipRequestAction (the only
        // real write path for this snapshot), so ward_was_minor_at_creation must be set
        // explicitly here -- otherwise it defaults to null ("not applicable"), which would
        // misrepresent this deliberately-a-minor demo scenario once TT-4.10b starts reading it.
        Guardianship::query()->create([
            'guardian_id' => $guardian->id,
            'ward_id' => $minor->id,
            'ward_was_minor_at_creation' => ! $minor->isAdult(),
        ]);

        $counsellorUser = User::factory()->create([
            'firstName' => 'VideoConsent',
            'lastName' => 'DemoCounsellor',
            'email' => 'video.consent.demo.counsellor@example.com',
            'username' => 'video_consent_demo_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $counsellor = $counsellorUser->counsellor()->create([
            'name' => 'Dr. VideoConsent DemoCounsellor',
            'about' => 'Seeded counsellor for testing the guardian video-consent UI (SCRUM-285).',
            'email' => $counsellorUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);

        // PER_THERAPY mode already set, and deliberately left WITHOUT a grant -- exercises the
        // "blocked, guardian must approve" golden path immediately, rather than requiring a mode
        // to be set by hand before anything else can be tested.
        $therapy = $minor->addedTherapies()->create([
            'name' => 'Video Consent Demo Therapy',
            'background_story' => 'Seeded minor-client therapy for testing the guardian video-consent tab, approve/revoke controls, and the consent-required video-join banner (SCRUM-285).',
            'counsellor_id' => $counsellor->id,
            'session_type' => 'Once',
            'payment_type' => 'FREE',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
            'video_consent_mode' => 'PER_THERAPY',
            // TT-4.10a/SCRUM-290: bypasses CreateTherapyAction (the real write path), so set
            // explicitly -- see the Guardianship seed just above for the same reasoning.
            'client_was_minor_at_creation' => ! $minor->isAdult(),
        ]);

        // Immediately in-progress and online, so "join video" (and therefore the
        // VideoConsentRequiredBanner, before the guardian approves) is reachable without waiting.
        $this->seedSession($counsellor, $therapy, [
            'name' => 'Video Consent Demo Session',
            'about' => 'Seeded online, in-progress session for testing the video-join consent gate.',
            'start_time' => now()->subMinutes(5),
            'end_time' => now()->addHour(),
            'type' => 'online',
            'status' => 'in_session',
            'payment_type' => 'FREE',
        ]);
    }

    // TT-3.1f/SCRUM-279 closeout: a plain adult client + counsellor pair, no guardian-consent
    // complexity at all -- createGuardianVideoConsentDemoData() above is deliberately
    // minor-focused (it exists specifically to demo the consent gate), so it's the wrong fixture
    // to point at for simply trying the base dual-provider "join video" flow itself.
    private function createVideoCallDemoData(): void
    {
        $client = User::factory()->adult()->create([
            'firstName' => 'VideoCall',
            'lastName' => 'DemoClient',
            'email' => 'video.call.demo.client@example.com',
            'username' => 'video_call_demo_client',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $counsellorUser = User::factory()->create([
            'firstName' => 'VideoCall',
            'lastName' => 'DemoCounsellor',
            'email' => 'video.call.demo.counsellor@example.com',
            'username' => 'video_call_demo_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $counsellor = $counsellorUser->counsellor()->create([
            'name' => 'Dr. VideoCall DemoCounsellor',
            'about' => 'Seeded counsellor for testing the base video-call join/leave/end flow (SCRUM-279).',
            'email' => $counsellorUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);

        $therapy = $client->addedTherapies()->create([
            'name' => 'Video Call Demo Therapy',
            'background_story' => 'Seeded adult-client therapy for testing the base video-call join/leave/end flow across both providers (SCRUM-279) -- no guardian-consent complexity.',
            'counsellor_id' => $counsellor->id,
            'session_type' => 'Once',
            'payment_type' => 'FREE',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
        ]);

        // Immediately in-progress and online, so "join video" is reachable without waiting.
        $this->seedSession($counsellor, $therapy, [
            'name' => 'Video Call Demo Session',
            'about' => 'Seeded online, in-progress session for testing the base video-join flow.',
            'start_time' => now()->subMinutes(5),
            'end_time' => now()->addHour(),
            'type' => 'online',
            'status' => 'in_session',
            'payment_type' => 'FREE',
        ]);
    }

    // TT-3.2c/SCRUM-310 (qa-engineer finding): no seeded fixture existed for GroupTherapy video at
    // all -- verifying this ticket required hand-building data via tinker.
    private function createGroupVideoCallDemoData(): void
    {
        $counsellorUser = User::factory()->create([
            'firstName' => 'GroupVideoCall',
            'lastName' => 'DemoCounsellor',
            'email' => 'group.video.call.demo.counsellor@example.com',
            'username' => 'group_video_call_demo_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $counsellor = $counsellorUser->counsellor()->create([
            'name' => 'Dr. GroupVideoCall DemoCounsellor',
            'about' => 'Seeded counsellor for testing group video call join/removal (SCRUM-308/309/310).',
            'email' => $counsellorUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);

        $creator = User::factory()->adult()->create([
            'firstName' => 'GroupVideoCall',
            'lastName' => 'DemoCreator',
            'email' => 'group.video.call.demo.creator@example.com',
            'username' => 'group_video_call_demo_creator',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        // TT-3.2f-j/SCRUM-327: explicitly adult -- a bare factory user has a null dob, which this
        // platform treats as a minor, and a minor member can never raise a hand.
        $member = User::factory()->adult()->create([
            'firstName' => 'GroupVideoCall',
            'lastName' => 'DemoMember',
            'email' => 'group.video.call.demo.member@example.com',
            'username' => 'group_video_call_demo_member',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $groupTherapy = $creator->addedGroupTherapies()->create([
            'name' => 'Group Video Call Demo',
            'about' => 'Seeded FREE group therapy for testing group video call join/removal across both providers (SCRUM-308/309/310) -- no payment or guardian-consent complexity.',
            'session_type' => 'Once',
            'payment_type' => 'FREE',
            'max_users' => 10,
            'allow_anyone' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
        ]);
        $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE']);
        $groupTherapy->users()->attach($member->id, ['anonymous' => false, 'background_story' => 'test', 'was_minor_at_join' => false]);

        // TT-3.2f-j/SCRUM-327: the other two receive-only cases -- a minor member (watch only, no
        // raise-hand) and an anonymous member (granted audio-only, under a masked name).
        $minorMember = User::factory()->create([
            'firstName' => 'GroupVideoCall',
            'lastName' => 'DemoMinorMember',
            'email' => 'group.video.call.demo.minor.member@example.com',
            'username' => 'group_video_call_demo_minor_member',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'dob' => now()->subYears(15)->toDateString(),
        ]);
        $groupTherapy->users()->attach($minorMember->id, ['anonymous' => false, 'background_story' => 'test', 'was_minor_at_join' => true]);

        $anonymousMember = User::factory()->adult()->create([
            'firstName' => 'GroupVideoCall',
            'lastName' => 'DemoAnonymousMember',
            'email' => 'group.video.call.demo.anonymous.member@example.com',
            'username' => 'group_video_call_demo_anonymous_member',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $groupTherapy->users()->attach($anonymousMember->id, ['anonymous' => true, 'background_story' => 'test', 'was_minor_at_join' => false]);

        // Immediately in-progress and online, so "join video" is reachable without waiting.
        $session = new Session([
            'name' => 'Group Video Call Demo Session',
            'about' => 'Seeded online, in-progress session for testing the group video join/remove-participant flow.',
            'start_time' => now()->subMinutes(5),
            'end_time' => now()->addHour(),
            'type' => 'online',
            'status' => 'in_session',
            'payment_type' => 'FREE',
        ]);
        $session->for()->associate($groupTherapy);
        $counsellor->addedSessions()->save($session);

        // TT-3.2e/SCRUM-312 closeout (qa-engineer finding): no seeded fixture existed for the
        // minor-creator-hard-block case -- verifying it required hand-building data via tinker.
        // "Join video" is intentionally still VISIBLE to a minor creator (see TherapyComponent.vue's
        // own comment on why the frontend never pre-checks this), so this fixture's own value is in
        // exercising the backend's clean 422 block on click, not a hidden button.
        $minorCreator = User::factory()->create([
            'firstName' => 'GroupVideoCall',
            'lastName' => 'DemoMinorCreator',
            'email' => 'group.video.call.demo.minor.creator@example.com',
            'username' => 'group_video_call_demo_minor_creator',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'dob' => now()->subYears(15)->toDateString(),
        ]);

        // SCRUM-288: both minors here need an active guardian, or their therapies are paused (see
        // createPausedMinorDemoData()) and these fixtures stop showing what they were made for.
        $guardian = User::factory()->adult()->create([
            'firstName' => 'GroupVideoCall',
            'lastName' => 'DemoGuardian',
            'email' => 'group.video.call.demo.guardian@example.com',
            'username' => 'group_video_call_demo_guardian',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        foreach ([$minorMember, $minorCreator] as $ward) {
            Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id, 'ward_was_minor_at_creation' => true]);
        }

        $minorCreatedGroupTherapy = $minorCreator->addedGroupTherapies()->create([
            'name' => 'Group Video Call Demo (Minor Creator)',
            'about' => 'Seeded FREE group therapy whose own creator is a minor, for testing the hard video block (SCRUM-308) ahead of the group-scoped guardian-consent flow (SCRUM-313, unstarted).',
            'session_type' => 'Once',
            'payment_type' => 'FREE',
            'max_users' => 10,
            'allow_anyone' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
            // Bypasses CreateGroupTherapyAction (the real write path), so set explicitly -- same
            // reasoning as createGuardianVideoConsentDemoData()'s own identical comment.
            'client_was_minor_at_creation' => ! $minorCreator->isAdult(),
        ]);
        $minorCreatedGroupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE']);

        $minorCreatorSession = new Session([
            'name' => 'Group Video Call Demo Session (Minor Creator)',
            'about' => 'Seeded online, in-progress session for testing the group video minor-creator hard block.',
            'start_time' => now()->subMinutes(5),
            'end_time' => now()->addHour(),
            'type' => 'online',
            'status' => 'in_session',
            'payment_type' => 'FREE',
        ]);
        $minorCreatorSession->for()->associate($minorCreatedGroupTherapy);
        $counsellor->addedSessions()->save($minorCreatorSession);
    }

    private function createDobChangeNoGuardianDemoData(): void
    {
        $minor = User::factory()->create([
            'firstName' => 'DobChange',
            'lastName' => 'DemoNoGuardian',
            'email' => 'dobchange.demo.no.guardian@example.com',
            'username' => 'dobchange_demo_no_guardian',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'dob' => now()->subYears(15)->toDateString(),
        ]);

        // No Guardianship record for this user -- exercises the "no guardian exists, any admin
        // may respond" path immediately, via a pending self-service dob-change request created
        // the same way ProfileController::update() -> CreateRequestAction would (from == for ==
        // this minor, to left unassociated).
        $request = new Request;
        $request->type = RequestTypeEnum::dobChange->value;
        $request->status = RequestStatusEnum::pending->value;
        $request->data = [
            'newDob' => now()->subYears(30)->toDateString(),
            'priorDob' => $minor->dob->toDateString(),
        ];
        $request->from()->associate($minor);
        $request->for()->associate($minor);
        $request->save();
    }

    // TT-4.10f/SCRUM-295: exercises "any one of a ward's multiple guardians may respond," not just
    // the one the request's `to` happens to be fixed to at creation -- log in as the SECOND
    // guardian to confirm they can see and act on a request addressed to the first.
    private function createDobChangeMultiGuardianDemoData(): void
    {
        $minor = User::factory()->create([
            'firstName' => 'DobChange',
            'lastName' => 'DemoMultiGuardian',
            'email' => 'dobchange.demo.multi.guardian@example.com',
            'username' => 'dobchange_demo_multi_guardian',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'dob' => now()->subYears(15)->toDateString(),
        ]);

        $firstGuardian = User::factory()->adult()->create([
            'firstName' => 'DobChange',
            'lastName' => 'DemoFirstGuardian',
            'email' => 'dobchange.demo.first.guardian@example.com',
            'username' => 'dobchange_demo_first_guardian',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $secondGuardian = User::factory()->adult()->create([
            'firstName' => 'DobChange',
            'lastName' => 'DemoSecondGuardian',
            'email' => 'dobchange.demo.second.guardian@example.com',
            'username' => 'dobchange_demo_second_guardian',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        Guardianship::query()->create(['guardian_id' => $firstGuardian->id, 'ward_id' => $minor->id]);
        Guardianship::query()->create(['guardian_id' => $secondGuardian->id, 'ward_id' => $minor->id]);

        $request = new Request;
        $request->type = RequestTypeEnum::dobChange->value;
        $request->status = RequestStatusEnum::pending->value;
        $request->data = [
            'newDob' => now()->subYears(30)->toDateString(),
            'priorDob' => $minor->dob->toDateString(),
        ];
        $request->from()->associate($minor);
        $request->for()->associate($minor);
        $request->to()->associate($firstGuardian);
        $request->save();
    }

    // TT-4.11e/SCRUM-306: a normal adult user with a `dob` already set, plus a pending
    // ageVerification request (self-attestation only, no document -- document upload is
    // optional per the feature's own design) -- log in as any admin to see and act on it in the
    // Requests modal.
    private function createAgeVerificationDemoData(): void
    {
        $user = User::factory()->create([
            'firstName' => 'AgeVerification',
            'lastName' => 'DemoUser',
            'email' => 'age.verification.demo@example.com',
            'username' => 'age_verification_demo_user',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'dob' => now()->subYears(25)->toDateString(),
        ]);

        $request = new Request;
        $request->type = RequestTypeEnum::ageVerification->value;
        $request->status = RequestStatusEnum::pending->value;
        $request->data = [
            'attestation' => 'I confirm that the date of birth on my account is accurate.',
            'attestedDob' => $user->dob->toDateString(),
        ];
        $request->from()->associate($user);
        $request->for()->associate($user);
        $request->save();
    }

    private function createPayoutDemoData(): void
    {
        $client = User::factory()->create([
            'firstName' => 'Payout',
            'lastName' => 'DemoClient',
            'email' => 'payout.demo.client@example.com',
            'username' => 'payout_demo_client',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $counsellorUser = User::factory()->create([
            'firstName' => 'Payout',
            'lastName' => 'DemoCounsellor',
            'email' => 'payout.demo.counsellor@example.com',
            'username' => 'payout_demo_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $counsellor = $counsellorUser->counsellor()->create([
            'name' => 'Dr. Payout DemoCounsellor',
            'about' => 'Seeded counsellor with pending earnings for testing payout onboarding + withdrawal (SCRUM-228).',
            'email' => $counsellorUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);

        // Two separate PAID therapies/transactions, each backing one earning -- counsellor_earnings
        // has a unique (transaction_id, counsellor_id) constraint, one earning per transaction.
        $earningAmounts = [
            ['gross' => 9000, 'fee' => 900, 'net' => 8100],
            ['gross' => 6000, 'fee' => 600, 'net' => 5400],
        ];

        foreach ($earningAmounts as $index => $amounts) {
            $therapy = $client->addedTherapies()->create([
                'name' => 'Payout Demo Therapy '.($index + 1),
                'background_story' => 'Seeded PAID therapy whose transaction backs a demo counsellor earning (SCRUM-228).',
                'counsellor_id' => $counsellor->id,
                'session_type' => 'Once',
                'payment_type' => 'PAID',
                'allow_in_person' => false,
                'anonymous' => false,
                'public' => false,
                'status' => 'in_session',
                'payment_data' => [
                    'amount' => 100,
                    'currency' => 'GHS',
                    'per' => 'PER_THERAPY',
                ],
            ]);

            $transaction = Transaction::query()->create([
                'for_type' => $therapy::class,
                'for_id' => $therapy->id,
                'user_id' => $client->id,
                'reference' => 'payout_demo_'.fake()->unique()->uuid(),
                'amount' => $amounts['gross'],
                'currency' => 'GHS',
                'status' => TransactionStatusEnum::success->value,
            ]);

            // Both pending, in GHS -- their combined net amount clears the seeded GHS minimum
            // payout threshold (config('settings.minimum_payout_amount.GHS')) as soon as the
            // counsellor onboards a GHS payout destination, so the withdraw button is reachable
            // without any extra setup.
            CounsellorEarning::query()->create([
                'transaction_id' => $transaction->id,
                'counsellor_id' => $counsellor->id,
                'gross_amount' => $amounts['gross'],
                'fee_amount' => $amounts['fee'],
                'net_amount' => $amounts['net'],
                'currency' => 'GHS',
                'status' => CounsellorEarningStatusEnum::pending->value,
            ]);
        }
    }

    private function createOrganizationDashboardDemoData(): void
    {
        $admin = User::factory()->create([
            'firstName' => 'Org',
            'lastName' => 'DemoAdmin',
            'email' => 'org.demo.admin@example.com',
            'username' => 'org_demo_admin',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $organization = Organization::factory()->create([
            'name' => 'Org Demo Wellness Collective',
            'legal_name' => 'Org Demo Wellness Collective Ltd',
            'registration_number' => 'REG-ORGDEMO-001',
            'description' => 'Seeded organization for testing the admin dashboard (SCRUM-165). Both a provider (affiliates counsellors) and a consumer (sponsors members).',
            'email' => 'contact@orgdemo.example.com',
            'phone' => fake()->phoneNumber(),
            'is_provider' => true,
            'is_consumer' => true,
            'self_apply_enabled' => true,
            'verified_at' => now(),
        ]);
        $organization->admins()->attach($admin->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

        // TT-7.3b-i/SCRUM-240: a real registration requires a live Paystack checkout round trip
        // (no PAYSTACK_SECRET_KEY configured in this dev environment), so the "already has a
        // payment method on file" state of the new payment-instrument page can't otherwise be
        // reached here -- seeded directly instead, same convention as this method's other
        // already-settled demo rows.
        OrganizationPaymentInstrument::factory()->create([
            'organization_id' => $organization->id,
            'masked_card_number' => '**** 4242',
            'card_type' => 'visa',
            'bank' => 'Test Bank',
            'exp_month' => '12',
            'exp_year' => '2030',
            'currency' => 'GHS',
            'pending_credit_amount' => 100,
        ]);

        // A second, plain-role admin -- without this, exercising promote/demote/remove or the
        // last-owner-protection error (SCRUM-166) requires hand-creating an account via tinker
        // first, contrary to this project's seeding convention.
        $plainAdmin = User::factory()->create([
            'firstName' => 'Org',
            'lastName' => 'DemoPlainAdmin',
            'email' => 'org.demo.plain.admin@example.com',
            'username' => 'org_demo_plain_admin',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $organization->admins()->attach($plainAdmin->id, ['role' => OrganizationAdminRoleEnum::admin->value]);

        // An already-active affiliation, with agreed compensation, so the counsellors table
        // shows a fully-settled row alongside the pending application below.
        $affiliatedCounsellorUser = User::factory()->create([
            'firstName' => 'Org',
            'lastName' => 'DemoCounsellor',
            'email' => 'org.demo.counsellor@example.com',
            'username' => 'org_demo_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $affiliatedCounsellor = $affiliatedCounsellorUser->counsellor()->create([
            'name' => 'Dr. Org DemoCounsellor',
            'about' => 'Seeded counsellor already affiliated with the demo organization (SCRUM-165).',
            'email' => $affiliatedCounsellorUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);
        $affiliation = $organization->organizationCounsellors()->create([
            'counsellor_id' => $affiliatedCounsellor->id,
            'status' => OrganizationCounsellorStatusEnum::active->value,
            'source' => 'INVITED',
        ]);
        $affiliation->compensations()->create([
            'set_by_id' => $admin->id,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 2000,
            'currency' => 'USD',
            'effective_from' => now(),
        ]);

        // A pending compensation-CHANGE negotiation on top of the already-settled compensation
        // above -- an active affiliation can still have a new proposed change in flight (SCRUM-167),
        // distinct from the settled amount created just above. Exercises this counsellor's own
        // accept/reject/counter-offer UI on their my-organizations dashboard.
        $pendingCompensationChange = new Request([
            'type' => RequestTypeEnum::organizationCounsellorCompensationChange->value,
            'status' => RequestStatusEnum::pending->value,
            'round' => 1,
            'expires_at' => now()->addDays(7),
            'data' => [
                'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
                'amount' => 2500,
                'currency' => 'USD',
                // Accept resolves accountability for the accepted terms via this id (see
                // RespondToOrganizationCounsellorCompensationRequestAction) -- omitting it (as an
                // earlier version of this seed data did) made "accept" fail on this seeded row
                // with "the original proposer no longer exists" (QA-caught, SCRUM-167).
                'proposedById' => $admin->id,
            ],
        ]);
        $pendingCompensationChange->from()->associate($organization);
        $pendingCompensationChange->to()->associate($affiliatedCounsellor);
        $pendingCompensationChange->for()->associate($affiliation);
        $pendingCompensationChange->save();

        // A second counsellor with a pending APPLICATION request -- distinct from the affiliated
        // row above, per AC7's "pending Request" vs "pending affiliation" distinction.
        $applicantCounsellorUser = User::factory()->create([
            'firstName' => 'Org',
            'lastName' => 'DemoApplicant',
            'email' => 'org.demo.applicant@example.com',
            'username' => 'org_demo_applicant',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $applicantCounsellor = $applicantCounsellorUser->counsellor()->create([
            'name' => 'Dr. Org DemoApplicant',
            'about' => 'Seeded counsellor with a pending application to the demo organization (SCRUM-165).',
            'email' => $applicantCounsellorUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);
        // from/to/for are morph columns, deliberately excluded from Request::$fillable --
        // associate() (not create()) is required, mirroring CreateLinkAction's own pattern.
        $counsellorApplication = new Request([
            'type' => RequestTypeEnum::organizationCounsellorApplication->value,
            'status' => RequestStatusEnum::pending->value,
            'data' => [],
        ]);
        $counsellorApplication->from()->associate($applicantCounsellor);
        $counsellorApplication->to()->associate($organization);
        $counsellorApplication->for()->associate($organization);
        $counsellorApplication->save();

        // An active member with billing config set, plus a member with a pending self-apply
        // application -- same "settled vs. queued" pairing as the counsellor side above.
        $activeMember = User::factory()->create([
            'firstName' => 'Org',
            'lastName' => 'DemoMember',
            'email' => 'org.demo.member@example.com',
            'username' => 'org_demo_member',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $membership = $organization->members()->create([
            'user_id' => $activeMember->id,
            'status' => OrganizationMemberStatusEnum::active->value,
            'source' => 'INVITED',
        ]);
        $membership->billingConfigs()->create([
            'mode' => OrganizationMemberBillingModeEnum::retainer->value,
            'include_group_therapies' => true,
            'effective_from' => now(),
        ]);

        // SCRUM-242 (TT-7.3b-k): a PAID therapy between the retainer-covered member above and
        // the org's own affiliated counsellor -- exercises the client-facing org-billing
        // disclosure ("no payment needed from you") in place of a Pay control, on both the
        // PER_THERAPY surface (TherapyPaymentDetails.vue) and the PER_SESSION one
        // (UnifiedTherapy.vue's session-actions modal), mirroring createPaymentDemoData()'s
        // pairing above.
        $activeMember->addedTherapies()->create([
            'name' => 'Org Retainer Demo Therapy (Per Therapy)',
            'background_story' => 'Seeded PAID, PER_THERAPY therapy covered by the demo org\'s retainer billing -- no client payment ever due (SCRUM-242).',
            'counsellor_id' => $affiliatedCounsellor->id,
            'session_type' => 'Once',
            'payment_type' => 'PAID',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'pending',
            'payment_data' => [
                'amount' => 150,
                'currency' => 'USD',
                'per' => 'PER_THERAPY',
            ],
        ]);

        $orgRetainerPerSessionTherapy = $activeMember->addedTherapies()->create([
            'name' => 'Org Retainer Demo Therapy (Per Session)',
            'background_story' => 'Seeded PAID, PER_SESSION therapy covered by the demo org\'s retainer billing -- no client payment ever due (SCRUM-242).',
            'counsellor_id' => $affiliatedCounsellor->id,
            'session_type' => 'Periodic',
            'payment_type' => 'PAID',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
            'payment_data' => [
                'amount' => 50,
                'currency' => 'USD',
                'per' => 'PER_SESSION',
            ],
        ]);

        $orgRetainerDemoSession = $this->seedSession($affiliatedCounsellor, $orgRetainerPerSessionTherapy, [
            'name' => 'Org Retainer Demo Session',
            // Started a minute ago (not "starts in 5 minutes", unlike createPaymentDemoData()'s
            // sibling) -- getActiveSession() (used by TherapyResource's 'activeSession', which the
            // session-actions modal reads) requires start_time <= now(), so this is immediately
            // active with no window to wait out.
            'about' => 'Seeded PAID session, immediately active so the disclosure in the session-actions modal is reachable without waiting.',
            'start_time' => now()->subMinute(),
            'end_time' => now()->addHour(),
            'type' => 'online',
            'status' => 'pending',
            'payment_type' => 'PAID',
        ]);

        // SCRUM-241 (TT-7.3b-j): demo data for the org-admin reconciliation view, which had
        // nothing to show until now -- every session/therapy above stays at `pending`/`in_session`
        // (no real settlement pipeline ever ran against them), so these rows are hand-seeded at
        // their FINAL state directly, matching this seeder's own established convention elsewhere
        // (e.g. the already-settled compensation above) rather than simulating the full charge/
        // settlement pipeline end to end.

        // -- Pay-per-use financed session: a second, PAY_PER_USE member (distinct from the
        // retainer member above) with a real Transaction + CounsellorEarning + a SUCCEEDED payout,
        // so the "Financed Sessions (Pay-Per-Use)" table has a fully-resolved row to show.
        $payPerUseMember = User::factory()->create([
            'firstName' => 'Org',
            'lastName' => 'DemoPayPerUseMember',
            'email' => 'org.demo.payperuse.member@example.com',
            'username' => 'org_demo_payperuse_member',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $payPerUseMembership = $organization->members()->create([
            'user_id' => $payPerUseMember->id,
            'status' => OrganizationMemberStatusEnum::active->value,
            'source' => 'INVITED',
        ]);
        $payPerUseMembership->billingConfigs()->create([
            'mode' => OrganizationMemberBillingModeEnum::payPerUse->value,
            'per' => 'PER_THERAPY',
            'include_group_therapies' => true,
            'effective_from' => now(),
        ]);
        $payPerUseTherapy = $payPerUseMember->addedTherapies()->create([
            'name' => 'Org Pay-Per-Use Demo Therapy',
            'background_story' => 'Seeded PAID, pay-per-use therapy financed directly by the demo org\'s payment instrument (SCRUM-241).',
            'counsellor_id' => $affiliatedCounsellor->id,
            'session_type' => 'Once',
            'payment_type' => 'PAID',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
            'payment_data' => [
                'amount' => 100,
                'currency' => 'USD',
                'per' => 'PER_THERAPY',
            ],
        ]);
        $payPerUseTransaction = Transaction::query()->create([
            'for_type' => $payPerUseTherapy::class,
            'for_id' => $payPerUseTherapy->id,
            'user_id' => $payPerUseMember->id,
            'organization_id' => $organization->id,
            'reference' => 'org_demo_charge_'.Str::uuid(),
            'amount' => 8000,
            'currency' => 'USD',
            'status' => TransactionStatusEnum::success->value,
        ]);
        $payPerUsePayout = CounsellorPayout::query()->create([
            'counsellor_id' => $affiliatedCounsellor->id,
            'initiated_by_id' => $admin->id,
            'reference' => 'org_demo_payout_'.Str::uuid(),
            'amount' => 7000,
            'currency' => 'USD',
            'status' => CounsellorPayoutStatusEnum::succeeded->value,
        ]);
        CounsellorEarning::query()->create([
            'transaction_id' => $payPerUseTransaction->id,
            'counsellor_id' => $affiliatedCounsellor->id,
            'counsellor_payout_id' => $payPerUsePayout->id,
            'gross_amount' => 8000,
            'net_amount' => 7000,
            'fee_amount' => 1000,
            'currency' => 'USD',
            'status' => CounsellorEarningStatusEnum::paidOut->value,
        ]);

        // -- Retainer invoices: one already-settled (last period), one still open/accruing
        // (current period) -- demonstrates both halves of the ticket's own "current-period accrued
        // balance AND invoice/settlement status" requirement.
        $settledInvoice = OrganizationInvoice::query()->create([
            'organization_id' => $organization->id,
            'currency' => 'USD',
            'period_start' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            'status' => OrganizationInvoiceStatusEnum::settled->value,
            'amount' => 4500,
        ]);
        $settledInvoiceLine = $settledInvoice->lines()->create([
            'session_id' => $orgRetainerDemoSession->id,
            'counsellor_id' => $affiliatedCounsellor->id,
            'net_amount' => 4000,
            'fee_amount' => 500,
            'currency' => 'USD',
        ]);
        $settlementTransaction = Transaction::query()->create([
            'for_type' => OrganizationInvoice::class,
            'for_id' => $settledInvoice->id,
            'user_id' => $admin->id,
            'organization_id' => $organization->id,
            'reference' => 'org_demo_settlement_'.Str::uuid(),
            'amount' => 4500,
            'currency' => 'USD',
            'status' => TransactionStatusEnum::success->value,
        ]);
        CounsellorEarning::query()->create([
            'transaction_id' => $settlementTransaction->id,
            'counsellor_id' => $affiliatedCounsellor->id,
            'organization_invoice_line_id' => $settledInvoiceLine->id,
            'gross_amount' => 4500,
            'net_amount' => 4000,
            'fee_amount' => 500,
            'currency' => 'USD',
            'status' => CounsellorEarningStatusEnum::pending->value,
        ]);

        $openInvoiceSession = $this->seedSession($affiliatedCounsellor, $orgRetainerPerSessionTherapy, [
            'name' => 'Org Retainer Demo Session (Current Period)',
            'about' => 'A second retainer-covered session, already held, accruing against the CURRENT (still-open) invoice period.',
            'start_time' => now()->subDay(),
            'end_time' => now()->subDay()->addHour(),
            'type' => 'online',
            'status' => 'held',
            'payment_type' => 'PAID',
        ]);
        $openInvoice = OrganizationInvoice::query()->create([
            'organization_id' => $organization->id,
            'currency' => 'USD',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'status' => OrganizationInvoiceStatusEnum::open->value,
        ]);
        $openInvoice->lines()->create([
            'session_id' => $openInvoiceSession->id,
            'counsellor_id' => $affiliatedCounsellor->id,
            'net_amount' => 3500,
            'fee_amount' => 400,
            'currency' => 'USD',
        ]);

        $applicantMember = User::factory()->create([
            'firstName' => 'Org',
            'lastName' => 'DemoMemberApplicant',
            'email' => 'org.demo.member.applicant@example.com',
            'username' => 'org_demo_member_applicant',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $memberApplication = new Request([
            'type' => RequestTypeEnum::organizationMemberApplication->value,
            'status' => RequestStatusEnum::pending->value,
            'data' => [],
        ]);
        $memberApplication->from()->associate($applicantMember);
        $memberApplication->to()->associate($organization);
        $memberApplication->for()->associate($organization);
        $memberApplication->save();

        // A user with a pending org-INVITE (org-initiated, no membership row yet) -- distinct
        // from $applicantMember's self-initiated APPLICATION above, and needed to exercise a
        // member's accept/reject of an invite via the generic Requests inbox (SCRUM-168 AC2).
        $invitedMember = User::factory()->create([
            'firstName' => 'Org',
            'lastName' => 'DemoMemberInvitee',
            'email' => 'org.demo.member.invitee@example.com',
            'username' => 'org_demo_member_invitee',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $memberInvite = new Request([
            'type' => RequestTypeEnum::organizationMemberInvite->value,
            'status' => RequestStatusEnum::pending->value,
            'data' => [],
        ]);
        $memberInvite->from()->associate($organization);
        $memberInvite->to()->associate($invitedMember);
        $memberInvite->for()->associate($organization);
        $memberInvite->save();
    }

    // TT-7.3b-followup/SCRUM-245: log in as the super admin (mr_robertamoah) and visit
    // /administrator/organization-billing to see this row -- "retry settlement" (payment
    // instrument is on file, so a retry via Http::fake()-free real Paystack call will only
    // actually succeed with a live sandbox key; without one it fails the same graceful way
    // TT-7.3b-i's own registration flow does) and "lift suspension" are both directly usable here.
    private function createBillingSuspendedOrganizationDemoData(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Suspended Demo Collective',
            'is_consumer' => true,
            'verified_at' => now(),
        ]);
        $admin = User::factory()->create([
            'firstName' => 'Suspended',
            'lastName' => 'DemoAdmin',
            'email' => 'suspended.demo.admin@example.com',
            'username' => 'suspended_demo_admin',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $organization->admins()->attach($admin->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
        OrganizationPaymentInstrument::factory()->create(['organization_id' => $organization->id]);

        $invoice = OrganizationInvoice::factory()->create([
            'organization_id' => $organization->id,
            'status' => OrganizationInvoiceStatusEnum::failed->value,
        ]);
        OrganizationInvoiceLine::factory()->create([
            'organization_invoice_id' => $invoice->id,
            'net_amount' => 4500,
            'fee_amount' => 500,
            'currency' => $invoice->currency,
        ]);

        $organization->suspendBilling("Retainer invoice settlement failed for the period starting {$invoice->period_start->toDateString()}.");
    }

    // SCRUM-340: "Report Demo Group Therapy" (maria_garcia's, counselled by emma_williams, with
    // amy_taylor and robert_miller as anonymous members) and one finished session holding public and
    // confidential messages. Three reports by maria_garcia on that group:
    //  - "naming amy_taylor": open, reported person recorded -- its conversation can be read; only
    //    amy_taylor's confidential messages show, robert_miller's stays hidden;
    //  - "nobody named yet": open, no reported person -- the page asks you to record one first;
    //  - "already dismissed": closed, with history -- shows reopen, and nothing can be unlocked.
    private function createReportReviewDemoData(User $superAdmin): void
    {
        $maria = User::where('username', 'maria_garcia')->firstOrFail();
        $amy = User::where('username', 'amy_taylor')->firstOrFail();
        $robert = User::where('username', 'robert_miller')->firstOrFail();
        $emma = User::where('username', 'emma_williams')->firstOrFail()->counsellor;
        throw_if(! $emma, new RuntimeException('Report demo seeder: expected emma_williams to be a counsellor.'));

        $group = $maria->addedGroupTherapies()->create([
            'name' => 'Report Demo Group Therapy',
            'about' => 'Seeded group with confidential messages and reports, for trying the admin report review (SCRUM-340).',
            'session_type' => 'Once',
            'payment_type' => 'FREE',
            'max_users' => 10,
            'allow_anyone' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
        ]);
        $group->users()->attach($maria->id, ['anonymous' => false]);
        $group->users()->attach($amy->id, ['anonymous' => true]);
        $group->users()->attach($robert->id, ['anonymous' => true]);
        $group->counsellors()->attach($emma->id, ['state' => 'ACTIVE']);

        // for_* aren't fillable on Session, so the group is associated explicitly.
        $session = $emma->addedSessions()->make([
            'name' => 'Report Demo Session',
            'about' => 'Seeded finished session whose messages are reviewed in the report demo.',
            'start_time' => now()->subDays(2),
            'end_time' => now()->subDays(2)->addHour(),
            'type' => 'online',
            'status' => 'HELD',
        ]);
        $session->for()->associate($group);
        $session->save();

        $message = function ($from, $to, string $content, bool $confidential, int $minutes) use ($session) {
            $message = new Message(['content' => $content, 'type' => 'NORMAL', 'status' => 'SENT', 'confidential' => $confidential]);
            $message->from()->associate($from);
            if ($to) {
                $message->to()->associate($to);
            }
            $message->for()->associate($session);
            $message->created_at = $session->start_time->copy()->addMinutes($minutes);
            $message->save();

            return $message;
        };

        $message($emma, null, 'Welcome, everyone. Let us start by sharing how the week went.', false, 1);
        $message($amy, null, 'Honestly, some people here should keep their opinions to themselves.', false, 3);
        $message($amy, $emma, '[Report demo] Confidential from amy_taylor: I am going to make the creator regret posting.', true, 5);
        $message($robert, $emma, '[Report demo] Confidential from robert_miller: I am struggling to sleep. This one must stay hidden.', true, 6);
        $message($emma, $amy, '[Report demo] Confidential reply to amy_taylor: Please keep the group respectful.', true, 8);
        $message($amy, $emma, '[Report demo] Confidential from amy_taylor, later deleted for everyone.', true, 9)->delete();

        $report = function (string $description, array $data) use ($maria, $group) {
            $report = new Report(['description' => $description, 'data' => $data]);
            $report->addedby()->associate($maria);
            $report->reportable()->associate($group);
            $report->save();

            return $report;
        };

        $report('[Report demo: naming amy_taylor] A member has been hostile to me in the group and I think in private messages too.', ['userId' => $amy->id]);
        $report('[Report demo: nobody named yet] Someone in this group is making me uncomfortable.', []);

        $dismissed = $report('[Report demo: already dismissed] The counsellor ended the session early.', ['counsellorId' => $emma->id]);
        $dismissed->forceFill([
            'status' => ReportStatusEnum::dismissed,
            'resolution_note' => 'The session ended on time; no breach found.',
            'resolved_at' => now()->subDay(),
            'resolved_by' => $superAdmin->id,
        ])->save();
        ReportStatusHistory::create([
            'report_id' => $dismissed->id,
            'user_id' => $superAdmin->id,
            'event' => ReportStatusHistory::EVENT_STATUS_CHANGE,
            'from_status' => 'open',
            'to_status' => 'dismissed',
            'note' => 'The session ended on time; no breach found.',
        ]);
    }

    private function createSuspensionDemoData(): void
    {
        $user = fn (string $username, string $first, string $last) => User::factory()->create([
            'firstName' => $first,
            'lastName' => $last,
            'email' => str_replace('_', '.', $username).'@example.com',
            'username' => $username,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'dob' => now()->subYears(30)->toDateString(),
        ]);
        $counsellor = fn (User $user, string $about) => $user->counsellor()->create([
            'name' => "Dr. {$user->firstName} {$user->lastName}",
            'about' => $about,
            'email' => $user->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);
        $therapy = fn (User $client, Counsellor $counsellor, string $name) => $client->addedTherapies()->create([
            'name' => $name,
            'background_story' => 'Seeded therapy for trying suspensions (SCRUM-359).',
            'counsellor_id' => $counsellor->id,
            'session_type' => 'Periodic',
            'payment_type' => 'FREE',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
        ]);
        $resolvedReport = function (User $reporter, $reportable, array $data, string $description) {
            $report = new Report(['description' => $description, 'data' => $data]);
            $report->addedby()->associate($reporter);
            $report->reportable()->associate($reportable);
            $report->save();
            $report->forceFill([
                'status' => ReportStatusEnum::resolved,
                'outcome' => ReportOutcomeEnum::suspension_recommended,
                'resolution_note' => 'Seeded: the messages broke the community rules.',
                'resolved_at' => now()->subHour(),
                'created_at' => now()->subDays(2),
            ])->save();

            return $report;
        };

        // A second super admin who "applied" the seeded suspensions, so the seeded super admin can
        // decide the waiting appeal (the admin who applied one never may).
        $applier = $user('suspension_demo_admin', 'Suspension', 'DemoAdmin');
        $applier->administrator()->create(['type' => 'SUPER', 'verified_at' => now()]);

        $client = $user('suspension_demo_client', 'Suspension', 'DemoClient');

        // 1. Ready to suspend: resolved with "suspension recommended", nobody suspended yet. Its
        //    counsellor has an upcoming session and a waiting request, to see what suspending does.
        $ready = $counsellor($user('suspension_ready_counsellor', 'Ready', 'ToSuspend'), 'Seeded counsellor whose report is ready for a suspension (SCRUM-359).');
        $readyTherapy = $therapy($client, $ready, 'Suspension Demo Therapy (ready to suspend)');
        // for_* aren't fillable on Session, so the therapy is associated explicitly.
        $upcoming = $ready->addedSessions()->make([
            'name' => 'Suspension Demo Upcoming Session',
            'about' => 'Fails when its counsellor is suspended for 7 days or more.',
            'start_time' => now()->addDays(3),
            'end_time' => now()->addDays(3)->addHour(),
            'type' => 'online',
            'status' => 'pending',
            'payment_type' => 'FREE',
        ]);
        $upcoming->for()->associate($readyTherapy);
        $upcoming->save();

        // ...and a client waiting on them, whose request is declined by a suspension.
        $waiting = $user('suspension_waiting_client', 'Waiting', 'Client');
        $waitingTherapy = $waiting->addedTherapies()->create([
            'name' => 'Suspension Demo Therapy (waiting for a counsellor)',
            'background_story' => 'Seeded therapy whose request to a counsellor is declined if they are suspended (SCRUM-359).',
            'session_type' => 'Once',
            'payment_type' => 'FREE',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'pending',
        ]);
        $request = $waiting->sentRequests()->create(['type' => RequestTypeEnum::therapy->value, 'data' => [], 'status' => RequestStatusEnum::pending->value]);
        $request->to()->associate($ready);
        $request->for()->associate($waitingTherapy);
        $request->save();
        $resolvedReport($client, $readyTherapy, ['counsellorId' => $ready->id], '[Suspension demo: ready to suspend] My counsellor insulted me in our sessions.');

        // 2. A counsellor suspended (counsellor role, 30 days): still signs in as a client.
        $suspendedCounsellor = $counsellor($user('suspended_counsellor_demo', 'Suspended', 'Counsellor'), 'Seeded counsellor whose counsellor role is suspended (SCRUM-359).');
        $suspendedTherapy = $therapy($client, $suspendedCounsellor, 'Suspension Demo Therapy (counsellor suspended)');
        $report = $resolvedReport($client, $suspendedTherapy, ['counsellorId' => $suspendedCounsellor->id], '[Suspension demo: counsellor suspended] My counsellor shared what I told them.');
        $this->seedSuspension($report, $suspendedCounsellor->user, $applier, 'counsellor', now()->addDays(30), 'Sharing a client\'s private information.');

        // 3. A client whose whole account is suspended until lifted, with an appeal waiting.
        $suspendedClient = $user('suspended_client_demo', 'Suspended', 'Client');
        $clientTherapy = $therapy($suspendedClient, $ready, 'Suspension Demo Therapy (client suspended)');
        $report = $resolvedReport($ready->user, $clientTherapy, ['userId' => $suspendedClient->id], '[Suspension demo: client suspended] My client sent threatening messages.');
        $suspension = $this->seedSuspension($report, $suspendedClient, $applier, 'account', null, 'Threatening messages to your counsellor.');
        $suspension->forceFill(['appeal_text' => 'I was upset and I apologise. It will not happen again.', 'appealed_at' => now()->subMinutes(30)])->save();
        ReportStatusHistory::create(['report_id' => $report->id, 'user_id' => $suspendedClient->id, 'event' => ReportStatusHistory::EVENT_APPEAL_SUBMITTED, 'data' => ['suspension' => $suspension->id]]);

        // 4. A suspension that ran its course (7 days, ended last week).
        $ended = $resolvedReport($ready->user, $readyTherapy, ['userId' => $client->id], '[Suspension demo: ended] My client was abusive in a session.');
        $old = $this->seedSuspension($ended, $client, $applier, 'account', now()->subDays(7), 'Abusive messages in a session.', now()->subDays(14));
        $old->forceFill(['end_processed_at' => now()->subDays(7)])->save();
        ReportStatusHistory::create(['report_id' => $ended->id, 'user_id' => null, 'event' => ReportStatusHistory::EVENT_SUSPENSION_ENDED, 'data' => ['suspension' => $old->id]]);

        // A normal (not super) administrator: sees suspensions, but can't apply, lift or decide them.
        $user('suspension_demo_normal_admin', 'Normal', 'DemoAdmin')->administrator()->create(['type' => 'NORMAL', 'verified_at' => now()]);
    }

    private function seedSuspension(Report $report, User $person, User $admin, string $scope, $endsAt, string $reason, $startsAt = null): Suspension
    {
        $suspension = Suspension::create([
            'user_id' => $person->id,
            'report_id' => $report->id,
            'scope' => $scope,
            'starts_at' => $startsAt ?? now()->subDay(),
            'ends_at' => $endsAt,
            'public_reason' => $reason,
            'internal_note' => 'Seeded (SCRUM-359).',
            'created_by' => $admin->id,
        ]);

        ReportStatusHistory::create([
            'report_id' => $report->id,
            'user_id' => $admin->id,
            'event' => ReportStatusHistory::EVENT_SUSPENSION_APPLIED,
            'data' => ['suspension' => $suspension->id, 'scope' => $scope, 'endsAt' => $suspension->ends_at?->toJSON()],
        ]);

        return $suspension;
    }

    // SCRUM-316: every seeded session is linked to its therapy/group explicitly -- for_id/for_type
    // aren't fillable on Session (deliberately: nothing should mass-assign them), so passing them
    // to create() only worked while db:seed ran unguarded.
    private function seedSession(Counsellor|User $by, Therapy|GroupTherapy $for, array $attributes): Session
    {
        $session = $by->addedSessions()->make($attributes);
        $session->for()->associate($for);
        $session->save();

        return $session;
    }

    private function createAdminReadLogDemoData(User $superAdmin): void
    {
        $session = Session::where('name', 'Report Demo Session')->firstOrFail();
        $license = License::query()->first();

        $rows = [
            ['resource' => AdminContentAccess::SESSION_MESSAGES, 'subject_type' => Session::class, 'subject_id' => $session->id, 'page' => 1, 'file_id' => null],
            ['resource' => AdminContentAccess::MESSAGE_REPLIES, 'subject_type' => Message::class, 'subject_id' => $session->messages()->value('id'), 'page' => 1, 'file_id' => null],
        ];

        if ($license) {
            $rows[] = ['resource' => AdminContentAccess::LICENSE_DOCUMENT, 'subject_type' => License::class, 'subject_id' => $license->id, 'page' => null, 'file_id' => $license->files()->value('files.id')];
        }

        foreach ($rows as $i => $row) {
            AdminContentAccess::create([...$row, 'admin_user_id' => $superAdmin->id, 'ip' => '127.0.0.1', 'user_agent' => 'Seeded (SCRUM-360)', 'created_at' => now()->subHours(3 - $i)]);
        }
    }
}
