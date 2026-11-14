<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Actions\Guardianship\HandleGuardianLossAction;
use App\DTOs\EnsureDobChangeIsAllowedDTO;
use App\DTOs\UpdateUserDTO;
use App\Exceptions\DobChangeRequiresApprovalException;
use App\Notifications\AccountEmailChangedByTeamNotification;
use App\Notifications\EmailChangedNotification;
use App\Support\MaskedEmail;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class UpdateUserAction extends Action
{
    public function execute(UpdateUserDTO $updateUserDTO)
    {
        $target = $updateUserDTO->updatedUser;
        // SCRUM-425: who may is EnsureCanUpdateUserByAdminAction's (UserService). A new address is never marked verified
        // here: its owner verifies it.
        $emailChanges = EnsureCanUpdateUserByAdminAction::changesEmail($target, $updateUserDTO->email);
        $wasVerified = (bool) $target->email_verified_at;

        $previousEmail = $emailChanges && $target->email_verified_at ? $target->email : null;
        $previousName = $target->name ?: $target->username;

        $data = [
            'firstName' => $updateUserDTO->firstName,
            'lastName' => $updateUserDTO->lastName,
            'otherNames' => $updateUserDTO->otherNames,
            'country' => $updateUserDTO->country,
            'email' => $updateUserDTO->email,
            // Kept when it was already verified (not re-stamped); a new address starts unverified.
            'email_verified_at' => ! $emailChanges && $updateUserDTO->emailVerified ? ($target->email_verified_at ?? now()->utc()) : null,
        ];

        // TT-4.10c/SCRUM-292: `dob` is only ever included below when a value was actually
        // submitted -- previously this always ran `new Carbon($updateUserDTO->dob)`
        // unconditionally, and Carbon treats a null argument as "now", so an admin edit that
        // didn't touch dob at all would have silently reset the target's dob to today. Bundled
        // fix, discovered while adding the gate this exact line needed anyway.
        if ($updateUserDTO->dob) {
            try {
                EnsureDobChangeIsAllowedAction::new()->execute(
                    EnsureDobChangeIsAllowedDTO::new()->fromArray([
                        'user' => $updateUserDTO->updatedUser,
                        'actor' => $updateUserDTO->user,
                        'newDob' => $updateUserDTO->dob,
                    ])
                );

                $data['dob'] = (new Carbon($updateUserDTO->dob))->utc();

                // TT-4.11c/SCRUM-304 security-review finding: this is a direct, unverified admin
                // edit (bypassing ApplyVerifiedDobAction entirely) -- an existing "verified"
                // marker must not silently survive an edit it didn't apply to, mirroring
                // ProfileController::update()'s identical dob_verified_at clearing.
                $data['dob_verified_at'] = null;
            } catch (DobChangeRequiresApprovalException) {
                // dob omitted from $data -- a pending approval Request was already created by
                // the gate above. Everything else the admin submitted still applies below.
            }
        }

        // SCRUM-382: a dob edit that leaves them paused for a missing guardian runs the loss handling.
        // Only then does it take the row lock and the pause reads; any other edit just saves.
        $user = $updateUserDTO->updatedUser->fill($data);

        if ($user->isDirty('dob')) {
            HandleGuardianLossAction::new()->around($user, HandleGuardianLossAction::DOB_CHANGED, fn () => $user->save());
        } else {
            $user->save();
        }

        if ($emailChanges) {
            Log::notice('Account email changed by an administrator', ['admin_id' => $updateUserDTO->user->id, 'user_id' => $target->id]);

            // The address it had before, when verified, is told by email (the new one masked, SCRUM-422); the account
            // itself in the app -- the only way to reach someone with no verified address (security review).
            EmailChangedNotification::sendTo($previousEmail, $previousName, $target->email, byTeam: true);
            $target->notify(new AccountEmailChangedByTeamNotification($target->email ? MaskedEmail::of($target->email) : null));
        } elseif (! $wasVerified && $target->email_verified_at) {
            Log::notice('Account email marked verified by an administrator', ['admin_id' => $updateUserDTO->user->id, 'user_id' => $target->id]);
        }

        return $updateUserDTO->updatedUser->refresh();
    }
}
