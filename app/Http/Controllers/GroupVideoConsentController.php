<?php

namespace App\Http\Controllers;

use App\Actions\VideoConsent\GetGroupVideoConsentForGuardianAction;
use App\Actions\VideoConsent\GetGroupVideoConsentSessionAction;
use App\Actions\VideoConsent\GrantGroupVideoConsentAction;
use App\Actions\VideoConsent\RequestGroupVideoConsentAction;
use App\Actions\VideoConsent\RevokeVideoConsentAction;
use App\Enums\SessionStatusEnum;
use App\Exceptions\VideoConsentException;
use App\Http\Resources\VideoConsentResource;
use App\Models\GroupTherapy;
use App\Models\User;
use App\Models\VideoConsent;
use App\Support\FailureResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

// SCRUM-313: a minor's video consent in a group, answered by their guardians from their list of their ward's groups
// (they can't open a group they're only a member's guardian of). JSON for that panel. Every action re-checks the
// guardian is an active guardian of a ward who is in the group and needs consent; a consent id or ward id from
// the request is only ever looked up within that.
class GroupVideoConsentController extends Controller
{
    public function show(Request $request)
    {
        try {
            return response()->json(['data' => $this->state($request->user(), $this->group($request))]);
        } catch (Throwable $th) {
            return FailureResponse::json($th);
        }
    }

    public function grant(Request $request)
    {
        $request->validate([
            'wardId' => ['required', 'integer'],
            'scope' => ['required', Rule::in(['group', 'session'])],
        ]);

        try {
            $group = $this->group($request);
            $ward = User::query()->find($request->integer('wardId'))
                ?? throw new VideoConsentException(GrantGroupVideoConsentAction::NOT_YOUR_WARD, 422);
            // Before anything about the group (whether it has a session coming up) is answered.
            GrantGroupVideoConsentAction::new()->ensureMayGrant($request->user(), $ward, $group);
            $consentable = $request->input('scope') === 'group'
                ? $group
                : (GetGroupVideoConsentSessionAction::new()->execute($group)
                    ?? throw new VideoConsentException('This group has no session coming up to give consent for.', 422));

            GrantGroupVideoConsentAction::new()->execute($request->user(), $ward, $consentable);

            return response()->json(['data' => $this->state($request->user(), $group)]);
        } catch (Throwable $th) {
            return FailureResponse::json($th);
        }
    }

    public function revoke(Request $request)
    {
        try {
            $group = $this->group($request);
            // The caller's own wards in the group first (refused if none), then only their grants for this group: one
            // answer for every other consent id, so this can't be used to learn about anyone else's.
            $wardIds = collect(GetGroupVideoConsentForGuardianAction::new()->execute($request->user(), $group)['wards'])
                ->map(fn (array $entry) => $entry['ward']->id);
            $consent = VideoConsent::query()
                ->whereKey($request->route('consentId'))
                ->whereIn('ward_id', $wardIds)
                ->whereForGroup($group)
                ->first()
                ?? throw new VideoConsentException('There is no such video consent to withdraw.', 422);

            RevokeVideoConsentAction::new()->execute($request->user(), $consent);

            return response()->json(['data' => $this->state($request->user(), $group)]);
        } catch (Throwable $th) {
            return FailureResponse::json($th);
        }
    }

    // The ward's own: ask their guardians.
    public function request(Request $request)
    {
        try {
            $sent = RequestGroupVideoConsentAction::new()->execute($request->user(), $this->group($request));

            return response()->json(['sent' => $sent, 'message' => $sent
                ? 'Your parent or guardian has been asked.'
                : 'Your parent or guardian was asked recently. They can answer from their own account.']);
        } catch (Throwable $th) {
            return FailureResponse::json($th);
        }
    }

    private function state(User $guardian, GroupTherapy $group): array
    {
        $state = GetGroupVideoConsentForGuardianAction::new()->execute($guardian, $group);
        $session = $state['session'];

        return [
            'session' => $session ? ['id' => $session->id, 'startTime' => $session->start_time, 'isLive' => $session->status !== SessionStatusEnum::pending->value] : null,
            'wards' => array_map(fn (array $entry) => [
                'id' => $entry['ward']->id,
                'name' => $entry['ward']->name,
                'groupConsent' => $entry['groupConsent'] ? new VideoConsentResource($entry['groupConsent']) : null,
                'sessionConsent' => $entry['sessionConsent'] ? new VideoConsentResource($entry['sessionConsent']) : null,
            ], $state['wards']),
        ];
    }

    private function group(Request $request): GroupTherapy
    {
        return GroupTherapy::query()->find($request->route('groupTherapyId'))
            ?? throw new VideoConsentException('This group therapy was not found.', 422);
    }
}
