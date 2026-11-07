<?php

namespace App\Http\Controllers;

use App\Actions\Therapy\ResolveTherapyCardStateAction;
use App\DTOs\GetTherapyDTO;
use App\DTOs\GroupTherapyDTO;
use App\DTOs\JoinGroupTherapyDTO;
use App\Exceptions\BadRequestException;
use App\Exceptions\PaymentRequiredException;
use App\Http\Requests\CreateGroupTherapyRequest;
use App\Http\Requests\TherapyAssistanceRequest;
use App\Http\Requests\UpdateGroupTherapyRequest;
use App\Http\Resources\GroupTherapyMiniResource;
use App\Http\Resources\GroupTherapyPreviewResource;
use App\Http\Resources\GroupTherapyResource;
use App\Http\Resources\RequestResource;
use App\Http\Resources\SessionResource;
use App\Http\Resources\TherapyTopicResource;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Services\GroupTherapyService;
use App\Support\FailureResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Throwable;

class GroupTherapyController extends Controller
{
    public function getRandomGroupTherapies(Request $request)
    {
        try {
            $therapies = GroupTherapyService::new()->getRandomGroupTherapies($request->user(), $request->query('seed'));

            return GroupTherapyMiniResource::collection($therapies);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }

    public function getUserGroupTherapies(Request $request)
    {
        try {
            $therapies = GroupTherapyService::new()->getUserGroupTherapies($request->user());

            return GroupTherapyMiniResource::collection($therapies);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function getWardGroupTherapies(Request $request)
    {
        try {
            $therapies = GroupTherapyService::new()->getWardGroupTherapies($request->user());

            return GroupTherapyMiniResource::collection($therapies);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function getCounsellorGroupTherapies(Request $request)
    {
        try {
            $therapies = GroupTherapyService::new()->getCounsellorGroupTherapies($request->user());

            return GroupTherapyMiniResource::collection($therapies);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function createGroupTherapy(CreateGroupTherapyRequest $request)
    {
        try {
            $therapy = GroupTherapyService::new()->createGroupTherapy(
                GroupTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    // SCRUM-296: counsellorId was previously trusted as an arbitrary lookup
                    // (Counsellor::find($request->counsellorId)), letting any caller attribute
                    // the group to a Counsellor they don't own -- CreateGroupTherapyAction treats
                    // a present 'counsellor' as the group's addedby/owner (and, since SCRUM-290,
                    // as the actor whose minor-status snapshot is or isn't captured). The frontend
                    // only ever sends the caller's OWN counsellor id (the "create as a counsellor"
                    // checkbox), so counsellorId is treated purely as a boolean flag here and the
                    // actual Counsellor is always resolved from the authenticated user's own
                    // relation -- mirroring GetPayoutTargetCounsellorAction's identical
                    // don't-trust-a-client-supplied-actor-id precedent (TT-7.6c/SCRUM-227).
                    'counsellor' => $request->counsellorId ? $request->user()->counsellor : null,
                    'name' => $request->name,
                    'about' => $request->about,
                    'per' => $request->per,
                    'currency' => $request->currency,
                    'amount' => $request->amount ? (float) $request->amount : null,
                    'inPersonAmount' => $request->inPersonAmount ? (float) $request->inPersonAmount : null,
                    'allowInPerson' => $request->allowInPerson,
                    'anonymous' => $request->anonymous,
                    'public' => $request->public,
                    'sessionType' => $request->sessionType,
                    'paymentType' => $request->paymentType,
                    'maxSessions' => $request->maxSessions,
                    'cases' => $request->cases,
                    'maxCounsellors' => $request->maxCounsellors,
                    'maxUsers' => $request->maxUsers,
                    'allowAnyone' => $request->allowAnyone,
                    'shareEqually' => $request->shareEqually,
                    'sharePercentage' => $request->sharePercentage ?: null,
                    'counsellorIds' => $request->counsellorIds,
                    'strictPaymentGate' => $request->strictPaymentGate,
                    'allowFreeHistoricalAccess' => $request->allowFreeHistoricalAccess,
                ])
            );

            return response()->json([
                'status' => true,
                'groupTherapy' => new GroupTherapyMiniResource($therapy),
            ]);
        } catch (Throwable $th) {
            return FailureResponse::json($th);
        }
    }

    // Every lookup below reads $request->route(...) rather than the magic ->groupTherapyId
    // property -- see the identical fix/rationale in SessionController (SCRUM-116).
    // SCRUM-380: update/payment-gate/end/delete are reached both by Inertia forms (web routes -- they
    // expect the redirect, and read a failure from errors.alert) and by JSON callers
    // (api.group.therapies.*), exactly like TherapyController's. successResponse()/failureResponse() give
    // each its own answer.
    public function updateGroupTherapy(UpdateGroupTherapyRequest $request)
    {
        try {
            $therapy = GroupTherapyService::new()->updateGroupTherapy(
                GroupTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'groupTherapy' => GroupTherapy::find($request->route('groupTherapyId')),
                    'name' => $request->name,
                    'about' => $request->about,
                    'per' => $request->per,
                    'currency' => $request->currency,
                    'amount' => $request->amount ? (float) $request->amount : null,
                    'inPersonAmount' => $request->inPersonAmount ? (float) $request->inPersonAmount : null,
                    'public' => $request->public,
                    'allowInPerson' => $request->allowInPerson,
                    'anonymous' => $request->anonymous,
                    'sessionType' => $request->sessionType,
                    'paymentType' => $request->paymentType,
                    'maxSessions' => $request->maxSessions,
                    'cases' => $request->cases,
                    'maxCounsellors' => $request->maxCounsellors,
                    'maxUsers' => $request->maxUsers,
                    'allowAnyone' => $request->allowAnyone,
                    'shareEqually' => $request->shareEqually,
                    'sharePercentage' => $request->sharePercentage ?: null,
                    'counsellorIds' => $request->counsellorIds,
                    'strictPaymentGate' => $request->strictPaymentGate,
                    'allowFreeHistoricalAccess' => $request->allowFreeHistoricalAccess,
                ])
            );

            return $this->successResponse($request, ['status' => true, 'groupTherapy' => new GroupTherapyMiniResource($therapy)], Redirect::back());
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    // TT-7.5b-b1/SCRUM-265: see the route comment and GroupTherapyService::updateGroupTherapyPaymentGate()'s
    // own comment -- deliberately separate from updateGroupTherapy() above, mirroring
    // TherapyController::updateStrictPaymentGate()'s exact precedent, so an active counsellor who
    // isn't this group's own addedby can still reach it.
    public function updateGroupTherapyPaymentGate(Request $request)
    {
        $request->validate([
            'strictPaymentGate' => ['nullable', 'boolean'],
            'allowFreeHistoricalAccess' => ['nullable', 'boolean'],
        ]);

        try {
            // SCRUM-380: thrown, so a JSON caller gets the 422 {message} and a visit the same alert.
            if (is_null($request->strictPaymentGate) && is_null($request->allowFreeHistoricalAccess)) {
                throw new BadRequestException('At least one payment gate setting must be provided.', 422);
            }

            GroupTherapyService::new()->updateGroupTherapyPaymentGate(
                GroupTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'groupTherapy' => GroupTherapy::find($request->route('groupTherapyId')),
                    'strictPaymentGate' => is_null($request->strictPaymentGate) ? null : $request->boolean('strictPaymentGate'),
                    'allowFreeHistoricalAccess' => is_null($request->allowFreeHistoricalAccess) ? null : $request->boolean('allowFreeHistoricalAccess'),
                ])
            );

            return $this->successResponse($request, ['status' => true], Redirect::back());
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function deleteGroupTherapy(Request $request)
    {
        try {
            GroupTherapyService::new()->deleteGroupTherapy(
                GroupTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'groupTherapy' => GroupTherapy::find($request->route('groupTherapyId')),
                ])
            );

            return $this->successResponse($request, ['status' => true], Redirect::route('home'));
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function endGroupTherapy(Request $request)
    {
        try {
            GroupTherapyService::new()->endGroupTherapy(
                GroupTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    // SCRUM-380: was 'therapy', a key GroupTherapyDTO doesn't have -- every end failed
                    // with the generic 500 alert.
                    'groupTherapy' => GroupTherapy::find($request->route('groupTherapyId')),
                ])
            );

            return $this->successResponse($request, ['status' => true], Redirect::back());
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function getGroupTherapy(Request $request)
    {
        try {
            $user = $request->user();
            // SCRUM-383: opened from a member invitation link -- the page offers to join (and shows
            // a private group's preview). SCRUM-73: a former member sees the preview of a private
            // group too, to report it or ask to rejoin.
            $page = GroupTherapyService::new()->getGroupTherapyPage(
                GetTherapyDTO::new()->fromArray([
                    'user' => $user,
                    'groupTherapy' => GroupTherapy::find($request->route('groupTherapyId')),
                    'invitationUuid' => is_string($request->query('invitation')) ? $request->query('invitation') : null,
                    'allowPreview' => true,
                ]),
            );
            $therapy = $page['therapy'];
            $invitation = $page['invitation'];
            // What the group is, not who is in it or what has happened in it.
            $preview = $page['preview'];

            $pendingRequest = $preview ? null : $therapy->pendingRequestFor($user?->counsellor);
            // SCRUM-389: the page's "offer to help" / "find a counsellor" from the same rules as the cards.
            if (! $preview) {
                ResolveTherapyCardStateAction::new()->execute(collect([$therapy]), $user);
            }
            $pendingMembershipRequest = $user
                ? $therapy->pendingMembershipRequestFor($user)
                : null;

            return Inertia::render('GroupTherapy/Index', [
                'therapy' => $preview ? new GroupTherapyPreviewResource($therapy) : new GroupTherapyResource($therapy),
                'preview' => $preview,
                // SCRUM-73: they may report the group (and rejoin it by its usual rules).
                'isFormerMember' => $user && $therapy->isFormerMember($user),
                'session' => session('session'),
                'transactionStatus' => session('transactionStatus'),
                'pendingRequest' => $pendingRequest ? new RequestResource($pendingRequest) : null,
                'pendingMembershipRequest' => $pendingMembershipRequest ? new RequestResource($pendingMembershipRequest) : null,
                'linkInvitation' => $invitation ? ['uuid' => $invitation->uuid] : null,
                'recentSessions' => $preview ? [] : SessionResource::collection($therapy->sessions()->with('latestTransaction')->latest()->take(5)->get()->each->setRelation('for', $therapy)),
                'recentTopics' => $preview ? [] : TherapyTopicResource::collection($therapy->topics()->latest()->take(5)->get()),
            ]);
        } catch (PaymentRequiredException $th) {
            // TT-7.5b-b2/SCRUM-266: mirrors TherapyController::getTherapy()'s own identical
            // catch -- without this, a strict-gated GroupTherapy member blocked by
            // EnsureStrictPaymentGateSatisfiedAction fell through to the generic Throwable catch
            // below and lost the "pay to continue" flash state entirely (no prior catch for this
            // exception existed here at all, since GroupTherapy had no strict payment gate before
            // this ticket).
            return $this->redirectForPaymentRequired($th, $request);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::route('home')->with('message', $message);
        }
    }

    public function joinGroupTherapy(Request $request)
    {
        try {
            $result = GroupTherapyService::new()->joinGroupTherapy(
                JoinGroupTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'groupTherapy' => GroupTherapy::find($request->route('groupTherapyId')),
                    'anonymous' => $request->boolean('anonymous'),
                ])
            );

            return response()->json([
                'status' => true,
                'result' => $result instanceof GroupTherapy
                    ? new GroupTherapyResource($result)
                    : new RequestResource($result),
            ]);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    // SCRUM-389: the creator invites more counsellors (counsellorIds), or a counsellor offers to help.
    public function sendAssistanceRequest(TherapyAssistanceRequest $request)
    {
        try {
            GroupTherapyService::new()->sendAssistanceRequest(
                $request->user(),
                GroupTherapy::query()->find($request->route('groupTherapyId')),
                Counsellor::query()->whereKey($request->input('counsellorIds', []))->get(),
                count($request->input('counsellorIds', [])),
            );

            return response()->json(['status' => true]);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    // SCRUM-73
    public function leaveGroupTherapy(Request $request)
    {
        try {
            GroupTherapyService::new()->leaveGroupTherapy(
                GroupTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'groupTherapy' => GroupTherapy::find($request->route('groupTherapyId')),
                ])
            );

            return response()->json(['status' => true]);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function chat(Request $request)
    {
        try {
            $therapy = GroupTherapyService::new()->getGroupTherapy(
                GetTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'groupTherapy' => GroupTherapy::find($request->route('groupTherapyId')),
                ])
            );

            return Inertia::render('GroupTherapy/Chat', [
                'therapy' => new GroupTherapyResource($therapy),
            ]);
        } catch (PaymentRequiredException $th) {
            // TT-7.5b-b2/SCRUM-266: chat() reaches the same EnsureUserHasAccessToTherapyAction as
            // getGroupTherapy() above, so it can throw this exact exception too -- see that
            // catch's own comment.
            return $this->redirectForPaymentRequired($th, $request);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::route('home')->with('message', $message);
        }
    }

    // TT-7.5b-b2/SCRUM-266: mirrors TherapyController::redirectForPaymentRequired()'s own
    // precedent exactly, keyed by groupTherapyId instead of therapyId.
    private function redirectForPaymentRequired(PaymentRequiredException $th, Request $request)
    {
        return Redirect::route('home')
            ->with('message', $th->getMessage())
            ->with('paymentRequired', true)
            ->with('paymentRequiredGroupTherapyId', $request->route('groupTherapyId'));
    }
}
