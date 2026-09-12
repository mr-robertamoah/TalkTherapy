<?php

use App\Actions\GroupTherapy\JoinGroupTherapyAction;
use App\Actions\Request\CreateRequestAction;
use App\Actions\Request\RespondToGroupTherapyMembershipRequestAction;
use App\Actions\Request\RespondToTherapyAssistanceRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\GroupTherapyDTO;
use App\DTOs\JoinGroupTherapyDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\GroupTherapyMembershipRequestAcceptedGuardianNotification;
use App\Notifications\TherapyAssistanceRequestAcceptedGuardianNotification;
use App\Notifications\TherapyCreatedNotification;
use App\Services\GroupTherapyService;
use App\Services\TherapyService;
use Illuminate\Support\Facades\Notification;

// TT-4.10b/SCRUM-291: each of AlertGuardianAction's 5 call sites must pass its own record ('for')
// only when that record's client_was_minor_at_creation snapshot genuinely corresponds to the
// user being alerted about -- AlertGuardianActionTest.php already proves the action's own
// snapshot-preference logic in isolation; these prove each call site wires it correctly,
// including the subtle cases where the naive "just pass the therapy in scope" wiring would have
// alerted about (or silently skipped alerting) the WRONG person entirely.

test('TherapyService::createTherapy alerts the client\'s own guardian, snapshot-based', function () {
    Notification::fake();
    $minorClient = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minorClient->id]);

    TherapyService::new()->createTherapy(CreateTherapyDTO::new()->fromArray([
        'user' => $minorClient,
        'name' => 'Wiring Test Therapy',
        'backgroundStory' => 'Test background story.',
        'public' => true,
        'maxSessions' => 1,
        'sessionType' => 'ONCE',
        'anonymous' => false,
        'allowInPerson' => false,
        'paymentType' => 'FREE',
    ]));

    Notification::assertSentTo($guardian, TherapyCreatedNotification::class);
});

test('GroupTherapyService::createGroupTherapy alerts the client\'s own guardian when created as a user', function () {
    Notification::fake();
    $minorClient = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minorClient->id]);

    GroupTherapyService::new()->createGroupTherapy(GroupTherapyDTO::new()->fromArray([
        'user' => $minorClient,
        'name' => 'Wiring Test Group',
        'about' => 'Test',
        'public' => true,
        'anonymous' => false,
        'allowInPerson' => false,
        'allowAnyone' => false,
        'sessionType' => 'ONCE',
        'paymentType' => 'FREE',
    ]));

    Notification::assertSentTo($guardian, TherapyCreatedNotification::class);
});

// The subtle case: when the SAME account creates a group AS A COUNSELLOR, the resulting
// GroupTherapy's addedby is the Counsellor, not this user -- its client_was_minor_at_creation
// snapshot is null (not applicable, per SCRUM-290) and must NOT be read as "this user is an
// adult." GroupTherapyService's own 'for' => null wiring for this branch is what keeps this
// correct (see its own code comment) -- a naive "just always pass $therapy" implementation would
// have broken this exact case, since clientIsMinor() would incorrectly report false.
test('GroupTherapyService::createGroupTherapy still alerts the guardian of a minor counsellor creating a group AS a counsellor', function () {
    Notification::fake();
    $minorCounsellorUser = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $counsellor = Counsellor::factory()->create(['user_id' => $minorCounsellorUser->id]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minorCounsellorUser->id]);

    GroupTherapyService::new()->createGroupTherapy(GroupTherapyDTO::new()->fromArray([
        'user' => $minorCounsellorUser,
        'counsellor' => $counsellor,
        'name' => 'Wiring Test Counsellor Group',
        'about' => 'Test',
        'public' => true,
        'anonymous' => false,
        'allowInPerson' => false,
        'allowAnyone' => false,
        'sessionType' => 'ONCE',
        'paymentType' => 'FREE',
    ]));

    Notification::assertSentTo($guardian, TherapyCreatedNotification::class);
});

// The subtle case: RespondToTherapyAssistanceRequestAction's $request->from can be a Counsellor
// OFFERING to assist someone else's therapy (TherapyService::sendAssistanceRequest's own
// counsellor-initiated branch) -- $request->for's client_was_minor_at_creation snapshot is about
// THAT THERAPY'S CLIENT, a different person entirely, and must not be read as this counsellor's
// own minor status.
test('RespondToTherapyAssistanceRequestAction alerts the guardian of a minor counsellor offering assistance, not based on the therapy\'s own (adult) client snapshot', function () {
    Notification::fake();
    $adultClient = User::factory()->adult()->create();
    $minorCounsellorUser = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $counsellor = Counsellor::factory()->create(['user_id' => $minorCounsellorUser->id]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minorCounsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $adultClient->id,
        'client_was_minor_at_creation' => false,
        'status' => TherapyStatusEnum::pending->value,
    ]);

    $request = CreateRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray([
            'from' => $counsellor,
            'to' => $adultClient,
            'for' => $therapy,
            'type' => RequestTypeEnum::therapy->value,
        ])
    );

    RespondToTherapyAssistanceRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray([
            'request' => $request,
            'response' => 'accepted',
            'user' => $adultClient,
        ])
    );

    Notification::assertSentTo($guardian, TherapyAssistanceRequestAcceptedGuardianNotification::class);
});

// The subtle case: RespondToGroupTherapyMembershipRequestAction's $request->from is the JOINING
// MEMBER, never the group's own addedby/owner -- $groupTherapy->client_was_minor_at_creation
// answers a question about a DIFFERENT person (whoever created the group). This must stay on
// AlertGuardianAction's live-isAdult() fallback for the joining member themselves; passing
// 'for' => $groupTherapy here (a minor-created group) would incorrectly fire this adult member's
// stale guardian alert, based on someone else's minor status entirely.
test('RespondToGroupTherapyMembershipRequestAction alerts based on the JOINING MEMBER\'s own status, never the group creator\'s', function () {
    Notification::fake();
    $minorCreator = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $creatorGuardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $creatorGuardian->id, 'ward_id' => $minorCreator->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $minorCreator->id,
        'client_was_minor_at_creation' => true,
        'allow_anyone' => false,
        'max_users' => 10,
    ]);

    $adultMember = User::factory()->adult()->create();
    // An adult today can still have a stale Guardianship row from before they turned 18 --
    // Guardianship rows aren't auto-deleted on reaching adulthood. If this call site incorrectly
    // read the GROUP's own (minor) snapshot instead of $adultMember's own live status, this
    // guardian would be wrongly alerted.
    $staleGuardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $staleGuardian->id, 'ward_id' => $adultMember->id]);

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $adultMember,
            'groupTherapy' => $groupTherapy,
            'anonymous' => false,
        ])
    );

    RespondToGroupTherapyMembershipRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray([
            'user' => $minorCreator,
            'response' => 'accepted',
            'request' => $request,
        ])
    );

    Notification::assertNotSentTo($staleGuardian, GroupTherapyMembershipRequestAcceptedGuardianNotification::class);
    Notification::assertNotSentTo($creatorGuardian, GroupTherapyMembershipRequestAcceptedGuardianNotification::class);
});
