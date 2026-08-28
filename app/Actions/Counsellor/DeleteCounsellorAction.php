<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\DTOs\DeleteCounsellorDTO;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Models\Counsellor;
use App\Models\User;
use App\Notifications\CounsellorAccountDeletedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DeleteCounsellorAction extends Action
{
    // SCRUM-134: resolves the former "TODO clean up before deletion" -- everything here plus the
    // soft-delete itself runs in one transaction, mirroring ProfileController::destroy's wrap.
    public function execute(DeleteCounsellorDTO $deleteCounsellorDTO)
    {
        $counsellor = $deleteCounsellorDTO->counsellor;

        $formerClients = $this->getFormerClients($counsellor);

        DB::transaction(function () use ($counsellor) {
            // State-flip, not detach: the pivot's `state` column already models "no longer
            // active" everywhere else this counsellor's group-therapy affiliations are read
            // (e.g. GroupTherapy::isCounsellor()), and hard-detaching would throw away exactly
            // the history that column exists to preserve.
            DB::table('counsellor_group_therapy')
                ->where('counsellor_id', $counsellor->id)
                ->update(['state' => CounsellorGroupTherapyStateEnum::inactive->value, 'updated_at' => now()]);

            // No state column on this pivot and no history to preserve -- mirrors
            // RemoveCounsellorFromDiscussionAction's existing hard-detach.
            $counsellor->discussions()->detach();

            $counsellor->organizationCounsellors()
                ->where('status', OrganizationCounsellorStatusEnum::active->value)
                ->update(['status' => OrganizationCounsellorStatusEnum::ended->value]);

            // Requests this counsellor sent (verification, org applications, discussion invites
            // they initiated) are now moot -- requests awaiting their own decision were already
            // blocked by EnsureCanDeleteCounsellorAction and can't exist here.
            $counsellor->sentRequests()
                ->wherePending()
                ->update(['status' => RequestStatusEnum::inconsequencial->value]);

            $counsellor->delete();
        });

        foreach ($formerClients as $client) {
            $client->notify(new CounsellorAccountDeletedNotification($counsellor));
        }

        return true;
    }

    private function getFormerClients(Counsellor $counsellor): Collection
    {
        $fromTherapies = $counsellor->therapies()->get()
            ->map(fn ($therapy) => $therapy->addedby_type === User::class ? $therapy->addedby : null);

        // Deliberately NOT GroupTherapy::getUsers() -- that helper also returns every OTHER
        // counsellor attached to the group (and, since this counsellor's own pivot row hasn't
        // been flipped to inactive yet at this point, the counsellor being deleted themselves).
        // Only actual members (the group_therapy_user pivot) and a User-owner count as clients.
        $fromGroupTherapies = $counsellor->groupTherapies()->get()
            ->flatMap(function ($groupTherapy) {
                $owner = $groupTherapy->addedby_type === User::class ? $groupTherapy->addedby : null;

                return $groupTherapy->users->merge([$owner]);
            });

        return $fromTherapies->merge($fromGroupTherapies)->filter()->unique('id');
    }
}
