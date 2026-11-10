<?php

namespace App\Http\Controllers;

use App\Actions\VideoConsent\GetCurrentValidVideoConsentForTherapyAction;
use App\Actions\VideoConsent\GetCurrentVideoConsentableForTherapyAction;
use App\Actions\VideoConsent\GetVideoConsentAuditTrailForWardAction;
use App\Actions\VideoConsent\GetWardForVideoConsentableAction;
use App\Actions\VideoConsent\GrantVideoConsentAction;
use App\Actions\VideoConsent\RevokeVideoConsentAction;
use App\Actions\VideoConsent\SetVideoConsentModeAction;
use App\Exceptions\VideoConsentException;
use App\Http\Resources\VideoConsentResource;
use App\Models\Therapy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

// TT-3.1e-f/SCRUM-285: the first HTTP-reachable surface for the guardian video-consent Actions
// (TT-3.1e-b/c) -- none of them had a route before this ticket. Mirrors
// TherapyController::updateStrictPaymentGate()'s own shape (Redirect::back() on both success and
// failure, so the frontend uses router.patch()/router.post(), not axios, for the write endpoints).
//
// Security-review requirement carried over from SCRUM-282's own review: revoke() calls
// RevokeVideoConsentAction (self-authorizing via isGuardianOf), NEVER InvalidateVideoConsentAction
// directly -- that action has no authorization check of its own by design.
//
// Security-review finding (2026-09-11): every method here used to call straight into its
// underlying Action with no gate of its own, trusting each Action's own authorization check to
// reject an unrelated caller -- correct for WHETHER the request is denied, but each Action's
// denial message differs by the therapy's actual state ("no minor client" vs. "only applies to a
// minor client" vs. "not a guardian"), letting any authenticated stranger probe an arbitrary
// therapyId and learn, purely from which message came back, whether its client is a minor --
// exactly the safeguarding-sensitive fact this whole feature exists to protect. Fixed the same
// way EnsureVideoIsAvailableForSessionAction's own participant check was ordered first for the
// identical reason (see that action's own SCRUM-275 comment): requireRelationshipToTherapy() runs
// before any Action, and returns the SAME generic denial regardless of the therapy's actual
// minor/adult/mode/guardian state.
class VideoConsentController extends Controller
{
    public function updateMode(Request $request)
    {
        $request->validate(['mode' => ['required', 'string']]);

        try {
            $therapy = $this->therapy($request);
            $this->requireRelationshipToTherapy($request, $therapy);

            SetVideoConsentModeAction::new()->execute(
                $therapy,
                $request->user(),
                $request->string('mode')->toString()
            );

            return Redirect::back();
        } catch (Throwable $th) {
            return $this->failure($th);
        }
    }

    public function grant(Request $request)
    {
        try {
            $therapy = $this->therapy($request);
            $this->requireRelationshipToTherapy($request, $therapy);
            $consentable = GetCurrentVideoConsentableForTherapyAction::new()->execute($therapy);

            if (! $consentable) {
                return Redirect::back()->withErrors(['alert' => 'No session currently requires video consent.']);
            }

            GrantVideoConsentAction::new()->execute($request->user(), $consentable);

            return Redirect::back();
        } catch (Throwable $th) {
            return $this->failure($th);
        }
    }

    // Resolves "the currently-valid grant for this therapy's current scope" itself, rather than
    // taking a consent id from the client -- a guardian revokes "the current thing," not an
    // arbitrary VideoConsent row by id, and this avoids the frontend ever needing to know one.
    public function revoke(Request $request)
    {
        try {
            $therapy = $this->therapy($request);
            $this->requireRelationshipToTherapy($request, $therapy);
            $consent = GetCurrentValidVideoConsentForTherapyAction::new()->execute($therapy);

            if (! $consent) {
                return Redirect::back()->withErrors(['alert' => 'There is no active video consent to revoke.']);
            }

            RevokeVideoConsentAction::new()->execute($request->user(), $consent);

            return Redirect::back();
        } catch (Throwable $th) {
            return $this->failure($th);
        }
    }

    // JSON, not Inertia -- fetched on demand (the guardian expanding the audit trail section),
    // matching GuardianshipSection.vue's own existing axios.get lazy-fetch pattern rather than
    // inflating every therapy page load with data only a guardian ever needs.
    public function auditTrail(Request $request)
    {
        try {
            $therapy = $this->therapy($request);
            $this->requireRelationshipToTherapy($request, $therapy);
            $ward = GetWardForVideoConsentableAction::new()->execute($therapy);

            if (! $ward) {
                throw new VideoConsentException('This therapy has no minor client.', 422);
            }

            $trail = GetVideoConsentAuditTrailForWardAction::new()->execute($request->user(), $ward);

            return response()->json(['data' => VideoConsentResource::collection($trail)]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);

            return response()->json(['message' => $this->messageFor($th, $status)], $status);
        }
    }

    // Reads $request->route('therapyId'), never a body/query param -- matches
    // VideoSessionController::session()'s own established convention against a client sending a
    // different id in the payload than the URL's own.
    private function therapy(Request $request): Therapy
    {
        return Therapy::find($request->route('therapyId')) ?? throw new VideoConsentException('Therapy was not found.', 422);
    }

    // The one gate every method above passes through before reaching any Action whose own denial
    // message could otherwise double as a minor/adult oracle. Deliberately generic and deliberately
    // resolves the ward itself (not via a downstream Action's own differently-worded check) so
    // every non-participant, non-guardian caller gets the exact same message regardless of whether
    // the therapy has no User addedby, an adult addedby, or a minor addedby they simply aren't a
    // guardian of.
    private function requireRelationshipToTherapy(Request $request, Therapy $therapy): void
    {
        $user = $request->user();
        $ward = GetWardForVideoConsentableAction::new()->execute($therapy);

        if ($therapy->isParticipant($user) || ($ward && $user->isGuardianOf($ward))) {
            return;
        }

        throw new VideoConsentException('You are not allowed to access this therapy\'s video consent settings.', 422);
    }

    private function failure(Throwable $th)
    {
        $status = $this->statusFor($th);
        $message = $this->messageFor($th, $status);

        return Redirect::back()->withErrors(['alert' => $message]);
    }
}
