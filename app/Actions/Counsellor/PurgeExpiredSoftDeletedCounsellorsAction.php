<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\Actions\License\PurgeLicenseDocumentsAction;
use App\Models\Counsellor;
use App\Models\License;
use Illuminate\Support\Facades\Log;
use Throwable;

// SCRUM-134: a Counsellor is only soft-deleted at deletion time (see DeleteCounsellorAction) so
// the grace period gives a window to notice and undo an accidental/malicious deletion.
// Permanently removed once that window (config('counsellor.deletion_grace_period_days'),
// default 60) has passed. Only the Counsellor row itself is force-deleted -- related
// historical records (therapies, sessions, licenses, testimonials) are left untouched, same
// as they already are for a merely-soft-deleted counsellor.
// SCRUM-338: the counsellor's license documents go with them (the License rows are kept, like
// the rest of their history) -- otherwise ID scans outlived the account they belonged to. One
// counsellor failing is logged and skipped, so it can't stop everyone after it being purged.
// SCRUM-376: moved out of AppService.
class PurgeExpiredSoftDeletedCounsellorsAction extends Action
{
    public function execute(): void
    {
        Counsellor::onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays(config('counsellor.deletion_grace_period_days')))
            ->with('licensesFor.files', 'licensesFor.licensingAuthority')
            ->get()
            ->each(function (Counsellor $counsellor) {
                try {
                    $counsellor->licensesFor->each(fn (License $license) => PurgeLicenseDocumentsAction::new()->execute(
                        $license,
                        clearNumber: $license->isNationalId(),
                    ));

                    $counsellor->forceDelete();
                } catch (Throwable $th) {
                    Log::error('Could not purge a soft-deleted counsellor.', ['counsellor' => $counsellor->id, 'error' => $th->getMessage()]);
                }
            });
    }
}
