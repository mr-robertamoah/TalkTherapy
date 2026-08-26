<?php

use App\Actions\Link\CreateLinkAction;
use App\Actions\Link\EnsureCanUseLinkAction;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkTypeEnum;
use App\Exceptions\LinkException;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-101: a link is deactivated once it has been used to successfully assign/attach
// something (see the PerformLinkAction siblings), so it can no longer be replayed indefinitely
// by whoever still holds the URL. This is the up-front gate that rejects that replay.

test('an inactive link cannot be used', function () {
    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $therapyOwner->id]);
    $counsellorUser = User::factory()->create();

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $therapyOwner,
            'for' => $therapy,
            'type' => LinkTypeEnum::therapyCounsellor->value,
        ])
    );
    $link->deactivate();

    expect(fn () => EnsureCanUseLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'user' => $counsellorUser,
            'link' => $link,
        ])
    ))->toThrow(LinkException::class, 'This link is no longer active.');
});

test('an active link with no `to` restriction can be used by anyone but its creator', function () {
    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $therapyOwner->id]);
    $counsellorUser = User::factory()->create();

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $therapyOwner,
            'for' => $therapy,
            'type' => LinkTypeEnum::therapyCounsellor->value,
        ])
    );

    expect(fn () => EnsureCanUseLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'user' => $counsellorUser,
            'link' => $link,
        ])
    ))->not->toThrow(LinkException::class);
});
