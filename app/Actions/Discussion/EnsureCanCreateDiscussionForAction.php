<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateDiscussionDTO;
use App\Exceptions\DiscussionException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Therapy;

class EnsureCanCreateDiscussionForAction extends Action
{
    // Deliberately no isAdmin() bypass, unlike the sibling EnsureCan*DiscussionAction checks
    // (update/delete/end), which gate actions on a discussion that already exists -- an admin
    // acting on an existing discussion isn't inventing any new relationship. Here, bypassing
    // this check would let an admin fabricate a counsellor's relationship to a therapy/group
    // therapy that doesn't actually exist, by setting `addedby` to any counsellor via
    // EnsureAddedbyIsValidAction's own admin bypass. That's a materially different, more
    // dangerous kind of bypass, so this check applies to every actor including admins.
    public function execute(CreateDiscussionDTO $createDiscussionDTO)
    {
        // A missing `for` isn't an authorization failure -- defer to
        // EnsureDiscussionDataIsValidAction, which runs right after and already gives this
        // case its own clearer "No therapy or group therapy for the discussion was given."
        // message, rather than masking it with the "not authorized" message below.
        if (is_null($createDiscussionDTO->for)) {
            return;
        }

        // `for->isCounsellor()` is duck-typed -- App\Models\User also defines a zero-argument
        // isCounsellor() with an unrelated meaning, and PHP does not error when extra
        // positional arguments are passed to a method that declares none. Without this
        // instanceof guard, a `for` that resolved to a User (rather than a Therapy/GroupTherapy)
        // would silently call User::isCounsellor(), ignore $addedby entirely, and authorize
        // based on whether $addedby happens to have any counsellor account at all. Today
        // CreateDiscussionDTO::$for's strict Therapy|GroupTherapy|null typing blocks this before
        // the action ever runs, but that's an accident of the DTO's typing, not a guarantee this
        // check should rely on.
        if (
            ($createDiscussionDTO->for instanceof Therapy || $createDiscussionDTO->for instanceof GroupTherapy) &&
            $createDiscussionDTO->addedby instanceof Counsellor &&
            $createDiscussionDTO->for->isCounsellor($createDiscussionDTO->addedby)
        ) {
            return;
        }

        throw new DiscussionException('You are not authorized to create a discussion for this therapy or group therapy.', 422);
    }
}
