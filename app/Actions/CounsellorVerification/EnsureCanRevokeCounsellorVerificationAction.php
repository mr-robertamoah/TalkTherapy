<?php

namespace App\Actions\CounsellorVerification;

use App\Actions\Action;
use App\Exceptions\CounsellorException;
use App\Models\Counsellor;
use App\Models\User;

// SCRUM-31: any administrator may revoke a counsellor's verification (as any may accept one), except
// their own, and only a super administrator a super administrator's -- with a reason, which the
// counsellor is sent. A verification that has ended can still be revoked (fraud found later is then
// on the record for whoever reviews their next request); one already revoked can't be again.
class EnsureCanRevokeCounsellorVerificationAction extends Action
{
    public const NOT_ALLOWED = 'You are not allowed to revoke this verification.';

    public const NOT_VERIFIED = 'This counsellor has no verification to revoke.';

    public const REASON = 'Give a reason of 10 to 1000 characters. The counsellor is sent it.';

    public const REASON_MIN = 10;

    public const REASON_MAX = 1000;

    public function execute(?User $actor, ?Counsellor $counsellor, mixed $reason): string
    {
        if (! $actor?->isAdmin() || ! $counsellor || (int) $counsellor->user_id === $actor->id
            || ($counsellor->user?->isSuperAdmin() && ! $actor->isSuperAdmin())) {
            throw new CounsellorException(self::NOT_ALLOWED, 403);
        }

        $reason = is_string($reason) ? trim($reason) : '';
        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX) {
            throw new CounsellorException(self::REASON, 422);
        }

        if (! $counsellor->wasEverVerified() || ! is_null($counsellor->verification_revoked_at)) {
            throw new CounsellorException(self::NOT_VERIFIED, 422);
        }

        return $reason;
    }
}
