<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Enums\ReportStatusEnum;
use App\Models\Counsellor;
use App\Models\Report;
use App\Models\Suspension;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

// SCRUM-367: the daily sweep that erases the personal details of accounts closed longer than the
// retention period (config user.closed_account_retention_days), one at a time through
// AnonymiseClosedAccountAction. An account still answerable for something is left until that's
// over: a suspension that hasn't ended (or hasn't started yet), or a report about it that is still
// open -- otherwise the person could sign up again with the same email meanwhile. One account
// failing doesn't stop the rest; it is retried on the next run.
// SCRUM-376: moved out of AppService.
class AnonymiseClosedAccountsAction extends Action
{
    public function execute(): void
    {
        $counts = ['anonymised' => 0, 'held' => 0, 'retried' => 0, 'failed' => 0];

        User::onlyTrashed()
            ->whereNull('anonymised_at')
            ->where('deleted_at', '<=', now()->subDays(config('user.closed_account_retention_days')))
            ->lazyById()
            ->each(function (User $user) use (&$counts) {
                if ($this->closedAccountIsOnHold($user)) {
                    $counts['held']++;

                    return;
                }

                try {
                    if (AnonymiseClosedAccountAction::new()->execute($user)) {
                        $counts['anonymised']++;
                    } else {
                        $counts['retried']++;
                        Log::warning('A closed account kept a file that could not be deleted; it will be retried.', ['user' => $user->id]);
                    }
                } catch (Throwable $th) {
                    $counts['failed']++;
                    Log::error('Could not anonymise a closed account.', ['user' => $user->id, 'error' => $th->getMessage()]);
                }
            });

        Log::info('Closed accounts anonymisation run.', $counts);
    }

    private function closedAccountIsOnHold(User $user): bool
    {
        $unfinishedSuspension = Suspension::query()
            ->where('user_id', $user->id)
            ->whereNull('lifted_at')
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->exists();

        if ($unfinishedSuspension) {
            return true;
        }

        $counsellorId = Counsellor::withTrashed()->where('user_id', $user->id)->value('id');

        return Report::query()
            ->whereIn('status', [ReportStatusEnum::open->value, ReportStatusEnum::under_review->value])
            ->where(fn ($query) => $query
                ->whereIn('data->userId', [$user->id, (string) $user->id])
                ->when($counsellorId, fn ($q) => $q->orWhereIn('data->counsellorId', [$counsellorId, (string) $counsellorId])))
            ->exists();
    }
}
