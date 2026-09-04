<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\Actions\Organization\RecordOrganizationInvoiceLineForSessionAction;
use App\DTOs\CreateSessionDTO;
use App\Enums\SessionStatusEnum;

class ChangeSessionStatusAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO, string $status)
    {
        $updatedby = null;

        if (
            $status == SessionStatusEnum::in_session->value &&
            ! in_array($createSessionDTO->session->status, [
                SessionStatusEnum::in_session->value,
                SessionStatusEnum::in_session_confirmation->value,
            ])
        ) {
            $status = SessionStatusEnum::in_session_confirmation->value;
        }

        if (
            $status == SessionStatusEnum::held->value &&
            ! in_array($createSessionDTO->session->status, [
                SessionStatusEnum::held->value,
                SessionStatusEnum::held_confirmation->value,
            ])
        ) {
            $status = SessionStatusEnum::held_confirmation->value;
        }

        $updatedby = $this->getUpdatedByBasedOnStatus($createSessionDTO, $status);

        // SCRUM-197: set once, on the first time this session actually reaches a terminal
        // status, and never touched again afterwards -- even if status later gets replayed or
        // flipped back (e.g. a repeat call to /end, or /in_session reopening an already-ended
        // session). SessionNote's edit-grace-window relies on ended_at being a durable "this
        // session ended at exactly this moment" marker, unlike updated_at, which every one of
        // these status-change calls freely re-touches regardless of whether anything changed.
        $endedAt = $createSessionDTO->session->ended_at;
        if (
            ! $endedAt &&
            in_array($status, [
                SessionStatusEnum::held->value,
                SessionStatusEnum::failed->value,
                SessionStatusEnum::abandoned->value,
            ])
        ) {
            $endedAt = now();
        }

        $createSessionDTO->session->update([
            'status' => $status,
            'ended_at' => $endedAt,
        ]);

        if ($createSessionDTO->session->updatedby) {
            $createSessionDTO->session->updatedby()->dissociate();
        }

        if ($updatedby) {
            $createSessionDTO->session->updatedby()->associate($updatedby);
        }

        $createSessionDTO->session->save();

        $session = $createSessionDTO->session->refresh();

        // TT-7.3b-e/SCRUM-236: checked against the FINAL, rewritten $status (not the raw
        // parameter this method received) -- a caller requesting `held` may have been rewritten
        // above to `held_confirmation`, which is not yet the actual clinical event. Deliberately
        // called AFTER the session update has already been saved, not wrapped in any transaction
        // of its own either: billing accrual is a side effect that must degrade to a logged
        // warning on failure, never block or undo the session's own status transition, which is
        // the primary clinical fact being recorded here.
        if ($status === SessionStatusEnum::held->value) {
            RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);
        }

        return $session;
    }

    private function getUpdatedByBasedOnStatus(CreateSessionDTO $createSessionDTO, string $status)
    {
        if (
            in_array($status, [
                SessionStatusEnum::in_session_confirmation->value,
                SessionStatusEnum::held_confirmation->value,
                SessionStatusEnum::pending->value,
                SessionStatusEnum::abandoned->value,
            ])
        ) {
            return $createSessionDTO->user->counsellor?->is($createSessionDTO->session->addedby)
                ? $createSessionDTO->user->counsellor
                : $createSessionDTO->user;
        }

        return null;
    }
}
