<?php

use App\DTOs\CreateMessageDTO;
use App\Enums\DiscussionStatusEnum;
use App\Enums\SessionStatusEnum;
use App\Exceptions\MessageException;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Services\MessageService;

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
});