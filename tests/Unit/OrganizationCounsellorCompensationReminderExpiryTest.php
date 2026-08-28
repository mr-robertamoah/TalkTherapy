<?php

use App\Actions\Organization\CreateOrganizationCounsellorCompensationAction;
use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\User;
use App\Notifications\OrganizationCounsellorCompensationChangeExpiredNotification;
use App\Notifications\OrganizationCounsellorCompensationChangeExpiryReminderNotification;
use App\Services\AppService;
use App\Services\OrganizationCounsellorCompensationService;
use Illuminate\Support\Facades\Notification;

// SCRUM-149 (TT-6.4c, 4/5): a daily sweep reminds a pending request's current recipient ~2 days
// before expires_at (once, via reminder_sent_at, not day-arithmetic alone), and auto-resolves any
// request past its expires_at to `rejected` -- same fairness-critical "never cascades to the
// affiliation" guarantee as SCRUM-147's manual reject.

function negotiationExpiring(int $daysUntilExpiry, int $windowDays = 7): array
{
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id, 'verified_at' => now()]);

    $affiliation = OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
    ]);

    $request = OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 5000,
            'currency' => 'GHS',
        ])
    );

    // created_at controls the offer's total window; expires_at controls how soon it expires --
    // independently adjustable so the "window under 3 days" skip and the "2 days from expiry"
    // trigger can each be tested in isolation.
    $request->created_at = now()->subDays($windowDays - $daysUntilExpiry);
    $request->save();
    $request->update(['expires_at' => now()->addDays($daysUntilExpiry)]);

    return [$request, $affiliation, $organization, $owner, $counsellor, $counsellorUser];
}

test('a request 2 days from expiry, with a window of at least 3 days, gets exactly one reminder', function () {
    Notification::fake();
    [$request, , , , $counsellor] = negotiationExpiring(daysUntilExpiry: 2, windowDays: 7);

    AppService::new()->sendCompensationRequestExpiryReminders();

    expect($request->refresh()->reminder_sent_at)->not->toBeNull();
    Notification::assertSentToTimes($counsellor, OrganizationCounsellorCompensationChangeExpiryReminderNotification::class, 1);

    // Running the sweep again must not send a second reminder.
    AppService::new()->sendCompensationRequestExpiryReminders();
    Notification::assertSentToTimes($counsellor, OrganizationCounsellorCompensationChangeExpiryReminderNotification::class, 1);
});

test('a request whose whole window was under 3 days never gets a reminder', function () {
    Notification::fake();
    [$request, , , , $counsellor] = negotiationExpiring(daysUntilExpiry: 1, windowDays: 2);

    AppService::new()->sendCompensationRequestExpiryReminders();

    expect($request->refresh()->reminder_sent_at)->toBeNull();
    Notification::assertNotSentTo($counsellor, OrganizationCounsellorCompensationChangeExpiryReminderNotification::class);
});

test('a request more than 2 days from expiry gets no reminder yet', function () {
    Notification::fake();
    [$request, , , , $counsellor] = negotiationExpiring(daysUntilExpiry: 5, windowDays: 10);

    AppService::new()->sendCompensationRequestExpiryReminders();

    expect($request->refresh()->reminder_sent_at)->toBeNull();
    Notification::assertNotSentTo($counsellor, OrganizationCounsellorCompensationChangeExpiryReminderNotification::class);
});

test('a request past its expiry is auto-resolved to rejected with no compensation row and no affiliation change', function () {
    Notification::fake();
    [$request, $affiliation, , , $counsellor] = negotiationExpiring(daysUntilExpiry: -1, windowDays: 7);

    AppService::new()->expireStaleCompensationRequests();

    expect($request->refresh()->status)->toBe(RequestStatusEnum::rejected->value);
    expect($request->data['resolvedBy'])->toBe('expiry');
    expect($affiliation->compensations()->count())->toBe(0);
    expect($affiliation->refresh()->status)->toBe(OrganizationCounsellorStatusEnum::pending->value);

    Notification::assertSentTo($counsellor, OrganizationCounsellorCompensationChangeExpiredNotification::class);
});

// AC5 / fairness-critical: same guarantee as SCRUM-147's manual reject, now for auto-expiry too,
// verified against an already-active affiliation with existing accepted terms behind it.
test('expiring a renegotiation request never changes the affiliation status or its existing terms', function () {
    [$request, $affiliation, , $owner] = negotiationExpiring(daysUntilExpiry: -1, windowDays: 7);
    $affiliation->activate();

    $existing = CreateOrganizationCounsellorCompensationAction::new()->execute(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 9999,
            'currency' => 'GHS',
        ])
    );

    AppService::new()->expireStaleCompensationRequests();

    expect($affiliation->refresh()->status)->toBe(OrganizationCounsellorStatusEnum::active->value);
    expect($affiliation->currentCompensation()->id)->toBe($existing->id);
    expect($affiliation->currentCompensation()->amount)->toBe(9999);
});

test('a request not yet expired is left untouched by the expiry sweep', function () {
    [$request] = negotiationExpiring(daysUntilExpiry: 1, windowDays: 7);

    AppService::new()->expireStaleCompensationRequests();

    expect($request->refresh()->status)->toBe(RequestStatusEnum::pending->value);
});

test('an already-resolved request is left untouched by both sweeps', function () {
    Notification::fake();
    [$request, , , , $counsellor] = negotiationExpiring(daysUntilExpiry: -1, windowDays: 7);
    $request->update(['status' => RequestStatusEnum::accepted->value]);

    AppService::new()->expireStaleCompensationRequests();
    AppService::new()->sendCompensationRequestExpiryReminders();

    expect($request->refresh()->status)->toBe(RequestStatusEnum::accepted->value);
    expect($request->data)->not->toHaveKey('resolvedBy');
    Notification::assertNotSentTo($counsellor, OrganizationCounsellorCompensationChangeExpiredNotification::class);
    Notification::assertNotSentTo($counsellor, OrganizationCounsellorCompensationChangeExpiryReminderNotification::class);
});

// The recipient can be the Organization itself once a counter-offer (SCRUM-148) flips direction
// -- Organization isn't Notifiable, so every admin must be notified individually. Constructed
// directly since this branch predates SCRUM-148's counter-offer action.
test('every admin of the organization is notified when it is the current recipient at expiry', function () {
    Notification::fake();
    [$request, , $organization] = negotiationExpiring(daysUntilExpiry: -1, windowDays: 7);

    $secondAdmin = User::factory()->create();
    $organization->admins()->attach($secondAdmin->id, ['role' => OrganizationAdminRoleEnum::admin->value]);

    $request->to_type = Organization::class;
    $request->to_id = $organization->id;
    $request->save();

    AppService::new()->expireStaleCompensationRequests();

    Notification::assertSentTo($organization->admins()->get(), OrganizationCounsellorCompensationChangeExpiredNotification::class);
});

// Security review (PR #87, post-merge, findings 1/5): both sweeps re-lock and re-check `pending`
// immediately before writing -- calling either sweep a second time (simulating a row no longer
// pending by the time its per-row processing runs) must be a true no-op, not a second write.
test('running the expiry sweep twice back to back only resolves and notifies once', function () {
    Notification::fake();
    [$request, , , , $counsellor] = negotiationExpiring(daysUntilExpiry: -1, windowDays: 7);

    AppService::new()->expireStaleCompensationRequests();
    AppService::new()->expireStaleCompensationRequests();

    expect($request->refresh()->status)->toBe(RequestStatusEnum::rejected->value);
    Notification::assertSentToTimes($counsellor, OrganizationCounsellorCompensationChangeExpiredNotification::class, 1);
});

// Security review (PR #87, post-merge, finding 2): a request whose recipient no longer resolves
// (a soft-deleted Counsellor -- a real, already-supported lifecycle) must not abort the whole
// sweep. It still resolves correctly; only the notification is skipped (logged, not thrown).
test('a request whose recipient no longer exists is still resolved, and does not block other requests in the same sweep', function () {
    Notification::fake();
    [$requestA, $affiliationA, , , $counsellorA] = negotiationExpiring(daysUntilExpiry: -1, windowDays: 7);
    [$requestB, , , , $counsellorB] = negotiationExpiring(daysUntilExpiry: -1, windowDays: 7);

    $counsellorA->delete();

    AppService::new()->expireStaleCompensationRequests();

    expect($requestA->refresh()->status)->toBe(RequestStatusEnum::rejected->value);
    expect($requestA->data['resolvedBy'])->toBe('expiry');
    expect($affiliationA->refresh()->status)->toBe(OrganizationCounsellorStatusEnum::pending->value);

    expect($requestB->refresh()->status)->toBe(RequestStatusEnum::rejected->value);
    Notification::assertSentTo($counsellorB, OrganizationCounsellorCompensationChangeExpiredNotification::class);
});

// Security review (PR #87, post-merge, finding 3): an organization with no (remaining) admins
// must not crash the sweep -- the request still resolves, the notification is just skipped.
test('a request addressed to an organization with no admins is still resolved without crashing', function () {
    Notification::fake();
    [$request, $affiliation] = negotiationExpiring(daysUntilExpiry: -1, windowDays: 7);

    $adminlessOrg = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $request->to_type = Organization::class;
    $request->to_id = $adminlessOrg->id;
    $request->save();

    AppService::new()->expireStaleCompensationRequests();

    expect($request->refresh()->status)->toBe(RequestStatusEnum::rejected->value);
    expect($affiliation->refresh()->status)->toBe(OrganizationCounsellorStatusEnum::pending->value);
});

// Security review (PR #87, post-merge, finding 4): a malformed expires_at <= created_at pair
// (unreachable today via the 1-30 day expiryDays bound, but defended against regardless) must
// not send a nonsensical reminder or crash on Carbon's absolute-value diffInDays().
test('a request with a malformed expires_at at or before created_at never gets a reminder', function () {
    Notification::fake();
    [$request, , , , $counsellor] = negotiationExpiring(daysUntilExpiry: 1, windowDays: 7);
    $request->update(['expires_at' => $request->created_at->copy()->subDay()]);

    AppService::new()->sendCompensationRequestExpiryReminders();

    expect($request->refresh()->reminder_sent_at)->toBeNull();
    Notification::assertNotSentTo($counsellor, OrganizationCounsellorCompensationChangeExpiryReminderNotification::class);
});

// Security review (PR #87, post-merge, finding 6): the shallow array_merge onto `data` must not
// drop the original proposed-terms fields -- only add the resolvedBy marker alongside them.
test('expiry preserves the original proposed terms in data, not just the resolvedBy marker', function () {
    [$request] = negotiationExpiring(daysUntilExpiry: -1, windowDays: 7);

    AppService::new()->expireStaleCompensationRequests();

    $data = $request->refresh()->data;
    expect($data['type'])->toBe('FIXED');
    expect($data['amount'])->toBe(5000);
    expect($data['currency'])->toBe('GHS');
    expect($data['resolvedBy'])->toBe('expiry');
});
