<?php

use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Models\Counsellor;
use App\Models\Link;
use App\Models\Report;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

// SCRUM-97: the controllers that catch exceptions inline (instead of through a shared
// returnFailure helper) -- each failure path answers in its own shape with the domain message.

function icfCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
}

// Counsellor-scope only: an account suspension is redirected to /suspended before any controller.
function icfSuspendAsCounsellor(User $user): void
{
    $report = new Report(['description' => 'x', 'data' => []]);
    $report->addedby()->associate(User::factory()->create());
    $report->save();
    Suspension::create(['user_id' => $user->id, 'report_id' => $report->id, 'scope' => 'counsellor', 'starts_at' => now(), 'public_reason' => 'For the test.']);
}

// --- CounsellorController --------------------------------------------------------------------

test('verifyEmail redirects to the profile with the failure under the alert error key', function () {
    $url = URL::temporarySignedRoute('counsellor.verification.verify', now()->addMinutes(10), [
        'counsellorId' => 999999, 'hash' => sha1('nobody@example.com'),
    ]);

    $this->get($url)
        ->assertRedirect(route('counsellor.show', ['counsellorId' => 999999]))
        ->assertSessionHasErrors(['alert' => 'No counsellor was found.']);
});

test('getRandomCounsellors and getCounsellors answer guests and signed-in users', function () {
    icfCounsellor();

    $this->getJson(route('api.counsellors.random'))->assertOk();
    $this->getJson(route('api.counsellors'))->assertOk();
    $this->actingAs(User::factory()->adult()->create())->getJson(route('api.counsellors', ['name' => 'a']))->assertOk();
});

test('createCounsellor answers a refused request with JSON carrying the domain message', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(15)]);

    $this->actingAs($minor)->postJson(route('counsellors.create'), ['name' => 'Young One'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'is not an adult and cannot be a counsellor'));
});

test('show sends an unknown counsellor back home with the message', function () {
    $this->get(route('counsellor.show', ['counsellorId' => 999999]))
        ->assertRedirect(route('home'))
        ->assertSessionHas('message', 'Counsellor with the given id is nonexistent.');
});

test('updateCounsellor, verifyCounsellor and deleteCounsellor refuse someone else\'s profile with an alert', function () {
    $counsellor = icfCounsellor();
    // A counsellor themselves: VerifyCounsellorRequest only admits counsellors and admins.
    $stranger = icfCounsellor()->user;

    $this->actingAs($stranger)->from('/profile')
        ->post(route('counsellor.update', ['counsellorId' => $counsellor->id]), ['about' => 'hijacked'])
        ->assertRedirect('/profile')
        ->assertSessionHasErrors(['alert' => 'You are not authorized to update this counsellor.']);

    $this->actingAs($stranger)->from('/profile')
        ->post(route('counsellor.verify', ['counsellorId' => $counsellor->id]), ['licenseNumber' => 'X1'])
        ->assertRedirect('/profile')
        ->assertSessionHasErrors(['alert' => 'You are not authorized to update this counsellor.']);

    $this->actingAs($stranger)->from('/profile')
        ->delete(route('counsellor.delete', ['counsellorId' => $counsellor->id]), ['password' => 'password'])
        ->assertRedirect('/profile')
        ->assertSessionHasErrors(['alert' => 'You are either not authorized to delete this counsellor account or there are some sessions to finish.']);

    expect($counsellor->fresh())->not->toBeNull()
        ->and($counsellor->fresh()->about)->not->toBe('hijacked');
});

// --- AlertController -------------------------------------------------------------------------

test('waitingForAlert answers an unknown alertable with a 422 and the domain message', function () {
    $this->actingAs(User::factory()->adult()->create())
        ->postJson(route('alert.wait'), ['alertableType' => 'Post', 'alertableId' => 1])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Nothing (Therapy/Group Therapy) is provided for this alert.');
});

// --- LinkController::performAction -----------------------------------------------------------

test('performAction sends a 422 failure home with the alert in the session', function () {
    $this->actingAs(User::factory()->adult()->create())
        ->get(route('links.get', ['uuid' => (string) Str::uuid()]))
        ->assertRedirect(route('home'))
        ->assertSessionHas('alert', 'The link was not found.');

    // Flashed: shown on the home page once, not on every later visit.
    $this->get(route('home'))->assertInertia(fn ($page) => $page->where('alert', 'The link was not found.'));
    $this->get(route('home'))->assertInertia(fn ($page) => $page->where('alert', null));
});

test('performAction sends any other failure home too, and answers XHR with JSON', function () {
    $client = User::factory()->adult()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => null, 'public' => false]);
    $link = new Link(['uuid' => (string) Str::uuid(), 'type' => LinkTypeEnum::therapyCounsellor->value, 'state' => LinkStateEnum::active->value]);
    $link->addedby()->associate($client);
    $link->for()->associate($therapy);
    $link->save();
    $suspendedCounsellor = icfCounsellor();
    icfSuspendAsCounsellor($suspendedCounsellor->user);

    // A browser navigation (Accept: text/html,...,*/*) -- used to get a raw JSON 403 page.
    $this->actingAs($suspendedCounsellor->user)
        ->withHeader('Accept', 'text/html,application/xhtml+xml,*/*;q=0.8')
        ->get(route('links.get', ['uuid' => $link->uuid]))
        ->assertRedirect(route('home'))
        ->assertSessionHas('alert');

    $this->actingAs($suspendedCounsellor->user)
        ->getJson(route('links.get', ['uuid' => $link->uuid]))
        ->assertForbidden()
        ->assertJsonStructure(['message']);

    expect($therapy->fresh()->counsellor_id)->toBeNull();
});
