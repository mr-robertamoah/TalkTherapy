<?php

namespace App\Actions\CounsellorVerification;

use App\Actions\Action;
use App\Enums\CounsellorVerificationEventEnum;
use App\Models\Counsellor;
use App\Models\CounsellorVerificationEvent;
use App\Models\Request;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;

// SCRUM-31: the one writer of a counsellor's verification history. With a $dedupeKey, a second
// write of the same key is a no-op (returns null) -- how the sweeps send a reminder, or record a
// lapse, once.
class RecordCounsellorVerificationEventAction extends Action
{
    public function execute(
        Counsellor $counsellor,
        CounsellorVerificationEventEnum $event,
        ?User $actor = null,
        ?Request $request = null,
        ?string $reason = null,
        ?CarbonInterface $expiresAt = null,
        ?int $windowDays = null,
        ?string $dedupeKey = null,
    ): ?CounsellorVerificationEvent {
        $attributes = [
            'counsellor_id' => $counsellor->id,
            'event' => $event,
            'actor_user_id' => $actor?->id,
            'request_id' => $request?->id,
            'license_ids' => $request ? array_values(array_filter([
                $request->data['nationalIdLicense'] ?? null,
                $request->data['otherLicense'] ?? null,
            ])) ?: null : null,
            'reason' => $reason,
            'expires_at' => $expiresAt,
            'window_days' => $windowDays,
            'dedupe_key' => $dedupeKey,
        ];

        if (is_null($dedupeKey)) {
            return CounsellorVerificationEvent::query()->create($attributes);
        }

        if (CounsellorVerificationEvent::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        try {
            return CounsellorVerificationEvent::query()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
