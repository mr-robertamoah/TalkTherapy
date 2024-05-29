<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\Exceptions\LinkException;
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
        ) throw new LinkException("You are already a guardian of this user.", 422);

        $createLinkDTO->user->wards()->create(['ward_id' => $createLinkDTO->link->for->id]);

        return Redirect::route('profile.show');
    }
}