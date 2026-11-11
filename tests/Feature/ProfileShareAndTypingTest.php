<?php

use App\Models\Counsellor;
use App\Models\Report;
use App\Models\Suspension;
use App\Models\User;

// SCRUM-51: a counsellor's profile is offered for sharing only while verified and not suspended -- to
// visitors and to the counsellor alike (who is told why not, in the page). SCRUM-68: who is typing comes
// from Reverb's stamp on the whisper, which it only adds when it accepts client events from channel
// members. Helpers are file-local and pst-prefixed (--parallel).

function pstCounsellor(array $attributes = []): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id, ...$attributes]);
}

function pstShareable($test, Counsellor $counsellor, ?User $viewer = null): bool
{
    auth()->forgetGuards();
    if ($viewer) {
        $test->actingAs($viewer);
    }

    return $test->get(route('counsellor.show', ['counsellorId' => $counsellor->id]))->assertOk()
        ->viewData('page')['props']['shareable'];
}

test('a verified counsellor\'s profile is shareable, to signed-out visitors as well', function () {
    $counsellor = pstCounsellor();

    expect(pstShareable($this, $counsellor))->toBeTrue()
        ->and(pstShareable($this, $counsellor, $counsellor->user))->toBeTrue();
});

test('an unverified or suspended counsellor\'s profile isn\'t offered for sharing', function () {
    expect(pstShareable($this, pstCounsellor(['verified_at' => null, 'verification_expires_at' => null])))->toBeFalse();

    $suspended = pstCounsellor();
    $report = new Report(['description' => 'x', 'data' => []]);
    $report->addedby()->associate(User::factory()->create());
    $report->save();
    Suspension::create(['user_id' => $suspended->user_id, 'report_id' => $report->id, 'scope' => 'counsellor', 'starts_at' => now(), 'public_reason' => 'For the test.']);

    expect(pstShareable($this, $suspended))->toBeFalse();
});

test('Reverb takes whispers from channel members only, so it stamps who sent them', function () {
    expect(config('reverb.apps.apps.0.accept_client_events_from'))->toBe('members');
});
