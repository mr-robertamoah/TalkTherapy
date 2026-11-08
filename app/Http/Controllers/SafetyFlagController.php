<?php

namespace App\Http\Controllers;

use App\Actions\Safety\EnsureCanFlagClientAction;
use App\Actions\Safety\GetFlaggableMembersAction;
use App\Actions\Safety\GetSafetyFlagStatusesAction;
use App\Actions\Safety\RaiseSafetyFlagAction;
use App\Actions\Safety\SafetyFlagSubject;
use App\Actions\Safety\WithdrawSafetyFlagAction;
use App\Enums\SafetyFlagDiscussedEnum;
use App\Enums\SafetyFlagUrgencyEnum;
use App\Models\GroupTherapy;
use App\Models\HelpRequest;
use App\Models\SafetyFlag;
use App\Models\Therapy;
use App\Support\FailureResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

// SCRUM-32: a counsellor raises a safety flag -- for their 1:1 client, a member of their group (by key),
// or the person in an urgent help chat they answered. Always JSON (the modal posts with axios). The
// response says only that it was raised.
class SafetyFlagController extends Controller
{
    public function storeForTherapy(Request $request, int $therapyId)
    {
        return $this->raise($request, fn () => EnsureCanFlagClientAction::new()->forTherapy($request->user(), Therapy::query()->find($therapyId)));
    }

    public function storeForGroup(Request $request, int $groupTherapyId)
    {
        return $this->raise($request, fn () => EnsureCanFlagClientAction::new()->forGroup(
            $request->user(), GroupTherapy::query()->find($groupTherapyId),
            is_scalar($request->input('memberKey')) ? (string) $request->input('memberKey') : null,
        ));
    }

    public function storeForHelpChat(Request $request, int $helpRequest)
    {
        return $this->raise($request, fn () => EnsureCanFlagClientAction::new()->forHelpChat($request->user(), HelpRequest::query()->find($helpRequest)));
    }

    // The group's members a counsellor can flag (by key).
    public function groupMembers(Request $request, int $groupTherapyId)
    {
        try {
            return response()->json(['members' => GetFlaggableMembersAction::new()->execute($request->user(), GroupTherapy::query()->find($groupTherapyId))]);
        } catch (Throwable $th) {
            return FailureResponse::json($th);
        }
    }

    // TT-4.3b: the flags on a therapy, group or help chat, as its counsellors see them (status only).
    public function statusesForTherapy(Request $request, int $therapyId)
    {
        return $this->statuses(fn () => GetSafetyFlagStatusesAction::new()->forTherapy($request->user(), Therapy::query()->find($therapyId)));
    }

    public function statusesForGroup(Request $request, int $groupTherapyId)
    {
        return $this->statuses(fn () => GetSafetyFlagStatusesAction::new()->forGroup($request->user(), GroupTherapy::query()->find($groupTherapyId)));
    }

    public function statusesForHelpChat(Request $request, int $helpRequest)
    {
        return $this->statuses(fn () => GetSafetyFlagStatusesAction::new()->forHelpChat($request->user(), HelpRequest::query()->find($helpRequest)));
    }

    // TT-4.3b: the counsellor who raised it withdraws it, with a reason.
    public function withdraw(Request $request, string $flag)
    {
        $input = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        try {
            WithdrawSafetyFlagAction::new()->execute($request->user(), SafetyFlag::findPublic($flag), $input['reason']);

            return response()->json(['status' => true]);
        } catch (Throwable $th) {
            if (FailureResponse::status($th) === 500) {
                report($th);
            }

            return FailureResponse::json($th);
        }
    }

    private function statuses(callable $statuses)
    {
        try {
            return response()->json(['flags' => $statuses()]);
        } catch (Throwable $th) {
            if (FailureResponse::status($th) === 500) {
                report($th);
            }

            return FailureResponse::json($th);
        }
    }

    private function raise(Request $request, callable $subject)
    {
        try {
            // Who first, so a refusal never depends on what was typed.
            /** @var SafetyFlagSubject $resolved */
            $resolved = $subject();

            $input = $request->validate([
                'urgency' => ['required', Rule::in(SafetyFlagUrgencyEnum::values())],
                'reason' => ['required', 'string', 'min:10', 'max:2000'],
                'discussedWithClient' => ['required', Rule::in(SafetyFlagDiscussedEnum::values())],
                'emergencyServicesContacted' => ['required', 'boolean'],
                'emergencyServicesNote' => ['nullable', 'string', 'max:500'],
            ], [
                'reason.min' => 'Please say a little more about why (at least 10 characters).',
            ]);

            RaiseSafetyFlagAction::new()->execute($request->user(), $resolved, [
                'urgency' => $input['urgency'],
                'reason' => trim($input['reason']),
                'discussedWithClient' => $input['discussedWithClient'],
                'emergencyServicesContacted' => (bool) $input['emergencyServicesContacted'],
                'emergencyServicesNote' => isset($input['emergencyServicesNote']) ? trim($input['emergencyServicesNote']) : null,
            ]);

            return response()->json(['status' => true], 201);
        } catch (ValidationException $e) {
            throw $e; // Field errors, as a 422.
        } catch (Throwable $th) {
            // A flag that failed for an unexpected reason must never fail silently (security review).
            if (FailureResponse::status($th) === 500) {
                report($th);
            }

            return FailureResponse::json($th);
        }
    }
}
