<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\FileUploadDTO;
use App\DTOs\SubmitAgeVerificationDTO;
use App\Enums\RequestTypeEnum;
use App\Models\Request;
use App\Models\User;
use App\Services\FileService;
use Illuminate\Support\Facades\DB;

// TT-4.11b/SCRUM-303: a user's own self-attestation (+ optional document) that their dob is
// accurate -- always admin-reviewed (RequestTypeEnum::ageVerification's own null-`to` shape),
// never auto-applied. Document upload is deliberately OPTIONAL (user's own explicit decision):
// requiring one would be a real barrier to someone who needs help right now.
class SubmitAgeVerificationAction extends Action
{
    public function execute(SubmitAgeVerificationDTO $dto): Request
    {
        // Idempotent and race-safe, mirroring EnsureDobChangeIsAllowedAction's own
        // find-or-reuse-under-lock shape: locks the target User row before checking for an
        // already-outstanding request, so two near-simultaneous submissions can never both
        // create a duplicate pending request. No DB-level constraint enforces this (MySQL 8's
        // lack of partial/filtered unique indexes -- same accepted limitation as video_consents
        // and dobChange) -- this is the sole enforcement.
        return DB::transaction(function () use ($dto) {
            User::query()->lockForUpdate()->find($dto->user->id);

            $existing = Request::query()
                ->whereType(RequestTypeEnum::ageVerification->value)
                ->wherePending()
                ->whereFor($dto->user)
                ->first();

            // TT-4.11c/SCRUM-304 security-review finding: `attestedDob` snapshots the dob the
            // user is actually vouching for AT SUBMISSION TIME -- without it, an admin approving
            // this request days later would unconditionally re-affirm whatever `dob` happens to
            // be current on the User row at THAT moment, which may have drifted since (an
            // unrelated edit, a concurrent dobChange approval), silently certifying a value
            // nobody's attestation/document was actually about.
            $data = ['attestation' => $dto->attestation, 'attestedDob' => $dto->user->dob?->toDateString()];

            $request = $existing
                ? tap($existing)->update(['data' => $data])
                : CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
                    'from' => $dto->user,
                    'to' => null,
                    'for' => $dto->user,
                    'type' => RequestTypeEnum::ageVerification->value,
                    'data' => $data,
                ]));

            if ($dto->document) {
                $this->replaceDocument($request, $dto);
            }

            return $request->refresh();
        });
    }

    private function replaceDocument(Request $request, SubmitAgeVerificationDTO $dto): void
    {
        $oldDocument = $request->identityDocument()->first();

        $fileData = FileService::new()->uploadFile(FileUploadDTO::new()->fromArray([
            'disk' => 'identity_documents',
            'path' => '',
            'file' => $dto->document,
        ]));

        $file = FileService::new()->saveFile($dto->user, $fileData);
        $request->identityDocument()->sync([$file->id]);

        if ($oldDocument) {
            FileService::new()->deleteFile($oldDocument);
        }
    }
}
