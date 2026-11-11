<?php

use App\Actions\Link\PerformDiscussionRequestLinkAction;
use App\Actions\Link\PerformTherapyCounsellorLinkAction;
use App\Actions\Link\PreviewLinkAction;
use App\Enums\ConstantsEnum;
use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Link;
use App\Models\Report;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// SCRUM-418: the links that act -- guardianship, a therapy's counsellor, a discussion, a group's counsellor
// -- change nothing when opened: Links/Confirm shows what they'd agree to (only what each preview()
// chooses), and confirming (api.links.join) is what uses them. Helpers are file-local and lcf-prefixed
// (--parallel).

function lcfLink(object $creator, string $type, $for, ?object $to = null): Link
{
    return Link::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'type' => $type, 'state' => LinkStateEnum::active->value,
        'addedby_type' => $creator::class, 'addedby_id' => $creator->id,
        'for_type' => $for::class, 'for_id' => $for->id,
        'to_type' => $to ? $to::class : null, 'to_id' => $to?->id,
    ]);
}

function lcfCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
}

function lcfAnonymousTherapy(array $attributes = []): array
{
    $client = User::factory()->adult()->create(['firstName' => 'Akosua', 'lastName' => 'Boateng']);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => null,
        'anonymous' => true, 'public' => false, 'name' => 'Private Grief Work', 'background_story' => 'A very private story.',
        ...$attributes,
    ]);

    return [$client, $therapy];
}

// What the page carries about the link, flattened to one string -- to check nothing it mustn't show is in
// it. Not the shared props (`auth` is the viewer's own account).
function lcfPageText($response): string
{
    return json_encode(Arr::only($response->viewData('page')['props'], ['uuid', 'type', 'summary', 'refusal']));
}

test('opening any of the four changes nothing; confirming is what uses it', function () {
    Notification::fake();
    $ward = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    [, $therapy] = lcfAnonymousTherapy();
    $owner = lcfCounsellor();
    $discussion = Discussion::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $owner->id, 'for_type' => Therapy::class, 'for_id' => $therapy->id, 'start_time' => now(), 'end_time' => now()->addHour()]);
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $owner->id, 'max_counsellors' => 3]);

    $links = [
        lcfLink($ward, LinkTypeEnum::guardianship->value, $ward),
        lcfLink($therapy->addedby, LinkTypeEnum::therapyCounsellor->value, $therapy),
        lcfLink($owner->user, LinkTypeEnum::discussion->value, $discussion),
        lcfLink($owner->user, LinkTypeEnum::groupTherapyCounsellor->value, $group),
    ];
    $guardian = User::factory()->adult()->create();
    $counsellor = lcfCounsellor();

    $writes = 0;
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) && ! preg_match('/"(sessions|cache|visitors)"/', $query->sql)) {
            $writes++;
        }
    });

    foreach ($links as $index => $link) {
        $this->actingAs($index === 0 ? $guardian : $counsellor->user)->get(route('links.get', ['uuid' => $link->uuid]))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Links/Confirm')->where('type', $link->type)->where('uuid', $link->uuid)->has('summary')->missing('refusal'));
    }

    expect($writes)->toBe(0)
        ->and(collect($links)->every(fn (Link $link) => $link->fresh()->state === LinkStateEnum::active->value && ! $link->fresh()->used_at))->toBeTrue()
        ->and($guardian->wards()->count())->toBe(0)
        ->and($therapy->fresh()->counsellor_id)->toBeNull();
    Notification::assertNothingSent();
});

test('confirming each lands where they can go next', function () {
    Notification::fake();
    $ward = User::factory()->create(['dob' => now()->subYears(15)->toDateString(), 'firstName' => 'Kofi', 'lastName' => 'Mensah']);
    $guardian = User::factory()->adult()->create();
    [, $therapy] = lcfAnonymousTherapy();
    $owner = lcfCounsellor();
    $discussion = Discussion::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $owner->id, 'for_type' => Therapy::class, 'for_id' => $therapy->id]);
    $counsellor = lcfCounsellor();

    $this->actingAs($guardian)->postJson(route('api.links.join', ['uuid' => lcfLink($ward, LinkTypeEnum::guardianship->value, $ward)->uuid]))->assertOk()
        ->assertJsonPath('message', "You are now {$ward->name}'s guardian.")->assertJsonPath('redirect', route('profile.show'));

    $this->actingAs($counsellor->user)->postJson(route('api.links.join', ['uuid' => lcfLink($therapy->addedby, LinkTypeEnum::therapyCounsellor->value, $therapy)->uuid]))->assertOk()
        ->assertJsonPath('message', PerformTherapyCounsellorLinkAction::JOINED)->assertJsonPath('redirect', route('therapies.get', ['therapyId' => $therapy->id]));

    // The discussion's chat, not the therapy's page -- that isn't theirs to open.
    $this->postJson(route('api.links.join', ['uuid' => lcfLink($owner->user, LinkTypeEnum::discussion->value, $discussion)->uuid]))->assertOk()
        ->assertJsonPath('message', PerformDiscussionRequestLinkAction::JOINED)->assertJsonPath('redirect', route('discussions.chat', ['discussionId' => $discussion->id]));
    $this->get(route('discussions.chat', ['discussionId' => $discussion->id]))->assertOk();

    expect($guardian->wards()->where('ward_id', $ward->id)->exists())->toBeTrue()
        ->and($therapy->fresh()->counsellor_id)->toBe($counsellor->id);
});

test('a guardianship page shows who is asking -- never their age, date of birth, email or that they are a minor', function () {
    $ward = User::factory()->create(['dob' => now()->subYears(15)->toDateString(), 'firstName' => 'Kofi', 'lastName' => 'Mensah', 'email' => 'kofi.private@example.test']);
    $link = lcfLink($ward, LinkTypeEnum::guardianship->value, $ward);

    $response = $this->actingAs(User::factory()->adult()->create())->get(route('links.get', ['uuid' => $link->uuid]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('summary.name', $ward->name)->where('summary.username', $ward->username));

    expect(array_keys($response->viewData('page')['props']['summary']))->toBe(['name', 'username', 'avatarUrl'])
        ->and(lcfPageText($response))->not->toContain('kofi.private')->not->toContain($ward->dob->toDateString())->not->toContain('"dob"')->not->toContain('minor');
});

test('a therapy page shows an anonymous client masked, and its story only to the counsellor it was sent to, or for a public therapy', function () {
    [$client, $therapy] = lcfAnonymousTherapy();
    $general = lcfLink($client, LinkTypeEnum::therapyCounsellor->value, $therapy);
    $counsellor = lcfCounsellor();
    $addressed = lcfLink($client, LinkTypeEnum::therapyCounsellor->value, $therapy, $counsellor);

    $response = $this->actingAs(lcfCounsellor()->user)->get(route('links.get', ['uuid' => $general->uuid]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('summary.client', ConstantsEnum::anonymousUserLabel->value)->where('summary.backgroundStory', null)->missing('summary.id')->missing('summary.userId'));
    expect(lcfPageText($response))->not->toContain('Akosua')->not->toContain('Boateng')->not->toContain('A very private story');

    $this->actingAs($counsellor->user)->get(route('links.get', ['uuid' => $addressed->uuid]))
        ->assertInertia(fn ($page) => $page->where('summary.backgroundStory', 'A very private story.'));

    [$publicClient, $public] = lcfAnonymousTherapy(['public' => true, 'background_story' => 'Shared openly.']);
    $this->actingAs(lcfCounsellor()->user)->get(route('links.get', ['uuid' => lcfLink($publicClient, LinkTypeEnum::therapyCounsellor->value, $public)->uuid]))
        ->assertInertia(fn ($page) => $page->where('summary.backgroundStory', 'Shared openly.'));
});

test('a discussion page shows the discussion -- not the client, or the therapy\'s name', function () {
    [, $therapy] = lcfAnonymousTherapy(['anonymous' => false]);
    $owner = lcfCounsellor();
    $discussion = Discussion::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $owner->id, 'for_type' => Therapy::class, 'for_id' => $therapy->id, 'name' => 'Case review', 'start_time' => now(), 'end_time' => now()->addHour()]);

    $response = $this->actingAs(lcfCounsellor()->user)->get(route('links.get', ['uuid' => lcfLink($owner->user, LinkTypeEnum::discussion->value, $discussion)->uuid]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('summary.name', 'Case review')->where('summary.startedBy', $owner->getName())->where('summary.about', 'therapy')->missing('summary.forId'));

    expect(lcfPageText($response))->not->toContain('Akosua')->not->toContain('Private Grief Work');
});

test('a group page masks an anonymous creator and lists no members', function () {
    $creator = User::factory()->adult()->create(['firstName' => 'Yaw', 'lastName' => 'Owusu']);
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'anonymous' => true, 'max_counsellors' => 3]);
    $member = User::factory()->adult()->create(['firstName' => 'Efua', 'lastName' => 'Sarpong']);
    $group->users()->attach($member->id, ['anonymous' => false]);

    $response = $this->actingAs(lcfCounsellor()->user)->get(route('links.get', ['uuid' => lcfLink($creator, LinkTypeEnum::groupTherapyCounsellor->value, $group)->uuid]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('summary.createdBy', ConstantsEnum::anonymousUserLabel->value)->missing('summary.id'));

    expect(lcfPageText($response))->not->toContain('Yaw')->not->toContain('Owusu')->not->toContain('Efua');
});

test('a refusal shows only the reason, and confirming checks a suspended counsellor too (it used to only on opening)', function () {
    [$client, $therapy] = lcfAnonymousTherapy();
    $link = lcfLink($client, LinkTypeEnum::therapyCounsellor->value, $therapy);
    $counsellor = lcfCounsellor();
    $report = new Report(['description' => 'x', 'data' => []]);
    $report->addedby()->associate(User::factory()->create());
    $report->save();
    Suspension::create(['user_id' => $counsellor->user_id, 'report_id' => $report->id, 'scope' => 'counsellor', 'starts_at' => now(), 'public_reason' => 'For the test.']);

    $response = $this->actingAs($counsellor->user)->get(route('links.get', ['uuid' => $link->uuid]))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Links/Confirm')->has('refusal')->missing('summary')->missing('type'));
    expect(lcfPageText($response))->not->toContain('Private Grief Work');

    $this->postJson(route('api.links.join', ['uuid' => $link->uuid]))->assertForbidden();
    expect($therapy->fresh()->counsellor_id)->toBeNull();
});

test('the four types are exactly the ones shown first', function () {
    expect(PreviewLinkAction::TYPES)->toEqualCanonicalizing([
        LinkTypeEnum::guardianship->value, LinkTypeEnum::therapyCounsellor->value, LinkTypeEnum::discussion->value, LinkTypeEnum::groupTherapyCounsellor->value,
    ]);
});
