<?php

use App\Enums\ContactTypeEnum;
use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Administrator;
use App\Models\Comment;
use App\Models\Contact;
use App\Models\HowTo;
use App\Models\Link;
use App\Models\Post;
use App\Models\Request as ModelsRequest;
use App\Models\User;
use Illuminate\Support\Str;

// Regression tests for SCRUM-133: same class of bug as SCRUM-116/SCRUM-130 --
// Illuminate\Http\Request::__get() prefers a same-named parsed-body/query key over the route
// parameter, so CommentController/ContactController/HowToController/LinkController/
// PostController/RequestController were all resolving their target record via a spoofable magic
// property instead of $request->route(...). One representative test per controller, using an
// admin actor wherever the target action has an isAdmin() bypass, and an owner actor otherwise.

function anAdmin(): User
{
    $admin = User::factory()->create();
    Administrator::factory()->create(['user_id' => $admin->id]);

    return $admin;
}

test('CommentController::deleteComment applies to the URL\'s comment, not a spoofed commentId in the body', function () {
    $owner = User::factory()->create();

    Comment::unguard();
    $ownedComment = Comment::create(['content' => 'Owned', 'user_id' => $owner->id]);
    $unrelatedComment = Comment::create(['content' => 'Untouched', 'user_id' => User::factory()->create()->id]);
    Comment::reguard();

    $response = $this
        ->actingAs($owner)
        ->deleteJson("/api/comments/{$ownedComment->id}", ['commentId' => $unrelatedComment->id]);

    $response->assertOk();
    expect(Comment::find($ownedComment->id))->toBeNull();
    expect(Comment::find($unrelatedComment->id))->not->toBeNull();
});

test('ContactController::deleteContact applies to the URL\'s contact, not a spoofed contactId in the body', function () {
    $owner = User::factory()->create();

    Contact::unguard();
    $ownedContact = Contact::create(['content' => 'Owned', 'type' => ContactTypeEnum::general->value, 'addedby_type' => User::class, 'addedby_id' => $owner->id]);
    $unrelatedContact = Contact::create(['content' => 'Untouched', 'type' => ContactTypeEnum::general->value, 'addedby_type' => User::class, 'addedby_id' => User::factory()->create()->id]);
    Contact::reguard();

    $response = $this
        ->actingAs($owner)
        ->deleteJson("/api/contacts/{$ownedContact->id}", ['contactId' => $unrelatedContact->id]);

    $response->assertOk();
    expect(Contact::find($ownedContact->id))->toBeNull();
    expect(Contact::find($unrelatedContact->id))->not->toBeNull();
});

test('HowToController::deleteHowTo applies to the URL\'s how-to, not a spoofed howToId in the body', function () {
    $admin = anAdmin();
    $ownedHowTo = HowTo::create(['name' => 'Owned', 'user_id' => $admin->id]);
    $unrelatedHowTo = HowTo::create(['name' => 'Untouched', 'user_id' => $admin->id]);

    $response = $this
        ->actingAs($admin)
        ->delete("/api/administrator/how-tos/{$ownedHowTo->id}", ['howToId' => $unrelatedHowTo->id]);

    $response->assertSessionHasNoErrors();
    expect(HowTo::find($ownedHowTo->id))->toBeNull();
    expect(HowTo::find($unrelatedHowTo->id))->not->toBeNull();
});

test('LinkController::changeLinkStatus applies to the URL\'s link, not a spoofed linkId in the body', function () {
    $admin = anAdmin();

    Link::unguard();
    $ownedLink = Link::create(['uuid' => (string) Str::uuid(), 'type' => LinkTypeEnum::guardianship->value, 'state' => LinkStateEnum::active->value, 'addedby_type' => User::class, 'addedby_id' => User::factory()->create()->id]);
    $unrelatedLink = Link::create(['uuid' => (string) Str::uuid(), 'type' => LinkTypeEnum::guardianship->value, 'state' => LinkStateEnum::active->value, 'addedby_type' => User::class, 'addedby_id' => User::factory()->create()->id]);
    Link::reguard();

    $response = $this
        ->actingAs($admin)
        ->post("/api/links/{$ownedLink->id}/status", ['linkId' => $unrelatedLink->id]);

    $response->assertOk();
    expect($ownedLink->fresh()->state)->toBe(LinkStateEnum::inactive->value);
    expect($unrelatedLink->fresh()->state)->toBe(LinkStateEnum::active->value);
});

// LinkController::performAction is keyed by uuid, not linkId, and (unlike changeLinkStatus)
// EnsureCanUseLinkAction has no admin bypass and its side effects actually create a relationship
// (guardianship) rather than just toggle a state -- both subagents flagged this as more
// consequential than changeLinkStatus, so it gets its own test rather than relying on that one.
test('LinkController::performAction applies to the URL\'s link, not a spoofed uuid in the query', function () {
    $guardian = User::factory()->create();
    $ownedWard = User::factory()->create();
    $unrelatedWard = User::factory()->create();

    Link::unguard();
    $ownedLink = Link::create([
        'uuid' => (string) Str::uuid(),
        'type' => LinkTypeEnum::guardianship->value,
        'state' => LinkStateEnum::active->value,
        'addedby_type' => User::class,
        'addedby_id' => User::factory()->create()->id,
        'for_type' => User::class,
        'for_id' => $ownedWard->id,
    ]);
    $unrelatedLink = Link::create([
        'uuid' => (string) Str::uuid(),
        'type' => LinkTypeEnum::guardianship->value,
        'state' => LinkStateEnum::active->value,
        'addedby_type' => User::class,
        'addedby_id' => User::factory()->create()->id,
        'for_type' => User::class,
        'for_id' => $unrelatedWard->id,
    ]);
    Link::reguard();

    $this
        ->actingAs($guardian)
        ->get("/links/{$ownedLink->uuid}?uuid={$unrelatedLink->uuid}");

    expect($guardian->wards()->where('ward_id', $ownedWard->id)->exists())->toBeTrue();
    expect($guardian->wards()->where('ward_id', $unrelatedWard->id)->exists())->toBeFalse();
    expect($ownedLink->fresh()->state)->toBe(LinkStateEnum::inactive->value);
    expect($unrelatedLink->fresh()->state)->toBe(LinkStateEnum::active->value);
});

test('PostController::deletePost applies to the URL\'s post, not a spoofed postId in the body', function () {
    $admin = anAdmin();

    Post::unguard();
    $ownedPost = Post::create(['content' => 'Owned', 'addedby_type' => User::class, 'addedby_id' => User::factory()->create()->id]);
    $unrelatedPost = Post::create(['content' => 'Untouched', 'addedby_type' => User::class, 'addedby_id' => User::factory()->create()->id]);
    Post::reguard();

    $response = $this
        ->actingAs($admin)
        ->delete("/api/posts/{$ownedPost->id}", ['postId' => $unrelatedPost->id]);

    $response->assertSessionHasNoErrors();
    expect(Post::find($ownedPost->id))->toBeNull();
    expect(Post::find($unrelatedPost->id))->not->toBeNull();
});

test('RequestController::respond applies to the URL\'s request, not a spoofed requestId in the body', function () {
    $admin = anAdmin();
    $guardian = User::factory()->create(['dob' => now()->subYears(30)]);
    $ward = User::factory()->create();
    $unrelatedWard = User::factory()->create();

    ModelsRequest::unguard();
    $ownedRequest = ModelsRequest::create([
        'type' => RequestTypeEnum::guardianship->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
        'from_type' => User::class,
        'from_id' => $ward->id,
        'to_type' => User::class,
        'to_id' => $guardian->id,
    ]);
    $unrelatedRequest = ModelsRequest::create([
        'type' => RequestTypeEnum::guardianship->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
        'from_type' => User::class,
        'from_id' => $unrelatedWard->id,
        'to_type' => User::class,
        'to_id' => $guardian->id,
    ]);
    ModelsRequest::reguard();

    $response = $this
        ->actingAs($admin)
        ->postJson("/api/requests/{$ownedRequest->id}", [
            'requestId' => $unrelatedRequest->id,
            'response' => 'rejected',
        ]);

    $response->assertCreated();
    expect($ownedRequest->fresh()->status)->toBe(RequestStatusEnum::rejected->value);
    expect($unrelatedRequest->fresh()->status)->toBe(RequestStatusEnum::pending->value);
});
