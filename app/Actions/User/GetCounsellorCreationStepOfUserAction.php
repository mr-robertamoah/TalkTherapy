<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Models\User;

class GetCounsellorCreationStepOfUserAction extends Action
{
    public function execute(User|null $user)
    {
        $step = 0;

        if (!$user) return $step;

        if ($user->isNotCounsellor()) return $step;

        $step += 1;

        if ($user->isNotVerifiedCounsellor()) return $step;

        $step += 1;

        if ($user->counsellor->hasNotEngagedAUserInTherapy()) return $step;

        $step += 1;

        if ($user->counsellor->hasNotHeldATherapySession()) return $step;

        $step += 1;

        return $step;
    }
}