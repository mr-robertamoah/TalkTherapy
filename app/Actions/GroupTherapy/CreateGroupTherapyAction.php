<?php

namespace App\Actions\GroupTherapy;

use App\Actions\Action;
use App\DTOs\GroupTherapyDTO;
use App\Enums\TherapyStatusEnum;
use App\Models\User;

class CreateGroupTherapyAction extends Action
{
    public function execute(GroupTherapyDTO $dto)
    {
        $addedby = $dto->counsellor ?: $dto->user;

        $data = [
            'status' => TherapyStatusEnum::pending->value,
            'public' => $dto->public,
            'payment_type' => $dto->paymentType,
            'session_type' => $dto->sessionType,
            'allow_in_person' => $dto->allowInPerson,
            'name' => $dto->name,
            'anonymous' => $dto->anonymous,
            'allow_anyone' => $dto->allowAnyone,
            'about' => $dto->about,
            // TT-4.10a/SCRUM-290: captured once, here, from the client's own live isAdult() at
            // this exact moment -- left null (not coerced to false) when $addedby is a
            // Counsellor, since a Counsellor-created group has no single "client" this applies
            // to at all. See the column's own migration for the full reasoning.
            'client_was_minor_at_creation' => $addedby instanceof User ? ! $addedby->isAdult() : null,
            'payment_data' => [
                'per' => $dto->per,
                'amount' => $dto->amount,
                'currency' => $dto->currency,
                'inPersonAmount' => $dto->inPersonAmount ?: '',
                'shareEqually' => $dto->shareEqually,
                'sharePercentage' => $dto->shareEqually ? null : $dto->sharePercentage,
                // TT-7.5b-b1/SCRUM-265: whoever is creating this GroupTherapy (User or Counsellor)
                // sets its initial values -- there is no prior state to protect yet, so no
                // authorization check is needed here (unlike a later update, which is
                // EnsureCanSetGroupTherapyPaymentGateAction's job). Defaults mirror TT-7.5a's own
                // trust-based default (strictPaymentGate: false) and the product decision for the
                // new sibling setting (allowFreeHistoricalAccess: true).
                'strictPaymentGate' => (bool) ($dto->strictPaymentGate ?? false),
                'allowFreeHistoricalAccess' => (bool) ($dto->allowFreeHistoricalAccess ?? true),
            ],
        ];

        // max_sessions/max_users/max_counsellors are NOT NULL columns with their own DB
        // defaults -- omit them when not provided so the DB default applies, instead of
        // passing null and crashing with a QueryException (SCRUM-77).
        foreach ([
            'max_sessions' => $dto->maxSessions,
            'max_users' => $dto->maxUsers,
            'max_counsellors' => $dto->maxCounsellors,
        ] as $column => $value) {
            if (! is_null($value)) {
                $data[$column] = $value;
            }
        }

        $therapy = $addedby->addedGroupTherapies()->create($data);

        if ($dto->cases && count($dto->cases)) {
            $therapy->cases()->attach($dto->cases);
        }

        return $therapy->refresh();
    }
}
