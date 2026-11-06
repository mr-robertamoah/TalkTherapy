<?php

namespace App\Actions\CounsellorVerification;

use App\Actions\Action;
use App\DTOs\VerifyCounsellorDTO;
use App\Exceptions\VerificationRequestRequirementException;
use App\Support\CounsellorVerification;

// SCRUM-31: a counsellor asks to be verified when they aren't (never were, it ended, or it was
// revoked) or to re-verify within the renewal window before it ends -- not a year early.
class EnsureCounsellorCanRequestVerificationAction extends Action
{
    public const TOO_EARLY = 'Your verification is current. You can re-verify from :days days before it ends.';

    public function execute(VerifyCounsellorDTO $dto): void
    {
        if (CounsellorVerification::mayRequest($dto->counsellor)) {
            return;
        }

        throw new VerificationRequestRequirementException(str_replace(':days', (string) CounsellorVerification::renewalWindowDays(), self::TOO_EARLY), 422);
    }
}
