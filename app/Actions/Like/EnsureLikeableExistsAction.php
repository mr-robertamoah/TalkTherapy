<?php

namespace App\Actions\Like;

use App\Actions\Action;
use App\DTOs\CreateLikeDTO;
use App\Exceptions\LikeException;
use App\Models\GroupTherapy;
use App\Models\Therapy;

class EnsureLikeableExistsAction extends Action
{
    public const NOT_AVAILABLE = 'This cannot be liked.';

    public function execute(CreateLikeDTO $createLikeDTO)
    {
        // SCRUM-351/354: a therapy or group must be public. A private one answers exactly like a
        // missing one, so its existence can't be probed.
        $likeable = $createLikeDTO->likeable;
        if ($likeable && (! ($likeable instanceof Therapy || $likeable instanceof GroupTherapy) || $likeable->acceptsPublicEngagement())) {
            return;
        }

        throw new LikeException(self::NOT_AVAILABLE, 422);
    }
}
