<?php

namespace App\Services;

use App\Actions\License\PurgeLicenseDocumentsAction;
use App\DTOs\CreateLicenseDTO;
use App\DTOs\FileUploadDTO;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Counsellor;
use App\Models\License;
use App\Models\LicensingAuthority;
use App\Models\Request;
use Carbon\CarbonInterface;
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
        $citations = $this->licenseCitations();
        $abandoned = $this->abandonedLicenses($citations->keys());
        $licenses = License::query()->with(['files', 'licensingAuthority'])->findMany($citations->keys())->keyBy('id')
            ->union($abandoned->keyBy('id'));

        return $citations
            ->map(function (Collection $citations, $licenseId) use ($licenses) {
                $license = $licenses->get($licenseId);

                if (! $license) {
                    return null;
                }

                $rule = $this->documentRetentionFor($license, $citations);
                $item = $rule && $rule['at'] <= now()
                    ? ['license' => $licenseId, 'clearNumber' => $rule['clearNumber'], 'reason' => $rule['reason']]
                    : null;

                // Nothing left to remove -- already purged (and number cleared, if it had to be).
                $pending = $item && ($license->files->isNotEmpty()
                    || is_null($license->documents_removed_at)
                    || ($item['clearNumber'] && ! is_null($license->number)));

                return $pending ? $item : null;
            })
            ->filter()
            ->merge($abandoned->map(function (License $license) {
                $rule = $this->documentRetentionFor($license, collect());

                return ['license' => $license->id, 'clearNumber' => $rule['clearNumber'], 'reason' => $rule['reason']];
            }))
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

    // SCRUM-50: the policy above for one license, given the verification requests citing it ([{status, decidedAt}],
    // licenseCitations()) -- when its documents go and what goes with them: ['at', 'clearNumber', 'reason'], or null
    // while they are kept (a pending request, or an accepted professional license). applyDocumentRetention() removes
    // them once 'at' has passed; "What we hold about you" shows 'at', so the two can't disagree.
    /**
     * @param  Collection<int, array{license: int, status: string, decidedAt: ?CarbonInterface}>  $citations
     * @return array{at: CarbonInterface, clearNumber: bool, reason: string}|null
     */
    public function documentRetentionFor(License $license, Collection $citations): ?array
    {
        $isNationalId = $license->isNationalId();

        // SCRUM-362: never cited -- an abandoned upload, unless an administrator validated it.
        if ($citations->isEmpty()) {
            return $license->validated ? null : [
                'at' => ($license->created_at?->copy() ?? now())->addDays((int) config('counsellor.license_abandoned_retention_days')),
                'clearNumber' => $isNationalId,
                'reason' => 'abandoned upload',
            ];
        }

        if ($citations->contains('status', RequestStatusEnum::pending->value)) {
            return null;
        }

        $accepted = $citations->where('status', RequestStatusEnum::accepted->value);

        if ($accepted->isNotEmpty()) {
            return $isNationalId ? [
                'at' => $this->decidedPlusDays($accepted->max('decidedAt'), 'counsellor.national_id_retention_days'),
                'clearNumber' => true,
                'reason' => 'accepted national ID',
            ] : null;
        }

        return [
            'at' => $this->decidedPlusDays($citations->max('decidedAt'), 'counsellor.license_rejected_retention_days'),
            'clearNumber' => $isNationalId,
            'reason' => 'rejected',
        ];
    }

    // SCRUM-50: documentRetentionFor() for each of $counsellor's own licenses, keyed by license id -- from their own
    // verification requests only (a license is only ever cited by its counsellor's requests).
    public function documentRetentionForCounsellor(Counsellor $counsellor, Collection $licenses): Collection
    {
        $citations = $this->collectCitations(Request::query()
            ->where('type', RequestTypeEnum::counsellor->value)
            ->where('from_type', $counsellor->getMorphClass())
            ->where('from_id', $counsellor->getKey())
            ->select(['id', 'status', 'data', 'responded_at', 'updated_at'])
            ->get());

        return $licenses->mapWithKeys(fn (License $license) => [
            $license->id => $this->documentRetentionFor($license, $citations->get($license->id, collect())),
        ]);
    }

    // A request decided without a recorded time has always counted as due (as comparing nothing to the cutoff did).
    private function decidedPlusDays(?CarbonInterface $decidedAt, string $configKey): CarbonInterface
    {
        return $decidedAt ? $decidedAt->copy()->addDays((int) config($configKey)) : now();
    }

    // SCRUM-362: licenses no verification request cites, with documents still to remove, whose time has come under
    // documentRetentionFor()'s abandoned-upload rule -- the one place that rule lives (SCRUM-50: the page shows it).
    private function abandonedLicenses(Collection $citedIds): Collection
    {
        return License::query()
            ->with(['files', 'licensingAuthority'])
            ->whereNotIn('id', $citedIds)
            ->whereHas('files')
            // Only narrows what's loaded each night (SCRUM-50 code review); documentRetentionFor() still decides.
            ->where('validated', false)
            ->where('created_at', '<=', now()->subDays((int) config('counsellor.license_abandoned_retention_days')))
            ->get()
            ->filter(fn (License $license) => ($rule = $this->documentRetentionFor($license, collect())) && $rule['at'] <= now())
            ->values();
    }

    // licenseId => [{status, decidedAt}, ...] across every counsellor verification request,
    // streamed so the nightly sweep doesn't hold every request at once. "Decided at" is when the
    // request was decided (SCRUM-362: responded_at, set once); updated_at only for a row without it.
    private function licenseCitations(): Collection
    {
        return $this->collectCitations(Request::query()
            ->where('type', RequestTypeEnum::counsellor->value)
            ->select(['id', 'status', 'data', 'responded_at', 'updated_at'])
            ->lazyById());
    }

    private function collectCitations(iterable $requests): Collection
    {
        $citations = collect();

        foreach ($requests as $request) {
            foreach (['nationalIdLicense', 'otherLicense'] as $key) {
                if (! empty($request->data[$key] ?? null)) {
                    $citations->push(['license' => (int) $request->data[$key], 'status' => $request->status, 'decidedAt' => $request->responded_at ?? $request->updated_at]);
                }
            }
        }

        return $citations->groupBy('license');
    }
}
