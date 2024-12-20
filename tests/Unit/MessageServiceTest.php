<?php

use App\DTOs\CreateMessageDTO;
use App\Enums\DiscussionStatusEnum;
use App\Enums\SessionStatusEnum;
use App\Events\MessageSentEvent;
use App\Events\MessageUpdatedEvent;
use App\Exceptions\MessageException;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\File;
use App\Models\Message;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Services\MessageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

describe("create message tests", function () {

    test(
        "fail message creation when message is not from user or counsellor accounts of the current user.", 
        function () {

        $actingUser = User::factory()->create();
        $fromUser = User::factory()->create();

        $this->expectException(MessageException::class, "There is either no sender or you are not the sender of the message.");

        MessageService::new()->createMessage(
            CreateMessageDTO::new()->fromArray([
                'from' => $fromUser,
                'user' => $actingUser
            ])
        );
    });

    test(
        "fail message creation when message when no discussion or session is provided.", 
        function () {

        $actingUser = User::factory()->create();

        $this->expectException(MessageException::class, "A message has to be created for a discussion or session.");

        MessageService::new()->createMessage(
            CreateMessageDTO::new()->fromArray([
                'from' => $actingUser,
                'user' => $actingUser
            ])
        );
    });

    test(
        "fail message creation when session's status does not accept messages.", 
        function () {

        $actingUser = User::factory()->create();
        $therapy = Therapy::factory()->create([
            'addedby_id' => $actingUser->id,
            'addedby_type' => $actingUser::class,
        ]);
        $session = Session::factory()->create([
            'addedby_id' => $actingUser->id,
            'addedby_type' => $actingUser::class,
            'status' => SessionStatusEnum::held_confirmation->value,
            'for_id' => $therapy->id,
            'for_type' => $therapy::class
        ]);

        $this->expectException(MessageException::class, "The message cannot be sent because the session may no more be in session.");

        MessageService::new()->createMessage(
            CreateMessageDTO::new()->fromArray([
                'from' => $actingUser,
                'user' => $actingUser,
                'for' => $session
            ])
        );
    });

    // test(
    //     "fail message creation when discussion's status does not accept messages.", 
    //     function () {

    //     $actingUser = User::factory()->create();
    //     $therapy = Therapy::factory()->create([
    //         'addedby_id' => $actingUser->id,
    //         'addedby_type' => $actingUser::class,
    //     ]);
    //     $discussion = Discussion::factory()->create([
    //         'addedby_id' => $actingUser->id,
    //         'addedby_type' => $actingUser::class,
    //         'status' => DiscussionStatusEnum::held_confirmation->value,
    //         'for_id' => $therapy->id,
    //         'for_type' => $therapy::class
    //     ]);

    //     $this->expectException(MessageException::class, "The message cannot be sent because the discussion may no more be in session.");

    //     MessageService::new()->createMessage(
    //         CreateMessageDTO::new()->fromArray([
    //             'from' => $actingUser,
    //             'user' => $actingUser,
    //             'for' => $discussion
    //         ])
    //     );
    // });

    test(
        "fail message creation when user/counsellor accounts of current user is not participating in session's therapy.", 
        function () {

        $actingUser = User::factory()->create();
        $therapyOwner = User::factory()->create();
        $counsellorUser = User::factory()->create();
        $counsellor = Counsellor::factory()->create([
            'user_id' => $counsellorUser->id,
        ]);
        $therapy = Therapy::factory()->create([
            'addedby_id' => $therapyOwner->id,
            'addedby_type' => $therapyOwner::class,
            'counsellor_id' => $counsellor->id,
        ]);
        $session = Session::factory()->create([
            'addedby_id' => $counsellor->id,
            'addedby_type' => $counsellor::class,
            'status' => SessionStatusEnum::in_session_confirmation->value,
            'for_id' => $therapy->id,
            'for_type' => $therapy::class
        ]);

        $this->expectException(MessageException::class, "You are not allowed to create a message for this session.");

        MessageService::new()->createMessage(
            CreateMessageDTO::new()->fromArray([
                'from' => $actingUser,
                'user' => $actingUser,
                'for' => $session
            ])
        );
    });

    // test(
    //     "fail message creation when counsellor account of current user is not participating in discussion.", 
    //     function () {

    //     $actingUser = User::factory()->create();
    //     $therapyOwner = User::factory()->create();
    //     $counsellorUser = User::factory()->create();
    //     $counsellor = Counsellor::factory()->create([
    //         'user_id' => $counsellorUser->id,
    //     ]);
    //     $therapy = Therapy::factory()->create([
    //         'addedby_id' => $therapyOwner->id,
    //         'addedby_type' => $therapyOwner::class,
    //         'counsellor_id' => $counsellor->id,
    //     ]);
    //     $discussion = Discussion::factory()->create([
    //         'addedby_id' => $counsellor->id,
    //         'addedby_type' => $counsellor::class,
    //         'status' => DiscussionStatusEnum::in_session_confirmation->value,
    //         'for_id' => $therapy->id,
    //         'for_type' => $therapy::class
    //     ]);

    //     $this->expectException(MessageException::class, "You are not allowed to create a message for this discussion.");

    //     MessageService::new()->createMessage(
    //         CreateMessageDTO::new()->fromArray([
    //             'from' => $actingUser,
    //             'user' => $actingUser,
    //             'for' => $discussion
    //         ])
    //     );
    // });

    test(
        "fail message creation when no recepient is provided for a therapy session's message.", 
        function () {

        $therapyOwner = User::factory()->create();
        $counsellorUser = User::factory()->create();
        $counsellor = Counsellor::factory()->create([
            'user_id' => $counsellorUser->id,
        ]);
        $therapy = Therapy::factory()->create([
            'addedby_id' => $therapyOwner->id,
            'addedby_type' => $therapyOwner::class,
            'counsellor_id' => $counsellor->id,
        ]);
        $session = Session::factory()->create([
            'addedby_id' => $counsellor->id,
            'addedby_type' => $counsellor::class,
            'status' => SessionStatusEnum::in_session_confirmation->value,
            'for_id' => $therapy->id,
            'for_type' => $therapy::class
        ]);

        $this->expectException(MessageException::class, "Recepient is required for a therapy session.");

        MessageService::new()->createMessage(
            CreateMessageDTO::new()->fromArray([
                'from' => $therapyOwner,
                'user' => $therapyOwner,
                'for' => $session
            ])
        );
    });

    test(
        "fail message creation when recepient to message is not a participant to therapy or discussion.", 
        function () {

        $otherUser = User::factory()->create();
        $therapyOwner = User::factory()->create();
        $counsellorUser = User::factory()->create();
        $counsellor = Counsellor::factory()->create([
            'user_id' => $counsellorUser->id,
        ]);
        $therapy = Therapy::factory()->create([
            'addedby_id' => $therapyOwner->id,
            'addedby_type' => $therapyOwner::class,
            'counsellor_id' => $counsellor->id,
        ]);
        $session = Session::factory()->create([
            'addedby_id' => $counsellor->id,
            'addedby_type' => $counsellor::class,
            'status' => SessionStatusEnum::in_session_confirmation->value,
            'for_id' => $therapy->id,
            'for_type' => $therapy::class
        ]);

        $this->expectException(MessageException::class, "You are sending the message to someone who is not participating in session/discussion.");

        MessageService::new()->createMessage(
            CreateMessageDTO::new()->fromArray([
                'from' => $therapyOwner,
                'user' => $therapyOwner,
                'for' => $session,
                'to' => $otherUser
            ])
        );
    });

    test(
        "fail message creation when no text or files are sent.", 
        function () {

        $therapyOwner = User::factory()->create();
        $counsellorUser = User::factory()->create();
        $counsellor = Counsellor::factory()->create([
            'user_id' => $counsellorUser->id,
        ]);
        $therapy = Therapy::factory()->create([
            'addedby_id' => $therapyOwner->id,
            'addedby_type' => $therapyOwner::class,
            'counsellor_id' => $counsellor->id,
        ]);
        $session = Session::factory()->create([
            'addedby_id' => $counsellor->id,
            'addedby_type' => $counsellor::class,
            'status' => SessionStatusEnum::in_session_confirmation->value,
            'for_id' => $therapy->id,
            'for_type' => $therapy::class
        ]);

        $this->expectException(MessageException::class, "There is not sufficient information to create a message. There should be content or files, at least.");

        MessageService::new()->createMessage(
            CreateMessageDTO::new()->fromArray([
                'from' => $therapyOwner,
                'user' => $therapyOwner,
                'for' => $session,
                'to' => $counsellor
            ])
        );
    });

    test(
        "successful message creation with the right from, to, for and content.", 
        function () {

        $therapyOwner = User::factory()->create();
        $counsellorUser = User::factory()->create();
        $counsellor = Counsellor::factory()->create([
            'user_id' => $counsellorUser->id,
        ]);
        $therapy = Therapy::factory()->create([
            'addedby_id' => $therapyOwner->id,
            'addedby_type' => $therapyOwner::class,
            'counsellor_id' => $counsellor->id,
        ]);
        $session = Session::factory()->create([
            'addedby_id' => $counsellor->id,
            'addedby_type' => $counsellor::class,
            'status' => SessionStatusEnum::in_session_confirmation->value,
            'for_id' => $therapy->id,
            'for_type' => $therapy::class
        ]);

        $message = MessageService::new()->createMessage(
            CreateMessageDTO::new()->fromArray([
                'from' => $therapyOwner,
                'user' => $therapyOwner,
                'for' => $session,
                'to' => $counsellor,
                'content' => 'message content'
            ])
        );

        $this->assertDatabaseHas('messages', [
            'content' => $message->content,
            'from_id' => $message->from_id,
            'from_type' => $message->from_type,
            'for_id' => $message->for_id,
            'for_type' => $message->for_type,
            'to_id' => $message->to_id,
            'to_type' => $message->to_type,
        ]);
    });

    test(
        "successful message creation with the right from, to, for and files.", 
        function () {

        Storage::fake('local');
        Event::fake();

        $therapyOwner = User::factory()->create();
        $counsellorUser = User::factory()->create();
        $counsellor = Counsellor::factory()->create([
            'user_id' => $counsellorUser->id,
        ]);
        $therapy = Therapy::factory()->create([
            'addedby_id' => $therapyOwner->id,
            'addedby_type' => $therapyOwner::class,
            'counsellor_id' => $counsellor->id,
        ]);
        $session = Session::factory()->create([
            'addedby_id' => $counsellor->id,
            'addedby_type' => $counsellor::class,
            'status' => SessionStatusEnum::in_session_confirmation->value,
            'for_id' => $therapy->id,
            'for_type' => $therapy::class
        ]);

        $files = [
            UploadedFile::fake()->image('photo1.jpg'),
            UploadedFile::fake()->image('photo2.jpg')
        ];

        $message = MessageService::new()->createMessage(
            CreateMessageDTO::new()->fromArray([
                'from' => $therapyOwner,
                'user' => $therapyOwner,
                'for' => $session,
                'to' => $counsellor,
                'files' => $files
            ])
        );

        $this->assertDatabaseHas('messages', [
            'content' => $message->content,
            'from_id' => $message->from_id,
            'from_type' => $message->from_type,
            'for_id' => $message->for_id,
            'for_type' => $message->for_type,
            'to_id' => $message->to_id,
            'to_type' => $message->to_type,
        ]);

        Event::assertDispatched(MessageSentEvent::class);

        expect($message->files()->count())->toEqual(2);
    });
});

describe("update message tests", function () {

    test("fails message update when no message is provided", function () {

        $this->expectException(MessageException::class, "The message was not found.");

        MessageService::new()->updateMessage(
            CreateMessageDTO::new()->fromArray([
                'message' => null
            ])
        );
    });

    test("fails message update when message was not created by current user's user/counsellor account.", function () {

        $actingUser = User::factory()->create();
        $fromUser = User::factory()->create();
        $session = Session::factory()->create();
        $message = Message::factory()->create([
            'from_id' => $fromUser->id,
            'from_type' => $fromUser::class,
            'for_id' => $session->id,
            'for_type' => $session::class,
        ]);

        $this->expectException(MessageException::class, "You are not authorized to update this message.");

        MessageService::new()->updateMessage(
            CreateMessageDTO::new()->fromArray([
                'message' => $message,
                'user' => $actingUser
            ])
        );
    });

    test("fails message update when no content, files or deleted files are provided.", function () {

        $fromUser = User::factory()->create();
        $session = Session::factory()->create();
        $message = Message::factory()->create([
            'from_id' => $fromUser->id,
            'from_type' => $fromUser::class,
            'for_id' => $session->id,
            'for_type' => $session::class,
        ]);

        $this->expectException(MessageException::class, "There is not sufficient information to create a message. There should be content or files, at least.");

        MessageService::new()->updateMessage(
            CreateMessageDTO::new()->fromArray([
                'message' => $message,
                'user' => $fromUser
            ])
        );
    });

    test("successfully update message with content when creator of original message.", function () {

        Event::fake();
        
        $fromUser = User::factory()->create();
        $session = Session::factory()->create();
        $message = Message::factory()->create([
            'from_id' => $fromUser->id,
            'from_type' => $fromUser::class,
            'for_id' => $session->id,
            'for_type' => $session::class,
        ]);

        $text = 'hello world';
        $message = MessageService::new()->updateMessage(
            CreateMessageDTO::new()->fromArray([
                'message' => $message,
                'user' => $fromUser,
                'content' => $text
            ])
        );

        Event::assertDispatched(MessageUpdatedEvent::class);
        expect($text)->toEqual($message->content);
    });

    test("successfully update message with files when creator of original message.", function () {

        Event::fake();
        Storage::fake('local');
        
        $fromUser = User::factory()->create();
        $session = Session::factory()->create();
        $message = Message::factory()->create([
            'from_id' => $fromUser->id,
            'from_type' => $fromUser::class,
            'for_id' => $session->id,
            'for_type' => $session::class,
        ]);

        $files = [
            UploadedFile::fake()->image('photo1.jpg'),
            UploadedFile::fake()->image('photo2.jpg')
        ];

        $this->assertDatabaseMissing('files', [
            'mime' => 'image/jpeg',
        ]);

        $message = MessageService::new()->updateMessage(
            CreateMessageDTO::new()->fromArray([
                'message' => $message,
                'user' => $fromUser,
                'files' => $files
            ])
        );

        Event::assertDispatched(MessageUpdatedEvent::class);

        $this->assertDatabaseHas('files', [
            'mime' => 'image/jpeg',
        ]);
        expect(count($files))->toEqual($message->files()->count());
    });

    test("successfully update message with ids of deleted files when creator of original message.", function () {

        Event::fake();
        Storage::fake('local');
        
        $files = [
            UploadedFile::fake()->image('photo1.jpg'),
            UploadedFile::fake()->image('photo2.jpg')
        ];

        $fromUser = User::factory()->create();
        $counsellorUser = User::factory()->create();
        $counsellor = Counsellor::factory()->create([
            'user_id' => $counsellorUser->id,
        ]);
        $therapy = Therapy::factory()->create([
            'addedby_id' => $fromUser->id,
            'addedby_type' => $fromUser::class,
            'counsellor_id' => $counsellor->id
        ]);
        $session = Session::factory()->create([
            'for_id' => $therapy->id,
            'for_type' => $therapy::class,
        ]);
        $message = MessageService::new()->createMessage(
            CreateMessageDTO::new()->fromArray([
                'user' => $fromUser,
                'from' => $fromUser,
                'for' => $session,
                'to' => $counsellor,
                'files' => $files,
            ])
        );
        $message = Message::factory()->create([
            'from_id' => $fromUser->id,
            'from_type' => $fromUser::class,
            'for_id' => $session->id,
            'for_type' => $session::class,
        ]);

        $this->assertDatabaseHas('files', [
            'mime' => 'image/jpeg',
        ]);
        expect(count($files))->toEqual(File::latest()->count());

        $message = MessageService::new()->updateMessage(
            CreateMessageDTO::new()->fromArray([
                'message' => $message,
                'user' => $fromUser,
                'deletedFiles' => [File::latest()->first()->id]
            ])
        );

        Event::assertDispatched(MessageUpdatedEvent::class);

        $this->assertDatabaseHas('files', [
            'mime' => 'image/jpeg',
        ]);
        expect(1)->toEqual(File::latest()->count());
        expect(count($files))->not->toEqual(File::latest()->count());
    });
});

describe("delete message tests", function () {

    test("", function () {

    });
});

describe("delete message for me tests", function () {

    test("", function () {

    });
});