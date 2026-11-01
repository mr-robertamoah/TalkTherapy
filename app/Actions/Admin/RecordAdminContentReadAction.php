<?php

namespace App\Actions\Admin;

use App\Actions\Action;
use App\Models\AdminContentAccess;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Message;
use App\Models\Therapy;
use App\Models\User;
use App\Support\AdminBypass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

// SCRUM-360: records an administrator reading content they could only reach because they are an
// administrator -- called by the read paths AFTER their own access check has passed, never from
// inside a boolean check (those also run for sending messages and channel auth). Not recorded:
//  - a non-admin (their access is their own);
//  - an admin who is a participant, or reading a public therapy anyone may read;
//  - an admin reading their own licence/identity document ($owner).
// One row per fetch (a page of messages, one file), never one per message. A file is recorded at
// most once per admin per ten minutes: a chat page loads every attachment in it.
class RecordAdminContentReadAction extends Action
{
    public function execute(
        ?User $viewer,
        string $resource,
        Model $subject,
        Therapy|GroupTherapy|Discussion|null $context = null,
        ?User $owner = null,
        ?int $fileId = null,
        ?int $page = null,
    ): void {
        // Checked first: every non-admin read passes through here, and must cost nothing.
        if (! $viewer?->isAdmin()) {
            return;
        }

        if ($subject instanceof Message && ! $context) {
            $context = AdminBypass::contextOf($subject);
        }

        if (! AdminBypass::applies($viewer, $context, $owner)) {
            return;
        }

        if ($fileId && ! Cache::add("admin-content-read:{$viewer->id}:{$subject->getMorphClass()}:{$subject->getKey()}:{$fileId}", 1, 600)) {
            return;
        }

        AdminContentAccess::create([
            'admin_user_id' => $viewer->id,
            'resource' => $resource,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'file_id' => $fileId,
            'page' => $page,
            'ip' => request()?->ip(),
            'user_agent' => ($agent = request()?->userAgent()) ? mb_substr($agent, 0, 512) : null,
        ]);
    }
}
