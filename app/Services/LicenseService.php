<?php

namespace App\Services;

use App\Actions\License\PurgeLicenseDocumentsAction;
use App\DTOs\CreateLicenseDTO;
use App\DTOs\FileUploadDTO;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\License;
use App\Models\LicensingAuthority;
use App\Models\Request;
use Illuminate\Support\Collection;

class LicenseService extends Service
{
    public function createLicense(CreateLicenseDTO $createLicenseDTO)
    {
        $license = $createLicenseDTO->licensingAuthority->licenses()->create([
            'number' => $createLicenseDTO->number,
        ]);

        $license->addedby()->associate($createLicenseDTO->addedby);

        $license->for()->associate($createLicenseDTO->for);

        $license->save();

        if ($createLicenseDTO->file) {
            $fileService = FileService::new();
            $data = $fileService->uploadFile(FileUploadDTO::new()->fromArray([
                'file' => $createLicenseDTO->file,
                // SCRUM-300: private, never the public disk -- see config/filesystems.php.
                'disk' => 'license_documents',
                'path' => 'licenses',
            ]));

            $file = $fileService->saveFile(
                $createLicenseDTO->addedby,
                $data
            );

            $license->files()->attach([$file->id]);
        }

        return $license->refresh();
    }

    // SCRUM-338: the retention policy for counsellor verification documents, driven from the
    // verification Requests (the only record of what a license was submitted for and how it was
    // decided):
    //  - every request that cites a license is REJECTED, the newest decided more than
    //    license_rejected_retention_days ago: delete the license's documents (and a national ID's
    //    number, which is itself identity data);
    //  - a license on an ACCEPTED request: only a national ID loses its scan and number, once
    //    national_id_retention_days have passed -- the professional license stays as the record
    //    of what verified the counsellor;
    //  - a license cited by a still-PENDING request is never touched, whatever its age;
    //  - SCRUM-362: a license no verification request ever cited (an abandoned upload), not
    //    validated, created more than license_abandoned_retention_days ago.
    // The License rows are always kept. Returns what it removed (or, with $dryRun, would remove).
    public function applyDocumentRetention(bool $dryRun = false): Collection
    {
        $rejectedCutoff = now()->subDays(config('counsellor.license_rejected_retention_days'));
        $nationalIdCutoff = now()->subDays(config('counsellor.national_id_retention_days'));

        $citations = $this->licenseCitations();
        $abandoned = $this->abandonedLicenses($citations->keys());
        $licenses = License::query()->with(['files', 'licensingAuthority'])->findMany($citations->keys())->keyBy('id')
            ->union($abandoned->keyBy('id'));

        return $citations
            ->map(function (Collection $citations, $licenseId) use ($licenses, $rejectedCutoff, $nationalIdCutoff) {
                $license = $licenses->get($licenseId);

                if (! $license || $citations->contains('status', RequestStatusEnum::pending->value)) {
                    return null;
                }

                $isNationalId = $license->isNationalId();
                $accepted = $citations->where('status', RequestStatusEnum::accepted->value);

                $item = match (true) {
                    $accepted->isNotEmpty() => $isNationalId && $accepted->max('decidedAt') <= $nationalIdCutoff
                        ? ['license' => $licenseId, 'clearNumber' => true, 'reason' => 'accepted national ID']
                        : null,
                    default => $citations->max('decidedAt') <= $rejectedCutoff
                        ? ['license' => $licenseId, 'clearNumber' => $isNationalId, 'reason' => 'rejected']
                        : null,
                };

                // Nothing left to remove -- already purged (and number cleared, if it had to be).
                $pending = $item && ($license->files->isNotEmpty()
                    || is_null($license->documents_removed_at)
                    || ($item['clearNumber'] && ! is_null($license->number)));

                return $pending ? $item : null;
            })
            ->filter()
            ->merge($abandoned->map(fn (License $license) => ['license' => $license->id, 'clearNumber' => $license->isNationalId(), 'reason' => 'abandoned upload']))
            ->each(function (array $item) use ($licenses, $dryRun) {
                if (! $dryRun) {
                    PurgeLicenseDocumentsAction::new()->execute($licenses->get($item['license']), $item['clearNumber']);
                }
            })
            ->values();
    }

    // SCRUM-338: before the fix, rejecting a verification request validated both of its licenses
    // (and a not-yet-validated licensing authority). Un-validates a license only when every request
    // citing it was rejected -- one that was ever part of an accepted request is left alone.
    // Licensing authorities are only listed, not changed: an authority may have been validated
    // legitimately by a later accepted request or by an admin, and the requests can't tell which.
    public function repairRejectedVerificationValidations(bool $dryRun = false): array
    {
        $onlyRejected = $this->licenseCitations()
            ->filter(fn (Collection $citations) => $citations->every('status', RequestStatusEnum::rejected->value))
            ->keys();

        $licenses = License::query()->whereIn('id', $onlyRejected)->where('validated', true)->get();

        if (! $dryRun) {
            $licenses->each->invalidate();
        }

        $authorities = LicensingAuthority::query()
            ->whereIn('id', $licenses->pluck('licensing_authority_id')->unique())
            ->where('validated', true)
            ->get(['id', 'name']);

        return ['licenses' => $licenses->pluck('id')->all(), 'authorities' => $authorities];
    }

    // SCRUM-362: licenses no verification request cites, with documents still to remove.
    private function abandonedLicenses(Collection $citedIds): Collection
    {
        return License::query()
            ->with(['files', 'licensingAuthority'])
            ->whereNotIn('id', $citedIds)
            ->where('validated', false)
            ->where('created_at', '<=', now()->subDays(config('counsellor.license_abandoned_retention_days')))
            ->whereHas('files')
            ->get();
    }

    // licenseId => [{status, decidedAt}, ...] across every counsellor verification request,
    // streamed so the nightly sweep doesn't hold every request at once. "Decided at" is when the
    // request was decided (SCRUM-362: responded_at, set once); updated_at only for a row without it.
    private function licenseCitations(): Collection
    {
        $citations = collect();

        Request::query()
            ->where('type', RequestTypeEnum::counsellor->value)
            ->select(['id', 'status', 'data', 'responded_at', 'updated_at'])
            ->lazyById()
            ->each(function (Request $request) use ($citations) {
                foreach (['nationalIdLicense', 'otherLicense'] as $key) {
                    if (! empty($request->data[$key] ?? null)) {
                        $citations->push(['license' => (int) $request->data[$key], 'status' => $request->status, 'decidedAt' => $request->responded_at ?? $request->updated_at]);
                    }
                }
            });

        return $citations->groupBy('license');
    }
}
