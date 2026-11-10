<?php

namespace App\Actions\VideoConsent;

use App\Actions\Action;
use App\Enums\VideoConsentModeEnum;
use App\Exceptions\VideoConsentException;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;
use Illuminate\Support\Facades\DB;

// TT-3.1e-b/SCRUM-281: any ONE guardian of the ward may grant -- no unanimity requirement across
// multiple guardians (user's own explicit decision, 2026-09-11). Idempotent and race-safe: two
// guardians (or the same guardian double-clicking) granting for the same scope at nearly the
// same instant must never create two simultaneously-valid rows, since nothing at the DB level
// prevents that (documented, deliberate limitation -- see documentation/decision-log.md's
// SCRUM-280 entry). Locks and re-checks inside a transaction before creating, the same
// find-or-create-under-lock shape JoinVideoSessionAction/JoinGroupTherapyAction already
// established for an identical race shape.
class GrantVideoConsentAction extends Action
{
    public function execute(User $guardian, Therapy|Session $consentable): VideoConsent
    {
        $wardResolver = GetWardForVideoConsentableAction::new();
        $ward = $wardResolver->execute($consentable);

        if (! $ward) {
            throw new VideoConsentException('This therapy has no minor client to grant video consent for.', 422);
        }

        // TT-4.10b/SCRUM-291: was a live $ward->isAdult() re-check -- now prefers the therapy's
        // own stable client_was_minor_at_creation snapshot (TT-4.10a), closing the self-editable-
        // dob bypass SCRUM-287 found.
        if (! $wardResolver->isMinor($consentable)) {
            throw new VideoConsentException('Video consent only applies to a minor client.', 422);
        }

        if (! $guardian->isGuardianOf($ward)) {
            throw new VideoConsentException('You are not a guardian of this client.', 422);
        }

        $therapy = $wardResolver->therapyFor($consentable);

        // Fail closed on an unset mode rather than silently defaulting to either interpretation
        // -- PER_THERAPY (one-time, broadest) is the more permissive of the two, so quietly
        // assuming it whenever nobody has actively chosen a mode would be the wrong direction to
        // default on a safeguarding feature. The counsellor or a guardian must set a mode
        // (SetVideoConsentModeAction) before any consent can be granted at all.
        if (! $therapy->video_consent_mode) {
            throw new VideoConsentException('This therapy has no video consent mode set yet.', 422);
        }

        // Scope must match the therapy's CURRENT mode at grant time -- prevents a PER_THERAPY
        // grant existing while the therapy is set to PER_SESSION (which would silently authorize
        // every session at once, defeating the whole point of the stricter mode), or vice versa.
        // "Mode switches are prospective-only" (TT-3.1e-a) means a grant already made under the
        // OLD mode still stays valid after a later switch -- this check only guards what NEW
        // grants may be created under the CURRENT mode, not what already exists.
        $expectedConsentableType = $therapy->video_consent_mode === VideoConsentModeEnum::per_session->value
            ? Session::class
            : Therapy::class;

        if ($consentable::class !== $expectedConsentableType) {
            throw new VideoConsentException('This consent scope does not match the therapy\'s current video consent mode.', 422);
        }

        return DB::transaction(function () use ($guardian, $consentable, $ward) {
            // There's no VideoConsent row guaranteed to exist yet to lock directly (the very
            // first grant for a scope has zero rows to lock, and locking a zero-row query result
            // locks nothing). Lock the one row that always already exists instead -- the
            // consentable itself -- to serialize concurrent grants the same way
            // JoinVideoSessionAction::currentOrNewVideoSession() locks the parent Session before
            // deciding whether to create the child VideoSession.
            $consentable::query()->lockForUpdate()->find($consentable->id);

            $existing = VideoConsent::query()
                ->whereValidFor($consentable::class, $consentable->id)
                ->first();

            if ($existing) {
                return $existing;
            }

            return VideoConsent::query()->create([
                'ward_id' => $ward->id,
                'guardian_id' => $guardian->id,
                'consentable_type' => $consentable::class,
                'consentable_id' => $consentable->id,
                'granted_at' => now(),
            ]);
        });
    }
}
