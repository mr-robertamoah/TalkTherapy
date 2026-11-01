<?php

use App\Actions\Message\EnsureConfidentialMessageHasRecipientAction;
use App\DTOs\CreateMessageDTO;
use App\Exceptions\MessageException;
use App\Models\Counsellor;
use App\Models\Message;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-361: a confidential message needs one recipient -- otherwise only its sender could ever
// read it. The chat only offers the switch when there is one.

test('a confidential message to the other person in an individual therapy is sent', function () {
    $client = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class, 'status' => 'IN_SESSION', 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour()]);

    $response = $this->actingAs($client)->postJson(route('api.messages.create'), [
        'content' => 'just for you', 'type' => 'NORMAL', 'confidential' => true,
        'fromId' => $client->id, 'fromType' => 'User', 'toId' => $counsellor->id, 'toType' => 'Counsellor',
        'forId' => $session->id, 'forType' => 'Session',
    ]);

    expect($response->status())->not->toBe(422, (string) $response->getContent());
});

test('a confidential message with nobody to send it to is refused', function () {
    $dto = CreateMessageDTO::new()->fromArray(['confidential' => true, 'to' => null]);

    expect(fn () => EnsureConfidentialMessageHasRecipientAction::new()->execute($dto))
        ->toThrow(MessageException::class, EnsureConfidentialMessageHasRecipientAction::NO_RECIPIENT);

    EnsureConfidentialMessageHasRecipientAction::new()->execute(CreateMessageDTO::new()->fromArray(['confidential' => false, 'to' => null]));
});

test('an edit is refused only when it newly makes a message with no recipient confidential', function () {
    $message = Message::factory()->make(['confidential' => false, 'to_id' => null]);
    $already = Message::factory()->make(['confidential' => true, 'to_id' => null]);

    expect(fn () => EnsureConfidentialMessageHasRecipientAction::new()->execute(CreateMessageDTO::new()->fromArray(['message' => $message, 'confidential' => true])))
        ->toThrow(MessageException::class);

    EnsureConfidentialMessageHasRecipientAction::new()->execute(CreateMessageDTO::new()->fromArray(['message' => $already, 'confidential' => true]));
    EnsureConfidentialMessageHasRecipientAction::new()->execute(CreateMessageDTO::new()->fromArray(['message' => $already, 'confidential' => null]));
});
