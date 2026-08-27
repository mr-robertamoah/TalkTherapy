<?php

use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\Guardianship;
use App\Models\Message;
use App\Models\Report;
use App\Models\Testimonial;
use App\Models\Therapy;
use App\Models\TherapyTopic;
use App\Models\User;

// Regression tests for SCRUM-130: same class of bug as SCRUM-116/SCRUM-110 --
// Illuminate\Http\Request::__get() prefers a same-named parsed-body/query key over the route
// parameter, so DiscussionController/MessageController/ReportController/TherapyTopicController/
// UserController::deleteGuardianship/CounsellorController/AdministratorController were all
// resolving their target record via a spoofable magic property instead of $request->route(...).
// One representative test per controller, using an admin actor wherever the target action has an
// isAdmin() bypass, to keep fixture setup minimal and focused on proving the URL wins.

function anAdmin(): User
{
    $admin = User::factory()->create();
    Administrator::factory()->create(['user_id' => $admin->id]);

    return $admin;
}

test('DiscussionController::showChat applies to the URL\'s discussion, not a spoofed discussionId in the query', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create();
    $ownedDiscussion = Discussion::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'name' => 'Owned',
    ]);
    $unrelatedDiscussion = Discussion::factory()->create(['name' => 'Untouched']);

    $response = $this
        ->actingAs($counsellorUser)
        ->get("/discussions/{$ownedDiscussion->id}/chat?discussionId={$unrelatedDiscussion->id}");

    $response
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Discussion/Chat')
            ->where('discussion.id', $ownedDiscussion->id)
        );
});

test('MessageController::deleteMessage applies to the URL\'s message, not a spoofed messageId in the body', function () {
    $admin = anAdmin();

    Message::unguard();
    $ownedMessage = Message::factory()->create(['content' => 'Owned', 'from_type' => User::class, 'from_id' => $admin->id]);
    $unrelatedMessage = Message::factory()->create(['content' => 'Untouched']);
    Message::reguard();

    $response = $this
        ->actingAs($admin)
        ->deleteJson("/api/messages/{$ownedMessage->id}", ['messageId' => $unrelatedMessage->id]);

    $response->assertOk();
    expect($ownedMessage->fresh()->trashed())->toBeTrue();
    expect($unrelatedMessage->fresh()->trashed())->toBeFalse();
});

test('ReportController::getReport applies to the URL\'s report, not a spoofed reportId in the query', function () {
    $user = User::factory()->create();
    $ownedReport = Report::create(['description' => 'Owned']);
    $unrelatedReport = Report::create(['description' => 'Untouched']);

    $response = $this
        ->actingAs($user)
        ->getJson("/api/reports/{$ownedReport->id}?reportId={$unrelatedReport->id}");

    $response->assertOk();
    expect($response->json('report.description'))->toBe('Owned');
});

test('TherapyTopicController::deleteTherapyTopic applies to the URL\'s topic, not a spoofed topicId in the body', function () {
    $admin = anAdmin();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $ownedTopic = TherapyTopic::create(['name' => 'Owned', 'counsellor_id' => $counsellor->id]);
    $unrelatedTopic = TherapyTopic::create(['name' => 'Untouched', 'counsellor_id' => $counsellor->id]);

    $response = $this
        ->actingAs($admin)
        ->delete("/api/topics/{$ownedTopic->id}", ['topicId' => $unrelatedTopic->id]);

    $response->assertSessionHasNoErrors();
    expect($ownedTopic->fresh()->trashed())->toBeTrue();
    expect($unrelatedTopic->fresh()->trashed())->toBeFalse();
});

test('UserController::deleteGuardianship applies to the URL\'s guardianship, not a spoofed guardianshipId in the body', function () {
    $admin = anAdmin();
    $ownedGuardianship = Guardianship::create(['guardian_id' => User::factory()->create()->id, 'ward_id' => User::factory()->create()->id]);
    $unrelatedGuardianship = Guardianship::create(['guardian_id' => User::factory()->create()->id, 'ward_id' => User::factory()->create()->id]);

    $response = $this
        ->actingAs($admin)
        ->deleteJson("/api/guardianship/{$ownedGuardianship->id}", ['guardianshipId' => $unrelatedGuardianship->id]);

    $response->assertOk();
    expect(Guardianship::find($ownedGuardianship->id))->toBeNull();
    expect(Guardianship::find($unrelatedGuardianship->id))->not->toBeNull();
});

test('CounsellorController::updateCounsellor applies to the URL\'s counsellor, not a spoofed counsellorId in the body', function () {
    $admin = anAdmin();
    $ownedCounsellorUser = User::factory()->create();
    $ownedCounsellor = Counsellor::factory()->create(['user_id' => $ownedCounsellorUser->id, 'about' => 'Original']);
    $unrelatedCounsellorUser = User::factory()->create();
    $unrelatedCounsellor = Counsellor::factory()->create(['user_id' => $unrelatedCounsellorUser->id, 'about' => 'Untouched']);

    $response = $this
        ->actingAs($admin)
        ->post("/counsellor/{$ownedCounsellor->id}", [
            'counsellorId' => $unrelatedCounsellor->id,
            'about' => 'Updated',
        ]);

    $response->assertSessionHasNoErrors();
    expect($ownedCounsellor->fresh()->about)->toBe('Updated');
    expect($unrelatedCounsellor->fresh()->about)->toBe('Untouched');
});

test('AdministratorController::getCounsellorStats applies to the URL\'s counsellor, not a spoofed counsellorId in the query', function () {
    $admin = anAdmin();
    $ownedCounsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $unrelatedCounsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    $response = $this
        ->actingAs($admin)
        ->getJson("/api/administrator/counsellors/{$ownedCounsellor->id}/stats?counsellorId={$unrelatedCounsellor->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($ownedCounsellor->id);
});

test('TestimonialController::deleteTestimonial applies to the URL\'s testimonial, not a spoofed testimonialId in the body', function () {
    $admin = anAdmin();
    $ownedTestimonial = Testimonial::create(['content' => 'Owned', 'use' => true]);
    $unrelatedTestimonial = Testimonial::create(['content' => 'Untouched', 'use' => true]);

    $response = $this
        ->actingAs($admin)
        ->delete("/api/testimonials/{$ownedTestimonial->id}", ['testimonialId' => $unrelatedTestimonial->id]);

    $response->assertSessionHasNoErrors();
    expect(Testimonial::find($ownedTestimonial->id))->toBeNull();
    expect(Testimonial::find($unrelatedTestimonial->id))->not->toBeNull();
});
