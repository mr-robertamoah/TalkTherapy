<?php

namespace App\Http\Controllers;

use App\Actions\Safety\AcknowledgeSafetyFlagAction;
use App\Actions\Safety\GetSafetyFlagsAction;
use App\Actions\Safety\ReassignSafetyFlagAction;
use App\Actions\Safety\RevealSafetyFlagAction;
use App\Enums\SafetyFlagStatusEnum;
use App\Models\SafetyFlag;
use App\Models\User;
use App\Support\FailureResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Throwable;

// SCRUM-32: the administrators' safety alerts. Behind the `admin` and `safety_flags` middleware; each
// action checks again (involved administrators get the same answer as for a missing one).
class AdminSafetyFlagController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Admin/SafetyFlags', [
            ...GetSafetyFlagsAction::new()->execute($request->user(), $this->status($request)),
            // For a super administrator handing one on (only on the page, not each refresh).
            'admins' => $request->user()->isSuperAdmin() ? GetSafetyFlagsAction::new()->admins() : [],
            'status' => $this->status($request) ?? '',
            'label' => SafetyFlag::label(),
        ]);
    }

    // The list again, for the page's live refresh.
    public function list(Request $request)
    {
        return response()->json(GetSafetyFlagsAction::new()->execute($request->user(), $this->status($request)));
    }

    public function acknowledge(Request $request, string $flag)
    {
        return $this->respond(function () use ($request, $flag) {
            AcknowledgeSafetyFlagAction::new()->execute($request->user(), SafetyFlag::findPublic($flag));

            return ['status' => true];
        });
    }

    public function reassign(Request $request, string $flag)
    {
        return $this->respond(function () use ($request, $flag) {
            ReassignSafetyFlagAction::new()->execute(
                $request->user(), SafetyFlag::findPublic($flag),
                is_numeric($request->input('adminId')) ? User::query()->find((int) $request->input('adminId')) : null,
            );

            return ['status' => true];
        });
    }

    public function who(Request $request, string $flag)
    {
        return $this->respond(fn () => RevealSafetyFlagAction::new()->who($request->user(), SafetyFlag::findPublic($flag)));
    }

    public function reason(Request $request, string $flag)
    {
        return $this->respond(fn () => RevealSafetyFlagAction::new()->reason($request->user(), SafetyFlag::findPublic($flag)));
    }

    private function status(Request $request): ?string
    {
        return SafetyFlagStatusEnum::tryFrom((string) $request->query('status'))?->value;
    }

    private function respond(callable $action)
    {
        try {
            return response()->json($action());
        } catch (Throwable $th) {
            return FailureResponse::json($th);
        }
    }
}
