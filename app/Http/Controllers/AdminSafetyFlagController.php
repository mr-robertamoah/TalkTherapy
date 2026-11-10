<?php

namespace App\Http\Controllers;

use App\Actions\Safety\AcknowledgeSafetyFlagAction;
use App\Actions\Safety\AddSafetyFlagNoteAction;
use App\Actions\Safety\DecideSafetyFlagNoticeAction;
use App\Actions\Safety\GetSafetyFlagsAction;
use App\Actions\Safety\ReassignSafetyFlagAction;
use App\Actions\Safety\ResolveSafetyFlagAction;
use App\Actions\Safety\RevealSafetyFlagAction;
use App\Actions\Safety\SendSafetyFlagNoticeAction;
use App\Enums\SafetyFlagActionEnum;
use App\Enums\SafetyFlagOutcomeEnum;
use App\Enums\SafetyFlagStatusEnum;
use App\Models\SafetyFlag;
use App\Models\User;
use App\Support\FailureResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            'outcomes' => collect(SafetyFlagOutcomeEnum::cases())->map(fn ($case) => ['value' => $case->value, 'label' => $case->label()])->all(),
            'actions' => collect(SafetyFlagActionEnum::cases())->map(fn ($case) => ['value' => $case->value, 'label' => $case->label()])->all(),
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

    // TT-4.3b: the handling administrator's notes, actions and resolution.
    public function note(Request $request, string $flag)
    {
        $input = $request->validate(['content' => ['required', 'string', 'max:2000']]);

        return $this->respond(function () use ($request, $flag, $input) {
            // The note itself, which they just wrote (no logged read of the rest needed -- reviews).
            return ['note' => AddSafetyFlagNoteAction::present(AddSafetyFlagNoteAction::new()->note($request->user(), SafetyFlag::findPublic($flag), $input['content']))];
        });
    }

    public function action(Request $request, string $flag)
    {
        $input = $request->validate([
            'action' => ['required', Rule::in(SafetyFlagActionEnum::values())],
            'content' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->respond(function () use ($request, $flag, $input) {
            return ['note' => AddSafetyFlagNoteAction::present(AddSafetyFlagNoteAction::new()->action($request->user(), SafetyFlag::findPublic($flag), SafetyFlagActionEnum::from($input['action']), $input['content'] ?? null))];
        });
    }

    public function resolve(Request $request, string $flag)
    {
        $input = $request->validate([
            'outcome' => ['required', Rule::in(SafetyFlagOutcomeEnum::values())],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->respond(function () use ($request, $flag, $input) {
            ResolveSafetyFlagAction::new()->execute($request->user(), SafetyFlag::findPublic($flag), SafetyFlagOutcomeEnum::from($input['outcome']), $input['note']);

            return ['status' => true];
        });
    }

    // TT-4.3c: send, hold or withhold the notice to the person, or to their guardians.
    public function notice(Request $request, string $flag)
    {
        $input = $request->validate([
            'which' => ['required', Rule::in([SendSafetyFlagNoticeAction::CLIENT, SendSafetyFlagNoticeAction::GUARDIANS])],
            'decision' => ['required', Rule::in([DecideSafetyFlagNoticeAction::SEND, DecideSafetyFlagNoticeAction::HOLD, DecideSafetyFlagNoticeAction::WITHHOLD])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->respond(function () use ($request, $flag, $input) {
            $decide = DecideSafetyFlagNoticeAction::new();
            $decide->execute($request->user(), SafetyFlag::findPublic($flag), $input['which'], $input['decision'], $input['reason'] ?? null);

            // The reason they just wrote, to add to what's shown (QA) -- no logged read of the rest.
            return ['status' => true, 'note' => $decide->note ? AddSafetyFlagNoteAction::present($decide->note) : null];
        });
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
