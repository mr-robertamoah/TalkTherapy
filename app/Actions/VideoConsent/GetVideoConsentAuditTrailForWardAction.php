<?php

namespace App\Actions\VideoConsent;

use App\Actions\Action;
use App\Exceptions\VideoConsentException;
use App\Models\User;
use App\Models\VideoConsent;
use Illuminate\Database\Eloquent\Collection;

// TT-3.1e-b/SCRUM-281: every guardian of the ward can see WHO granted/revoked consent and when,
// for every scope -- not just the guardian who acted (user's own decision, 2026-09-11). Returns
// every VideoConsent row for the ward (across all scopes and history, not just the currently-valid
// one) -- e-f's UI decides how to render the full grant/revoke/re-grant history this exposes.
class GetVideoConsentAuditTrailForWardAction extends Action
{
    public function execute(User $viewer, User $ward): Collection
    {
        if (! $viewer->isGuardianOf($ward)) {
            throw new VideoConsentException('You are not a guardian of this client.', 422);
        }

        return VideoConsent::query()
            ->where('ward_id', $ward->id)
            ->with(['guardian', 'revokedByGuardian'])
            ->latest('granted_at')
            ->get();
    }
}
