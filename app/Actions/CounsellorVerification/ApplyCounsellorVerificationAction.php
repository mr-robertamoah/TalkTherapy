<?php

namespace App\Actions\CounsellorVerification;

use App\Actions\Action;
use App\Enums\CounsellorVerificationEventEnum;
use App\Models\Counsellor;
use App\Models\Request;
use App\Models\User;
use App\Support\CounsellorVerification;

// SCRUM-31: an administrator ($actor) accepted $request, a counsellor verification request -- the
// only way a counsellor becomes (or stays) verified. Inside the caller's transaction, after its lock
// on the request: locks the counsellor, keeps their first verified date, sets the next end date
// (CounsellorVerification::nextExpiry()), lifts a revocation, and records it.
class ApplyCounsellorVerificationAction extends Action
{
    public function execute(Counsellor $counsellor, Request $request, ?User $actor): Counsellor
    {
        $counsellor = Counsellor::query()->lockForUpdate()->findOrFail($counsellor->id);

        $event = $counsellor->wasEverVerified() ? CounsellorVerificationEventEnum::reverified : CounsellorVerificationEventEnum::verified;
        $expiresAt = CounsellorVerification::nextExpiry($counsellor);

        $counsellor->forceFill([
            'verified_at' => $counsellor->verified_at ?? now(),
            'verification_expires_at' => $expiresAt,
            'verification_revoked_at' => null,
        ])->save();

        RecordCounsellorVerificationEventAction::new()->execute($counsellor, $event, $actor, $request, expiresAt: $expiresAt);

        return $counsellor;
    }
}
