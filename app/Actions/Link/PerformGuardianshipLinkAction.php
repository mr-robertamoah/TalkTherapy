<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use Illuminate\Support\Facades\Redirect;

class PerformGuardianshipLinkAction extends Action
{
    public function execute(CreateLinkDTO $createLinkDTO)
    {
        $createLinkDTO->user->wards()->create(['ward_id' => $createLinkDTO->link->for->id]);

        return Redirect::route('profile.show');
    }
}