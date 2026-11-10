<?php

namespace App\Actions\SessionScheduleProposal;

use App\Actions\Action;
use App\Actions\Session\AfterSessionCreatedAction;
use App\Actions\Session\CreateSessionAction;
use App\Actions\Session\EnsureCanCreateSessionAction;
use App\Actions\Session\EnsureSessionDataIsValidAction;
use App\DTOs\CreateSessionDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Exceptions\SessionException;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\SessionScheduleProposalAcceptedNotification;
use Illuminate\Support\Facades\DB;

class AcceptSessionScheduleProposalAction extends Action
{
    // "Option C" (user decision, SCRUM-24/TT-2.5): if the proposed time is no longer valid by
    // accept-time, this does NOT auto-reject and does NOT surface a raw error -- the request
    // stays pending, with `data.staleReason` set, so the counsellor UI (TT-2.5c) can present the
    // three explicit choices: reject outright, counter-propose a new time, or reject with a
    // reason asking the client to propose again. All three of those are just the existing
    // reject/counter actions -- nothing new is needed to support them once this state exists.
    public function execute(RequestResponseDTO $requestResponseDTO): Request
    {
        return DB::transaction(function () use ($requestResponseDTO) {
            $request = Request::query()->lockForUpdate()->findOrFail($requestResponseDTO->request->id);

            if ($request->status !== RequestStatusEnum::pending->value) {
                return $request;
            }

            // Locking the Therapy row too (in addition to the Request row above) closes the same
            // class of stale-read race fixed in SessionScheduleProposalService::propose() --
            // the counsellor could have changed, or another session could have been created,
            // between whenever this was proposed and this accept.
            $therapy = Therapy::query()->lockForUpdate()->findOrFail($request->for_id);

            if (! $therapy->counsellor) {
                $request->update(['data' => array_merge($request->data, [
                    'staleReason' => 'This therapy no longer has an assigned counsellor.',
                ])]);

                return $request->refresh();
            }

            // Defense-in-depth (security review, SCRUM-207): the therapy's counsellor isn't
            // currently reassignable while a proposal is pending anywhere else in the codebase,
            // but if that ever changes, a stale `to`/`from` party on this request must not be
            // able to accept on behalf of whoever the therapy's counsellor now actually is.
            if (! $therapy->counsellor->is($request->to) && ! $therapy->counsellor->is($request->from)) {
                $request->update(['data' => array_merge($request->data, [
                    'staleReason' => 'This therapy\'s assigned counsellor has changed since this proposal was made.',
                ])]);

                return $request->refresh();
            }

            // Always the counsellor, regardless of who actually clicks accept -- architect
            // review, SCRUM-24: CreateSessionAction/EnsureCanCreateSessionAction only ever
            // resolve the session's actor from an admin or a counsellor, so a client accepting a
            // counsellor's counter-proposal must not be passed through as `user` here.
            $sessionDto = CreateSessionDTO::new()->fromArray([
                'user' => $therapy->counsellor->user,
                'name' => $request->data['name'] ?? null,
                'about' => $request->data['about'] ?? null,
                'startTime' => $request->data['startTime'] ?? null,
                'endTime' => $request->data['endTime'] ?? null,
                'for' => $therapy,
                'type' => $request->data['type'] ?? null,
                'paymentType' => $request->data['paymentType'] ?? null,
            ]);

            try {
                EnsureCanCreateSessionAction::new()->execute($sessionDto);
                EnsureSessionDataIsValidAction::new()->execute($sessionDto);
            } catch (SessionException $e) {
                $request->update(['data' => array_merge($request->data, [
                    'staleReason' => $e->getMessage(),
                ])]);

                return $request->refresh();
            }

            $session = CreateSessionAction::new()->execute($sessionDto);

            AfterSessionCreatedAction::new()->execute($session, $sessionDto->user);

            $request->update([
                'status' => RequestStatusEnum::accepted->value,
                'data' => array_merge($request->data, ['sessionId' => $session->id]),
            ]);
            $request = $request->refresh();

            $proposer = User::find($request->data['proposedById'] ?? null);
            $proposer?->notify(new SessionScheduleProposalAcceptedNotification($request));

            return $request;
        });
    }
}
