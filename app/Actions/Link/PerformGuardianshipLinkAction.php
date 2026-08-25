<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\Exceptions\LinkException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Redirect;

class PerformGuardianshipLinkAction extends Action
{
    public function execute(CreateLinkDTO $createLinkDTO)
    {
        if (
            $createLinkDTO->user
                ->wards()
                ->where('ward_id', $createLinkDTO->link->for->id)
                ->exists()
        ) {
            throw new LinkException('You are already a guardian of this user.', 422);
        }

        // The existence check above is a courtesy for the common (sequential) case -- the
        // guardianship(guardian_id, ward_id) unique index (SCRUM-99) is what actually prevents
        // a duplicate row from two uses of this same link racing each other. Without this
        // catch, that race would surface as an uncaught UniqueConstraintViolationException
        // instead of the same graceful "already a guardian" error the sequential case gets.
        try {
            $createLinkDTO->user->wards()->create(['ward_id' => $createLinkDTO->link->for->id]);
        } catch (UniqueConstraintViolationException) {
            throw new LinkException('You are already a guardian of this user.', 422);
        }

        return Redirect::route('profile.show');
    }
}
