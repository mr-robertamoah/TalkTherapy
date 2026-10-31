<?php

namespace App\Actions\License;

use App\Actions\Action;
use App\Exceptions\LicenseDocumentAccessDeniedException;
use App\Models\File;
use App\Models\License;
use App\Models\User;

// SCRUM-300: gates the only retrieval path for a counsellor-verification document (national ID
// scan, professional license) -- an admin (any reviewer), or the counsellor the license belongs
// to, viewing their own upload. Mirrors EnsureUserCanViewIdentityDocumentAction, including its
// security-review rule: every refusal is the SAME 404, so a caller can't tell "this license/file
// pair doesn't exist" from "it does, but you can't see it" -- which would itself confirm that a
// given counsellor submitted identity documents.
class EnsureUserCanViewLicenseDocumentAction extends Action
{
    // 'public' only for rows the move migration hasn't reached yet (code deploys before migrate);
    // a file on any other disk is never streamed under license rules.
    private const LICENSE_DISKS = ['license_documents', 'public'];

    public function execute(User $user, License $license, File $file): void
    {
        $isOwner = $user->counsellor && $license->for()->is($user->counsellor);
        $isAuthorized = $user->isAdmin() || $isOwner;
        $isAttachedToLicense = $license->files()->where('files.id', $file->id)->exists();

        if ($isAuthorized && $isAttachedToLicense && in_array($file->storage, self::LICENSE_DISKS, true)) {
            return;
        }

        throw new LicenseDocumentAccessDeniedException('Document not found.', 404);
    }
}
